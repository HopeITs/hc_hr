<div class="@container">
    <section class="relative overflow-hidden rounded-surface border border-line-soft bg-card shadow-e2" aria-labelledby="program-employees-heading">
        <header class="space-y-4 border-b border-line-soft p-[clamp(1rem,2.5vw,2rem)]">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex min-w-0 flex-1 basis-[18rem] items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-surface bg-surface text-ink-2">
                        <x-icons.users class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 id="program-employees-heading" class="text-h4 font-black text-ink">@lang('general.program-employees')</h2>
                        <p class="mt-0.5 text-small text-ink-3">
                            {{ trans_choice('general.center-employee-count', $totalEmployees, ['count' => number_format($totalEmployees)]) }}
                            @if ($filtering)
                                · {{ trans_choice('general.center-employee-count', $employees->total(), ['count' => number_format($employees->total())]) }}
                            @endif
                        </p>
                    </div>
                </div>

                <div class="relative w-full @lg:w-auto @lg:min-w-80">
                    <label for="program-employee-search-{{ $programId }}" class="sr-only">@lang('general.search-program-employees')</label>
                    <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-ink-3">
                        <x-icons.search class="size-4" />
                    </span>
                    <input
                        id="program-employee-search-{{ $programId }}"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('general.search-program-employees') }}"
                        autocomplete="off"
                        class="min-h-11 w-full rounded-surface border border-line-soft bg-soft py-2 ps-9 pe-10 text-body font-medium text-ink placeholder:text-ink-3"
                    >
                    @if (filled($search))
                        <button type="button" wire:click="$set('search', '')" aria-label="{{ __('general.clear-search') }}" title="{{ __('general.clear-search') }}" class="absolute inset-y-0 end-1 flex size-11 items-center justify-center rounded-surface text-ink-3 transition hover:text-ink">
                            <x-icons.x-mark class="size-4" />
                        </button>
                    @endif
                </div>
            </div>

            {{-- Filters: only the centers, departments and levels this program's contracts use. --}}
            <div class="grid items-end gap-3 [grid-template-columns:repeat(auto-fit,minmax(min(100%,13rem),1fr))]">
                @if ($centers->isNotEmpty())
                <div class="flex min-w-0 flex-col gap-1.5">
                    <label for="pe-center-{{ $programId }}" class="text-small font-semibold text-ink-2">{{ trans_choice('general.center', 1) }}</label>
                    <x-forms.select2-input id="pe-center-{{ $programId }}" wire:model.live="centerId">
                        <option value="">@lang('general.all-centers')</option>
                        @foreach ($centers as $center)
                            <option value="{{ $center->id }}">{{ $center->name }}{{ $center->city ? ' · '.$center->city->name : '' }}</option>
                        @endforeach
                    </x-forms.select2-input>
                </div>
                @endif

                <div class="flex min-w-0 flex-col gap-1.5">
                    <label for="pe-dept-{{ $programId }}" class="text-small font-semibold text-ink-2">{{ trans_choice('general.department', 1) }}</label>
                    <x-forms.select2-input id="pe-dept-{{ $programId }}" wire:model.live="departmentId">
                        <option value="">@lang('general.all-departments')</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </x-forms.select2-input>
                </div>

                <div class="flex min-w-0 flex-col gap-1.5">
                    <label for="pe-level-{{ $programId }}" class="text-small font-semibold text-ink-2">{{ trans_choice('general.job-level', 1) }}</label>
                    <x-forms.select2-input id="pe-level-{{ $programId }}" wire:model.live="jobLevelId" :hideSearch="true">
                        <option value="">@lang('general.all-job-levels')</option>
                        @foreach ($jobLevels as $level)
                            <option value="{{ $level->id }}">{{ $level->name }}</option>
                        @endforeach
                    </x-forms.select2-input>
                </div>

                <x-button variant="outline" size="md" icon="reset" wire:click="resetFilters" :disabled="! $filtering" class="w-full">
                    @lang('general.reset-filters')
                </x-button>
            </div>
        </header>

        <div class="relative min-h-80 p-[clamp(1rem,2.5vw,2rem)]">
            <div wire:loading.flex wire:target="search,centerId,departmentId,jobLevelId,resetFilters,nextPage,previousPage,gotoPage" class="absolute inset-0 z-20 items-start justify-center bg-card/75 pt-24 backdrop-blur-[1px]">
                <div class="flex items-center gap-2 rounded-surface border border-line-soft bg-card px-4 py-3 text-body font-semibold text-ink-2 shadow-e3">
                    <x-icons.spinner class="size-5 animate-spin text-ink-2" /> @lang('general.loading')
                </div>
            </div>

            @if ($employees->isEmpty())
                <x-center-empty-state :variant="$filtering ? 'filtered' : 'empty'"
                    :title="$filtering ? __('general.no-search-results') : __('general.no-center-employees')"
                    :description="$filtering ? __('general.no-search-results-help') : __('general.no-program-employees-help')">
                    <x-slot:icon><x-icons.user class="size-9" /></x-slot:icon>
                </x-center-empty-state>
            @else
                <div class="grid items-stretch gap-4 [grid-template-columns:repeat(auto-fill,minmax(min(100%,15rem),1fr))]">
                    @foreach ($employees as $employee)
                        @php
                            $profileUrl = filled($employee->username) && (auth()->user()?->can('view', $employee) ?? false)
                                ? route('employees.show', ['employee' => $employee->username])
                                : null;
                            $initials = collect(preg_split('/\s+/u', trim((string) $employee->name), -1, PREG_SPLIT_NO_EMPTY))
                                ->take(2)
                                ->map(fn ($part) => mb_substr($part, 0, 1))
                                ->implode('');
                        @endphp

                        <article wire:key="program-employee-{{ $employee->id }}" class="group flex h-full min-w-0 flex-col items-center rounded-surface border border-line bg-card px-4 pb-5 pt-3 text-center shadow-e1 transition hover:-translate-y-0.5 hover:shadow-e3">
                            {{-- Actions in their own row, so an opened "Add to dashboard" never covers the person. --}}
                            <div class="flex min-h-11 w-full items-center justify-end gap-1">
                                @if ($profileUrl)
                                    <a href="{{ $profileUrl }}" wire:navigate
                                       aria-label="{{ __('general.view-profile') }}: {{ $employee->name }}" title="{{ __('general.view-profile') }}"
                                       class="inline-flex size-11 items-center justify-center rounded-full text-ink-3 transition hover:bg-hover hover:text-ink">
                                        <x-icons.eye class="size-5" />
                                    </a>
                                @endif
                                {{-- This person's work contact on the viewer's dashboard (rec.contact.<id>). --}}
                                <livewire:dashboard.add-to-dashboard :tile="\App\Support\Dashboard\DashboardLayout::recordKey('contact', $employee->id)" :compact="true" :key="'atd-contact-'.$employee->id" />
                            </div>

                            <div class="relative mt-1 shrink-0">
                                @if ($employee->avatar_url)
                                    <img src="{{ $employee->avatar_url }}" alt="{{ $employee->name }}" loading="lazy"
                                         class="size-24 rounded-full border-2 border-line object-cover">
                                @else
                                    <span class="flex size-24 items-center justify-center rounded-full border-2 border-line bg-surface text-h3 font-black uppercase text-ink-2">{{ $initials ?: '—' }}</span>
                                @endif
                                <span class="absolute bottom-1 end-1 grid size-5 place-items-center rounded-full border-2 border-card bg-ink text-on-ink" title="{{ __('general.active') }}"><x-ic name="check" class="size-3" /><span class="sr-only">{{ __('general.active') }}</span></span>
                            </div>

                            <h3 class="mt-3 line-clamp-2 w-full text-body font-black text-ink [overflow-wrap:anywhere]" title="{{ $employee->name }}">
                                @if ($profileUrl)
                                    <a href="{{ $profileUrl }}" wire:navigate class="hover:underline">{{ $employee->name }}</a>
                                @else
                                    {{ $employee->name }}
                                @endif
                            </h3>
                            <p class="mt-0.5 w-full truncate text-small text-ink-3" dir="ltr">{{ $employee->username ? '@'.$employee->username : __('general.not-specified') }}</p>

                            <div class="mt-3 w-full space-y-2">
                                @foreach ($employee->allContracts as $assignment)
                                    @php
                                        $position = $assignment->position;
                                        $city = $position?->center?->city ?? $position?->city;
                                        $where = $position?->city?->slug === \App\Models\City::NATIONAL_SLUG
                                            ? collect([__('general.head-office'), $position?->center?->city?->name])->filter()->join(' · ')
                                            : collect([$position?->center?->name, $city?->name])->filter()->join(' · ');
                                    @endphp
                                    <div class="border-t border-line-soft pt-2">
                                        <p class="line-clamp-2 text-small font-bold text-ink" title="{{ $position?->title }}">{{ $position?->title ?? __('general.not-specified') }}</p>
                                        <p class="mt-0.5 truncate text-caption text-ink-3" title="{{ $where }}">{{ $where ?: __('general.unassigned-center') }}</p>
                                        @if ($position?->jobLevel)
                                            <x-tag variant="soft" class="mt-1.5">{{ $position->jobLevel->name }}</x-tag>
                                        @endif
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
