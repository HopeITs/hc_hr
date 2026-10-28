{{--
    Team calendar (C5, team-coverage-contract.md). Everything shown comes from
    TeamCoverage::coverage(), already limited to the people the active role may
    see and to the fields its level allows (§4.4): no reason, no balance, and
    no request id at colleague level (row keys are opaque). Status is told by
    text and shape (tick and solid border, clock and dashed border), never by
    colour alone. Phones get the list instead of a tiny month grid.
--}}
<x-slot:title>
    {{ __('team_calendar.title') }}
</x-slot:title>

@php
    $locale = app()->getLocale();
    $weekdays = collect(range(0, 6))->map(fn (int $d) => \Carbon\CarbonImmutable::now()->startOfWeek(\Carbon\CarbonInterface::SUNDAY)->addDays($d)->locale($locale)->translatedFormat('D'));
    $rangeLabel = match ($viewName) {
        'day' => $from->locale($locale)->translatedFormat('l j F Y'),
        'week' => __('team_calendar.range', ['from' => $from->locale($locale)->translatedFormat('j M'), 'to' => $to->locale($locale)->translatedFormat('j M Y')]),
        default => $anchor->locale($locale)->translatedFormat('F Y'),
    };
    // The list shows the days that matter: in a month, only those of that month with someone away.
    $listDays = collect($days)->filter(fn (array $day) => match ($viewName) {
        'week', 'day' => true,
        default => $day['members'] !== [] && substr($day['date'], 0, 7) === $anchor->format('Y-m'),
    });
@endphp

