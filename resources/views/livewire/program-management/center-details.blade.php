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
            <section class="relative overflow-hidden rounded-surface border border-line-soft bg-card p-[clamp(1rem,3vw,2.25rem)] shadow-e1">
                <div class="pointer-events-none absolute inset-0 bg-surface/50"></div>

                <div class="relative flex flex-col gap-[clamp(1rem,2vw,1.5rem)]">
                    <x-page-header class="!mb-0" :title="$center->name"
                        :lead="__('general.located-in').' '.($center->city?->name ?? '—').' · '.__('general.centers-text')">
                        <x-slot:actions>
                            <x-button variant="outline" :href="route('centers.index')">
                                @lang('general.back')
                            </x-button>

                            @if(auth()->user()->canOpenReferencePage('positions'))
                            <x-button :href="route('positions.index', ['center' => $center->id])">
                                @lang('general.view-positions')
                            </x-button>
                            @endif
                        </x-slot:actions>
                    </x-page-header>

                    <div class="-mx-1 overflow-x-auto px-1 pb-1 [scrollbar-width:thin]">
                        <div
                            role="tablist"
                            aria-label="{{ __('general.center-details-sections') }}"
                            class="inline-flex min-w-max rounded-surface border border-line-soft bg-card p-1 shadow-e2"
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
                                :class="activeTab === 'contracts' ? 'bg-surface text-ink ring-1 ring-ink' : 'text-ink hover:bg-hover'"
                                class="inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-surface px-[clamp(0.75rem,2vw,1rem)] py-2 text-body font-semibold transition"
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
                                :class="activeTab === 'programs' ? 'bg-surface text-ink ring-1 ring-ink' : 'text-ink hover:bg-hover'"
                                class="inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-surface px-[clamp(0.75rem,2vw,1rem)] py-2 text-body font-semibold transition"
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
                                    :class="activeTab === 'employees' ? 'bg-surface text-ink ring-1 ring-ink' : 'text-ink hover:bg-hover'"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-surface px-[clamp(0.75rem,2vw,1rem)] py-2 text-body font-semibold transition"
                                >
                                    <x-icons.users
                                        class="size-4"
                                        x-bind:class="activeTab === 'employees' ? '!text-ink' : ''"
                                    />
                                    <span>{{ trans_choice('general.employee', 2) }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-caption font-bold" :class="activeTab === 'employees' ? 'bg-ink text-on-ink' : 'bg-surface text-ink-2'">
                                        {{ number_format($totalEmployees) }}
                                    </span>
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            <aside class="grid [grid-template-columns:repeat(auto-fit,minmax(min(100%,8rem),1fr))] overflow-hidden rounded-surface border border-line-soft bg-card shadow-e1" aria-label="{{ __('general.center-summary') }}">
                <div class="flex min-h-28 flex-col items-center justify-center p-4 text-center">
                    <p class="text-small font-semibold text-ink-2">@lang('general.total-employees')</p>
                    <p class="mt-1 text-h3 font-black text-ink">{{ number_format($totalEmployees) }}</p>
                </div>
                <div class="flex min-h-28 flex-col items-center justify-center border-s border-line-soft p-4 text-center @5xl:border-s-0 @5xl:border-t">
                    <p class="text-small font-semibold text-ink-2">@lang('general.active-assignments')</p>
                    <p class="mt-1 text-h3 font-black text-ink">{{ number_format($totalAssignments) }}</p>
                </div>
                <div class="flex min-h-28 flex-col items-center justify-center border-s border-line-soft p-4 text-center @5xl:border-s-0 @5xl:border-t">
                    <p class="text-small font-semibold text-ink-2">{{ trans_choice('general.program', 2) }}</p>
                    <p class="mt-1 text-h3 font-black text-ink">{{ number_format(count($programData)) }}</p>
                </div>
            </aside>
        </div>

        <section id="center-panel-contracts" role="tabpanel" aria-labelledby="center-tab-contracts" x-show="activeTab === 'contracts'">
            @if ($contractData !== [])
                <div class="grid justify-start gap-4 [grid-template-columns:repeat(auto-fill,minmax(min(100%,20rem),28rem))]">
                    @foreach ($contractData as $categories)
                        @php($categoryTotal = array_sum(array_column($categories['items'], 'count')))
                        <article class="rounded-surface border border-line-soft bg-card p-[clamp(1rem,2.5vw,2rem)] shadow-e2 transition hover:-translate-y-0.5 hover:shadow-e3">
                            <div class="flex items-center justify-between gap-4">
                                <h2 class="text-h4 font-bold text-ink">{{ $categories['label'] }}</h2>
                                <span class="text-body font-semibold text-ink-2">@lang('general.total') {{ $categoryTotal }}</span>
                            </div>
                            <div class="mt-5 space-y-4">
                                @foreach ($categories['items'] as $item)
                                    @php($percentage = $categoryTotal ? round(($item['count'] / $categoryTotal) * 100) : 0)
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between gap-3 text-body">
                                            <span class="text-ink-2">{{ $item['label'] }}</span>
                                            <span class="font-bold text-ink">{{ $item['count'] }}</span>
                                        </div>
                                        <div class="h-2 overflow-hidden rounded-full bg-surface" role="progressbar" aria-valuenow="{{ $percentage }}" aria-valuemin="0" aria-valuemax="100">
                                            <div class="h-full rounded-full bg-ink" style="width: {{ $percentage }}%"></div>
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
                        <article class="rounded-surface border border-line-soft bg-card p-[clamp(1rem,2.5vw,2rem)] shadow-e2 transition hover:-translate-y-0.5 hover:shadow-e3">
                            <div class="flex size-10 items-center justify-center rounded-surface bg-surface text-ink-2">
                                <x-icons.program class="size-5" />
                            </div>
                            <h2 class="mt-4 text-h4 font-extrabold text-ink">{{ $program['program'] }}</h2>
                            <p class="mt-1 text-body text-ink-3">@lang('general.total-employees')</p>
                            <p class="mt-3 text-h2 font-black text-ink">{{ number_format($program['count']) }}</p>
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
