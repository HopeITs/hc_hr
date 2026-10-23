<x-slot:title>
    {{ trans_choice('general.job-level', 2) }}
</x-slot:title>

<div>
    {{-- <div class="flex justify-between">
        <livewire:layout.breadcrumbs
            :title="trans_choice('general.job-level', 2)"
            :crumbs="[
                 ['label' => __('general.project-basics')],
                ['label' => trans_choice('general.job-level', 2), 'route' => 'job-levels.index'],
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

        <livewire:layout.breadcrumbs
            :title="trans_choice('general.job-level', 2)"
            :crumbs="[
                 ['label' => __('general.project-basics')],
                ['label' => trans_choice('general.job-level', 2), 'route' => 'job-levels.index'],
            ]"
            :showButton="true"
        />

        @activerole('HR', 'super-admin')
            <div class="flex items-center gap-2">
                <x-excel-export-button wire:click="$dispatchTo('basics.job-level-table', 'exportExcel')" />

                <x-primary-button wire:click="create" :showSpinner="false">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        @endactiverole
    </div>

    <livewire:basics.job-level-table />


    <x-modal.dialog id="showModal" :title="$modalTitle" wire:model.defer="showModal" close-action="requestClose" wire:ignore.self>
        <form class="space-y-4" wire:submit.prevent="save">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-1">
                    <x-forms.input-label for="name_ar" required>@lang('general.name') (AR)</x-forms.input-label>
                    <x-forms.input type="text" id="name_ar" wire:model.defer="item.name_ar"/>
                    <x-error-span>{{ $errors->first('item.name_ar') }}</x-error-span>
                </div>
                <div class="col-span-1">
                    <x-forms.input-label for="name_en" required>@lang('general.name') (EN)</x-forms.input-label>
                    <x-forms.input type="text" id="name_en" wire:model.defer="item.name_en"/>
                    <x-error-span>{{ $errors->first('item.name_en') }}</x-error-span>
                </div>

                <div class="col-span-2">
                    <x-forms.input-label for="description">@lang('general.description')</x-forms.input-label>
                    <x-forms.input type="text" id="description" wire:model.defer="item.description"/>
                    <x-error-span>{{ $errors->first('item.description') }}</x-error-span>
                </div>

                <div class="col-span-1">
                    <x-forms.input-label for="grade">@lang('general.grade')</x-forms.input-label>
                    <x-forms.input type="number" id="grade" wire:model.defer="item.grade"/>
                    <x-error-span>{{ $errors->first('item.grade') }}</x-error-span>
                </div>

                <div class="col-span-1">
                    <x-forms.input-label for="email">@lang('general.email')</x-forms.input-label>
                    <x-forms.input type="email" id="email" wire:model.defer="item.email"/>
                    <x-error-span>{{ $errors->first('item.email') }}</x-error-span>
                </div>
            </div>

            <div class="flex justify-end pt-4 space-x-2">
                <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
                <x-modal.cancel-btn/>
            </div>
        </form>
    </x-modal.dialog>
</div>
