<x-slot:title>
    {{ trans_choice('general.contract-type', 2) }}
</x-slot:title>

<div>
    {{-- <div class="flex justify-between">
        <livewire:layout.breadcrumbs :title="trans_choice('general.contract-type', 2)" :crumbs="[
            ['label' => __('general.project-basics')],
            ['label' => trans_choice('general.contract-type', 2), 'route' => 'contract-types.index'],
        ]" :showButton="true" />

        @activerole('HR', 'super-admin')
            <div>
                <x-primary-button wire:click="create" :showSpinner="false">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        @endactiverole
    </div> --}}
    {{-- @activerole('HR', 'super-admin')
            <div class="flex items-center space-x-2 gap-2"> <x-primary-button 
                    wire:click="$dispatchTo('basics.contract-type-table', 'exportExcel')" 
                    
                    :showSpinner="false"
                >
                    @lang('general.export-excel')
                </x-primary-button>

                <x-primary-button wire:click="create" :showSpinner="false">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        @endactiverole --}}
    <div class="flex justify-between items-center mb-6">

        <livewire:layout.breadcrumbs :title="trans_choice('general.contract-type', 2)" :crumbs="[
            ['label' => __('general.project-basics')],
            ['label' => trans_choice('general.contract-type', 2), 'route' => 'contract-types.index'],
        ]" :showButton="true" />

        @activerole('HR', 'super-admin')
            <div class="flex items-center gap-2">
                <x-excel-export-button wire:click="$dispatchTo('basics.contract-type-table', 'exportExcel')" />

                <x-primary-button wire:click="create" :showSpinner="false">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        @endactiverole
    </div>

    <livewire:basics.contract-type-table />


    <x-modal.dialog id="showModal" :title="$modalTitle" wire:model.defer="showModal" close-action="requestClose" wire:ignore.self>
        <form class="space-y-4" wire:submit.prevent="save">
            <div class="grid grid-cols-2 space-x-2 space-y-4">
                @foreach (config('app.supported_locales') as $locale)
                    <div class="col-span-1">
                        <x-forms.input-label for="name_{{ $locale }}" required>
                            @lang('general.name')({{ strtoupper($locale) }})
                        </x-forms.input-label>
                        <x-forms.input type="text" id="name_{{ $locale }}"
                            wire:model="item.name_{{ $locale }}" />
                        <x-error-span>{{ $errors->first('item.name_' . $locale) }}</x-error-span>
                    </div>
                @endforeach
            </div>

            <div class="flex justify-end space-x-2">
                <x-primary-button type="submit" class="!w-auto">
                    @lang('general.save')
                </x-primary-button>
                <x-modal.cancel-btn />
            </div>
        </form>
    </x-modal.dialog>
</div>