<div>
    <div class="mb-4">
        <livewire:layout.breadcrumbs :title="__('team_calendar.title')" :crumbs="[]" />
        <p class="text-small text-secondary-medium">{{ __('team_calendar.intro') }}</p>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div role="group" aria-label="{{ __('team_calendar.views.label') }}" class="flex flex-wrap gap-1.5">
            @foreach (\App\Livewire\Team\Calendar::VIEWS as $option)
                <button type="button" wire:click="$set('view', '{{ $option }}')" aria-pressed="{{ $viewName === $option ? 'true' : 'false' }}"
                        data-testid="calendar-view-{{ $option }}"
                        @class([
                            'min-h-11 rounded-control border px-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                            'border-primary bg-primary text-white' => $viewName === $option,
                            'border-neutral-300 bg-white text-neutral-800 hover:bg-neutral-50' => $viewName !== $option,
                            // The month grid is a desktop view; phones read the list.
                            'hidden md:inline-flex md:items-center' => $option === 'month',
                            'inline-flex items-center' => $option !== 'month',
                        ])>
                    {{ __("team_calendar.views.{$option}") }}
                </button>
            @endforeach
        </div>

        <div class="min-w-48 flex-1 sm:flex-none">
            <x-forms.input-label for="calendar-scope">{{ __('team_calendar.scopes.label') }}</x-forms.input-label>
            <x-forms.select-input id="calendar-scope" wire:model.live="scope" class="min-h-11">
                @foreach ($scopes as $option)
                    <option value="{{ $option }}" @selected($option === $currentScope)>{{ __("team_calendar.scopes.{$option}") }}</option>
                @endforeach
            </x-forms.select-input>
        </div>

        @if ($managerView)
            <div class="min-w-40 flex-1 sm:flex-none">
                <x-forms.input-label for="calendar-status">{{ __('team_calendar.status.label') }}</x-forms.input-label>
                <x-forms.select-input id="calendar-status" wire:model.live="status" class="min-h-11">
                    @foreach (\App\Livewire\Team\Calendar::STATUSES as $option)
                        <option value="{{ $option }}" @selected($option === $currentStatus)>{{ __("team_calendar.status.{$option}") }}</option>
                    @endforeach
                </x-forms.select-input>
            </div>
        @endif

        @if ($cities->isNotEmpty())
            <div class="min-w-40 flex-1 sm:flex-none">
                <x-forms.input-label for="calendar-city">{{ __('team_calendar.city.label') }}</x-forms.input-label>
                <x-forms.select-input id="calendar-city" wire:model.live="cityId" class="min-h-11">
                    <option value="0">{{ __('team_calendar.city.all') }}</option>
                    @foreach ($cities as $city)
                        <option value="{{ $city->id }}" @selected($city->id === $currentCity)>{{ $city->name }}</option>
                    @endforeach
                </x-forms.select-input>
            </div>
        @endif
    </div>

    {{-- Period navigation --}}
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-lg font-semibold text-neutral-900" aria-live="polite" data-testid="calendar-range">{{ $rangeLabel }}</h2>
        <div class="flex gap-1.5">
            <button type="button" wire:click="move(-1)" class="min-h-11 min-w-11 rounded-control border border-neutral-300 bg-white px-3 text-sm font-semibold text-neutral-800 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <span aria-hidden="true" class="rtl:hidden">‹</span><span aria-hidden="true" class="ltr:hidden">›</span>
                {{ __('team_calendar.nav_buttons.previous') }}
            </button>
            <button type="button" wire:click="goToday" class="min-h-11 rounded-control border border-neutral-300 bg-white px-3 text-sm font-semibold text-neutral-800 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                {{ __('team_calendar.nav_buttons.today') }}
            </button>
            <button type="button" wire:click="move(1)" class="min-h-11 min-w-11 rounded-control border border-neutral-300 bg-white px-3 text-sm font-semibold text-neutral-800 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                {{ __('team_calendar.nav_buttons.next') }}
                <span aria-hidden="true" class="rtl:hidden">›</span><span aria-hidden="true" class="ltr:hidden">‹</span>
            </button>
        </div>
    </div>

    <p class="mb-3 text-caption text-secondary-medium">{{ __('team_calendar.legend') }}</p>

    <div wire:loading.class="opacity-60" wire:target="move,goToday,view,scope,status,cityId" class="transition-opacity">
        @if ($failed)
            <div class="rounded-surface border border-[var(--line-hairline)] bg-white p-6 text-center" role="alert">
                <p class="text-body text-neutral-800">{{ __('team_calendar.failed') }}</p>
                <button type="button" wire:click="$refresh" class="mt-3 min-h-11 rounded-control border border-primary px-4 font-semibold text-primary">{{ __('general.toast.try-again') }}</button>
            </div>
        @else
            {{-- Month grid: desktop only. --}}
            @if ($viewName === 'month')
                <div class="hidden md:block" data-testid="calendar-month-grid">
                    <div class="grid grid-cols-7 gap-px overflow-hidden rounded-surface border border-neutral-200 bg-neutral-200">
                        @foreach ($weekdays as $weekday)
                            <div class="bg-neutral-50 px-2 py-1.5 text-center text-caption font-semibold text-neutral-700" aria-hidden="true">{{ $weekday }}</div>
                        @endforeach

                        @foreach ($days as $day)
                            @php($date = \Carbon\CarbonImmutable::parse($day['date']))
                            @php($inMonth = $date->format('Y-m') === $anchor->format('Y-m'))
                            <div wire:key="cell-{{ $day['date'] }}"
                                 @class([
                                     'min-h-28 p-1.5',
                                     'bg-white' => $inMonth && ! $day['is_weekend'] && ! $day['is_holiday'],
                                     'bg-neutral-50' => $inMonth && ($day['is_weekend'] || $day['is_holiday']),
                                     'bg-neutral-100 text-neutral-500' => ! $inMonth,
                                 ])>
                                <div class="flex items-baseline justify-between gap-1">
                                    <span @class([
                                        'text-sm font-semibold',
                                        'inline-flex size-6 items-center justify-center rounded-full bg-primary text-white' => $date->isToday(),
                                    ])>
                                        <span class="sr-only">{{ $date->locale($locale)->translatedFormat('l j F') }}</span>
                                        <span aria-hidden="true">{{ $date->day }}</span>
                                    </span>
                                    @if ($day['is_holiday'])
                                        <span class="text-[11px] font-medium text-neutral-600">{{ __('team_calendar.holiday') }}</span>
                                    @elseif ($day['is_weekend'])
                                        <span class="text-[11px] text-neutral-500">{{ __('team_calendar.weekend') }}</span>
                                    @endif
                                </div>

                                @if ($showCounts && $day['expected'] > 0 && ($day['away'] + $day['partial']) > 0)
                                    <p class="mt-0.5 text-[11px] font-medium text-neutral-700">{{ __('team_calendar.away_of', ['away' => $day['away'] + $day['partial'], 'expected' => $day['expected']]) }}</p>
                                @endif

                                <ul class="mt-1 space-y-1">
                                    @foreach (array_slice($day['members'], 0, 3) as $entry)
                                        <li wire:key="cell-{{ $day['date'] }}-{{ $entry['key'] }}">
                                            @include('livewire.team.partials.chip', ['entry' => $entry])
                                        </li>
                                    @endforeach
                                    @if (count($day['members']) > 3)
                                        <li>
                                            <button type="button" wire:click="openDay('{{ $day['date'] }}')"
                                                    class="text-[11px] font-semibold text-primary underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-primary">
                                                {{ __('team_calendar.more', ['count' => count($day['members']) - 3]) }}
                                            </button>
                                        </li>
                                    @endif
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- List: the week, day and list views, and the month on phones. --}}
            <div @class(['md:hidden' => $viewName === 'month']) data-testid="calendar-list">
                @if ($listDays->isEmpty())
                    <p class="rounded-surface border border-dashed border-neutral-300 bg-white p-6 text-center text-body text-neutral-700" data-testid="calendar-empty">
                        {{ __('team_calendar.empty') }}
                    </p>
                @else
                    <ol class="space-y-3">
                        @foreach ($listDays as $day)
                            @php($date = \Carbon\CarbonImmutable::parse($day['date']))
                            <li wire:key="day-{{ $day['date'] }}" class="rounded-surface border border-neutral-200 bg-white p-3">
                                <h3 class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span @class(['font-semibold text-neutral-900', 'text-primary' => $date->isToday()])>
                                        {{ $date->locale($locale)->translatedFormat('l j F') }}
                                        @if ($date->isToday())
                                            <span class="text-caption">· {{ __('team_calendar.nav_buttons.today') }}</span>
                                        @endif
                                    </span>
                                    <span class="text-caption text-neutral-600">
                                        @if ($day['is_holiday'])
                                            {{ __('team_calendar.holiday') }}
                                        @elseif ($day['is_weekend'])
                                            {{ __('team_calendar.weekend') }}
                                        @endif
                                        @if ($showCounts && $day['expected'] > 0)
                                            {{ __('team_calendar.away_of', ['away' => $day['away'] + $day['partial'], 'expected' => $day['expected']]) }}
                                        @endif
                                        @if ($day['pending'] > 0)
                                            · {{ __('team_calendar.pending_count', ['count' => $day['pending']]) }}
                                        @endif
                                    </span>
                                </h3>

                                @if ($day['members'] === [])
                                    <p class="mt-1 text-sm text-neutral-600">{{ __('team_calendar.nobody') }}</p>
                                @else
                                    <ul class="mt-2 divide-y divide-neutral-100">
                                        @foreach ($day['members'] as $entry)
                                            <li wire:key="row-{{ $day['date'] }}-{{ $entry['key'] }}" class="flex items-center gap-3 py-2">
                                                <img src="{{ $entry['avatar'] }}" alt="" loading="lazy" class="size-9 shrink-0 rounded-full bg-neutral-100 object-cover ring-1 ring-neutral-200">
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
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        @endif
    </div>
</div>
