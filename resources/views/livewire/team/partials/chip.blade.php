{{--
    One absence as a chip: status by text and shape, never colour alone
    (accepted: solid border + tick; pending: dashed border + clock; part of
    the day: half circle). A colleague-level entry has no status and is
    always accepted. $entry is a TeamCoverage projection.
--}}
@php
    $showName ??= true;
    $pending = $entry['status'] === 'pending';
    $label = $pending ? __('team_calendar.status.pending') : __('team_calendar.status.accepted');
@endphp
<span @class([
        'inline-flex max-w-full items-center gap-1 rounded-control px-1.5 py-0.5 text-[11px] leading-tight',
        'border border-dashed border-neutral-500 bg-white text-neutral-800' => $pending,
        'border border-secondary-dark/40 bg-secondary-soft text-secondary-dark' => ! $pending,
        'ring-1 ring-primary' => $entry['is_self'],
    ])
      data-status="{{ $pending ? 'pending' : 'accepted' }}">
    @if ($pending)
        <svg class="size-3 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="M10 6v4l2.5 2"/></svg>
    @else
        <svg class="size-3 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M4.5 10.5l3.5 3.5 7.5-8"/></svg>
    @endif
    @if ($entry['partial'])
        <svg class="size-3 shrink-0" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M10 3a7 7 0 010 14z" fill="currentColor"/></svg>
    @endif
    <span class="truncate">
        @if ($showName){{ $entry['is_self'] ? __('team_calendar.you') : $entry['name'] }} · @endif{{ $entry['type'] }}
    </span>
    {{-- Spoken: the status (when the level carries one) and part-day. --}}
    <span class="sr-only">
        @if ($entry['status'] !== null)({{ $label }})@endif
        @if ($entry['partial'])({{ __('team_calendar.partial') }})@endif
    </span>
    @if ($entry['status'] !== null && $pending)
        <span class="shrink-0 font-semibold" aria-hidden="true">{{ $label }}</span>
    @endif
</span>
