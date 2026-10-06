<?php

namespace Tests\Feature;

use App\Models\ChannelMonitorLog;
use App\Models\ChannelRecoveryLog;
use App\Models\ChannelRecoverySetting;
use App\Models\ChannelStatusSample;
use App\Services\ChannelStatusTimeline;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChannelStatusTimelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'app.timezone' => 'Asia/Shanghai',
            'channels.recovery_enabled' => true,
            'channels.schedule_cron' => '0 * * * *',
            'database.connections.channel_timeline_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'Asia/Shanghai'));
        Artisan::call('migrate', ['--database' => 'alerts', '--path' => 'database/migrations/alerts', '--force' => true]);
        DB::setDefaultConnection('channel_timeline_test');
        Schema::connection('channel_timeline_test')->create('channels', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('name');
            $table->integer('status');
            $table->integer('auto_ban');
            $table->string('key');
        });
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('channel_timeline_test');
        parent::tearDown();
    }

    public function test_capture_reads_only_monitored_channel_states_and_writes_to_alerts(): void
    {
        foreach ([1 => [1, 1], 2 => [3, 1], 3 => [2, 1], 4 => [1, 0]] as $id => [$status, $autoBan]) {
            DB::table('channels')->insert(['id' => $id, 'name' => '渠道 ' . $id, 'status' => $status, 'auto_ban' => $autoBan, 'key' => 'never-read-this-key']);
        }
        DB::enableQueryLog();
        app(ChannelStatusTimeline::class)->capture(ChannelRecoverySetting::current(), null);

        $this->assertSame([1, 2, 3], ChannelStatusSample::orderBy('channel_id')->pluck('channel_id')->all());
        $this->assertSame('2026-10-06 13:01:00', ChannelStatusSample::first()->expires_at->toDateTimeString());
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('key', $query['query']);
            $this->assertStringNotContainsString('*', $query['query']);
        }
        $this->assertSame(3, DB::table('channels')->where('id', 2)->value('status'));
        Http::assertNothingSent();
    }

    public function test_capture_respects_single_channel_scope(): void
    {
        foreach ([1, 2] as $id) {
            DB::table('channels')->insert(['id' => $id, 'name' => '渠道', 'status' => 1, 'auto_ban' => 1, 'key' => 'secret']);
        }
        app(ChannelStatusTimeline::class)->capture(ChannelRecoverySetting::current(), 2);
        $this->assertSame([2], ChannelStatusSample::pluck('channel_id')->all());
    }

    public function test_timeline_uses_disabled_timestamp_and_confirmed_recovery(): void
    {
        $this->sample('11:00', 1);
        $this->sample('11:30', 3);
        $this->monitor('11:30', ['disabled_at' => '2026-10-06 11:20:00']);
        $this->recovery('11:40');

        $row = $this->timeline()['rows'][0];
        $this->assertSame(['online', 'disabled', 'online'], array_column($row['segments'], 'state'));
        $this->assertSame($this->timestamp('11:20'), $row['segments'][0]['end']);
        $this->assertSame($this->timestamp('11:40'), $row['segments'][1]['end']);
        $this->assertSame('20 分钟', $row['disabled_duration']);
        $this->assertEquals(100, array_sum(array_column($row['segments'], 'width')));
    }

    public function test_healthy_dry_run_and_unconfirmed_actions_remain_disabled(): void
    {
        $this->monitor('11:10', ['result' => 'healthy', 'dry_run' => true]);
        foreach (['pending', 'failed', 'skipped'] as $result) {
            $this->recovery('11:20', ['result' => $result]);
        }
        $this->recovery('11:30', ['completed_at' => null]);
        $states = array_column($this->timeline()['rows'][0]['segments'], 'state');
        $this->assertSame(['unknown', 'disabled'], $states);
    }

    public function test_missing_observations_show_unknown_and_adjacent_samples_merge(): void
    {
        $this->sample('11:10', 1, ['expires_at' => '2026-10-06 11:20:00']);
        $this->sample('11:20', 1, ['expires_at' => '2026-10-06 11:30:00']);
        $this->sample('11:40', 3, ['expires_at' => '2026-10-06 11:50:00']);
        $segments = $this->timeline()['rows'][0]['segments'];
        $this->assertSame(['unknown', 'online', 'unknown', 'disabled', 'unknown'], array_column($segments, 'state'));
        $this->assertSame($this->timestamp('11:10'), $segments[1]['start']);
        $this->assertSame($this->timestamp('11:30'), $segments[1]['end']);
    }

    public function test_stale_disabled_time_does_not_overwrite_recovery_and_future_timestamp_is_ignored(): void
    {
        $this->monitor('11:10', ['disabled_at' => '2026-10-06 11:00:00']);
        $this->recovery('11:20');
        $this->monitor('11:30', ['disabled_at' => '2026-10-06 11:00:00']);
        $this->monitor('11:40', ['disabled_at' => '2026-10-06 13:00:00']);
        $segments = $this->timeline()['rows'][0]['segments'];
        $this->assertSame(['disabled', 'online', 'disabled'], array_column($segments, 'state'));
        $this->assertSame($this->timestamp('11:30'), $segments[1]['end']);
    }

    public function test_previous_state_is_carried_into_window_and_manual_disable_is_distinct(): void
    {
        $this->sample('10:30', 1);
        $this->sample('11:30', 2);
        $segments = $this->timeline()['rows'][0]['segments'];
        $this->assertSame(['online', 'manual'], array_column($segments, 'state'));
        $this->assertSame($this->timestamp('11:00'), $segments[0]['start']);
        $this->assertSame('0 秒', $this->timeline()['rows'][0]['disabled_duration']);
    }

    public function test_expired_historical_records_are_not_extended_to_now(): void
    {
        $this->sample('09:00', 1, ['expires_at' => '2026-10-06 10:01:00']);
        $this->monitor('09:20');
        $this->recovery('09:30');
        $this->assertSame(['unknown'], array_column($this->timeline()['rows'][0]['segments'], 'state'));
    }

    public function test_history_before_collection_shows_channel_name_and_unknown_status(): void
    {
        $this->sample('11:00', 1, ['channel_name' => '始终在线的渠道']);
        $row = $this->timeline(['timeline_end' => '2026-10-06T09:00'])['rows'][0];
        $this->assertSame('始终在线的渠道', $row['name']);
        $this->assertSame(['unknown'], array_column($row['segments'], 'state'));
    }

    public function test_all_scales_and_history_windows_have_correct_time_axis(): void
    {
        foreach (ChannelStatusTimeline::RANGES as $range => $options) {
            $timeline = $this->timeline(['timeline_range' => $range, 'timeline_end' => '2026-10-05T20:30']);
            $this->assertSame($options['seconds'], $timeline['end']->timestamp - $timeline['start']->timestamp);
            $this->assertSame('2026-10-05 20:30:00', $timeline['end']->toDateTimeString());
            $this->assertSame(0, $timeline['ticks'][0]['position']);
            $this->assertSame(100, $timeline['ticks'][$options['ticks']]['position']);
        }
    }

    public function test_fault_discovered_after_history_window_uses_original_disabled_time(): void
    {
        $this->sample('11:00', 1);
        $this->monitor('11:30', ['disabled_at' => '2026-10-06 11:20:00']);
        $row = $this->timeline(['timeline_end' => '2026-10-06T11:25'])['rows'][0];
        $this->assertSame(['unknown', 'online', 'disabled'], array_column($row['segments'], 'state'));
        $this->assertSame('5 分钟', $row['disabled_duration']);
    }

    public function test_future_fault_with_stale_disabled_time_does_not_override_window_recovery(): void
    {
        $this->monitor('11:10', ['disabled_at' => '2026-10-06 11:00:00']);
        $this->recovery('11:20');
        $this->monitor('11:30', ['disabled_at' => '2026-10-06 11:00:00']);
        $row = $this->timeline(['timeline_end' => '2026-10-06T11:25'])['rows'][0];
        $this->assertSame(['unknown', 'disabled', 'online'], array_column($row['segments'], 'state'));
        $this->assertSame($this->timestamp('11:25'), $row['segments'][2]['end']);
    }

    public function test_page_shows_chart_before_logs_without_external_queries_and_escapes_names(): void
    {
        $this->sample('11:00', 1, ['channel_name' => '<script>unsafe()</script>']);
        DB::enableQueryLog();
        $response = $this->withSession(['admin_authenticated' => true])->get('/admin/channel-recovery?timeline_range=day');
        $response->assertOk()->assertSee('渠道状态时间轴')->assertSee('＋ 放大')->assertSee('－ 缩小')
            ->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;', false)->assertDontSee('<script>unsafe()</script>', false);
        $this->assertLessThan(strpos($response->getContent(), 'id="monitor-heading"'), strpos($response->getContent(), 'id="timeline-heading"'));
        $this->assertSame([], DB::getQueryLog());
        Http::assertNothingSent();
    }

    public function test_chart_channel_pagination_and_filters_are_independent_of_log_results(): void
    {
        foreach (range(1, 27) as $id) {
            $this->sample('11:00', 1, ['channel_id' => $id]);
        }
        $this->monitor('11:10', ['channel_id' => 1, 'result' => 'healthy']);
        $this->recovery('11:20', ['channel_id' => 1]);
        $response = $this->withSession(['admin_authenticated' => true])
            ->get('/admin/channel-recovery?timeline_range=hour&monitor_result=failed&result=failed')->assertOk();
        $this->assertCount(25, $response->viewData('timeline')['rows']);
        $this->assertSame(27, $response->viewData('timeline')['channels']->total());
        $this->assertSame(['online', 'disabled', 'online'], array_column($response->viewData('timeline')['rows'][0]['segments'], 'state'));
        $this->assertStringContainsString('monitor_result=failed', $response->viewData('timeline')['channels']->url(2));
        $response = $this->get('/admin/channel-recovery?timeline_page=2')->assertOk();
        $this->assertCount(2, $response->viewData('timeline')['rows']);
        $response = $this->get('/admin/channel-recovery?timeline_channel_id=27&timeline_range=week')->assertOk();
        $this->assertSame([27], array_column($response->viewData('timeline')['rows'], 'id'));
    }

    public function test_invalid_timeline_parameters_are_rejected_and_guests_cannot_view_chart(): void
    {
        $this->get('/admin/channel-recovery?timeline_range=hour')->assertRedirect('/admin/login');
        $this->withSession(['admin_authenticated' => true]);
        foreach ([
            'timeline_range=invalid' => 'timeline_range', 'timeline_channel_id=-1' => 'timeline_channel_id',
            'timeline_end=invalid' => 'timeline_end', 'timeline_end=2026-10-07T12:00' => 'timeline_end',
        ] as $query => $field) {
            $this->get('/admin/channel-recovery?' . $query)->assertSessionHasErrors($field);
        }
    }

    private function timeline(array $filters = []): array
    {
        return app(ChannelStatusTimeline::class)->build(array_merge(['timeline_range' => 'hour'], $filters), ChannelRecoverySetting::current());
    }

    private function sample(string $time, int $status, array $overrides = []): void
    {
        ChannelStatusSample::create(array_merge([
            'channel_id' => 1, 'channel_name' => '测试渠道', 'status' => $status,
            'observed_at' => '2026-10-06 ' . $time . ':00', 'expires_at' => '2026-10-06 12:01:00',
        ], $overrides));
    }

    private function monitor(string $time, array $overrides = []): void
    {
        ChannelMonitorLog::create(array_merge([
            'channel_id' => 1, 'channel_name' => '测试渠道', 'source' => 'scheduled', 'result' => 'failed',
            'created_at' => '2026-10-06 ' . $time . ':00', 'disabled_at' => null,
        ], $overrides));
    }

    private function recovery(string $time, array $overrides = []): void
    {
        ChannelRecoveryLog::create(array_merge([
            'channel_id' => 1, 'channel_name' => '测试渠道', 'source' => 'scheduled', 'result' => 'recovered',
            'from_status' => 3, 'target_status' => 1, 'message' => '已确认恢复', 'completed_at' => '2026-10-06 ' . $time . ':00',
        ], $overrides));
    }

    private function timestamp(string $time): int
    {
        return Carbon::parse('2026-10-06 ' . $time . ':00', 'Asia/Shanghai')->timestamp;
    }
}
