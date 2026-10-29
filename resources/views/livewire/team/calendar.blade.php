{{--
    Team calendar (C5, team-coverage-contract.md). Everything shown comes from
    TeamCoverage::coverage(), already limited to the people the active role may
    see and to the fields its level allows (§4.4): no reason, no balance, and
    no request id at colleague level (row keys are opaque). Status is told by
    text and shape (tick and solid border, clock and dashed border), never by
    colour alone. Phones get the list instead of a tiny month grid.
    Someone who sets holidays (national HR, a program manager — §6.1) adds
    one from any day; the dialog is the holidays page's own, embedded.
--}}
<x-slot:title>
    {{ __('team_calendar.title') }}
</x-slot:title>

@php
    $locale = app()->getLocale();
    $weekdays = collect(range(0, 6))->map(fn (int $d) => \Carbon\CarbonImmutable::now()->startOfWeek(\Carbon\CarbonInterface::SUNDAY)->addDays($d)->locale($locale));
    $rangeLabel = match ($viewName) {
        'day' => $from->locale($locale)->translatedFormat('l j F Y'),
        'week' => __('team_calendar.range', ['from' => $from->locale($locale)->translatedFormat('j M'), 'to' => $to->locale($locale)->translatedFormat('j M Y')]),
        default => $anchor->locale($locale)->translatedFormat('F Y'),
    };
    // The list shows the days that matter: in a month, only those of that month with someone away or a holiday.
    $listDays = collect($days)->filter(fn (array $day) => match ($viewName) {
        'week', 'day' => true,
        default => ($day['members'] !== [] || isset($holidays[$day['date']]) || isset($birthdays[$day['date']])) && substr($day['date'], 0, 7) === $anchor->format('Y-m'),
    });
    $iconBtn = 'inline-flex size-10 items-center justify-center rounded-full border border-neutral-200 bg-white text-neutral-700 shadow-xs transition hover:border-neutral-300 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
@endphp

