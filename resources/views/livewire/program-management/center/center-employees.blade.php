<div class="@container">
    <section class="relative overflow-hidden rounded-surface border border-neutral-200 bg-white shadow-e2 dark:border-neutral-700 dark:bg-neutral-900" aria-labelledby="center-employees-heading">
        <header class="flex flex-wrap items-end justify-between gap-4 border-b border-neutral-100 p-[clamp(1rem,2.5vw,2rem)] dark:border-neutral-800">
            <div class="min-w-0 flex-1 basis-[18rem]">
                <div class="flex items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-surface bg-neutral-100 text-primary dark:bg-neutral-800">
                        <x-icons.users class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 id="center-employees-heading" class="text-h4 font-black text-secondary-dark dark:text-white">@lang('general.center-employees')</h2>
                        <p class="mt-0.5 text-small text-secondary-light">
                            {{ trans_choice('general.center-employee-count', $totalEmployees, ['count' => number_format($totalEmployees)]) }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="relative w-full @lg:w-auto @lg:min-w-80">
                <label for="center-employee-search-{{ $centerId }}" class="sr-only">@lang('general.search-center-employees')</label>
                <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-secondary-light">
                    <x-icons.search class="size-4" />
                </span>
                <input
                    id="center-employee-search-{{ $centerId }}"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('general.search-center-employees') }}"
                    autocomplete="off"
                    class="min-h-11 w-full rounded-surface border border-neutral-200 bg-neutral-50 py-2 ps-9 pe-10 text-body font-medium text-secondary-dark placeholder:text-secondary-light focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                >
                @if (filled($search))
                    <button type="button" wire:click="$set('search', '')" aria-label="{{ __('general.clear-search') }}" title="{{ __('general.clear-search') }}" class="absolute inset-y-0 end-1 flex size-11 items-center justify-center rounded-surface text-secondary-light transition hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                        <x-icons.x-mark class="size-4" />
                    </button>
                @endif
            </div>
        </header>

        <div class="relative min-h-80 p-[clamp(1rem,2.5vw,2rem)]">
            <div wire:loading.flex wire:target="search,nextPage,previousPage,gotoPage" class="absolute inset-0 z-20 items-start justify-center bg-white/75 pt-24 backdrop-blur-[1px] dark:bg-neutral-900/75">
                <div class="flex items-center gap-2 rounded-surface border border-neutral-200 bg-white px-4 py-3 text-body font-semibold text-secondary-medium shadow-e3 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white">
                    <x-icons.spinner class="size-5 animate-spin text-primary" /> @lang('general.loading')
                </div>
            </div>

            @if ($employees->isEmpty())
                <div class="flex min-h-72 items-center justify-center rounded-surface border border-dashed border-neutral-300 bg-neutral-50 p-8 text-center dark:border-neutral-700 dark:bg-neutral-800/50">
                    <div class="max-w-sm">
                        <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-white text-primary shadow-e1 dark:bg-neutral-800">
                            <x-icons.user class="size-7" />
                        </span>
                        <h3 class="mt-4 text-h4 font-bold text-secondary-dark dark:text-white">{{ filled($search) ? __('general.no-search-results') : __('general.no-center-employees') }}</h3>
                        <p class="mt-2 text-body leading-relaxed text-secondary-light">{{ filled($search) ? __('general.no-search-results-help') : __('general.no-center-employees-help') }}</p>
                    </div>
                </div>
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

                        <article wire:key="center-employee-{{ $employee->id }}" class="group relative flex h-full min-w-0 flex-col rounded-surface border border-neutral-200 bg-white p-3 shadow-e1 transition hover:-translate-y-0.5 hover:border-neutral-300 hover:shadow-e3 dark:border-neutral-700 dark:bg-neutral-900">
                            @if ($canViewProfile && $employee->username)
                                <a
                                    href="{{ route('employees.show', ['employee' => $employee->username]) }}"
                                    wire:navigate
                                    aria-label="{{ __('general.view-profile') }}: {{ $employee->name }}"
                                    title="{{ __('general.view-profile') }}"
                                    class="absolute end-2 top-2 z-10 inline-flex size-11 items-center justify-center rounded-full text-secondary-light transition hover:bg-neutral-100 hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary dark:hover:bg-neutral-800"
                                >
                                    <x-icons.eye class="size-5" />
                                </a>
                            @endif

                            <div class="flex min-w-0 items-center gap-2.5 pe-10">
                                <div class="relative shrink-0">
                                    @if ($canViewProfile)
                                        <img src="{{ $employee->avatar_url }}" alt="{{ $employee->name }}" loading="lazy" class="size-12 rounded-full object-cover ring-2 ring-white outline outline-1 outline-neutral-200 dark:ring-neutral-900 dark:outline-neutral-700">
                                    @else
                                        <span class="flex size-12 items-center justify-center rounded-full bg-neutral-100 text-small font-black uppercase text-primary outline outline-1 outline-neutral-200 dark:bg-neutral-800 dark:outline-neutral-700">{{ $initials ?: '—' }}</span>
                                    @endif
                                    <span class="absolute bottom-0 end-0 size-3 rounded-full border-2 border-white bg-neutral-500 dark:border-neutral-900" title="{{ __('general.active') }}"></span>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <h3 class="truncate text-body font-black text-secondary-dark dark:text-white" title="{{ $employee->name }}">{{ $employee->name }}</h3>
                                    <p class="mt-0.5 truncate text-small font-medium text-secondary-light" dir="ltr">{{ $employee->username ? '@'.$employee->username : __('general.not-specified') }}</p>
                                    @if ($assignments->count() > 1)
                                        <span class="mt-1 inline-flex rounded-full bg-neutral-100 px-2 py-0.5 text-caption font-bold text-secondary-medium dark:bg-neutral-800 dark:text-neutral-300">
                                            {{ trans_choice('general.assignment-count', $assignments->count(), ['count' => $assignments->count()]) }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-3 flex-1 space-y-2">
                                @foreach ($assignments as $assignment)
                                    <div class="rounded-surface border border-neutral-100 bg-neutral-50 p-2.5 dark:border-neutral-800 dark:bg-neutral-800/60">
                                        <div class="flex items-start gap-2">
                                            <x-icons.employee class="mt-0.5 size-4 shrink-0 text-primary" />
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-small font-bold text-secondary-dark dark:text-white" title="{{ $assignment->position?->title }}">{{ $assignment->position?->title ?? __('general.not-specified') }}</p>
                                                <p class="mt-0.5 truncate text-caption text-secondary-light" title="{{ $assignment->position?->program?->name }}">{{ $assignment->position?->program?->name ?? __('general.unassigned-program') }}</p>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($employees->hasPages())
                    <div class="mt-6 border-t border-neutral-100 pt-5 dark:border-neutral-800">
                        {{ $employees->onEachSide(1)->links() }}
                    </div>
                @endif
            @endif
        </div>
    </section>
</div>
