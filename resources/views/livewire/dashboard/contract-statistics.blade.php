<x-slot:title>
    {{ __('general.contract-statistics') }}
</x-slot:title>

<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8  py-12">

    <div class="mb-12 ">
        <h1 class="text-h1 font-black text-ink tracking-tight mb-6">
            @lang('general.contract-statistics')
        </h1>


        <div data-tour="contract-stats.filters" class="filter-select-box grid gap-8 sm:grid-cols-2 lg:grid-cols-3 items-end">

        <div class="flex flex-col gap-2">
            <label class="text-small font-semibold text-ink uppercase tracking-wide">
                {{ trans_choice('general.city', 1) }}
            </label>

            <x-forms.select2-input wire:model.lazy="selectedCity" :hideSearch="true">
                <option value="">{{ __('dashboard.all-cities') }}</option>
                @foreach($cities as $city)
                    <option value="{{ $city->id }}">{{ $city->name }}</option>
                @endforeach
            </x-forms.select2-input>
        </div>

            <div class="flex flex-col gap-2">
                <label class="text-small font-semibold text-ink uppercase tracking-wide">
                    {{ trans_choice('general.program', 1) }}
                </label>

                <x-forms.select2-input wire:model.lazy="selectedProgram" :hideSearch="true">
                    <option value="">{{ __('dashboard.all-programs') }}</option>
                    @foreach($programs as $program)
                        <option value="{{ $program->id }}">{{ $program->name }}</option>
                    @endforeach
                </x-forms.select2-input>
            </div>

            <div class="flex sm:col-span-2 lg:col-span-1 justify-start lg:justify-start">
                <x-button variant="outline" size="sm" icon="reset" wire:click="resetFilters" data-tour="contract-stats.reset"
                          class="w-full sm:w-auto">
                    @lang('general.reset-filters')
                </x-button>

            </div>
        </div>
    </div>


    <div wire:loading.flex wire:target="applyFilters, resetFilters" class="justify-center py-12">
        <div class="text-body text-ink-2 flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-ink-3 animate-pulse"></span>
            @lang('dashboard.updating-statistics')
        </div>
    </div>


    <div wire:loading.remove wire:target="applyFilters, resetFilters"
         class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 ">

        @forelse ($cards as $card)

            <article data-tour="contract-stats.card" class="border border-line-soft rounded-surface bg-card
                            shadow-sm hover:shadow-e2 transition-all duration-200
                            hover:-translate-y-1 flex flex-col">


                <div class="p-6 pb-1.5 flex justify-between items-start">
                    <div class="flex flex-col">
                        <span class="text-h4 font-black text-ink">
                            {{ $card['name'] }}
                        </span>

                        <span class="text-body text-ink-2 mt-2">
                            {{ trans_choice('general.employee', 2) }}:
                            <span class="font-bold text-ink num">{{ $card['employees'] }}</span>
                        </span>
                    </div>

                    <div class="text-end">
                        <span class="inline-block px-2 py-1 text-caption font-semibold rounded-control
                                     {{ $card['color'] }}">
                            {{ $cardType === 'program'
                                ? trans_choice('general.program', 2)
                                : trans_choice('general.center', 1) }}
                        </span>

                        @if ($card['centers'] !== null)
                            <p class="text-body text-center text-ink-2 mt-2.5">
                                {{ trans_choice('general.center', 2) }}:
                                <span class="font-semibold text-ink">{{ $card['centers'] }}</span>
                            </p>
                        @endif
                    </div>
                </div>


                <div data-tour="contract-stats.breakdown" class="grid grid-cols-3 gap-3 p-4">

                    @foreach ([
                        'annual'  => __('general.annual'),
                        'service' => __('general.service'),
                        'trial'   => __('general.trial')
                    ] as $key => $label)

                        @php
                            $data = $card[$key];
                            $isService = $key === 'service';

                            $total = $isService
                                ? $data['online'] + $data['onsite']
                                : $data['full'] + $data['part'];
                        @endphp

                        <div class="rounded-surface bg-soft border border-line-soft
                    p-3 hover:bg-hover transition">

                            <p class="text-small font-semibold text-ink
                      border-b border-line-soft pb-1 mb-1">
                                {{ $label }}
                            </p>

                            <div class="space-y-1 text-small">

                                @if ($isService)
                                    <div class="flex justify-between">
                                        <span class="text-ink-2">@lang('general.online')</span>
                                        <span class="font-semibold text-ink">{{ $data['online'] }}</span>
                                    </div>

                                    <div class="flex justify-between">
                                        <span class="text-ink-2">@lang('general.onsite')</span>
                                        <span class="font-semibold text-ink">{{ $data['onsite'] }}</span>
                                    </div>
                                @else
                                    <div class="flex justify-between">
                                        <span class="text-ink-2">@lang('general.full-time')</span>
                                        <span class="font-semibold text-ink">{{ $data['full'] }}</span>
                                    </div>

                                    <div class="flex justify-between">
                                        <span class="text-ink-2">@lang('general.part-time')</span>
                                        <span class="font-semibold text-ink">{{ $data['part'] }}</span>
                                    </div>
                                @endif

                                <div class="border-t border-line-soft pt-1 mt-1 flex justify-between">
                                    <span class="text-ink-2">@lang('general.total')</span>
                                    <span class="font-bold text-ink num">{{ $total }}</span>
                                </div>

                            </div>
                        </div>
                    @endforeach
                </div>




                <div class="mt-auto p-4">
                    <a href="{{ $cardType === 'program'
                                ? route('programs.show', ['program' => $card['program_slug']])
                                : ($cardType === 'center'
                                    ? route('centers.show', $card['center_id'])
                                    : '#') }}"
                       @if (in_array($cardType, ['program', 'center'], true)) wire:navigate @endif
                       data-tour="contract-stats.show-more"
                       class="text-small font-bold flex justify-end text-ink underline-offset-4 hover:underline">
                        @lang('general.show-more') <span aria-hidden="true" class="ms-1 inline-block rtl:rotate-180">→</span>
                    </a>
                </div>

            </article>


        @empty

            <x-center-empty-state variant="filtered" class="col-span-full" :description="__('general.no-data-filters')" />

        @endforelse

    </div>

</div>