<div class="space-y-5">
    {{-- Title --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <livewire:layout.breadcrumbs :title="__('team_calendar.title')" :crumbs="[]" />
            <p class="mt-1 max-w-2xl text-small text-secondary-medium">{{ __('team_calendar.intro') }}</p>
        </div>

        @if ($canManageHolidays)
            <button type="button" wire:click="$dispatch('openHolidayCreate', { date: '{{ now()->toDateString() }}' })"
                    data-testid="calendar-add-holiday"
                    class="inline-flex min-h-11 items-center gap-2 rounded-full bg-primary px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M10 4v12M4 10h12"/></svg>
                {{ __('team_calendar.add_holiday') }}
            </button>
        @endif
    </div>

    {{-- Toolbar --}}
    <div class="flex flex-wrap items-end gap-3 rounded-2xl border border-neutral-200 bg-white p-3 shadow-xs">
        <div role="group" aria-label="{{ __('team_calendar.views.label') }}" class="inline-flex rounded-full bg-neutral-100 p-1">
            @foreach (\App\Livewire\Team\Calendar::VIEWS as $option)
                <button type="button" wire:click="$set('view', '{{ $option }}')" aria-pressed="{{ $viewName === $option ? 'true' : 'false' }}"
                        data-testid="calendar-view-{{ $option }}"
                        @class([
                            'min-h-9 rounded-full px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                            'bg-white text-primary shadow-sm' => $viewName === $option,
                            'text-neutral-600 hover:text-neutral-900' => $viewName !== $option,
                            // The month grid is a desktop view; phones read the list.
                            'hidden md:inline-flex md:items-center' => $option === 'month',
                            'inline-flex items-center' => $option !== 'month',
                        ])>
                    {{ __("team_calendar.views.{$option}") }}
                </button>
            @endforeach
        </div>

        <div class="ms-auto flex flex-wrap items-end gap-3">
            <div class="min-w-48 flex-1 sm:flex-none">
                <x-forms.input-label for="calendar-scope" class="text-caption">{{ __('team_calendar.scopes.label') }}</x-forms.input-label>
                <x-forms.select-input id="calendar-scope" wire:model.live="scope" class="min-h-10">
                    @foreach ($scopes as $option)
                        <option value="{{ $option }}" @selected($option === $currentScope)>{{ __("team_calendar.scopes.{$option}") }}</option>
                    @endforeach
                </x-forms.select-input>
            </div>

            @if ($managerView)
                <div class="min-w-40 flex-1 sm:flex-none">
                    <x-forms.input-label for="calendar-status" class="text-caption">{{ __('team_calendar.status.label') }}</x-forms.input-label>
                    <x-forms.select-input id="calendar-status" wire:model.live="status" class="min-h-10">
                        @foreach (\App\Livewire\Team\Calendar::STATUSES as $option)
                            <option value="{{ $option }}" @selected($option === $currentStatus)>{{ __("team_calendar.status.{$option}") }}</option>
                        @endforeach
                    </x-forms.select-input>
                </div>
            @endif

            @if ($cities->isNotEmpty())
                <div class="min-w-40 flex-1 sm:flex-none">
                    <x-forms.input-label for="calendar-city" class="text-caption">{{ __('team_calendar.city.label') }}</x-forms.input-label>
                    <x-forms.select-input id="calendar-city" wire:model.live="cityId" class="min-h-10">
                        <option value="0">{{ __('team_calendar.city.all') }}</option>
                        @foreach ($cities as $city)
                            <option value="{{ $city->id }}" @selected($city->id === $currentCity)>{{ $city->name }}</option>
                        @endforeach
                    </x-forms.select-input>
                </div>
            @endif
        </div>
    </div>

    {{-- Period navigation and legend --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="move(-1)" class="{{ $iconBtn }}" aria-label="{{ __('team_calendar.nav_buttons.previous') }}">
                <svg class="size-4 rtl:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12.5 4.5L7 10l5.5 5.5"/></svg>
            </button>
            <button type="button" wire:click="move(1)" class="{{ $iconBtn }}" aria-label="{{ __('team_calendar.nav_buttons.next') }}">
                <svg class="size-4 rtl:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7.5 4.5L13 10l-5.5 5.5"/></svg>
            </button>
            <h2 class="ms-1 text-xl font-bold tracking-tight text-ink" aria-live="polite" data-testid="calendar-range">
                <x-month-picker :value="$anchor" :label="$rangeLabel" class="px-2 py-1" />
            </h2>
            <button type="button" wire:click="goToday"
                    class="ms-1 min-h-9 rounded-full border border-neutral-200 bg-white px-3.5 text-sm font-semibold text-neutral-700 shadow-xs transition hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                {{ __('team_calendar.nav_buttons.today') }}
            </button>
        </div>

        <ul class="flex flex-wrap items-center gap-x-4 gap-y-1 text-caption text-neutral-600" aria-label="{{ __('team_calendar.legend_label') }}">
            <li class="inline-flex items-center gap-1.5">
                <span class="inline-flex size-4 items-center justify-center rounded-full bg-neutral-100 text-neutral-700"><svg class="size-2.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M4.5 10.5l3.5 3.5 7.5-8"/></svg></span>
                {{ __('team_calendar.status.accepted') }}
            </li>
            <li class="inline-flex items-center gap-1.5">
                <span class="inline-flex size-4 items-center justify-center rounded-full border border-dashed border-neutral-400 text-neutral-700"><svg class="size-2.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="M10 6v4l2.5 2"/></svg></span>
                {{ __('team_calendar.status.pending') }}
            </li>
            <li class="inline-flex items-center gap-1.5">
                <svg class="size-3.5 text-neutral-600" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M10 3a7 7 0 010 14z" fill="currentColor"/></svg>
                {{ __('team_calendar.partial') }}
            </li>
            <li class="inline-flex items-center gap-1.5">
                <span class="size-3 rounded-sm bg-primary/15 ring-1 ring-primary/30"></span>
                {{ __('team_calendar.holiday') }}
            </li>
            <li class="inline-flex items-center gap-1.5">
                <svg class="size-3.5 text-neutral-600" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3.5 17h13M4.5 17v-5.5a1.5 1.5 0 011.5-1.5h8a1.5 1.5 0 011.5 1.5V17M4.5 13.5c1 .8 2.2.8 3 0s2.2-.8 3 0 2.2.8 3 0 1.5-.5 2 0M10 10V7.5M10 5.5c-.8-.7-.8-1.6 0-2.5.8.9.8 1.8 0 2.5z"/></svg>
                {{ __('team_calendar.birthday') }}
            </li>
        </ul>
    </div>
    <p class="sr-only">{{ __('team_calendar.legend') }}</p>

    <div wire:loading.class="opacity-60" wire:target="move,jumpTo,goToday,view,scope,status,cityId" class="transition-opacity">
        @if ($failed)
            <div class="rounded-2xl border border-neutral-200 bg-white p-6 text-center" role="alert">
                <p class="text-body text-neutral-800">{{ __('team_calendar.failed') }}</p>
                <button type="button" wire:click="$refresh" class="mt-3 min-h-11 rounded-full border border-primary px-4 font-semibold text-primary">{{ __('general.toast.try-again') }}</button>
            </div>
        @else
            {{-- Month grid: desktop only. --}}
            @if ($viewName === 'month')
                <div class="hidden overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm md:block" data-testid="calendar-month-grid">
                    <div class="grid grid-cols-7 border-b border-neutral-200 bg-neutral-50/80">
                        @foreach ($weekdays as $weekday)
                            <div class="px-3 py-2.5 text-center text-[11px] font-semibold uppercase tracking-wider text-neutral-500" aria-hidden="true">
                                <span class="lg:hidden">{{ $weekday->translatedFormat('D') }}</span>
                                <span class="hidden lg:inline">{{ $weekday->translatedFormat('l') }}</span>
                            </div>
                        @endforeach
                    </div>

                    @foreach (\App\Livewire\Team\Calendar::monthWeeks($days) as $week)
                        @php
                            // Rows: date, holidays, one per bar lane, "+N more", the away count.
                            $lanes = $week['lanes'];
                            $moreRow = 3 + $lanes;
                            $rows = 'auto auto'.($lanes > 0 ? " repeat({$lanes}, auto)" : '').' auto 1fr';
                        @endphp
                        <div wire:key="week-{{ $week['days'][0]['date'] }}"
                             @class(['grid min-h-32 grid-cols-7 gap-y-1', 'border-t border-neutral-100' => ! $loop->first])
                             style="grid-template-rows: {{ $rows }}">
                            @foreach ($week['days'] as $day)
                                @php
                                    $col = $loop->iteration;
                                    $date = \Carbon\CarbonImmutable::parse($day['date']);
                                    $inMonth = $date->format('Y-m') === $anchor->format('Y-m');
                                    $dayHolidays = $holidays[$day['date']] ?? [];
                                    $isHoliday = $day['is_holiday'] || $dayHolidays !== [];
                                    $awayCount = $day['away'] + $day['partial'];
                                    $dayBirthdays = $birthdays[$day['date']] ?? [];
                                    $requestHere = $requestUrl !== null && $day['date'] >= $today;
                                    $cellClasses = [
                                        'border-neutral-100',
                                        'border-e' => $col !== 7,
                                        'bg-white' => $inMonth && ! $isHoliday && ! $day['is_weekend'],
                                        'bg-neutral-50/70' => $inMonth && $day['is_weekend'] && ! $isHoliday,
                                        'bg-primary/[0.04]' => $inMonth && $isHoliday,
                                        'bg-neutral-50/40' => ! $inMonth,
                                    ];
                                @endphp
                                {{-- The cell's background, under its date, holidays and the bars crossing it.
                                     From today on, its empty space opens the leave form on that day. --}}
                                @if ($requestHere)
                                    <a wire:key="cell-{{ $day['date'] }}" href="{{ str_replace('__DATE__', $day['date'], $requestUrl) }}" wire:navigate
                                       data-testid="calendar-request-{{ $day['date'] }}"
                                       aria-label="{{ __('team_calendar.request_leave_on', ['date' => $date->locale($locale)->translatedFormat('l j F')]) }}"
                                       style="grid-column: {{ $col }}; grid-row: 1 / -1"
                                       @class([...$cellClasses, 'group/cell relative transition-colors hover:bg-neutral-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary'])>
                                        <span class="pointer-events-none absolute inset-x-0 top-2.5 flex justify-center opacity-0 transition-opacity group-hover/cell:opacity-100 group-focus-visible/cell:opacity-100" aria-hidden="true">
                                            <span class="inline-flex items-center gap-1 rounded-full bg-primary px-2 py-0.5 text-[10px] font-semibold text-white shadow-sm">
                                                <svg class="size-2.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M10 4v12M4 10h12"/></svg>
                                                {{ __('team_calendar.request_leave') }}
                                            </span>
                                        </span>
                                    </a>
                                @else
                                    <div wire:key="cell-{{ $day['date'] }}" aria-hidden="true"
                                         style="grid-column: {{ $col }}; grid-row: 1 / -1"
                                         @class($cellClasses)></div>
                                @endif

                                {{-- Clicks pass through to the cell's link; only the buttons take them. --}}
                                <div class="group pointer-events-none flex items-center justify-between gap-1 px-2 pt-2 [&_button]:pointer-events-auto" style="grid-column: {{ $col }}; grid-row: 1">
                                    <span @class([
                                        'inline-flex size-7 items-center justify-center rounded-full text-sm',
                                        'bg-primary font-bold text-white shadow-sm' => $date->isToday(),
                                        'font-semibold text-neutral-900' => ! $date->isToday() && $inMonth,
                                        'text-neutral-400' => ! $date->isToday() && ! $inMonth,
                                    ])>
                                        <span class="sr-only">{{ $date->locale($locale)->translatedFormat('l j F') }}</span>
                                        <span aria-hidden="true">{{ $date->day }}</span>
                                    </span>

                                    <div class="flex items-center gap-1">
                                        @if ($day['is_weekend'] && ! $isHoliday)
                                            <span class="text-[10px] font-medium uppercase tracking-wide text-neutral-400">{{ __('team_calendar.weekend') }}</span>
                                        @endif
                                        @if ($canManageHolidays)
                                            <button type="button"
                                                    wire:click="$dispatch('openHolidayCreate', { date: '{{ $day['date'] }}' })"
                                                    aria-label="{{ __('team_calendar.add_holiday_on', ['date' => $date->locale($locale)->translatedFormat('j F')]) }}"
                                                    title="{{ __('team_calendar.add_holiday') }}"
                                                    class="inline-flex size-6 items-center justify-center rounded-full text-neutral-400 opacity-0 transition hover:bg-primary/10 hover:text-primary focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-primary group-hover:opacity-100">
                                                <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M10 4v12M4 10h12"/></svg>
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                @if ($dayHolidays !== [] || $day['is_holiday'] || $dayBirthdays !== [])
                                    <div class="flex min-w-0 flex-col gap-1 px-2" style="grid-column: {{ $col }}; grid-row: 2">
                                        @foreach ($dayHolidays as $holiday)
                                            @if ($holiday['editable'])
                                                <button type="button" wire:click="$dispatch('openHolidayEdit', { id: {{ $holiday['id'] }} })"
                                                        class="flex w-full items-center gap-1 truncate rounded-md bg-primary/10 px-1.5 py-0.5 text-start text-[11px] font-semibold text-primary-hover transition hover:bg-primary/15 focus-visible:outline-2 focus-visible:outline-primary"
                                                        title="{{ $holiday['name'] }}">
                                                    <svg class="size-3 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 2.75a.75.75 0 011.5 0V3h9.25a.75.75 0 01.6 1.2L13.06 7.25l2.29 3.05a.75.75 0 01-.6 1.2H5.5v5.75a.75.75 0 01-1.5 0V2.75z"/></svg>
                                                    <span class="truncate">{{ $holiday['name'] }}</span>
                                                </button>
                                            @else
                                                <span class="flex items-center gap-1 truncate rounded-md bg-primary/10 px-1.5 py-0.5 text-[11px] font-semibold text-primary-hover" title="{{ $holiday['name'] }}">
                                                    <svg class="size-3 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 2.75a.75.75 0 011.5 0V3h9.25a.75.75 0 01.6 1.2L13.06 7.25l2.29 3.05a.75.75 0 01-.6 1.2H5.5v5.75a.75.75 0 01-1.5 0V2.75z"/></svg>
                                                    <span class="truncate">{{ $holiday['name'] }}</span>
                                                </span>
                                            @endif
                                        @endforeach
                                        @if ($day['is_holiday'] && $dayHolidays === [])
                                            <span class="truncate rounded-md bg-primary/10 px-1.5 py-0.5 text-[11px] font-semibold text-primary-hover">{{ __('team_calendar.holiday') }}</span>
                                        @endif
                                        @foreach ($dayBirthdays as $person)
                                            @include('livewire.team.partials.birthday', ['person' => $person])
                                        @endforeach
                                    </div>
                                @endif

                                @if ($week['hidden'][$col - 1] > 0)
                                    <div class="px-2" style="grid-column: {{ $col }}; grid-row: {{ $moreRow }}">
                                        <button type="button" wire:click="openDay('{{ $day['date'] }}')"
                                                class="rounded-full px-1.5 text-[11px] font-semibold text-primary hover:bg-primary/10 focus-visible:outline-2 focus-visible:outline-primary">
                                            {{ __('team_calendar.more', ['count' => $week['hidden'][$col - 1]]) }}
                                        </button>
                                    </div>
                                @endif

                                @if ($showCounts && $day['expected'] > 0 && $awayCount > 0)
                                    <div class="self-end px-2 pb-2" style="grid-column: {{ $col }}; grid-row: {{ $moreRow + 1 }}"
                                         title="{{ __('team_calendar.away_of', ['away' => $awayCount, 'expected' => $day['expected']]) }}">
                                        <div class="text-[10px] font-medium text-neutral-500">{{ __('team_calendar.away_of', ['away' => $awayCount, 'expected' => $day['expected']]) }}</div>
                                        <div class="mt-0.5 h-1 overflow-hidden rounded-full bg-neutral-100" aria-hidden="true">
                                            <div class="h-full rounded-full bg-primary/70" style="width: {{ min(100, max(4, round($awayCount / $day['expected'] * 100))) }}%"></div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach

                            {{-- One bar per absence per week, across the days it covers. --}}
                            @foreach ($week['bars'] as $bar)
                                <div wire:key="bar-{{ $week['days'][0]['date'] }}-{{ $bar['entry']['key'] }}"
                                     @class(['min-w-0', 'ps-1.5' => ! $bar['continues_before'], 'pe-1.5' => ! $bar['continues_after']])
                                     style="grid-column: {{ $bar['col'] }} / span {{ $bar['span'] }}; grid-row: {{ 3 + $bar['lane'] }}">
                                    @include('livewire.team.partials.chip', [
                                        'entry' => $bar['entry'],
                                        'bar' => true,
                                        'before' => $bar['continues_before'],
                                        'after' => $bar['continues_after'],
                                    ])
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- List: the week, day and list views, and the month on phones. --}}
            <div @class(['md:hidden' => $viewName === 'month']) data-testid="calendar-list">
                @if ($listDays->isEmpty())
                    <div class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-neutral-300 bg-white p-8 text-center" data-testid="calendar-empty">
                        <svg class="size-8 text-neutral-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
                        <p class="text-body text-neutral-700">{{ __('team_calendar.empty') }}</p>
                    </div>
                @else
                    <ol class="space-y-3">
                        @foreach ($listDays as $day)
                            @php
                                $date = \Carbon\CarbonImmutable::parse($day['date']);
                                $dayHolidays = $holidays[$day['date']] ?? [];
                            @endphp
                            <li wire:key="day-{{ $day['date'] }}" class="flex gap-3 rounded-2xl border border-neutral-200 bg-white p-3 shadow-xs sm:gap-4 sm:p-4">
                                <div @class([
                                    'flex w-14 shrink-0 flex-col items-center justify-center rounded-xl py-2',
                                    'bg-primary text-white' => $date->isToday(),
                                    'bg-primary/10 text-primary-hover' => ! $date->isToday() && ($day['is_holiday'] || $dayHolidays !== []),
                                    'bg-neutral-100 text-neutral-800' => ! $date->isToday() && ! $day['is_holiday'] && $dayHolidays === [],
                                ])>
                                    <span class="text-[11px] font-semibold uppercase">{{ $date->locale($locale)->translatedFormat('D') }}</span>
                                    <span class="text-xl font-bold leading-tight">{{ $date->day }}</span>
                                    <span class="text-[10px]">{{ $date->locale($locale)->translatedFormat('M') }}</span>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <h3 class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="font-semibold text-neutral-900">
                                            {{ $date->locale($locale)->translatedFormat('l j F') }}
                                            @if ($date->isToday())
                                                <span class="text-caption text-primary">· {{ __('team_calendar.nav_buttons.today') }}</span>
                                            @endif
                                        </span>
                                        <span class="flex items-center gap-2 text-caption text-neutral-600">
                                            @if ($day['is_weekend'])
                                                <span class="rounded-full bg-neutral-100 px-2 py-0.5">{{ __('team_calendar.weekend') }}</span>
                                            @endif
                                            @if ($showCounts && $day['expected'] > 0)
                                                <span>{{ __('team_calendar.away_of', ['away' => $day['away'] + $day['partial'], 'expected' => $day['expected']]) }}</span>
                                            @endif
                                            @if ($day['pending'] > 0)
                                                <span>· {{ __('team_calendar.pending_count', ['count' => $day['pending']]) }}</span>
                                            @endif
                                            @if ($requestUrl !== null && $day['date'] >= $today)
                                                <a href="{{ str_replace('__DATE__', $day['date'], $requestUrl) }}" wire:navigate
                                                   aria-label="{{ __('team_calendar.request_leave_on', ['date' => $date->locale($locale)->translatedFormat('l j F')]) }}"
                                                   class="inline-flex min-h-7 items-center gap-1 rounded-full border border-neutral-200 px-2.5 font-semibold text-primary hover:border-primary focus-visible:outline-2 focus-visible:outline-primary">
                                                    <svg class="size-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path d="M10 4v12M4 10h12"/></svg>
                                                    {{ __('team_calendar.request_leave') }}
                                                </a>
                                            @endif
                                            @if ($canManageHolidays)
                                                <button type="button" wire:click="$dispatch('openHolidayCreate', { date: '{{ $day['date'] }}' })"
                                                        aria-label="{{ __('team_calendar.add_holiday_on', ['date' => $date->locale($locale)->translatedFormat('j F')]) }}"
                                                        class="inline-flex size-7 items-center justify-center rounded-full text-neutral-500 hover:bg-primary/10 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary">
                                                    <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M10 4v12M4 10h12"/></svg>
                                                </button>
                                            @endif
                                        </span>
                                    </h3>

                                    @if ($dayHolidays !== [] || $day['is_holiday'])
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            @forelse ($dayHolidays as $holiday)
                                                @if ($holiday['editable'])
                                                    <button type="button" wire:click="$dispatch('openHolidayEdit', { id: {{ $holiday['id'] }} })"
                                                            class="rounded-full bg-primary/10 px-2.5 py-0.5 text-caption font-semibold text-primary-hover hover:bg-primary/15 focus-visible:outline-2 focus-visible:outline-primary">{{ $holiday['name'] }}</button>
                                                @else
                                                    <span class="rounded-full bg-primary/10 px-2.5 py-0.5 text-caption font-semibold text-primary-hover">{{ $holiday['name'] }}</span>
                                                @endif
                                            @empty
                                                <span class="rounded-full bg-primary/10 px-2.5 py-0.5 text-caption font-semibold text-primary-hover">{{ __('team_calendar.holiday') }}</span>
                                            @endforelse
                                        </div>
                                    @endif

                                    @if (($birthdays[$day['date']] ?? []) !== [])
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            @foreach ($birthdays[$day['date']] as $person)
                                                @include('livewire.team.partials.birthday', ['person' => $person, 'inline' => true])
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($day['members'] === [])
                                        <p class="mt-1 text-sm text-neutral-500">{{ __('team_calendar.nobody') }}</p>
                                    @else
                                        <ul class="mt-2 divide-y divide-neutral-100">
                                            @foreach ($day['members'] as $entry)
                                                <li wire:key="row-{{ $day['date'] }}-{{ $entry['key'] }}" class="flex items-center gap-3 py-2">
                                                    <img src="{{ $entry['avatar'] }}" alt="" loading="lazy" class="size-9 shrink-0 rounded-full bg-neutral-100 object-cover ring-2 ring-white">
                                                    <div class="min-w-0 flex-1">
                                                        <p class="truncate font-medium text-neutral-900">
                                                            {{ $entry['name'] }}
                                                            @if ($entry['is_self'])
                                                                <span class="text-caption text-neutral-600">({{ __('team_calendar.you') }})</span>
                                                            @endif
                                                        </p>
                                                        <p class="truncate text-caption text-neutral-600">{{ $entry['position'] }}</p>
                                                        @if ($entry['substitute'])
                                                            <p class="truncate text-caption text-neutral-600">{{ __('team_calendar.substitute', ['name' => $entry['substitute']]) }}</p>
                                                        @endif
                                                    </div>
                                                    <div class="shrink-0 text-end">
                                                        @include('livewire.team.partials.chip', ['entry' => $entry, 'showName' => false])
                                                        @if ($entry['hours'])
                                                            <p class="mt-0.5 text-caption text-neutral-600"><bdi dir="ltr">{{ $entry['hours'] }}</bdi></p>
                                                        @endif
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        @endif
    </div>

    @if ($canManageHolidays)
        <livewire:basics.holiday-module :embedded="true" />
    @endif
</div>
