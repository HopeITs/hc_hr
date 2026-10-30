{{--
    "Next holiday" tile (NextHolidayCard), drawn like media-request's .stat: a
    label, one big number, the words under it. One row puts the number and
    the name on a single line and drops the date. No holiday in the next 60 days: one
    quiet line, nothing to count down to.
--}}
@php
    $locale = app()->getLocale();
@endphp
<div class="hc-holiday flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.next-holiday') }}</h2>
            <x-ic name="calendar" class="hc-nt-icon ms-auto" aria-hidden="true" />
        </header>

        @if ($holiday === null)
            <p class="hc-nt-empty">{{ __('dashboard.next-holiday.none', ['count' => \App\Livewire\Dashboard\NextHolidayCard::WINDOW]) }}</p>
        @else
            <div class="hc-holiday-body">
                <p class="hc-holiday-count">
                    @if ($holiday['days'] === 0)
                        <span class="hc-nt-big">{{ __('dashboard.next-holiday.today') }}</span>
                    @else
                        <span class="hc-nt-big num">{{ $holiday['days'] }}</span>
                        <span class="text-small font-bold text-ink-2">{{ trans_choice('dashboard.next-holiday.days-left', $holiday['days'], ['count' => $holiday['days']]) }}</span>
                    @endif
                </p>
                <div class="hc-holiday-what min-w-0">
                    <p class="truncate text-body font-bold text-ink">{{ $holiday['name'] }}</p>
                    <p class="hc-holiday-date truncate text-small text-ink-2">
                        <time datetime="{{ $holiday['date']->toDateString() }}">{{ $holiday['date']->locale($locale)->isoFormat('dddd D MMMM') }}</time>
                        @if ($holiday['length'] > 1)
                            · {{ trans_choice('dashboard.next-holiday.length', $holiday['length'], ['count' => $holiday['length']]) }}
                        @endif
                    </p>
                </div>
            </div>
        @endif
    @endif
</div>
