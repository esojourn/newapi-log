<?php

namespace App\Services;

use App\Models\ChannelMonitorLog;
use App\Models\ChannelRecoveryLog;
use App\Models\ChannelRecoverySetting;
use App\Models\ChannelStatusSample;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChannelStatusTimeline
{
    public const RANGES = [
        'hour' => ['label' => '1 小时', 'seconds' => 3600, 'ticks' => 6],
        'day' => ['label' => '24 小时', 'seconds' => 86400, 'ticks' => 6],
        'week' => ['label' => '7 天', 'seconds' => 604800, 'ticks' => 7],
        'month' => ['label' => '30 天', 'seconds' => 2592000, 'ticks' => 6],
    ];

    public const STATES = [
        'online' => '正常 / 启用',
        'disabled' => '自动禁用',
        'manual' => '手动禁用',
        'unknown' => '未采集 / 状态未知',
    ];

    /** 只读渠道状态，不读取密钥，也不测试启用中的渠道。 */
    public function capture(ChannelRecoverySetting $settings, ?int $onlyChannelId): void
    {
        $observedAt = now();
        $expiresAt = $this->expiresAt($observedAt, $settings->schedule_cron);
        DB::table('channels')->select(['id', 'name', 'status', 'priority', 'weight'])
            ->where('auto_ban', 1)
            ->when($onlyChannelId !== null, fn ($query) => $query->where('id', $onlyChannelId))
            ->chunkById(100, function ($channels) use ($observedAt, $expiresAt) {
                $samples = [];
                $metadata = [];
                foreach ($channels as $channel) {
                    $samples[] = [
                        'channel_id' => $channel->id,
                        'channel_name' => Str::limit((string) $channel->name, 255, ''),
                        'status' => $channel->status,
                        'observed_at' => $observedAt->toDateTimeString(),
                        'expires_at' => $expiresAt->toDateTimeString(),
                    ];
                    $metadata[] = [
                        'channel_id' => $channel->id,
                        'priority' => $channel->priority ?? 0,
                        'weight' => $channel->weight ?? 0,
                        'updated_at' => $observedAt->toDateTimeString(),
                    ];
                }
                DB::connection('alerts')->transaction(function () use ($samples, $metadata) {
                    ChannelStatusSample::insert($samples);
                    DB::connection('alerts')->table('channel_monitor_metadata')
                        ->upsert($metadata, ['channel_id'], ['priority', 'weight', 'updated_at']);
                });
            });
    }

    public function build(array $filters, ChannelRecoverySetting $settings): array
    {
        $range = $filters['timeline_range'] ?? 'day';
        $end = empty($filters['timeline_end'])
            ? now()
            : Carbon::createFromFormat('Y-m-d\TH:i', $filters['timeline_end'], config('app.timezone'))->startOfMinute();
        $start = $end->copy()->subSeconds(self::RANGES[$range]['seconds']);
        $channelId = $filters['timeline_channel_id'] ?? null;

        // 本地记录决定展示范围；页面无需连接外部数据库。按渠道独立分页。
        $ids = ChannelStatusSample::query()->select('channel_id')
            ->union(ChannelMonitorLog::query()->select('channel_id'))
            ->union(ChannelRecoveryLog::query()->select('channel_id'));
        $channels = DB::connection('alerts')->query()->fromSub($ids, 'monitored_channels')
            ->leftJoin('channel_monitor_metadata as channel_sort', 'monitored_channels.channel_id', '=', 'channel_sort.channel_id')
            ->when($channelId, fn ($query) => $query->where('monitored_channels.channel_id', $channelId))
            ->orderByRaw('channel_sort.priority IS NULL ASC')
            ->orderByDesc('channel_sort.priority')->orderByDesc('channel_sort.weight')
            ->orderBy('monitored_channels.channel_id')
            ->paginate(25, ['monitored_channels.channel_id'], 'timeline_page')->withQueryString();
        $channelIds = $channels->pluck('channel_id')->all();
        $samples = $this->records(new ChannelStatusSample(), 'observed_at', $channelIds, $start, $end)->toBase()
            ->selectRaw('id, channel_id, channel_name, observed_at AS at, expires_at, status, NULL AS disabled_at, 0 AS priority');
        // 测试 healthy 与 dry-run 都不能证明渠道已启用。
        $monitors = $this->records(new ChannelMonitorLog(), 'created_at', $channelIds, $start, $end)->toBase()
            ->selectRaw('id, channel_id, channel_name, created_at AS at, NULL AS expires_at, 3 AS status, disabled_at, 1 AS priority');
        $recoveries = $this->records(new ChannelRecoveryLog(), 'completed_at', $channelIds, $start, $end, true)->toBase()
            ->selectRaw('id, channel_id, channel_name, completed_at AS at, NULL AS expires_at, 1 AS status, NULL AS disabled_at, 2 AS priority');
        $observations = DB::connection('alerts')->query()->fromSub($samples->unionAll($monitors)->unionAll($recoveries), 'observations')
            ->orderBy('at')->orderBy('priority')->orderBy('id')->cursor();
        $histories = [];
        // 按时间流式处理并合并相邻状态，30 天视图也无需把所有采样模型载入内存。
        foreach ($observations as $observation) {
            $id = (int) $observation->channel_id;
            $histories[$id] = $histories[$id] ?? ['name' => '', 'segments' => [], 'last_non_disabled_at' => null];
            $at = Carbon::parse($observation->at, config('app.timezone'));
            $expires = $observation->expires_at ? Carbon::parse($observation->expires_at, config('app.timezone')) : $this->expiresAt($at, $settings->schedule_cron);
            $disabledAt = $observation->disabled_at ? Carbon::parse($observation->disabled_at, config('app.timezone'))->timestamp : null;
            $this->observe($histories[$id], $observation->channel_name, $at->timestamp, $expires->timestamp, $this->state((int) $observation->status), $disabledAt);
        }

        $rows = [];
        $missingNames = array_filter($channelIds, fn ($id) => empty($histories[$id]['name']));
        $names = $this->names($missingNames);
        foreach ($channels as $channel) {
            $history = $histories[$channel->channel_id] ?? ['name' => '', 'segments' => []];
            $history['name'] = $history['name'] ?: ($names[$channel->channel_id] ?? '');
            $rows[] = $this->row((int) $channel->channel_id, $history, $start, $end);
        }

        $ticks = [];
        $tickCount = self::RANGES[$range]['ticks'];
        for ($index = 0; $index <= $tickCount; $index++) {
            $time = $start->copy()->addSeconds((int) (self::RANGES[$range]['seconds'] * $index / $tickCount));
            $ticks[] = [
                'position' => 100 * $index / $tickCount,
                'label' => $time->format(in_array($range, ['hour', 'day'], true) ? 'H:i' : 'm/d'),
                'full' => $time->toDateTimeString(),
            ];
        }

        return compact('range', 'start', 'end', 'ticks', 'rows', 'channels');
    }

    /** 下一次计划采集后留 1 分钟余量；中断采集后显示未知，不无限延长正常状态。 */
    private function expiresAt(Carbon $observedAt, string $cron): Carbon
    {
        return Carbon::instance((new CronExpression($cron))->getNextRunDate($observedAt, 0, false, config('app.timezone')))->addMinute();
    }

    /** 窗口内原始事件及每个渠道在窗口前的最后一次记录，保留跨窗口状态。 */
    private function records(Model $model, string $timeColumn, array $channelIds, Carbon $start, Carbon $end, bool $recoveredOnly = false): Builder
    {
        $table = $model->getTable();
        $query = $model->newQuery()->whereIn('channel_id', $channelIds);
        if ($recoveredOnly) {
            $query->where('result', 'recovered')->where('target_status', NewApiChannelClient::STATUS_ENABLED);
        }
        $before = (clone $query)->where($timeColumn, '<', $start)
            ->select('channel_id')->selectRaw('MAX(' . $timeColumn . ') AS last_at')->groupBy('channel_id');
        $previousIds = $model->newQuery()->joinSub($before, 'previous', function ($join) use ($table, $timeColumn) {
            $join->on($table . '.channel_id', '=', 'previous.channel_id')->on($table . '.' . $timeColumn, '=', 'previous.last_at');
        })->select($table . '.id');

        // 截至时间之后首次发现的禁用，仍可通过上游提供的禁用时间还原窗口内故障。
        $futureIds = null;
        if ($model instanceof ChannelMonitorLog) {
            $future = (clone $query)->where('created_at', '>', $end)->where('disabled_at', '<=', $end)
                ->select('channel_id')->selectRaw('MIN(created_at) AS first_at')->groupBy('channel_id');
            $futureIds = $model->newQuery()->joinSub($future, 'future', function ($join) use ($table) {
                $join->on($table . '.channel_id', '=', 'future.channel_id')->on($table . '.created_at', '=', 'future.first_at');
            })->select($table . '.id');
        }

        return $query->where(function ($query) use ($timeColumn, $start, $end, $previousIds, $futureIds) {
            $query->where(fn ($query) => $query->where($timeColumn, '<=', $end)
                ->where(fn ($query) => $query->where($timeColumn, '>=', $start)->orWhereIn('id', $previousIds)));
            if ($futureIds !== null) {
                $query->orWhereIn('id', $futureIds);
            }
        });
    }

    private function state(int $status): string
    {
        return [1 => 'online', 2 => 'manual', 3 => 'disabled'][$status] ?? 'unknown';
    }

    /** 尚未开始采集的历史窗口仍展示渠道名称，不把缺少历史记录当作未命名。 */
    private function names(array $channelIds): array
    {
        $names = [];
        if (!$channelIds) {
            return $names;
        }
        foreach ([new ChannelStatusSample(), new ChannelMonitorLog(), new ChannelRecoveryLog()] as $model) {
            $latestIds = $model->newQuery()->whereIn('channel_id', $channelIds)->selectRaw('MAX(id)')->groupBy('channel_id');
            foreach ($model->newQuery()->whereIn('id', $latestIds)->get(['channel_id', 'channel_name']) as $channel) {
                if (!empty($channel->channel_name) && empty($names[$channel->channel_id])) {
                    $names[$channel->channel_id] = $channel->channel_name;
                }
            }
        }

        return $names;
    }

    private function observe(array &$history, ?string $name, int $observedAt, int $expiresAt, string $state, ?int $disabledAt): void
    {
        $history['name'] = $name ?: $history['name'];
        $at = $observedAt;
        // 旧 status_time 不得覆盖已确认的其他状态。
        if ($disabledAt !== null && $disabledAt <= $at
            && ($history['last_non_disabled_at'] === null || $disabledAt > $history['last_non_disabled_at'])) {
            $at = $disabledAt;
        }
        $segments = &$history['segments'];
        while ($segments && $segments[count($segments) - 1]['start'] >= $at) {
            array_pop($segments);
        }
        if ($segments) {
            $last = count($segments) - 1;
            $segments[$last]['end'] = min($segments[$last]['end'], $at);
        }
        $this->append($segments, $at, $expiresAt, $state);
        if ($state !== 'disabled') {
            $history['last_non_disabled_at'] = $observedAt;
        }
    }

    private function row(int $id, array $history, Carbon $start, Carbon $end): array
    {
        $visible = [];
        $cursor = $start->timestamp;
        $disabledSeconds = 0;
        foreach ($history['segments'] as $segment) {
            $from = max($start->timestamp, $segment['start']);
            $to = min($end->timestamp, $segment['end']);
            if ($to <= $from) {
                continue;
            }
            if ($from > $cursor) {
                $this->append($visible, $cursor, $from, 'unknown');
            }
            $this->append($visible, $from, $to, $segment['state']);
            if ($segment['state'] === 'disabled') {
                $disabledSeconds += $to - $from;
            }
            $cursor = $to;
        }
        if ($cursor < $end->timestamp) {
            $this->append($visible, $cursor, $end->timestamp, 'unknown');
        }
        foreach ($visible as &$segment) {
            $segment['left'] = 100 * ($segment['start'] - $start->timestamp) / ($end->timestamp - $start->timestamp);
            $segment['width'] = 100 * ($segment['end'] - $segment['start']) / ($end->timestamp - $start->timestamp);
            $segment['description'] = '#' . $id . ' · ' . self::STATES[$segment['state']] . ' · '
                . Carbon::createFromTimestamp($segment['start'], config('app.timezone'))->toDateTimeString() . ' → '
                . Carbon::createFromTimestamp($segment['end'], config('app.timezone'))->toDateTimeString()
                . '（' . $this->duration($segment['end'] - $segment['start']) . '）';
        }
        unset($segment);

        return ['id' => $id, 'name' => $history['name'] ?: '未命名渠道', 'segments' => $visible, 'disabled_duration' => $this->duration($disabledSeconds)];
    }

    private function append(array &$segments, int $start, int $end, string $state): void
    {
        $last = count($segments) - 1;
        if ($last >= 0 && $segments[$last]['state'] === $state && $segments[$last]['end'] === $start) {
            $segments[$last]['end'] = $end;
        } else {
            $segments[] = compact('start', 'end', 'state');
        }
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' 秒';
        }
        $minutes = intdiv($seconds, 60);

        return $minutes < 60 ? $minutes . ' 分钟' : intdiv($minutes, 60) . ' 小时 ' . ($minutes % 60) . ' 分钟';
    }
}
