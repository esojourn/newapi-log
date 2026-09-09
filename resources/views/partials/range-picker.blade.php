{{--
    时间范围控件：天数切换 + 基准时间（窗口右端）。

    需要 $days 与 $range（见 StatsController::rangeNav()）。天数链接用 fullUrlWithQuery
    保留 at，「最新」则显式丢掉 at 回到跟随最新数据的状态。
--}}
<div class="flex flex-wrap items-center gap-2">
    <div class="flex rounded-md shadow-sm">
        @foreach ([1, 3, 7, 30, 90] as $d)
            <a href="{{ request()->fullUrlWithQuery(['days' => $d]) }}"
                class="px-2 sm:px-3 py-1 sm:py-1.5 text-xs sm:text-sm border {{ $days == $d ? 'alz-btn-active' : 'bg-white text-gray-700 border-gray-300 alz-btn-day' }} {{ $d == 1 ? 'rounded-l-md' : '' }} {{ $d == 90 ? 'rounded-r-md' : '' }}">
                {{ $d == 1 ? '24小时' : $d . '天' }}
            </a>
        @endforeach
    </div>

    {{-- 基准时间：统计基准时间所在整点/整日及其之前的一整个窗口 --}}
    <form method="GET" action="{{ url()->current() }}" class="flex items-center gap-1"
        title="基准时间，统计窗口：{{ $range['label'] }}">
        <input type="hidden" name="days" value="{{ $days }}">
        <a href="{{ request()->fullUrlWithQuery([$range['param'] => $range['prev']]) }}"
            class="px-2 py-1 sm:py-1.5 text-xs sm:text-sm border border-gray-300 rounded-md bg-white text-gray-700 alz-btn-day leading-none"
            title="向前一段">&lsaquo;</a>
        <input type="datetime-local" name="{{ $range['param'] }}" value="{{ $range['value'] }}"
            max="{{ $range['max'] }}" step="3600" onchange="this.form.submit()"
            class="px-2 py-1 sm:py-1.5 text-xs sm:text-sm border rounded-md bg-white {{ $range['anchored'] ? 'border-gray-400 text-gray-800' : 'border-gray-300 text-gray-500' }}">
        @if ($range['next'])
            <a href="{{ request()->fullUrlWithQuery([$range['param'] => $range['next']]) }}"
                class="px-2 py-1 sm:py-1.5 text-xs sm:text-sm border border-gray-300 rounded-md bg-white text-gray-700 alz-btn-day leading-none"
                title="向后一段">&rsaquo;</a>
        @else
            <span class="px-2 py-1 sm:py-1.5 text-xs sm:text-sm border border-gray-200 rounded-md bg-white text-gray-300 leading-none"
                title="已经是最新">&rsaquo;</span>
        @endif
        @if ($range['anchored'])
            <a href="{{ url()->current() }}?days={{ $days }}"
                class="px-2 py-1 sm:py-1.5 text-xs sm:text-sm text-gray-500 hover:text-gray-700 underline" title="回到最新数据">最新</a>
        @endif
    </form>
</div>
