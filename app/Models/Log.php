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
     * - cache_creation_tokens：写入缓存的 token（Claude）
     * - uncached_prompt_tokens：未命中缓存的新输入 token
     *
     * prompt_tokens 是否包含缓存取决于计费语义（见 StatsController 的
     * promptExcludesCacheExpr）：Anthropic 语义下不含，OpenAI 语义下包含。
     * 缓存写入量在有 5m/1h 分档时要取分档之和，合并字段只记了 5m 那一档。
     *
     * other 可能是 NULL、空串或非法 JSON（老日志、充值等非消费类日志），
     * 一律回落到 0。
     *
     * @param object|self $log 查询构造器返回的行或模型实例
     */
    public static function cacheTokens($log): array
    {
        $other = json_decode($log->other ?? '', true);
        if (!is_array($other)) {
            $other = [];
        }

        $cacheTokens = (int) ($other['cache_tokens'] ?? 0);
        $merged = (int) ($other['cache_creation_tokens'] ?? 0);

        // 上游把 cacheWriteTokensTotal() 的结果写进 cache_write_tokens，
        // 等价于 max(合并字段, 5m + 1h)。老日志没有它时按分档自行合并。
        if (isset($other['cache_write_tokens'])) {
            $creation = (int) $other['cache_write_tokens'];
        } else {
            $creation = max(
                $merged,
                (int) ($other['cache_creation_tokens_5m'] ?? 0)
                    + (int) ($other['cache_creation_tokens_1h'] ?? 0)
            );
        }

        // Anthropic 语义，或语义缺失但缓存写入有 5m/1h 分档（legacy Claude 派生）时，
        // prompt_tokens 本身就是未命中的新输入，不需要再减
        $promptExcludesCache = ($other['usage_semantic'] ?? null) === 'anthropic'
            || (!isset($other['usage_semantic']) && $creation > $merged);

        $imageTokens = (int) ($other['image_output'] ?? 0);
        $uncached = (int) $log->prompt_tokens - $imageTokens;
        if (!$promptExcludesCache) {
            // 减的是合并字段，与上游 baseTokens.Sub(dCachedCreationTokens) 一致
            $uncached -= $cacheTokens + $merged;
        }

        return [
            'cache_tokens' => $cacheTokens,
            'cache_creation_tokens' => $creation,
            'uncached_prompt_tokens' => max(0, $uncached),
        ];
    }
}
