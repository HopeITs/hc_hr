<div class="px-3 py-3 truncate text-table text-ink tabular-nums">
    @if($value === null)
        <span class="opacity-25">&mdash;</span>
    @elseif($column->isRaw())
        {!! $value !!}
    @else
        {{ $value }}
    @endif
</div>
