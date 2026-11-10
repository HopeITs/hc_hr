<x-slot:title>
    {{ __('general.annual_leave_rules') }}
</x-slot:title>

<div>
    {{-- <div class="flex justify-between">
        <livewire:layout.breadcrumbs :title="__('general.annual_leave_rules')" :crumbs="[
            ['label' => __('general.project-basics')],
            ['label' => __('general.annual_leave_rules'), 'route' => 'annual-leave-rules.index'],
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

        <livewire:layout.breadcrumbs :title="__('general.annual_leave_rules')" :crumbs="[
            ['label' => __('general.project-basics')],
            ['label' => __('general.annual_leave_rules'), 'route' => 'annual-leave-rules.index'],
        ]" :showButton="true" />

        @activerole('HR', 'super-admin')
            <div class="flex items-center gap-2">
                <x-excel-export-button wire:click="$dispatchTo('basics.annual-leave-rule-table', 'exportExcel')" />

                <x-primary-button wire:click="create" :showSpinner="false">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        @endactiverole
    </div>

    <livewire:basics.annual-leave-rule-table />

    <x-modal.dialog id="showModal" :title="$modalTitle" wire:model.defer="showModal" close-action="requestClose" wire:ignore.self>
        <form class="space-y-4" wire:submit.prevent="save">
            <div class="grid grid-cols-1 gap-4">
                <div>
                    <x-forms.input-label for="days" required>@lang('general.days_per_year')</x-forms.input-label>
                    <x-forms.input type="number" wire:model="item.days" id="days" />
                    <x-error-span>{{ $errors->first('item.days') }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="day_per_month" required>@lang('general.day_per_month')</x-forms.input-label>
                    <x-forms.input type="number" step="0.1" wire:model="item.day_per_month" id="day_per_month" />
                    <x-error-span>{{ $errors->first('item.day_per_month') }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="years_of_volunteering" required>@lang('general.years_of_volunteering')</x-forms.input-label>
                    <x-forms.input type="number" wire:model="item.years_of_volunteering" id="years_of_volunteering" />
                    <x-error-span>{{ $errors->first('item.years_of_volunteering') }}</x-error-span>
                </div>
            </div>
            <div class="flex justify-end space-x-2">
                <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
                <x-modal.cancel-btn />
            </div>


        </form>
    </x-modal.dialog>
</div>
