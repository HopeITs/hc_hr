<div class="@container">
    <section class="relative overflow-hidden rounded-surface border border-line-soft bg-card shadow-e2" aria-labelledby="center-employees-heading">
        <header class="flex flex-wrap items-end justify-between gap-4 border-b border-line-soft p-[clamp(1rem,2.5vw,2rem)]">
            <div class="min-w-0 flex-1 basis-[18rem]">
                <div class="flex items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-surface bg-surface text-ink-2">
                        <x-icons.users class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 id="center-employees-heading" class="text-h4 font-black text-ink">@lang('general.center-employees')</h2>
                        <p class="mt-0.5 text-small text-ink-3">
                            {{ trans_choice('general.center-employee-count', $totalEmployees, ['count' => number_format($totalEmployees)]) }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="relative w-full @lg:w-auto @lg:min-w-80">
                <label for="center-employee-search-{{ $centerId }}" class="sr-only">@lang('general.search-center-employees')</label>
                <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-ink-3">
                    <x-icons.search class="size-4" />
                </span>
                <input
                    id="center-employee-search-{{ $centerId }}"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('general.search-center-employees') }}"
                    autocomplete="off"
                    class="min-h-11 w-full rounded-surface border border-line-soft bg-soft py-2 ps-9 pe-10 text-body font-medium text-ink placeholder:text-ink-3"
                >
                @if (filled($search))
                    <button type="button" wire:click="$set('search', '')" aria-label="{{ __('general.clear-search') }}" title="{{ __('general.clear-search') }}" class="absolute inset-y-0 end-1 flex size-11 items-center justify-center rounded-surface text-ink-3 transition hover:text-ink">
                        <x-icons.x-mark class="size-4" />
                    </button>
                @endif
            </div>
        </header>

        <div class="relative min-h-80 p-[clamp(1rem,2.5vw,2rem)]">
            <div wire:loading.flex wire:target="search,nextPage,previousPage,gotoPage" class="absolute inset-0 z-20 items-start justify-center bg-card/75 pt-24 backdrop-blur-[1px]">
                <div class="flex items-center gap-2 rounded-surface border border-line-soft bg-card px-4 py-3 text-body font-semibold text-ink-2 shadow-e3">
                    <x-icons.spinner class="size-5 animate-spin text-ink-2" /> @lang('general.loading')
                </div>
            </div>

            @if ($employees->isEmpty())
                <x-center-empty-state :variant="filled($search) ? 'filtered' : 'empty'"
                    :title="filled($search) ? __('general.no-search-results') : __('general.no-center-employees')"
                    :description="filled($search) ? __('general.no-search-results-help') : __('general.no-center-employees-help')">
                    <x-slot:icon><x-icons.user class="size-9" /></x-slot:icon>
                </x-center-empty-state>
            @else
                <div class="grid items-stretch gap-3 [grid-template-columns:repeat(auto-fit,minmax(min(100%,16.5rem),1fr))]">
                    @foreach ($employees as $employee)
                        @php
                            $assignments = $employee->allContracts;
                            $canViewProfile = auth()->user()?->can('view', $employee) ?? false;
                            $initials = collect(preg_split('/\s+/u', trim($employee->name), -1, PREG_SPLIT_NO_EMPTY))
                                ->take(2)
                                ->map(fn ($part) => mb_substr($part, 0, 1))
                                ->implode('');
                        @endphp

                        <article wire:key="center-employee-{{ $employee->id }}" class="group relative flex h-full min-w-0 flex-col rounded-surface border border-line-soft bg-card p-3 shadow-e1 transition hover:-translate-y-0.5 hover:border-line hover:shadow-e3">
                            @if ($canViewProfile && $employee->username)
                                <a
                                    href="{{ route('employees.show', ['employee' => $employee->username]) }}"
                                    wire:navigate
                                    aria-label="{{ __('general.view-profile') }}: {{ $employee->name }}"
                                    title="{{ __('general.view-profile') }}"
                                    class="absolute end-2 top-2 z-10 inline-flex size-11 items-center justify-center rounded-full text-ink-3 transition hover:bg-hover hover:text-ink"
                                >
                                    <x-icons.eye class="size-5" />
                                </a>
                            @endif

                            <div class="flex min-w-0 items-center gap-2.5 pe-10">
                                <div class="relative shrink-0">
                                    @if ($canViewProfile)
                                        <img src="{{ $employee->avatar_url }}" alt="{{ $employee->name }}" loading="lazy" class="size-12 rounded-full object-cover ring-2 ring-card outline outline-1 outline-line-soft">
                                    @else
                                        <span class="flex size-12 items-center justify-center rounded-full bg-surface text-small font-black uppercase text-ink-2 outline outline-1 outline-line-soft">{{ $initials ?: '—' }}</span>
                                    @endif
                                    <span class="absolute bottom-0 end-0 grid size-4 place-items-center rounded-full border-2 border-card bg-ink text-on-ink" title="{{ __('general.active') }}"><x-ic name="check" class="size-2.5" /><span class="sr-only">{{ __('general.active') }}</span></span>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <h3 class="truncate text-body font-black text-ink" title="{{ $employee->name }}">{{ $employee->name }}</h3>
                                    <p class="mt-0.5 truncate text-small font-medium text-ink-3" dir="ltr">{{ $employee->username ? '@'.$employee->username : __('general.not-specified') }}</p>
                                    @if ($assignments->count() > 1)
                                        <span class="mt-1 inline-flex rounded-full bg-surface px-2 py-0.5 text-caption font-bold text-ink-2">
                                            {{ trans_choice('general.assignment-count', $assignments->count(), ['count' => $assignments->count()]) }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-3 flex-1 space-y-2">
                                @foreach ($assignments as $assignment)
                                    <div class="rounded-surface border border-line-soft bg-soft p-2.5">
                                        <div class="flex items-start gap-2">
                                            <x-icons.employee class="mt-0.5 size-4 shrink-0 text-ink-3" />
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-small font-bold text-ink" title="{{ $assignment->position?->title }}">{{ $assignment->position?->title ?? __('general.not-specified') }}</p>
                                                <p class="mt-0.5 truncate text-caption text-ink-3" title="{{ $assignment->position?->program?->name }}">{{ $assignment->position?->program?->name ?? __('general.unassigned-program') }}</p>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($employees->hasPages())
                    <div class="mt-6 border-t border-line-soft pt-5">
                        {{ $employees->onEachSide(1)->links() }}
                    </div>
                @endif
            @endif
        </div>
    </section>
</div>
