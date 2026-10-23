<x-slot:title>
    {{ trans_choice('general.city', 2) }}
</x-slot:title>

<div>

    @php($locale = app()->getLocale())
    <div class="flex justify-between items-center mb-6">

        <livewire:layout.breadcrumbs :title="trans_choice('general.city', 2)" :crumbs="[
            ['label' => __('general.project-basics')],
            ['label' => trans_choice('general.city', 2), 'route' => 'cities.index'],
        ]" />

        @activerole('HR','super-admin')
            <div class="flex items-center gap-2">
                <x-excel-export-button wire:click="$dispatchTo('basics.city-table', 'exportExcel')" />
            </div>
        @endactiverole

    </div>
    <livewire:basics.city-table />

    {{-- Edit: the HR hotline only (see CityModule). --}}
    <x-modal.dialog id="showModal" :title="$modalTitle" wire:model.defer="showModal" close-action="requestClose" wire:ignore.self>
        <form wire:submit.prevent="save" class="space-y-4">
            <p class="text-body font-semibold text-secondary-dark">{{ $item['name'] }}</p>

            <div>
                <x-forms.input-label for="hr_hotline">@lang('digital_id.city.hotline')</x-forms.input-label>
                <x-forms.input type="tel" id="hr_hotline" dir="ltr" wire:model="item.hr_hotline" />
                <p class="mt-1 text-small text-secondary-light">@lang('digital_id.city.hotline_help')</p>
                <x-error-span>{{ $errors->first('item.hr_hotline') }}</x-error-span>
            </div>

            <div class="flex justify-end gap-2">
                <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
                <x-modal.cancel-btn />
            </div>
        </form>
    </x-modal.dialog>
</div>
