@php
    $title = __('Course Categories');
@endphp
<x-slot:title>
    {{ $title }}
</x-slot:title>

<div>
    {{-- <div class="flex justify-between">
        <livewire:layout.breadcrumbs :title="$title" :crumbs="[
            ['label' => __('general.project-basics')],
            ['label' => $title, 'route' => 'course-categories.index'],
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

        <livewire:layout.breadcrumbs :title="$title" :crumbs="[
            ['label' => __('general.project-basics')],
            ['label' => $title, 'route' => 'course-categories.index'],
        ]" :showButton="true" />
        @activerole('HR', 'super-admin')
            <div class="flex items-center gap-2">

                <x-excel-export-button wire:click="$dispatchTo('basics.course-category-table', 'exportExcel')" />



                <x-primary-button wire:click="create" :showSpinner="false">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        @endactiverole
    </div>

    <livewire:basics.course-category-table />
    <x-modal.dialog id="showModal" :title="$modalTitle" wire:model.defer="showModal" close-action="requestClose" wire:ignore.self>
        <form wire:submit.prevent="save" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-forms.input-label for="name_en" required>{{ __('Name (EN)') }}</x-forms.input-label>
                    <x-forms.input type="text" wire:model="item.name_en" id="name_en" />
                    <x-error-span>{{ $errors->first('item.name_en') }}</x-error-span>
                </div>
                <div>
                    <x-forms.input-label for="name_ar" required>{{ __('Name (AR)') }}</x-forms.input-label>
                    <x-forms.input type="text" wire:model="item.name_ar" id="name_ar" />
                    <x-error-span>{{ $errors->first('item.name_ar') }}</x-error-span>
                </div>
            </div>

            <div class="flex justify-end space-x-2">
                <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
                <x-modal.cancel-btn />
            </div>
        </form>
    </x-modal.dialog>
</div>
