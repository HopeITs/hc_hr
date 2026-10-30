@php use App\Enums\SalaryPer; @endphp
<x-slot:title>
    {{ trans_choice('general.contracts', 2) }}
</x-slot:title>

<div>
    @php($locale = app()->getLocale())

    <div class="flex flex-wrap justify-between items-center gap-3 mb-6">
        <livewire:layout.breadcrumbs
            :title="trans_choice('general.contracts', 2)"
            :crumbs="[
                ['label' => trans_choice('general.contracts', 2)],
            ]"
            :showButton="true"
        />
        @activerole('HR', 'super-admin')
        <div class="flex items-center gap-2">
            {{-- The file follows the columns the table shows (B4D-Q6); the hint says so. --}}
            <span id="contracts-export-hint" class="hidden text-small text-ink-2 sm:inline">{{ __('contracts.export-columns-shown') }}</span>
            <x-excel-export-button data-tour="list.export" aria-describedby="contracts-export-hint" wire:click="$dispatchTo('contract.contracts-table', 'exportExcel')" />
            <x-primary-button data-tour="list.add" :href="route('contracts.create')">
                @lang('general.add-new')
            </x-primary-button>
        </div>
        @endactiverole
    </div>

    {{-- Keeps the employee and position columns pinned while the rest of this
         wide table scrolls sideways. Scoped here on purpose: every other table
         in the app shares the same vendor view and is left alone. --}}
    <div class="table-frozen-2"
        x-data="{ openerId: null, opener: null }"
        x-on:contract-drawer-opening.window="opener = document.activeElement; openerId = $event.detail.id"
        x-on:contract-drawer-closed.window="$nextTick(() => {
            const visible = (el) => el && document.body.contains(el) && el.offsetParent !== null;
            const target = visible(opener) ? opener : [...document.querySelectorAll('[data-contract-view=\'' + openerId + '\']')].find(visible);
            target?.focus();
        })">
        <livewire:contract.contracts-table />
    </div>

    {{-- Read-only details; separate from the table so opening it keeps search, filters, sort and page. --}}
    <livewire:contract.contract-drawer />

    {{-- One instance for the whole table; each row's Renew button addresses it
         by dispatching `open-renew-modal`. --}}
    <livewire:contract.contract-renew-modal />
</div>