<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 统计页面的冒烟测试。
 *
 * 只断言各路由能正常渲染（缓存表达式的 SQL 合法、视图字段齐全），
 * 不断言具体数值——数值依赖外部 newapi 库的真实数据。
 */
class StatsSmokeTest extends TestCase
{
    private function asAdmin(): self
    {
        $this->withSession(['admin_authenticated' => true]);

        return $this;
    }

    public function test_dashboard_renders(): void
    {
        $this->asAdmin()->get('/admin?days=7')->assertOk();
    }

    public function test_dashboard_renders_for_all_ranges(): void
    {
        foreach ([1, 3, 7, 30, 90] as $days) {
            $this->asAdmin()->get('/admin?days=' . $days)->assertOk();
        }
    }

    /**
     * days=1 走小时粒度：24 个整点桶，且 SQL 桶键与 PHP 桶键必须逐字一致。
     *
     * 格式对不上时页面照样返回 200，只是所有序列静默变 0，所以这里显式比对键。
     */
    public function test_one_day_range_buckets_by_hour(): void
    {
        $response = $this->asAdmin()->get('/admin?days=1')->assertOk();

        $dates = $response->viewData('dates');
        $this->assertCount(24, $dates);
        $this->assertMatchesRegularExpression('/^\d{2}-\d{2} \d{2}:00$/', $dates[0]);
        $this->assertTrue($response->viewData('hourly'));

        foreach ($response->viewData('dailyAmounts')->keys() as $key) {
            $this->assertContains(
                $key,
                $dates,
                "SQL 桶键 {$key} 不在 PHP 桶列表中，检查 DATE_FORMAT 与 Carbon 的格式/时区是否一致"
            );
        }
    }

    public function test_multi_day_range_buckets_by_date(): void
    {
        $response = $this->asAdmin()->get('/admin?days=7')->assertOk();

        $dates = $response->viewData('dates');
        $this->assertCount(8, $dates);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $dates[0]);
        $this->assertFalse($response->viewData('hourly'));

