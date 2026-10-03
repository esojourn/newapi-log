<?php

namespace App\Services;

use App\Support\ChannelLogMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ChannelLogGroups
{
    public function paginate(Builder $query, array $columns, string $pageName = 'page'): LengthAwarePaginator
    {
        // alerts 固定使用本地 SQLite。连接级函数让历史记录也能直接按正文聚合，
        // 无需回填或将全部日志载入 PHP 内存。
        $connection = $query->getConnection();
        $connection->getPdo()->sqliteCreateFunction('channel_log_message_key', [ChannelLogMessage::class, 'groupingKey'], 1);

        // 先对全部筛选结果聚合，再分页；名称和其他快照取最后写入的记录。
        $groups = (clone $query)
            ->selectRaw('MAX(id) AS latest_id, COUNT(*) AS occurrence_count, MIN(created_at) AS first_seen_at, MAX(created_at) AS last_seen_at');
        foreach ($columns as $column) {
            if (in_array($column, ['message', 'disabled_reason'], true)) {
                $groups->groupByRaw('channel_log_message_key(' . $connection->getQueryGrammar()->wrap($column) . ')');
            } else {
                $groups->groupBy($column);
            }
        }
        $model = $query->getModel();
        $table = $model->getTable();

        return $model->newQuery()
            ->joinSub($groups, 'log_groups', $table . '.id', '=', 'log_groups.latest_id')
            ->select($table . '.*', 'log_groups.occurrence_count', 'log_groups.first_seen_at', 'log_groups.last_seen_at')
            ->withCasts([
                'occurrence_count' => 'integer',
                'first_seen_at' => 'datetime',
                'last_seen_at' => 'datetime',
            ])
            ->orderByDesc('log_groups.last_seen_at')
            ->orderByDesc($table . '.id')
            ->paginate(25, ['*'], $pageName)
            ->withQueryString();
    }
}
