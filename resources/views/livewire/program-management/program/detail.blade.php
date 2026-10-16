<x-slot:title>
    {{ $program->name }}
</x-slot:title>

<div class="relative isolate" x-data="{ activeTab: 'details' }">

    <div class="space-y-12">
        <div class="grid gap-6 md:grid-cols-[1fr_auto] items-start">

            <div
                class="relative overflow-hidden rounded-surface border border-neutral-200 bg-white backdrop-blur-xl p-9 shadow-e1">
                <div class="absolute inset-0 opacity-50 bg-neutral-100"></div>

                <div class="relative flex flex-col gap-3">

                    <div class="flex w-full justify-between items-start">
                        <div>
                            <h1 class="text-h2 font-black text-neutral-900">
                                {{ $program->name }}
                            </h1>
                        </div>

                        <div class="flex items-center gap-3">
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
                        </div>
                    </div>

                    <p class="text-neutral-600 dark:text-neutral-400 max-w-prose line-clamp-3">
                        {{ $program->{'description_' . app()->getLocale()} }}
                    </p>

                    <div class="relative mt-3 -ms-1">
                        <div
                            class="inline-flex rounded-surface border border-neutral-200 bg-white backdrop-blur-xl p-1 shadow-e3">
                            <button
                                @click="activeTab = 'details'"
                                class="ui-tab cursor-pointer px-4 py-2 rounded-surface text-body font-semibold transition-all
                                duration-300
                                "
                                :class="activeTab === 'details'
                                    ? 'bg-primary text-white'
                                    : 'text-neutral-700'">
                                @lang('general.project-info')
                            </button>

                            <button
                                @click="activeTab = 'centers'"
                                class="ui-tab cursor-pointer px-4 py-2 rounded-surface text-body font-semibold transition-all
                                duration-300
                                "
                                :class="activeTab === 'centers'
                                    ? 'bg-primary text-white'
                                    : 'text-neutral-700'">
                                {{trans_choice('general.center' , 1)}}
                            </button>

                            <button
                                @click="activeTab = 'employees'"
                                class="ui-tab cursor-pointer px-4 py-2 rounded-surface text-body font-semibold transition-all
                                duration-300
                                "
                                :class="activeTab === 'employees'
                                    ? 'bg-primary text-white'
                                    : 'text-neutral-700'">
                                {{trans_choice('general.employee' , 2)}}
                            </button>
                        </div>
                    </div>

                </div>
            </div>

            <div
                class="w-full h-full text-center flex items-center rounded-surface border border-neutral-200 bg-white backdrop-blur-xl px-4 shadow-e1">
                <div class="relative grid grid-rows-2 gap-4 mx-auto">
                    <div>
                        <p class="text-body font-semibold text-neutral-500">@lang('general.total-employees')</p>
                        <p class="text-h3 font-bold text-secondary-dark">
                            {{ $employees->total() }}
                        </p>
                    </div>

                    <div>
                        <p class="text-body font-semibold text-neutral-500">{{trans_choice('general.center' , 1)}}</p>
                        <p class="text-h3 font-bold text-secondary-dark">
                            {{ $program->centers->count() }}
                        </p>
                    </div>
                </div>
            </div>

        </div>


        <div class="pt-1">

            <div x-show="activeTab === 'details'" x-cloak>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <div
                        class="group relative overflow-hidden rounded-surface border border-neutral-200 bg-white backdrop-blur-xl p-9 shadow-e2">
                        <div class="flex items-start gap-3">

                            <div>
                                <p class="font-semibold text-neutral-900">@lang('general.program-manager')</p>
                                <p class="text-neutral-600">
                                    {{ $program->programManager?->name ?? '—' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        class="group relative overflow-hidden rounded-surface border bg-white backdrop-blur-xl p-9 shadow-e2">
                        <div class="flex items-start gap-3">
                            <div>
                                <p class="font-semibold text-neutral-900">@lang('general.start-date')</p>
                                <p class="text-neutral-600">
                                    {{ $program->start_date?->format('d M, Y') ?? '—' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        class="group relative overflow-hidden rounded-surface border bg-white backdrop-blur-xl p-9 shadow-e2">
                        <div class="flex items-start gap-3">
                            <div>
                                <p class="font-semibold text-neutral-900">@lang('general.end-date')</p>
                                <p class="text-neutral-600">
                                    {{ $program->end_date?->format('d M, Y') ?? __('general.active') }}
                                </p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>


            <div x-show="activeTab === 'centers'" x-cloak>
                @if ($program->centers->isNotEmpty())
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                        @foreach ($program->centers as $center)
                            <a href="{{ route('centers.show', $center->id) }}" wire:navigate
                               class="group relative rounded-surface border bg-white backdrop-blur-xl p-9 shadow hover:-translate-y-1 hover:shadow-e3 transition">

                                <h3 class="text-h4 font-bold text-neutral-900">
                                    {{ $center->name }}
                                </h3>

                                <p class="mt-1 text-neutral-500 text-body">
                                    {{trans_choice('general.city' , 2)}}:
                                    {{ $center->city?->{'name_' . app()->getLocale()} ?? '—' }}
                                </p>

                                <p class="mt-2 text-body text-neutral-700">
                                    @lang('general.manager'): <span class="font-semibold">
                                        {{ $center->projectManager?->name ?? '—' }}
                                    </span>
                                </p>

                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-12 text-neutral-500">
                        @lang('general.no-centers')
                    </div>
                @endif
            </div>


            <div x-show="activeTab === 'employees'" x-cloak>
                @if ($employees && $employees->isNotEmpty())
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">

                        @foreach ($employees as $employee)
                            <div class="group relative flex flex-col justify-center items-center text-center
                            rounded-surface border border-neutral-300 bg-white py-6
                            shadow-e1 hover:shadow-e2 transition-all duration-300">

                                <div class="flex text-h1 items-center justify-center size-26  flex-shrink-0 rounded-full overflow-hidden
                                    bg-white border-2 border-primary text-primary font-medium uppercase ">
                                    @if($employee->avatar_url)
                                        <img src="{{ $employee->avatar_url }}"
                                             alt="{{ $employee->displayName ?? $employee->name }}"
                                             class="h-full w-full object-cover">
                                    @else
                                        {{ collect(explode(' ', $employee->displayName ?? $employee->name))
                                            ->map(fn($n) => Str::substr($n, 0, 1))
                                            ->join('') }}
                                    @endif
                                </div>


                                <h3 class="text-h4 font-bold text-secondary-dark mt-3 mb-1">
                                    {{ $employee->{'name_' . app()->getLocale()} }}
                                </h3>

                                <div class="space-y-3 text-small text-secondary-dark mt-3 w-full">
                                    @foreach ($employee->contracts as $contract)
                                        <div class="border-t first:border-none pt-2">
                                            <p class="font-semibold text-secondary-light">
                                                {{ $contract->position?->{'title_' . app()->getLocale()} }}
                                            </p>
                                        </div>
                                    @endforeach
                                </div>

                            </div>
                        @endforeach

                    </div>

                    <div class="mt-6">
                        {{ $employees->onEachSide(1)->links() }}
                    </div>

                @else
                    <div class="text-center py-12 text-neutral-500">
                        @lang('general.no-employees')
                    </div>
                @endif
            </div>


        </div>

    </div>

</div>
