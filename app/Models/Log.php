<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    use HasFactory;

    protected $table = 'logs';

    /**
     * 从一行日志的 other 列（NewAPI 写入的 JSON 字符串）解析缓存 token 明细。
     *
     * - cache_tokens：命中缓存被读取的输入 token，按 cache_ratio 打折计费
     * - cache_creation_tokens：写入缓存的 token（Claude），通常按 1.25 倍计费
     *
     * prompt_tokens 已包含这两者。other 可能是 NULL、空串或非法 JSON
     * （老日志、充值等非消费类日志），一律回落到 0。
     *
     * @param object|self $log 查询构造器返回的行或模型实例
     */
    public static function cacheTokens($log): array
    {
        $other = json_decode($log->other ?? '', true);
        if (!is_array($other)) {
            $other = [];
        }

        return [
            'cache_tokens' => (int) ($other['cache_tokens'] ?? 0),
            'cache_creation_tokens' => (int) ($other['cache_creation_tokens'] ?? 0),
        ];
    }
}
