<x-slot:title>
    {{ __('general.termination_reasons') }}
</x-slot:title>

<div>
    <div class="flex justify-between">
        <livewire:layout.breadcrumbs :title="__('general.termination_reasons')" :crumbs="[
            ['label' => __('general.project-basics')],
            ['label' => __('general.termination_reasons'), 'route' => 'termination-reasons.index'],
        ]" :showButton="true" />

        @activerole('HR', 'super-admin')
            <div>
                <x-primary-button wire:click="create" :showSpinner="false">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        @endactiverole
    </div>

    <livewire:basics.termination-reason-table />

    <x-modal.dialog id="showModal" :title="$modalTitle" wire:model.defer="showModal" close-action="requestClose" wire:ignore.self>
        <form class="space-y-4" wire:submit.prevent="save">
            <div class="grid grid-cols-1 gap-4">
                <div>
                    <x-forms.input-label for="title_en" required>@lang('general.title')(EN)</x-forms.input-label>
                    <x-forms.input type="text" wire:model="item.title_en" id="title_en" />
                    <x-error-span>{{ $errors->first('item.title_en') }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="title_ar" required>@lang('general.title')(AR)</x-forms.input-label>
                    <x-forms.input type="text" wire:model="item.title_ar" id="title_ar" />
                    <x-error-span>{{ $errors->first('item.title_ar') }}</x-error-span>
                </div>
            </div>

            <div class="flex justify-end space-x-2">
                <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
                <x-modal.cancel-btn />
            </div>
        </form>
    </x-modal.dialog>
</div>
