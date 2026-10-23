@php
    use App\Enums\ChecklistItemOrder;
@endphp

<x-slot:title>
    {{ trans_choice('general.checklist-items', 2) }}
</x-slot:title>

<div>
    {{-- <div class="flex justify-between">
        <livewire:layout.breadcrumbs
            :title="trans_choice('general.checklist-items',2)"
            :crumbs="[
                ['label' => __('general.project-basics')],
                ['label' => trans_choice('general.checklist-items',2), 'route' => 'basic-checklist-items.index'],
            ]"
            :showButton="true"
        />

        @activerole('HR','super-admin')
            <div>
                <x-primary-button wire:click="create" :showSpinner="false">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        @endactiverole
    </div> --}}
    <div class="flex justify-between items-center mb-6">

        <livewire:layout.breadcrumbs :title="trans_choice('general.contract-type', 2)" :crumbs="[
            ['label' => __('general.project-basics')],
            ['label' => trans_choice('general.contract-type', 2), 'route' => 'contract-types.index'],
        ]" :showButton="true" />

        @activerole('HR', 'super-admin')
            <div class="flex items-center gap-2">
                <x-excel-export-button wire:click="$dispatchTo('basics.checklist-item-table', 'exportExcel')" />
                <x-primary-button wire:click="create" :showSpinner="false">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        @endactiverole
    </div>


    <livewire:basics.checklist-item-table />


    <x-modal.dialog id="showModal" :title="$modalTitle" wire:model.defer="showModal" close-action="requestClose" wire:ignore.self>
        <form class="space-y-4" wire:submit.prevent="save">
            <div class="grid grid-cols-1 space-x-2 space-y-4">
                <div class="col-span-1">
                    <x-forms.input-label for="name" required>@lang('general.name')</x-forms.input-label>
                    <x-forms.input type="text" id="name" wire:model="item.name" />
                    <x-error-span>{{ $errors->first('item.name') }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="order" required>@lang('general.order')</x-forms.input-label>
                    <x-forms.select2-input id="order" wire:model="item.order">
                        <option value="">{{ __('general.select') }}</option>
                        <option value="{{ ChecklistItemOrder::ProbationContract }}">
                            {{ ChecklistItemOrder::getLocalizedName(ChecklistItemOrder::ProbationContract)['value'] }}
                        </option>
                        <option value="{{ ChecklistItemOrder::AnnualContract }}">
                            {{ ChecklistItemOrder::getLocalizedName(ChecklistItemOrder::AnnualContract)['value'] }}
                        </option>
                        <option value="{{ ChecklistItemOrder::Resignation }}">
                            {{ ChecklistItemOrder::getLocalizedName(ChecklistItemOrder::Resignation)['value'] }}
                        </option>
                    </x-forms.select2-input>
                </div>
            </div>

            <div class="flex justify-end space-x-2">
                <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
                <x-modal.cancel-btn />
            </div>
        </form>
    </x-modal.dialog>
</div>
