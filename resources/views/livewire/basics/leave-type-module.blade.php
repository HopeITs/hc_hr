@php
    use App\Enums\LeaveType;
@endphp
<x-slot:title>
    {{ trans_choice('general.leave-type', 2) }}
</x-slot:title>

<div>
    {{-- <div class="flex justify-between">
        <livewire:layout.breadcrumbs :title="trans_choice('general.leave-type', 2)" :crumbs="[
            ['label' => __('general.project-basics')],
            ['label' => trans_choice('general.leave-type', 2), 'route' => 'leave-types.index'],
        ]" :showButton="true" />
        @activerole('HR', 'super-admin')
            <div>
                <x-primary-button wire:click="create" :showSpinner="false">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        @endactiverole
    </div> --}}
    <div class="flex justify-between items-center mb-6">

        <livewire:layout.breadcrumbs :title="trans_choice('general.leave-type', 2)" :crumbs="[
            ['label' => __('general.project-basics')],
            ['label' => trans_choice('general.leave-type', 2), 'route' => 'leave-types.index'],
        ]" :showButton="true" />

        @activerole('HR', 'super-admin')
            <div class="flex items-center gap-2">
                <x-excel-export-button wire:click="$dispatchTo('basics.leave-type-table', 'exportExcel')" />
                <x-primary-button wire:click="create" :showSpinner="false">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        @endactiverole
    </div>

    <livewire:basics.leave-type-table />

    <x-modal.dialog id="showModal" :title="$modalTitle" wire:model.defer="showModal" close-action="requestClose" wire:ignore.self>
        <form wire:submit.prevent="save" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                @foreach (config('app.supported_locales') as $locale)
                    <div>
                        <x-forms.input-label for="name_{{ $locale }}" required>
                            @lang('general.name') ({{ strtoupper($locale) }})
                        </x-forms.input-label>
                        <x-forms.input type="text" wire:model="item.name_{{ $locale }}"
                            id="name_{{ $locale }}" />
                        <x-error-span>{{ $errors->first('item.name_' . $locale) }}</x-error-span>
                    </div>
                @endforeach

                <div>
                    <x-forms.input-label for="base_days" required>@lang('general.base-days')</x-forms.input-label>
                    <x-forms.input type="number" wire:model="item.base_days" id="base_days" />
                    <x-error-span>{{ $errors->first('item.base_days') }}</x-error-span>
                </div>

                <div>
                    <x-forms.radio-group :legend="__('general.allowed-gender')" error="item.allowed_gender" required>
                        <x-forms.radio-input value="{{ LeaveType::GENDER_ANY }}"
                            wire:model="item.allowed_gender">{{ LeaveType::getGender(LeaveType::GENDER_ANY)['value'] }}</x-forms.radio-input>
                        <x-forms.radio-input value="{{ LeaveType::GENDER_MALE }}"
                            wire:model="item.allowed_gender">{{ LeaveType::getGender(LeaveType::GENDER_MALE)['value'] }}</x-forms.radio-input>
                        <x-forms.radio-input value="{{ LeaveType::GENDER_FEMALE }}"
                            wire:model="item.allowed_gender">{{ LeaveType::getGender(LeaveType::GENDER_FEMALE)['value'] }}</x-forms.radio-input>
                    </x-forms.radio-group>
                </div>

                <div>
                    <x-forms.radio-group :legend="__('general.marital_status')" error="item.marital_status" required>
                        <x-forms.radio-input value="{{ LeaveType::MARITAL_STATUS_ANY }}"
                            wire:model="item.marital_status">{{ LeaveType::getMaritalStatus(LeaveType::MARITAL_STATUS_ANY)['value'] }}</x-forms.radio-input>
                        <x-forms.radio-input value="{{ LeaveType::MARITAL_STATUS_SINGLE }}"
                            wire:model="item.marital_status">{{ LeaveType::getMaritalStatus(LeaveType::MARITAL_STATUS_SINGLE)['value'] }}</x-forms.radio-input>
                        <x-forms.radio-input value="{{ LeaveType::MARITAL_STATUS_MARRIED }}"
                            wire:model="item.marital_status">{{ LeaveType::getMaritalStatus(LeaveType::MARITAL_STATUS_MARRIED)['value'] }}</x-forms.radio-input>
                    </x-forms.radio-group>
                </div>
            </div>

            <div class="flex justify-end space-x-2">
                <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
                <x-modal.cancel-btn />
            </div>
        </form>
    </x-modal.dialog>
</div>
