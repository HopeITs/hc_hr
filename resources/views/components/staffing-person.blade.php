{{--
    One person on the staffing chart: a compact row, not an ID card.

    Avatar, name, job title, and a small neutral tag only when the seat is held
    on a service agreement. A tap opens the profile drawer, the same event the
    organisation chart's card sends.

    position: the post they hold (for the title and the contract kind); optional.
    compact:  the chart's compact density (a narrower, shorter row).
    fluid:    full width with a 44px+ touch target (the phone outline).

    The size is fixed (not content-driven) so the canvas measures every card the
    same way and the chips line up in columns.
--}}
@props(['employee', 'position' => null, 'compact' => false, 'fluid' => false])

@php
    $isService = (bool) $position?->activeContracts
        ->firstWhere('employee_id', $employee->id)
        ?->isServiceAgreement();
@endphp

<button type="button" data-no-pan
        wire:click="$dispatch('open-profile', { employeeId: {{ $employee->id }} })"
        aria-label="{{ __('general.view-profile-of', ['name' => $employee->name]) }}"
        {{ $attributes->class([
            'flex shrink-0 cursor-pointer items-center rounded-control border border-line-soft bg-card text-start',
            'transition-colors duration-150 hover:border-line hover:bg-soft motion-reduce:transition-none',
            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink',
            $fluid ? 'min-h-14 w-full gap-3 px-3 py-2' : ($compact ? 'h-12 w-48 gap-2 px-2' : 'h-14 w-56 gap-2.5 px-2.5'),
        ]) }}>
    <img src="{{ $employee->avatar_url }}" alt="" loading="lazy"
         @class(['shrink-0 rounded-full bg-soft object-cover ring-1 ring-line-soft', $compact && ! $fluid ? 'size-8' : 'size-9'])>

    <span class="min-w-0 flex-1">
        <span class="block truncate text-small font-semibold leading-tight text-ink" dir="auto" title="{{ $employee->name }}">{{ $employee->name }}</span>
        @if ($position?->title || $isService)
            <span class="mt-0.5 flex min-w-0 items-center gap-1.5 text-caption leading-tight text-ink-2">
                @if ($position?->title)
                    <span class="truncate" dir="auto">{{ $position->title }}</span>
                @endif
                @if ($isService)
                    <span class="shrink-0 rounded-inset bg-surface px-1 font-semibold text-ink" title="{{ __('general.service-agreement') }}">{{ __('general.service-agreement-short') }}</span>
                @endif
            </span>
        @endif
    </span>
</button>
