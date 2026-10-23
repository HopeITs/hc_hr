<x-slot:title>
    {{ __('general.default-deliverables') }}
</x-slot:title>

<div>
    @php($locale = app()->getLocale())

    <div class="flex justify-between items-center mb-6">
        <livewire:layout.breadcrumbs :title="__('general.default-deliverables')" :crumbs="[
            ['label' => trans_choice('general.employee-management', 2)],
            ['label' => __('general.default-deliverables')],
        ]" :showButton="true" />
        @activerole('HR', 'super-admin')
            <div class="flex items-center gap-2">
                <x-excel-export-button data-tour="list.export"
                    wire:click="$dispatchTo('program-management.default-deliverable.default-deliverables-table', 'exportExcel')" />
                <x-primary-button type="button" wire:click.prevent="create" data-tour="list.add">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        @endactiverole
    </div>

    <livewire:program-management.default-deliverable.default-deliverables-table />

    <x-modal.dialog id="showModal" :title="$modalTitle" wire:model="showModal" close-action="requestClose" wire:ignore.self>
        <form wire:submit.prevent="save" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2 md:col-span-1">
                    <x-forms.input-label
                        for="employee_id" required>{{ trans_choice('general.employee', 1) }}</x-forms.input-label>
                    <x-forms.select2-input id="employee_id" wire:model="item.employee_id">
                        <option value="">{{ __('general.select') }}</option>
                        @foreach ($employees as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first('item.employee_id') }}</x-error-span>
                </div>

                <div class="col-span-2 md:col-span-1">
                    <x-forms.input-label for="city_id" required>{{ trans_choice('general.city', 1) }}</x-forms.input-label>
                    <x-forms.select2-input id="city_id" wire:model="item.city_id">
                        <option value="">{{ __('general.select') }}</option>
                        @foreach ($cities as $city)
                            <option value="{{ $city->id }}">
                                {{ is_array($city->name) ? $city->name[$locale] ?? ($city->name['en'] ?? $city->id) : $city->name }}
                            </option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first('item.city_id') }}</x-error-span>
                </div>

                <div class="col-span-2">
                    <x-forms.input-label for="item_id" required>@lang('general.item')</x-forms.input-label>
                    <x-forms.select2-input id="item_id" wire:model="item.item_id">
                        <option value="">{{ __('general.select') }}</option>
                        @foreach ($checklistItems as $ci)
                            <option value="{{ $ci->id }}">{{ $ci->name }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first('item.item_id') }}</x-error-span>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
                <x-modal.cancel-btn />
            </div>
        </form>
    </x-modal.dialog>
</div>
