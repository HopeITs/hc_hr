<div>
@unless ($inSettings)
<x-slot:title>
    {{ __('holidays.title') }}
</x-slot:title>
@endunless
    @unless ($inSettings)
    <div class="flex">
        <livewire:layout.breadcrumbs :title="__('holidays.title')" :crumbs="[
            ['label' => __('holidays.title'), 'route' => 'holidays.index'],
        ]" :showButton="true" />
    </div>
    @endunless

    @unless ($isNationalHr)
        <p class="mb-3 text-small text-ink-2">@lang('holidays.manager-hint')</p>
    @endunless

    <livewire:basics.holiday-table>
        <livewire:slot name="actions">
            <div class="flex flex-wrap items-center gap-2">
                {{-- The page's live card on the dashboard (Dashboard\AddToDashboard). --}}
                <livewire:dashboard.add-to-dashboard tile="next-holiday" />
                <x-primary-button wire:click="create" :showSpinner="false">
                    @lang('general.add-new')
                </x-primary-button>
            </div>
        </livewire:slot>
    </livewire:basics.holiday-table>

    @include('livewire.basics.partials.holiday-dialog')
</div>
