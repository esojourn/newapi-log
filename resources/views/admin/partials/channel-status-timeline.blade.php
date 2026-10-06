<section class="bg-white rounded-lg shadow overflow-hidden" aria-labelledby="timeline-heading">
    <div class="p-5 md:p-6 border-b space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="timeline-heading" class="text-lg font-semibold">渠道状态时间轴 <span class="text-sm font-normal text-gray-500">（{{ $timeline['channels']->total() }} 个渠道）</span></h2>
                <p class="text-xs text-gray-500 mt-1">每行一个渠道，横轴为时间。正常表示渠道已启用；自动禁用表示故障停用。悬停或点击色块查看时段。</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-xs text-gray-600" aria-label="状态图例">
                @foreach ($timelineStates as $state => $label)
                    <span class="inline-flex items-center gap-1"><span class="alz-timeline-dot alz-timeline-{{ $state }}" aria-hidden="true"></span>{{ $label }}</span>
                @endforeach
            </div>
        </div>
        <form method="GET" action="{{ route('admin.channel-recovery') }}#timeline-heading" class="flex flex-wrap items-end gap-3">
            @foreach (['channel_id', 'result', 'monitor_channel_id', 'monitor_result'] as $filter)
                @if (!empty($filters[$filter]))
                    <input type="hidden" name="{{ $filter }}" value="{{ $filters[$filter] }}">
                @endif
            @endforeach
            <div>
                <label for="timeline-range" class="block text-xs text-gray-600 mb-1">时间范围 / 缩放</label>
                <select id="timeline-range" name="timeline_range" class="alz-input text-sm">
                    @foreach ($timelineRanges as $range => $options)
                        <option value="{{ $range }}" {{ $timeline['range'] === $range ? 'selected' : '' }}>{{ $options['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="timeline-end" class="block text-xs text-gray-600 mb-1">截至时间</label>
                <input type="datetime-local" id="timeline-end" name="timeline_end" class="alz-input text-sm" max="{{ now()->format('Y-m-d\TH:i') }}" value="{{ $filters['timeline_end'] ?? '' }}">
            </div>
            <div>
                <label for="timeline-channel-id" class="block text-xs text-gray-600 mb-1">渠道 ID</label>
                <input type="number" min="1" step="1" id="timeline-channel-id" name="timeline_channel_id" class="alz-input text-sm w-32" placeholder="全部渠道" value="{{ $filters['timeline_channel_id'] ?? '' }}">
            </div>
            <button type="submit" class="alz-btn">更新时间轴</button>
        </form>
        @php
            $timelineQuery = array_intersect_key($filters, array_flip(['channel_id', 'result', 'monitor_channel_id', 'monitor_result', 'timeline_range', 'timeline_end', 'timeline_channel_id']));
            $timelineQuery['timeline_range'] = $timeline['range'];
            $nextEnd = $timeline['end']->copy()->addSeconds($timelineRanges[$timeline['range']]['seconds']);
            if ($nextEnd->gt(now())) {
                $nextEnd = now();
            }
            $rangeKeys = array_keys($timelineRanges);
            $rangeIndex = array_search($timeline['range'], $rangeKeys, true);
        @endphp
        <div class="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-600">
            <p>{{ $timeline['start']->format('Y-m-d H:i') }} → {{ $timeline['end']->format('Y-m-d H:i') }} · {{ config('app.timezone') }}</p>
            <div class="flex flex-wrap items-center gap-3" aria-label="时间轴导航">
                <a class="alz-link" href="{{ route('admin.channel-recovery', array_merge($timelineQuery, ['timeline_end' => $timeline['start']->format('Y-m-d\TH:i')])) }}#timeline-heading">← 上一时段</a>
                @if (!empty($filters['timeline_end']))
                    <a class="alz-link" href="{{ route('admin.channel-recovery', array_merge($timelineQuery, ['timeline_end' => $nextEnd->format('Y-m-d\TH:i')])) }}#timeline-heading">下一时段 →</a>
                @else
                    <span class="text-gray-400" aria-disabled="true">下一时段 →</span>
                @endif
                @if ($rangeIndex > 0)
                    <a class="alz-link" href="{{ route('admin.channel-recovery', array_merge($timelineQuery, ['timeline_range' => $rangeKeys[$rangeIndex - 1]])) }}#timeline-heading">＋ 放大</a>
                @endif
                @if ($rangeIndex < count($rangeKeys) - 1)
                    <a class="alz-link" href="{{ route('admin.channel-recovery', array_merge($timelineQuery, ['timeline_range' => $rangeKeys[$rangeIndex + 1]])) }}#timeline-heading">－ 缩小</a>
                @endif
                <a class="alz-link" href="{{ route('admin.channel-recovery', array_diff_key($timelineQuery, array_flip(['timeline_end']))) }}#timeline-heading">回到现在</a>
                @if (!empty($filters['timeline_channel_id']))
                    <a class="alz-link" href="{{ route('admin.channel-recovery', array_diff_key($timelineQuery, array_flip(['timeline_channel_id']))) }}#timeline-heading">全部渠道</a>
                @endif
            </div>
        </div>
    </div>
    @if (!$timeline['rows'])
        <div class="px-5 py-12 text-center text-sm text-gray-500">{{ !empty($filters['timeline_channel_id']) ? '没有该渠道的状态记录。' : '暂无渠道状态记录。开启自动恢复后，下一轮检查会开始采集自动封禁已开启的渠道。' }}</div>
    @else
        <div class="alz-timeline-scroll" tabindex="0" role="region" aria-label="渠道状态图，可横向滚动">
            <table class="alz-timeline-table w-full text-sm">
                <colgroup><col style="width: 180px;"><col><col style="width: 135px;"></colgroup>
                <thead>
                    <tr>
                        <th scope="col" class="alz-timeline-channel text-left font-medium px-5 py-3">渠道</th>
                        <th scope="col" class="font-normal">
                            <div class="alz-timeline-axis" aria-label="时间">
                                @foreach ($timeline['ticks'] as $tick)
                                    <span class="alz-timeline-tick" style="left: {{ $tick['position'] }}%;" title="{{ $tick['full'] }}">{{ $tick['label'] }}</span>
                                @endforeach
                            </div>
                        </th>
                        <th scope="col" class="text-right font-medium px-5 py-3">自动禁用时长</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($timeline['rows'] as $row)
                        <tr>
                            <th scope="row" class="alz-timeline-channel text-left px-5 py-3 font-normal">
                                <div class="font-medium truncate" title="{{ $row['name'] }}">{{ $row['name'] }}</div>
                                <div class="text-xs text-gray-500 mt-1">#{{ $row['id'] }}</div>
                            </th>
                            <td>
                                <div class="alz-timeline-track" aria-label="#{{ $row['id'] }} 状态时段">
                                    @foreach ($timeline['ticks'] as $tick)
                                        <span class="alz-timeline-grid" style="left: {{ $tick['position'] }}%;" aria-hidden="true"></span>
                                    @endforeach
                                    @foreach ($row['segments'] as $segment)
                                        <button type="button" class="alz-timeline-segment alz-timeline-{{ $segment['state'] }}" style="left: {{ $segment['left'] }}%; width: {{ $segment['width'] }}%;" title="{{ $segment['description'] }}" aria-label="{{ $segment['description'] }}"></button>
                                    @endforeach
                                </div>
                            </td>
                            <td class="text-right px-5 py-3 whitespace-nowrap text-xs {{ $row['disabled_duration'] === '0 秒' ? 'text-gray-400' : 'text-red-600' }}">{{ $row['disabled_duration'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-5 py-3 border-t text-xs text-gray-600">
            <p id="timeline-detail" role="status" aria-live="polite">点击色块查看状态、起止时间和持续时长。</p>
        </div>
    @endif
    @if ($timeline['channels']->hasPages())
        <div class="px-5 py-4 border-t">{{ $timeline['channels']->fragment('timeline-heading')->links() }}</div>
    @endif
    <p class="px-5 py-3 border-t text-xs text-gray-500">状态按检查计划采集，未采集时段显示灰色；两次检查之间的短暂状态变化可能无法记录。测试正常须确认恢复后才显示启用。时间精度取决于采集频率。</p>
</section>
