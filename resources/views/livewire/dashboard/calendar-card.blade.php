{{--
    The calendar tile (Dashboard\CalendarCard). Markers tell event types apart
    by shape, never colour (DESIGN.md Status): the legend and the day dialog
    name them. Size-aware through the tile container ("calendar tile" in
    dashboard.css): the month grid always; from 6×4 up also "Next 7 days"
    and the legend.
--}}
@php
    $dialogId = 'hc-cal-dialog-'.$this->getId();
    $chevron = 'M15 18l-6-6 6-6';
@endphp

<div class="hc-cal flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm"
     x-data="{
        day: null,
        opener: null,
        labels: @js($labels),
        withEvents: @js(array_fill_keys(array_keys($days), true)),
        today: @js($today),
        requestUrl: @js($requestUrl),
        open(date, el) { this.opener = el; this.day = date },
        close() { this.day = null; this.$nextTick(() => this.opener?.isConnected && this.opener.focus()) },
     }">
    <header class="flex shrink-0 items-center gap-2 px-4 pt-3 pb-1">
        <h2 class="min-w-0 truncate text-base font-extrabold text-ink">
            {{ __('dashboard.tiles.calendar') }}
            <span class="font-medium text-ink-2"><span aria-hidden="true"> · </span><x-month-picker :value="$shown" class="px-1" /></span>
        </h2>

        <div class="ms-auto flex shrink-0 items-center gap-1">
            @unless ($isCurrentMonth)
                <button type="button" wire:click="goToday" class="hc-cal-nav px-2 text-small font-bold" title="{{ __('dashboard_calendar.show-today') }}">
                    {{ __('dashboard_calendar.today') }}
                </button>
            @endunless
            {{-- The chevrons point to the reading direction's start and end: mirrored in RTL. --}}
            <button type="button" wire:click="prev" class="hc-cal-nav" aria-label="{{ __('dashboard_calendar.previous-month') }}" title="{{ __('dashboard_calendar.previous-month') }}">
                <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $chevron }}"/></svg>
            </button>
            <button type="button" wire:click="next" class="hc-cal-nav" aria-label="{{ __('dashboard_calendar.next-month') }}" title="{{ __('dashboard_calendar.next-month') }}">
                <svg class="size-4 -scale-x-100 rtl:scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $chevron }}"/></svg>
            </button>
        </div>
    </header>

    @if ($loadFailed)
        <div class="min-h-0 flex-1 p-3">
            <x-ui.load-error class="h-full min-h-0!" />
        </div>
    @else
        <div class="hc-cal-body min-h-0 flex-1 px-3 pb-3">
            <div class="hc-cal-month">
                <div class="hc-cal-weekdays" aria-hidden="true">
                    @foreach ($weekdays as $weekday)
                        <abbr title="{{ $weekday['name'] }}">{{ $weekday['short'] }}</abbr>
                    @endforeach
                </div>

                <div class="hc-cal-days">
                    @foreach ($cells as $cell)
                        <button type="button"
                                wire:key="cal-{{ $cell['date'] }}"
                                class="hc-cal-day"
                                data-date="{{ $cell['date'] }}"
                                @if (! $cell['inMonth']) data-out @endif
                                @if ($cell['isToday']) aria-current="date" @endif
                                aria-haspopup="dialog"
                                aria-label="{{ $cell['label'] }}"
                                x-on:click="open(@js($cell['date']), $el)">
                            <span class="hc-cal-num num">{{ $cell['day'] }}</span>
                            <span class="hc-cal-marks" aria-hidden="true">
                                @foreach (array_slice($cell['markers'], 0, 4) as $type)
                                    <span class="hc-mark hc-mark--{{ $type }}"></span>
                                @endforeach
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>

            <aside class="hc-cal-side" aria-label="{{ __('dashboard_calendar.next-days') }}">
                <h3 class="text-small font-bold text-ink">{{ __('dashboard_calendar.next-days') }}</h3>

                <ul class="min-h-0 flex-1 space-y-2 overflow-y-auto pe-1">
                    @forelse ($upcoming as $date => $events)
                        @foreach ($events as $event)
                            <li wire:key="next-{{ $event['key'] }}" class="flex items-start gap-2 text-small" data-testid="calendar-next">
                                <span class="hc-mark hc-mark--lg hc-mark--{{ $event['type'] }} mt-1.5" aria-hidden="true"></span>
                                <span class="min-w-0">
                                    <span class="block truncate font-bold text-ink">{{ $event['title'] }}</span>
                                    <span class="block truncate text-ink-2">
                                        {{ \Carbon\CarbonImmutable::parse($date)->locale(app()->getLocale())->translatedFormat('D j M') }}<span aria-hidden="true"> · </span>{{ __('dashboard_calendar.types.'.$event['type']) }}
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    @empty
                        <li class="text-small text-ink-2">{{ __('dashboard_calendar.next-days-empty') }}</li>
                    @endforelse
                </ul>

                @if ($types !== [])
                    <div class="hc-cal-legend">
                        <h3 class="sr-only">{{ __('dashboard_calendar.legend') }}</h3>
                        <ul class="flex flex-wrap gap-x-3 gap-y-1 text-caption text-ink-2">
                            @foreach ($types as $type)
                                <li class="flex items-center gap-1.5"><span class="hc-mark hc-mark--lg hc-mark--{{ $type }}" aria-hidden="true"></span>{{ __('dashboard_calendar.types.'.$type) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </aside>
        </div>
    @endif

    {{-- Tiles clip what overflows them, so the dialog lives on <body>. --}}
    <template x-teleport="body">
        <div x-cloak x-show="day !== null" x-transition.opacity.duration.150ms
             class="fixed inset-0 z-50 flex items-center justify-center bg-backdrop p-4"
             x-on:click.self="close()">
            <div id="{{ $dialogId }}" role="dialog" aria-modal="true" aria-labelledby="{{ $dialogId }}-title"
                 x-trap.noscroll="day !== null"
                 x-on:keydown.escape.prevent.stop="close()"
                 class="flex max-h-[85vh] w-full max-w-md flex-col gap-4 overflow-y-auto rounded-surface border border-line-soft bg-card p-5 shadow">
                <div class="flex items-start gap-3">
                    <h2 id="{{ $dialogId }}-title" class="min-w-0 flex-1 text-h4 font-extrabold text-ink" x-text="labels[day] ?? ''"></h2>
                    <button type="button" class="hc-cal-nav -me-2 -mt-2" x-on:click="close()" aria-label="{{ __('dashboard_calendar.close') }}">
                        <x-ic name="x" class="size-5" />
                    </button>
                </div>

                @foreach ($days as $date => $events)
                    <ul class="space-y-3" data-day="{{ $date }}" x-show="day === @js($date)" wire:key="dialog-{{ $date }}">
                        @foreach ($events as $event)
                            <li class="flex items-start gap-3" data-testid="calendar-event">
                                <span class="hc-mark hc-mark--lg hc-mark--{{ $event['type'] }} mt-2" aria-hidden="true"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-small text-ink-2">{{ __('dashboard_calendar.types.'.$event['type']) }}</p>
                                    @if ($event['url'])
                                        <a href="{{ $event['url'] }}" wire:navigate class="font-bold text-ink underline underline-offset-2 hover:no-underline">{{ $event['title'] }}</a>
                                    @else
                                        <p class="font-bold text-ink">{{ $event['title'] }}</p>
                                    @endif
                                    @if ($event['detail'])
                                        <p class="text-small text-ink-2">{{ $event['detail'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endforeach

                <p class="text-body text-ink-2" x-show="day !== null && ! withEvents[day]">{{ __('dashboard_calendar.nothing') }}</p>

                @if ($canRequestLeave)
                    <div x-show="day !== null && day >= today">
                        <a x-bind:href="requestUrl.replace('__DATE__', day ?? '')" wire:navigate class="hc-btn hc-btn--primary w-full" data-testid="calendar-request-leave">
                            <x-ic name="plus" />{{ __('dashboard_calendar.request-leave') }}
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </template>
</div>
