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
