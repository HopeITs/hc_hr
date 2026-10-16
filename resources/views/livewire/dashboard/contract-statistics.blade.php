<x-slot:title>
    {{ __('general.contract-statistics') }}
</x-slot:title>

<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8  py-12">

    <div class="mb-12 ">
        <h1 class="text-h1 font-black text-neutral-900 tracking-tight mb-6">
            @lang('general.contract-statistics')
        </h1>


        <div data-tour="contract-stats.filters" class="filter-select-box grid gap-8 sm:grid-cols-2 lg:grid-cols-3 items-end">

        <div class="flex flex-col gap-2">
            <label class="text-small font-semibold text-secondary-dark uppercase tracking-wide">
                {{ trans_choice('general.city', 1) }}
            </label>

            <x-forms.select2-input wire:model.lazy="selectedCity" :hideSearch="true">
                <option value="">{{ __('general.all') }} {{ trans_choice('general.city', 2) }}</option>
                @foreach($cities as $city)
                    <option value="{{ $city->id }}">{{ $city->name }}</option>
                @endforeach
            </x-forms.select2-input>
        </div>

            <div class="flex flex-col gap-2">
                <label class="text-small font-semibold text-secondary-dark uppercase tracking-wide">
                    {{ trans_choice('general.program', 1) }}
                </label>

                <x-forms.select2-input wire:model.lazy="selectedProgram" :hideSearch="true">
                    <option value="">{{ __('general.all') }} {{ trans_choice('general.program', 2) }}</option>
                    @foreach($programs as $program)
                        <option value="{{ $program->id }}">{{ $program->name }}</option>
                    @endforeach
                </x-forms.select2-input>
            </div>

            <div class="flex sm:col-span-2 lg:col-span-1 justify-start lg:justify-start">
                {{-- <button wire:click="resetFilters"
                        class="px-4 py-3 text-body text-secondary-dark font-semibold border border-secondary-soft
           rounded-surface bg-white hover:shadow-e1 cursor-pointer transition w-full sm:w-auto
           flex items-center justify-center"> --}}

              <button wire:click="resetFilters" data-tour="contract-stats.reset"
                        class="px-4 h-[38px] text-body text-secondary-dark font-semibold border border-secondary-soft
           rounded-surface bg-white hover:shadow-e1 cursor-pointer transition w-full sm:w-auto
           flex items-center justify-center gap-2">

                    <svg xmlns="http://www.w3.org/2000/svg"
                         viewBox="0 0 24 24"
                         class="w-5 h-5"
                         fill="currentColor">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                              d="M18.364 3.05762C18.7782 3.05762 19.114 3.3934 19.114 3.80762V8.05026C19.114 8.46447 18.7782 8.80026 18.364 8.80026H14.1213C13.7071 8.80026 13.3713 8.46447 13.3713 8.05026C13.3713 7.63604 13.7071 7.30026 14.1213 7.30026H16.4817C13.6363 5.05718 9.4987 5.24825 6.87348 7.87348C4.04217 10.7048 4.04217 15.2952 6.87348 18.1265C9.70478 20.9578 14.2952 20.9578 17.1265 18.1265C19.0234 16.2297 19.6504 13.5428 19.0039 11.1219C18.897 10.7217 19.1348 10.3106 19.535 10.2038C19.9352 10.0969 20.3462 10.3347 20.4531 10.7349C21.2321 13.6518 20.478 16.8964 18.1872 19.1872C14.7701 22.6043 9.2299 22.6043 5.81282 19.1872C2.39573 15.7701 2.39573 10.2299 5.81282 6.81282C9.04483 3.5808 14.1762 3.40576 17.614 6.28768V3.80762C17.614 3.3934 17.9497 3.05762 18.364 3.05762Z"/>
                    </svg>

                        <span>
        @lang('general.reset-filters')
    </span>

                </button>

            </div>
        </div>
    </div>


    <div wire:loading.flex wire:target="applyFilters, resetFilters" class="justify-center py-12">
        <div class="text-body text-secondary-dark flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-primary animate-pulse"></span>
            @lang('general.updating-statistics')
        </div>
    </div>


    <div wire:loading.remove wire:target="applyFilters, resetFilters"
         class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 ">

        @forelse ($cards as $card)

            <article data-tour="contract-stats.card" class="border border-secondary-soft rounded-surface bg-white
                            shadow-[0px_0px_25px_-14px_rgba(88,_89,_91,_0.30)] hover:shadow-e2 transition-all duration-200
                            hover:-translate-y-1 flex flex-col">


                <div class="p-6 pb-1.5 flex justify-between items-start">
                    <div class="flex flex-col">
                        <span class="text-h4 font-black text-neutral-900">
                            {{ $card['name'] }}
                        </span>

                        <span class="text-body text-secondary-dark mt-2">
                            {{ trans_choice('general.employee', 2) }}:
                            <span class="font-bold text-[#58595b]">{{ $card['employees'] }}</span>
                        </span>
                    </div>

                    <div class="text-right">
                        <span class="inline-block px-2 py-1 text-caption font-semibold text-white rounded-control scale-[0.8] origin-right
                                     {{ $card['color'] }}">
                            {{ $cardType === 'program'
                                ? trans_choice('general.program', 2)
                                : trans_choice('general.center', 1) }}
                        </span>

                        @if ($card['centers'] !== null)
                            <p class="text-body text-center text-secondary-dark mt-[10.2px]">
                                {{ trans_choice('general.center', 2) }}:
                                <span class="font-semibold text-neutral-800">{{ $card['centers'] }}</span>
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

                        <div class="rounded-surface bg-neutral-50 border border-neutral-200
                    p-3 shadow-inner hover:bg-neutral-100 transition">

                            <p class="text-small font-semibold text-neutral-800
                      border-b border-neutral-200 pb-1 mb-1">
                                {{ $label }}
                            </p>

                            <div class="space-y-1 text-small">

                                @if ($isService)
                                    <div class="flex justify-between">
                                        <span class="text-neutral-600">@lang('general.online')</span>
                                        <span class="font-semibold text-neutral-800">{{ $data['online'] }}</span>
                                    </div>

                                    <div class="flex justify-between">
                                        <span class="text-neutral-600">@lang('general.onsite')</span>
                                        <span class="font-semibold text-neutral-800">{{ $data['onsite'] }}</span>
                                    </div>
                                @else
                                    <div class="flex justify-between">
                                        <span class="text-neutral-600">@lang('general.full-time')</span>
                                        <span class="font-semibold text-neutral-800">{{ $data['full'] }}</span>
                                    </div>

                                    <div class="flex justify-between">
                                        <span class="text-neutral-600">@lang('general.part-time')</span>
                                        <span class="font-semibold text-neutral-800">{{ $data['part'] }}</span>
                                    </div>
                                @endif

                                <div class="border-t border-neutral-200 pt-1 mt-1 flex justify-between">
                                    <span class="text-neutral-600">@lang('general.total')</span>
                                    <span class="font-bold text-[#58595b]">{{ $total }}</span>
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
                       class="text-small flex justify-end  text-primary hover:underline">
                        @lang('general.show-more') →
                    </a>
                </div>

            </article>


        @empty

            <div class="col-span-full text-center py-12 text-secondary-dark">
                @lang('general.no-data-filters')
            </div>

        @endforelse

    </div>

</div>
