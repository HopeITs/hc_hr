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


                @include('livewire.dashboard.partials.contract-stat-body', ['card' => $card, 'cardType' => $cardType])

                <div class="mt-auto flex items-center justify-between gap-2 p-4">
                    {{-- This card on the viewer's dashboard (DashboardLayout::contractStatKey; the button draws nothing for a card the role may not have). --}}
                    <livewire:dashboard.add-to-dashboard :tile="$card['tile']" :compact="true" :key="'atd-'.$card['tile']" />
                    <a href="{{ $cardType === 'program'
                                ? route('programs.show', ['program' => $card['program_slug']])
                                : ($cardType === 'center'
                                    ? route('centers.show', $card['center_id'])
                                    : '#') }}"
                       @if (in_array($cardType, ['program', 'center'], true)) wire:navigate @endif
                       data-tour="contract-stats.show-more"
                       class="ms-auto text-small font-bold flex justify-end text-ink underline-offset-4 hover:underline">
                        @lang('general.show-more') <span aria-hidden="true" class="ms-1 inline-block rtl:rotate-180">→</span>
                    </a>
                </div>

            </article>


        @empty

            <x-center-empty-state variant="filtered" class="col-span-full" :description="__('general.no-data-filters')" />

        @endforelse

    </div>

</div>
