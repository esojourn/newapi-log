@props(['firstSeen', 'lastSeen', 'detail'])

<details class="alz-log-detail">
    <summary title="{{ $detail }}">
        <div>最近 <time datetime="{{ $lastSeen->toIso8601String() }}">{{ $lastSeen->format('Y-m-d H:i:s') }}</time></div>
        <div class="text-xs text-gray-500 mt-1">首次 <time datetime="{{ $firstSeen->toIso8601String() }}">{{ $firstSeen->format('Y-m-d H:i:s') }}</time></div>
    </summary>
    <p class="text-xs text-gray-500 mt-2 whitespace-normal">{{ $detail }}</p>
</details>
