<x-slot:title>
    {{ $center->name }}
</x-slot:title>

<div
    class="relative isolate"
    x-data="{
        activeTab: 'contracts',
        selectTab(tab) {
            this.activeTab = tab;
        },
        moveTab(event, direction) {
            const tabs = Array.from(event.currentTarget.closest('[role=tablist]').querySelectorAll('[role=tab]'));
            const currentIndex = tabs.indexOf(event.currentTarget);
            const nextTab = tabs[(currentIndex + direction + tabs.length) % tabs.length];

            this.activeTab = nextTab.dataset.tab;
            nextTab.focus();
        }
    }"
>
    <div class="@container mx-auto max-w-[100rem] space-y-[clamp(1.5rem,4vw,3rem)]">
        <div class="grid items-stretch gap-4 @5xl:grid-cols-[minmax(0,1fr)_13rem] @5xl:gap-6">
            <section class="relative overflow-hidden rounded-surface border border-neutral-200 bg-white p-[clamp(1rem,3vw,2.25rem)] shadow-e1 dark:border-neutral-700 dark:bg-neutral-900">
                <div class="pointer-events-none absolute inset-0 bg-neutral-100/50 dark:bg-neutral-800/50"></div>

                <div class="relative flex flex-col gap-[clamp(1rem,2vw,1.5rem)]">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1 basis-[22rem]">
                            <h1 class="text-[clamp(1.75rem,4vw,2.5rem)] font-black leading-tight text-neutral-900 dark:text-white">
                                {{ $center->name }}
                            </h1>
                            <p class="mt-3 max-w-3xl text-body leading-relaxed text-neutral-600 dark:text-neutral-300">
                                <span>@lang('general.located-in')</span>
                                <span class="font-semibold text-neutral-900 dark:text-white">{{ $center->city?->name ?? '—' }}</span>
                                <span aria-hidden="true"> · </span>
                                <span>@lang('general.centers-text')</span>
                            </p>
                        </div>

                        <div class="flex min-w-0 flex-wrap items-center justify-end gap-2">
                            <a
                                href="{{ route('centers.index') }}"
                                wire:navigate
                                class="inline-flex min-h-11 items-center justify-center rounded-surface border border-neutral-200 bg-white px-4 py-2 text-small font-semibold text-neutral-800 shadow-e1 transition hover:border-neutral-300 hover:bg-neutral-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                            >
                                @lang('general.back')
                            </a>

                            @if(auth()->user()->canOpenReferencePage('positions'))
                            <a
                                href="{{ route('positions.index', ['center' => $center->id]) }}"
                                wire:navigate
                                class="inline-flex min-h-11 items-center justify-center rounded-surface bg-primary px-4 py-2 text-small font-semibold text-white shadow-e2 transition hover:bg-primary-hover hover:shadow-e3 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                            >
                                @lang('general.view-positions')
                            </a>
                            @endif
                        </div>
                    </div>

                    <div class="-mx-1 overflow-x-auto px-1 pb-1 [scrollbar-width:thin]">
                        <div
                            role="tablist"
                            aria-label="{{ __('general.center-details-sections') }}"
                            class="inline-flex min-w-max rounded-surface border border-neutral-200 bg-white p-1 shadow-e2 dark:border-neutral-700 dark:bg-neutral-900"
                        >
                            <button
                                id="center-tab-contracts"
                                type="button"
                                role="tab"
                                data-tab="contracts"
                                aria-controls="center-panel-contracts"
                                :aria-selected="activeTab === 'contracts'"
                                :tabindex="activeTab === 'contracts' ? 0 : -1"
                                @click="selectTab('contracts')"
                                @keydown.right.prevent="moveTab($event, 1)"
                                @keydown.left.prevent="moveTab($event, -1)"
                                :class="activeTab === 'contracts' ? 'bg-primary text-white shadow-e1' : 'text-neutral-700 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                class="inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-surface px-[clamp(0.75rem,2vw,1rem)] py-2 text-body font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                            >
                                <x-icons.contracts class="size-4" />
                                <span>@lang('general.employees-by-contract-type')</span>
                            </button>

                            <button
                                id="center-tab-programs"
                                type="button"
                                role="tab"
                                data-tab="programs"
                                aria-controls="center-panel-programs"
                                :aria-selected="activeTab === 'programs'"
                                :tabindex="activeTab === 'programs' ? 0 : -1"
                                @click="selectTab('programs')"
                                @keydown.right.prevent="moveTab($event, 1)"
                                @keydown.left.prevent="moveTab($event, -1)"
                                :class="activeTab === 'programs' ? 'bg-primary text-white shadow-e1' : 'text-neutral-700 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                class="inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-surface px-[clamp(0.75rem,2vw,1rem)] py-2 text-body font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                            >
                                <x-icons.program class="size-4" />
                                <span>@lang('general.employees-by-program')</span>
                            </button>

                            @if ($canViewEmployees)
                                <button
                                    id="center-tab-employees"
                                    type="button"
                                    role="tab"
                                    data-tab="employees"
                                    aria-controls="center-panel-employees"
                                    :aria-selected="activeTab === 'employees'"
                                    :tabindex="activeTab === 'employees' ? 0 : -1"
                                    @click="selectTab('employees')"
                                    @keydown.right.prevent="moveTab($event, 1)"
                                    @keydown.left.prevent="moveTab($event, -1)"
                                    :class="activeTab === 'employees' ? 'bg-primary text-white shadow-e1' : 'text-neutral-700 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-surface px-[clamp(0.75rem,2vw,1rem)] py-2 text-body font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                                >
                                    <x-icons.users
                                        class="size-4"
                                        x-bind:class="activeTab === 'employees' ? '!text-white' : ''"
                                    />
                                    <span>{{ trans_choice('general.employee', 2) }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-caption font-bold" :class="activeTab === 'employees' ? 'bg-white/20 text-white' : 'bg-neutral-100 text-secondary-medium dark:bg-neutral-800 dark:text-neutral-300'">
                                        {{ number_format($totalEmployees) }}
                                    </span>
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            <aside class="grid [grid-template-columns:repeat(auto-fit,minmax(min(100%,8rem),1fr))] overflow-hidden rounded-surface border border-neutral-200 bg-white shadow-e1 dark:border-neutral-700 dark:bg-neutral-900" aria-label="{{ __('general.center-summary') }}">
                <div class="flex min-h-28 flex-col items-center justify-center p-4 text-center">
                    <p class="text-small font-semibold text-neutral-500 dark:text-neutral-400">@lang('general.total-employees')</p>
                    <p class="mt-1 text-h3 font-black text-secondary-dark dark:text-white">{{ number_format($totalEmployees) }}</p>
                </div>
                <div class="flex min-h-28 flex-col items-center justify-center border-s border-neutral-100 p-4 text-center @5xl:border-s-0 @5xl:border-t dark:border-neutral-800">
                    <p class="text-small font-semibold text-neutral-500 dark:text-neutral-400">@lang('general.active-assignments')</p>
                    <p class="mt-1 text-h3 font-black text-primary">{{ number_format($totalAssignments) }}</p>
                </div>
                <div class="flex min-h-28 flex-col items-center justify-center border-s border-neutral-100 p-4 text-center @5xl:border-s-0 @5xl:border-t dark:border-neutral-800">
                    <p class="text-small font-semibold text-neutral-500 dark:text-neutral-400">{{ trans_choice('general.program', 2) }}</p>
                    <p class="mt-1 text-h3 font-black text-secondary-dark dark:text-white">{{ number_format(count($programData)) }}</p>
                </div>
            </aside>
        </div>

        <section id="center-panel-contracts" role="tabpanel" aria-labelledby="center-tab-contracts" x-show="activeTab === 'contracts'">
            @if ($contractData !== [])
                <div class="grid justify-start gap-4 [grid-template-columns:repeat(auto-fill,minmax(min(100%,20rem),28rem))]">
                    @foreach ($contractData as $categories)
                        @php($categoryTotal = array_sum(array_column($categories['items'], 'count')))
                        <article class="rounded-surface border border-neutral-200 bg-white p-[clamp(1rem,2.5vw,2rem)] shadow-e2 transition hover:-translate-y-0.5 hover:shadow-e3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex items-center justify-between gap-4">
                                <h2 class="text-h4 font-bold text-neutral-900 dark:text-white">{{ $categories['label'] }}</h2>
                                <span class="text-body font-semibold text-secondary-medium">@lang('general.total') {{ $categoryTotal }}</span>
                            </div>
                            <div class="mt-5 space-y-4">
                                @foreach ($categories['items'] as $item)
                                    @php($percentage = $categoryTotal ? round(($item['count'] / $categoryTotal) * 100) : 0)
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between gap-3 text-body">
                                            <span class="text-neutral-600 dark:text-neutral-300">{{ $item['label'] }}</span>
                                            <span class="font-bold text-neutral-900 dark:text-white">{{ $item['count'] }}</span>
                                        </div>
                                        <div class="h-2 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800" role="progressbar" aria-valuenow="{{ $percentage }}" aria-valuemin="0" aria-valuemax="100">
                                            <div class="h-full rounded-full bg-primary" style="width: {{ $percentage }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <x-center-empty-state :title="__('general.no-contract-data')" :description="__('general.no-contract-data-help')" />
            @endif
        </section>

        <section id="center-panel-programs" role="tabpanel" aria-labelledby="center-tab-programs" x-show="activeTab === 'programs'" x-cloak>
            @if ($programData !== [])
                <div class="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(min(100%,17rem),1fr))]">
                    @foreach ($programData as $program)
                        <article class="rounded-surface border border-neutral-200 bg-white p-[clamp(1rem,2.5vw,2rem)] shadow-e2 transition hover:-translate-y-0.5 hover:shadow-e3 dark:border-neutral-700 dark:bg-neutral-900">
                            <div class="flex size-10 items-center justify-center rounded-surface bg-neutral-100 text-primary dark:bg-neutral-800">
                                <x-icons.program class="size-5" />
                            </div>
                            <h2 class="mt-4 text-h4 font-extrabold text-secondary-dark dark:text-white">{{ $program['program'] }}</h2>
                            <p class="mt-1 text-body text-secondary-light">@lang('general.total-employees')</p>
                            <p class="mt-3 text-h2 font-black text-primary">{{ number_format($program['count']) }}</p>
                        </article>
                    @endforeach
                </div>
            @else
                <x-center-empty-state :title="__('general.no-programs-for-center')" :description="__('general.no-programs-for-center-help')" />
            @endif
        </section>

        @if ($canViewEmployees)
            <section id="center-panel-employees" role="tabpanel" aria-labelledby="center-tab-employees" x-show="activeTab === 'employees'" x-cloak>
                <livewire:program-management.center.center-employees :center-id="$center->id" :key="'center-employees-'.$center->id" />
            </section>
        @endif
    </div>

    <style>
        [x-cloak] { display: none !important; }

        @media (prefers-reduced-motion: reduce) {
            #center-panel-contracts *,
            #center-panel-programs *,
            #center-panel-employees * {
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</div>