        foreach ($response->viewData('dailyAmounts')->keys() as $key) {
            $this->assertContains($key, $dates, "SQL 桶键 {$key} 不在 PHP 桶列表中");
        }
    }

    /**
     * 基准时间把 24 小时窗口整体挪到过去：末桶就是基准整点，共仍是 24 个桶。
     */
    public function test_anchor_shifts_hourly_window(): void
    {
        $anchor = \Carbon\Carbon::now()->subDays(2)->startOfHour();

        $response = $this->asAdmin()
            ->get('/admin?days=1&at=' . $anchor->format('Y-m-d\TH:i'))
            ->assertOk();

        $dates = $response->viewData('dates');
        $this->assertCount(24, $dates);
        $this->assertSame($anchor->format('m-d H') . ':00', end($dates));
        $this->assertSame($anchor->copy()->subHours(23)->format('m-d H') . ':00', $dates[0]);
        $this->assertTrue($response->viewData('range')['anchored']);
    }

    /**
     * 只给日期时按当天最后一个整点解释，于是 days=1 正好覆盖那一整天。
     */
    public function test_date_only_anchor_covers_whole_day(): void
    {
        $day = \Carbon\Carbon::now()->subDays(3)->startOfDay();

        $dates = $this->asAdmin()
            ->get('/admin?days=1&at=' . $day->format('Y-m-d'))
            ->assertOk()
            ->viewData('dates');

        $this->assertSame($day->format('m-d') . ' 00:00', $dates[0]);
        $this->assertSame($day->format('m-d') . ' 23:00', end($dates));
    }

    /**
     * 非法与未来的基准时间一律回落到「最新」，不能把窗口推进没有数据的区间。
     */
    public function test_invalid_or_future_anchor_falls_back_to_latest(): void
    {
        $latest = \Carbon\Carbon::now()->startOfHour()->format('m-d H') . ':00';

        foreach (['not-a-time', \Carbon\Carbon::now()->addDay()->format('Y-m-d\TH:i')] as $at) {
            $response = $this->asAdmin()->get('/admin?days=1&at=' . urlencode($at))->assertOk();

            $dates = $response->viewData('dates');

            $this->assertFalse($response->viewData('range')['anchored'], "at={$at} 不应被当作有效基准");
            $this->assertSame($latest, end($dates));
        }
    }

    /**
     * 窗口右端真的落到了 SQL 上：锚定过去某刻时，之后的日志必须被排除在统计外。
     */
    public function test_anchor_excludes_logs_after_the_window(): void
    {
        $bounds = \Illuminate\Support\Facades\DB::table('logs')
            ->selectRaw('MIN(created_at) as mn, MAX(created_at) as mx, COUNT(*) as c')
            ->first();

        if (!$bounds->c || ($bounds->mx - $bounds->mn) < 86400) {
            $this->markTestSkipped('logs 表跨度不足 24 小时，无法验证窗口右端');
        }

        // 锚到最早一条记录所在整点：窗口只覆盖开头那一段，后面的记录必须被裁掉
        $anchor = \Carbon\Carbon::createFromTimestamp($bounds->mn)->startOfHour();
        $until = $anchor->copy()->addHour();

        $total = $this->asAdmin()
            ->get('/admin?days=1&at=' . $anchor->format('Y-m-d\TH:i'))
            ->assertOk()
            ->viewData('overview')->total_requests;

        $expected = \Illuminate\Support\Facades\DB::table('logs')
            ->where('created_at', '>=', $until->copy()->subHours(24)->timestamp)
            ->where('created_at', '<', $until->timestamp)
            ->count();

        $this->assertSame($expected, (int) $total);
        $this->assertLessThan((int) $bounds->c, (int) $total, '窗口右端没有生效，统计仍包含基准时间之后的日志');
    }

    /**
     * 用户维度的三条路径与仪表盘共用同一份视图和 resolveRange()，
     * 基准时间控件少传变量时只会在这几条路径上炸，所以一并冒烟。
     */
    public function test_user_pages_render_with_anchor(): void
    {
        $token = \Illuminate\Support\Facades\DB::table('tokens')
            ->where('key', '<>', '')
            ->first();

        if (!$token) {
            $this->markTestSkipped('tokens 表为空');
        }

        $at = urlencode(\Carbon\Carbon::now()->subDay()->format('Y-m-d\TH:i'));
        $apikey = 'sk-' . $token->key;

        $this->asAdmin()
            ->get('/admin/user/' . urlencode($token->name) . '?days=1&at=' . $at)
            ->assertOk();

        $this->withSession(['user_token_name' => $token->name, 'user_api_key' => $apikey])
            ->get('/usage?days=1&at=' . $at)
            ->assertOk();

        $this->get('/user/' . $apikey . '?days=1&at=' . $at)->assertOk();
    }

    public function test_hourly_breakdown_matches_daily_total(): void
    {
        $token = \Illuminate\Support\Facades\DB::table('logs')
            ->where('type', 2)
            ->value('token_name');

        if ($token === null) {
            $this->markTestSkipped('logs 表无消费记录');
        }

        $date = \Illuminate\Support\Facades\DB::table('logs')
            ->where('token_name', $token)
            ->selectRaw('DATE(FROM_UNIXTIME(created_at)) d')
            ->orderByDesc('created_at')
            ->value('d');

        $response = $this->asAdmin()
            ->get("/admin/user/{$token}/hourly?date={$date}")
            ->assertOk();

        // 逐小时金额之和应等于 SQL 按当日分组的金额，时区一致才成立
        $hourlyTotal = $response->json('total_amount');

        $dailyQuota = \Illuminate\Support\Facades\DB::table('logs')
            ->where('token_name', $token)
            ->whereRaw('DATE(FROM_UNIXTIME(created_at)) = ?', [$date])
            ->sum('quota');

        $this->assertEqualsWithDelta(
            round($dailyQuota / 500000, 4),
            $hourlyTotal,
            0.01,
            '逐小时合计与当日汇总不一致，检查 app.timezone 与数据库会话时区是否相同'
        );
    }
}
