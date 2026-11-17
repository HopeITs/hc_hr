{{--
    "Employees" tile (EmployeesCard): the employees page as a card. A search
    field (live, by name or username) and the matching people, or the people
    added most recently when nothing is typed; each opens their profile. The
    photo only when the avatar ability allows it, otherwise the initial.
--}}
<div class="hc-emps flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.employees') }}</h2>
            <x-button variant="outline" size="sm" :href="route('employees.index')" class="ms-auto shrink-0">{{ __('general.show-more') }}</x-button>
        </header>

        <div class="px-4 pb-2">
            <label for="hc-emps-q-{{ $this->getId() }}" class="sr-only">{{ __('dashboard.employees.search') }}</label>
            <input id="hc-emps-q-{{ $this->getId() }}" type="search" wire:model.live.debounce.300ms="q" autocomplete="off"
                   placeholder="{{ __('dashboard.employees.search') }}"
                   class="h-10 w-full rounded-full border border-line bg-soft px-4 text-small text-ink placeholder:text-ink-3 focus:bg-card">
        </div>

        <p class="px-4 pb-1 text-caption font-semibold text-ink-3" aria-live="polite">
            {{ trim($q) === '' ? __('dashboard.employees.recent') : trans_choice('dashboard.employees.found', $people->count(), ['count' => $people->count()]) }}
        </p>

        @if ($people->isEmpty())
            <p class="hc-nt-empty">{{ __('dashboard.employees.none') }}</p>
        @else
            <ul class="hc-list" data-testid="employees-list">
                @foreach ($people as $person)
                    @php($title = $person->contracts->first()?->position?->title)
                    <li wire:key="emp-{{ $person->id }}">
                        <a href="{{ route('employees.show', $person->username) }}" wire:navigate class="hc-list-row">
                            @can('viewAvatar', $person)
                                <img class="hc-nt-av" src="{{ $person->avatar_url }}" alt="" loading="lazy">
                            @else
                                <span class="hc-nt-av hc-me-initial" aria-hidden="true">{{ mb_substr((string) $person->name, 0, 1) }}</span>
                            @endcan
                            <span class="min-w-0 flex-1">
                                <b class="block truncate">{{ $person->name }}</b>
                                @if ($title)
                                    <span class="block truncate text-small text-ink-2">{{ $title }}</span>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
