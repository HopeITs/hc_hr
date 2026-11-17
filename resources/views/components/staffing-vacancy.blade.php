{{--
    An open seat on the staffing chart: the same footprint as a person chip,
    drawn as an outline instead of a filled row.

    Dashed line, a dashed circle where the photo would be, the word "Vacant" with
    a small red dot (the seat waits on the viewer) and the post's title.

    position: the post the seat belongs to; optional.
    count:    seats this one chip stands for (shown as "×N" when more than one).
    label:    the words; defaults to general.vacant-position.
    compact / fluid: as on <x-staffing-person>.
--}}
@props(['position' => null, 'count' => 1, 'label' => null, 'compact' => false, 'fluid' => false])

@php
    $count = max(1, (int) $count);
@endphp

<div {{ $attributes->class([
        'flex shrink-0 items-center rounded-control border border-dashed border-line bg-soft',
        $fluid ? 'min-h-14 w-full gap-3 px-3 py-2' : ($compact ? 'h-12 w-48 gap-2 px-2' : 'h-14 w-56 gap-2.5 px-2.5'),
    ]) }}>
    <span aria-hidden="true"
          @class(['flex shrink-0 items-center justify-center rounded-full border-[1.5px] border-dashed border-ink-3 text-ink-3', $compact && ! $fluid ? 'size-8' : 'size-9'])>
        <flux:icon.user-plus variant="micro" class="size-4" />
    </span>

    <span class="min-w-0 flex-1">
        <span class="flex min-w-0 items-center gap-1.5 text-small font-semibold leading-tight text-ink">
            <span class="size-1.5 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
            <span class="truncate">{{ $label ?? __('general.vacant-position') }}</span>
            @if ($count > 1)
                <span class="shrink-0 tabular-nums text-ink-2" aria-hidden="true">×{{ $count }}</span>
                <span class="sr-only">{{ __('general.vacant-seats-count', ['count' => $count]) }}</span>
            @endif
        </span>
        @if ($position?->title)
            <span class="mt-0.5 block truncate text-caption leading-tight text-ink-2" dir="auto">{{ $position->title }}</span>
        @endif
    </span>
</div>
