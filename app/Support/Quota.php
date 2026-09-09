<?php

namespace App\Support;

/**
 * NewAPI 的 quota 与美元金额之间的换算。
 *
 * 换算比例原先散落在 StatsController、余额计算和几个视图里，预警阈值需要
 * 反向换算（用户填美元 → 存 quota），所以在这里收口一次。
 */
class Quota
{
    /** 1 美元对应的 quota，见 docs/database-schema.md「配额转换公式」 */
    public const PER_DOLLAR = 500000;

    /** quota → 美元，保留 4 位（与页面上各处金额展示一致） */
    public static function toAmount(int $quota): float
    {
        return round($quota / self::PER_DOLLAR, 4);
    }

    /** 美元 → quota，取整存库 */
    public static function fromAmount(float $amount): int
    {
        return (int) round($amount * self::PER_DOLLAR);
    }

    /** quota → '$0.0000' */
    public static function format(int $quota): string
    {
        return '$' . number_format(self::toAmount($quota), 4);
    }
}
