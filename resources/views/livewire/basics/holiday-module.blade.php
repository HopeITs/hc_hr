<x-slot:title>
    {{ __('holidays.title') }}
</x-slot:title>

<div>
    <div class="flex justify-between">
        <livewire:layout.breadcrumbs :title="__('holidays.title')" :crumbs="[
            ['label' => __('holidays.title'), 'route' => 'holidays.index'],
        ]" :showButton="true" />

        <div>
            <x-primary-button wire:click="create" :showSpinner="false">
                @lang('general.add-new')
            </x-primary-button>
        </div>
    </div>

    @unless ($isNationalHr)
        <p class="mb-3 text-sm text-neutral-600">@lang('holidays.manager-hint')</p>
    @endunless

    <livewire:basics.holiday-table />

    @include('livewire.basics.partials.holiday-dialog')
</div>
