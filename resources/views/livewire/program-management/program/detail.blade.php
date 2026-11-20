<x-slot:title>
    {{ $program->name }}
</x-slot:title>

<div class="relative isolate" x-data="{ activeTab: @js($initialTab) }">

    <div class="space-y-12">
        <div class="grid gap-6 md:grid-cols-[1fr_auto] items-start">

            <div
                class="relative overflow-hidden rounded-surface border border-line-soft bg-card backdrop-blur-xl p-9 shadow-e1">
                <div class="absolute inset-0 opacity-50 bg-surface"></div>

                <div class="relative flex flex-col gap-3">

                    <x-page-header class="!mb-0" :title="$program->name" :lead="$program->{'description_' . app()->getLocale()}">
                        <x-slot:actions>
                            {{-- The mailing list that stands for this project, when there is one. --}}
                            @if($groupList = \App\Models\MailList::emailFor(null, (int) $program->id, null))
                                <x-button variant="outline" :href="$groupList->mailto()" :wire:navigate="false" data-testid="email-group">
                                    <x-icons.envelope class="size-4" /> @lang('mail_lists.email_group')
                                </x-button>
                            @endif
                            {{-- This program on the viewer's dashboard (item.program.<id>). --}}
                            <livewire:dashboard.add-to-dashboard :tile="\App\Support\Dashboard\DashboardLayout::itemKey('program', $program->id)" :key="'atd-program-'.$program->id" />
                            <x-forms.toggle-input
                                class="!me-2 text-h4 p-2"
                                :active="$program->is_active"
                                :model-id="$program->id"
                                wire-event="confirmToggleItemStatus"
                            />

                            <x-table.edit-btn
                                class="!p-0 me-2"
                                :href="route('programs.edit', ['program' => $program->slug])"
                            />

                            <x-table.delete-btn class="!p-0" :modelId="$program->id"/>
                        </x-slot:actions>
                    </x-page-header>

                    <div class="relative mt-3 -ms-1">
                        <div
                            class="inline-flex rounded-surface border border-line-soft bg-card backdrop-blur-xl p-1 shadow-e3">
                            <button type="button"
                                @click="activeTab = 'details'"
                                class="ui-tab cursor-pointer px-4 py-2 rounded-surface text-body font-semibold transition-all
                                duration-300
                                "
                                :class="activeTab === 'details'
                                    ? 'bg-surface text-ink ring-1 ring-ink'
                                    : 'text-ink hover:bg-hover'">
                                @lang('general.project-info')
                            </button>

                            <button type="button"
                                @click="activeTab = 'centers'"
                                class="ui-tab cursor-pointer px-4 py-2 rounded-surface text-body font-semibold transition-all
                                duration-300
                                "
                                :class="activeTab === 'centers'
                                    ? 'bg-surface text-ink ring-1 ring-ink'
                                    : 'text-ink hover:bg-hover'">
                                {{trans_choice('general.center' , 1)}}
                            </button>

                            @if ($canViewEmployees)
                            <button type="button"
                                @click="activeTab = 'employees'"
                                class="ui-tab cursor-pointer px-4 py-2 rounded-surface text-body font-semibold transition-all
                                duration-300
                                "
                                :class="activeTab === 'employees'
                                    ? 'bg-surface text-ink ring-1 ring-ink'
                                    : 'text-ink hover:bg-hover'">
                                {{trans_choice('general.employee' , 2)}}
                            </button>
                            @endif
                        </div>
                    </div>

                </div>
            </div>

            <div
                class="w-full h-full text-center flex items-center rounded-surface border border-line-soft bg-card backdrop-blur-xl px-4 shadow-e1">
                <div class="relative grid grid-rows-2 gap-4 mx-auto">
                    <div>
                        <p class="text-body font-semibold text-ink-2">@lang('general.total-employees')</p>
                        <p class="text-h3 font-bold text-ink">
                            {{ $totalEmployees }}
                        </p>
                    </div>

                    <div>
                        <p class="text-body font-semibold text-ink-2">{{trans_choice('general.center' , 1)}}</p>
                        <p class="text-h3 font-bold text-ink">
                            {{ $centers->count() }}
                        </p>
                    </div>
                </div>
            </div>

        </div>


        <div class="pt-1">

            <div x-show="activeTab === 'details'" x-cloak>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <div
                        class="group relative overflow-hidden rounded-surface border border-line-soft bg-card backdrop-blur-xl p-9 shadow-e2">
                        <div class="flex items-start gap-3">

                            <div>
                                <p class="font-semibold text-ink">@lang('general.program-manager')</p>
                                <p class="text-ink-2">
                                    {{ $program->programManager?->name ?? '—' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        class="group relative overflow-hidden rounded-surface border border-line-soft bg-card backdrop-blur-xl p-9 shadow-e2">
                        <div class="flex items-start gap-3">
                            <div>
                                <p class="font-semibold text-ink">@lang('general.start-date')</p>
                                <p class="text-ink-2">
                                    {{ $program->start_date?->format('d M, Y') ?? '—' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        class="group relative overflow-hidden rounded-surface border border-line-soft bg-card backdrop-blur-xl p-9 shadow-e2">
                        <div class="flex items-start gap-3">
                            <div>
                                <p class="font-semibold text-ink">@lang('general.end-date')</p>
                                <p class="text-ink-2">
                                    {{ $program->end_date?->format('d M, Y') ?? __('general.active') }}
                                </p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>


            <div x-show="activeTab === 'centers'" x-cloak>
                @if ($centers->isNotEmpty())
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                        @foreach ($centers as $center)
                            <div class="relative" wire:key="center-card-{{ $center->id }}">
                            {{-- This centre on the viewer's dashboard (item.center.<id>); beside the link, never inside it. --}}
                            <div class="absolute top-3 end-3 z-10">
                                <livewire:dashboard.add-to-dashboard :tile="\App\Support\Dashboard\DashboardLayout::itemKey('center', $center->id)" :compact="true" :key="'atd-center-'.$center->id" />
                            </div>
                            <a href="{{ route('centers.show', $center->id) }}" wire:navigate
                               class="group relative block h-full rounded-surface border border-line-soft bg-card backdrop-blur-xl p-9 pe-14 shadow hover:-translate-y-1 hover:shadow-e3 transition">

                                <h3 class="text-h4 font-bold text-ink">
                                    {{ $center->name }}
                                </h3>

                                <p class="mt-1 text-ink-2 text-body">
                                    {{trans_choice('general.city' , 2)}}:
                                    {{ $center->city?->{'name_' . app()->getLocale()} ?? '—' }}
                                </p>

                                <p class="mt-2 text-body text-ink">
                                    @lang('general.manager'): <span class="font-semibold">
                                        {{-- This program's manager at the center, else the center's head (Center::managerNamesFor). --}}
                                        {{ $center->managerNamesFor($program) }}
                                    </span>
                                </p>

                            </a>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-12 text-ink-2">
                        @lang('general.no-centers')
                    </div>
                @endif
            </div>


            @if ($canViewEmployees)
                <div x-show="activeTab === 'employees'" x-cloak>
                    <livewire:program-management.program.program-employees :program-id="$program->id" :key="'program-employees-'.$program->id" />
                </div>
            @endif


        </div>

    </div>

</div>
