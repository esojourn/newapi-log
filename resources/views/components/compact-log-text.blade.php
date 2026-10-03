@props(['text', 'lines' => 2])

<details class="alz-log-detail">
    <summary title="{{ $text }}">
        <span class="alz-log-preview{{ $lines === 1 ? ' alz-log-preview-single' : '' }}">{{ $text }}</span>
    </summary>
</details>
