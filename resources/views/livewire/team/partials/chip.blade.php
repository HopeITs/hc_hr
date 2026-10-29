{{--
    One absence as a chip: status by text and shape, never colour alone
    (accepted: solid border + tick; pending: dashed border + clock; part of
    the day: half circle). A colleague-level entry has no status and is
    always accepted. $entry is a TeamCoverage projection.
    $bar: the month grid's form, one bar across the days it covers in a week
    ($before / $after: it carries on past that week, so that end is squared
    off and points onward), like a multi-day event in Google Calendar.
--}}
@php
    $showName ??= true;
    $bar ??= false;
    $before ??= false;
    $after ??= false;
    $pending = $entry['status'] === 'pending';
    $label = $pending ? __('team_calendar.status.pending') : __('team_calendar.status.accepted');
    $who = $entry['is_self'] ? __('team_calendar.you') : $entry['name'];
    $tone = [
        'border border-dashed border-neutral-400 bg-white text-neutral-700' => $pending && ! $entry['is_self'],
        'border border-dashed border-primary/50 bg-white text-primary-hover' => $pending && $entry['is_self'],
        'border border-transparent bg-neutral-100 text-neutral-800' => ! $pending && ! $entry['is_self'],
        'border border-primary/25 bg-primary/10 text-primary-hover' => ! $pending && $entry['is_self'],
    ];
    $fmt = fn (string $date) => \Carbon\CarbonImmutable::parse($date)->locale(app()->getLocale())->translatedFormat('j M');
    $range = $entry['from'] === $entry['to'] ? $fmt($entry['from']) : __('team_calendar.range', ['from' => $fmt($entry['from']), 'to' => $fmt($entry['to'])]);
@endphp

@php($statusIcon = $pending
    ? '<svg class="size-3 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="M10 6v4l2.5 2"/></svg>'
    : '<svg class="size-3 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M4.5 10.5l3.5 3.5 7.5-8"/></svg>')
@php($partialIcon = '<svg class="size-3 shrink-0" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M10 3a7 7 0 010 14z" fill="currentColor"/></svg>')

@if ($bar)
    <span @class([
              'flex h-full w-full min-w-0 items-center gap-1.5 rounded-md px-1.5 py-1 leading-tight',
              'rounded-s-none border-s-0' => $before,
              'rounded-e-none border-e-0' => $after,
              ...$tone,
          ])
          data-status="{{ $pending ? 'pending' : 'accepted' }}"
          title="{{ $who.' · '.$entry['type'].($entry['status'] !== null ? ' · '.$label : '').' · '.$range }}">
        @if ($before)
            <svg class="size-3 shrink-0 opacity-70 ltr:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M7.5 4.5L13 10l-5.5 5.5"/></svg>
        @endif
        <img src="{{ $entry['avatar'] }}" alt="" loading="lazy" class="size-5 shrink-0 rounded-full bg-neutral-200 object-cover">
        <span class="min-w-0 flex-1">
            <span class="block truncate text-[11px] font-semibold">{{ $who }}</span>
            <span class="flex min-w-0 items-center gap-1 text-[10px] opacity-80">
                {!! $statusIcon !!}
                @if ($entry['partial']){!! $partialIcon !!}@endif
                <span class="truncate">{{ $entry['type'] }}@if ($entry['status'] !== null && $pending) · <span class="font-semibold">{{ $label }}</span>@endif</span>
            </span>
        </span>
        @if ($after)
            <svg class="size-3 shrink-0 opacity-70 rtl:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M7.5 4.5L13 10l-5.5 5.5"/></svg>
        @endif
        <span class="sr-only">
            {{ $range }}
            @if ($entry['status'] !== null)({{ $label }})@endif
            @if ($entry['partial'])({{ __('team_calendar.partial') }})@endif
        </span>
    </span>
@else
    <span @class(['inline-flex max-w-full items-center gap-1 rounded-full px-2 py-0.5 text-[11px] leading-tight', ...$tone])
          data-status="{{ $pending ? 'pending' : 'accepted' }}">
        {!! $statusIcon !!}
        @if ($entry['partial']){!! $partialIcon !!}@endif
        <span class="truncate">
            @if ($showName)<span class="font-medium">{{ $who }}</span> · @endif{{ $entry['type'] }}
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
@endif
