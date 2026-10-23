<x-slot:title>
    {{ trans_choice('general.department', 2) }}
</x-slot:title>

<div>
    {{-- Header --}}
    {{-- <div class="flex justify-between">
        <livewire:layout.breadcrumbs
            :title="trans_choice('general.department', 2)"
            :crumbs="[
                ['label' => __('general.project-basics')],
                ['label' => trans_choice('general.department', 2), 'route' => 'departments.index'],
            ]"
            :showButton="true"
        />

        @activerole('HR', 'super-admin')
        <div>
            <x-primary-button wire:click="create" :showSpinner="false">
                @lang('general.add-new')
            </x-primary-button>
        </div>
    @endactiverole
    </div> --}}

    <div class="flex justify-between items-center mb-6">

        <livewire:layout.breadcrumbs :title="trans_choice('general.department', 2)" :crumbs="[
            ['label' => __('general.project-basics')],
            ['label' => trans_choice('general.department', 2), 'route' => 'departments.index'],
        ]" :showButton="true" />

        @activerole('HR', 'super-admin')
            <div class="flex items-center gap-2">
                <x-excel-export-button wire:click="$dispatchTo('basics.department-table', 'exportExcel')" />
                <x-primary-button wire:click="create" :showSpinner="false">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        @endactiverole
    </div>


    <livewire:basics.department-table />

    {{-- Modal --}}
    <x-modal.dialog id="showModal" :title="$modalTitle" wire:model.defer="showModal" close-action="requestClose" wire:ignore.self>
        <form wire:submit.prevent="save" class="space-y-4">
            <div class="grid grid-cols-2 space-x-2 space-y-4">
                {{-- Name Input  --}}
                <div class="col-span-1">
                    <x-forms.input-label for="name" required>
                        @lang('general.department-name')
                    </x-forms.input-label>
                    <x-forms.input type="text" id="name" wire:model="item.name" />
                    <x-error-span>{{ $errors->first('item.name') }}</x-error-span>
                </div>
                <div class="col-span-1">
                    <x-forms.input-label for="code" required>
                        @lang('general.code')
                    </x-forms.input-label>
                    <x-forms.input type="text" id="code" wire:model="item.code" />
                    <x-error-span>{{ $errors->first('item.code') }}</x-error-span>
                </div>

                {{-- Email --}}
                <div class="col-span-2">
                    <x-forms.input-label for="email">@lang('general.department-email')</x-forms.input-label>
                    <x-forms.input type="email" id="email" wire:model="item.email" />
                    <x-error-span>{{ $errors->first('item.email') }}</x-error-span>
                </div>

                {{-- Description Inputs --}}
                <div class="col-span-2">
                    <x-forms.input-label for="description_en">
                        @lang('general.department-description') (EN)
                    </x-forms.input-label>
                    <x-forms.textarea type="text" id="description_en" wire:model="item.description_en" />
                    <x-error-span>{{ $errors->first('item.description_en') }}</x-error-span>
                </div>
                <div class="col-span-2">
                    <x-forms.input-label for="description_ar">
                        @lang('general.department-description') (AR)
                    </x-forms.input-label>
                    <x-forms.textarea type="text" id="description_ar" wire:model="item.description_ar" />
                    <x-error-span>{{ $errors->first('item.description_ar') }}</x-error-span>
                </div>
            </div>

            <div class="flex justify-end space-x-2">
                <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
                <x-modal.cancel-btn />
            </div>
        </form>
    </x-modal.dialog>
</div>
