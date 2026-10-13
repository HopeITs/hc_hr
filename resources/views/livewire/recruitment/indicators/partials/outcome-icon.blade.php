{{--
    The glyph for a matching outcome. Pass `outcome` for a fixed one, or
    `expr` (an Alpine expression returning the outcome) for a live cell.
--}}
@php
    $icons = ['met' => 'check', 'partial' => 'minus', 'not_met' => 'x-mark', 'not_applicable' => 'no-symbol', 'unknown' => 'question-mark-circle', 'info' => 'information-circle'];
@endphp
@if (isset($expr))
    @foreach ($icons as $outcome => $icon)
        <flux:icon :name="$icon" variant="micro" :class="$size ?? 'size-4'" aria-hidden="true" x-show="{{ $expr }} === '{{ $outcome }}'" />
    @endforeach
@else
    <flux:icon :name="$icons[$outcome] ?? 'minus'" variant="micro" :class="$size ?? 'size-3.5'" aria-hidden="true" />
@endif
