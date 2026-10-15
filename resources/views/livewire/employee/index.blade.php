<x-slot:title>
    {{ trans_choice('general.employee', 2) }}
</x-slot:title>

<div>
    <div class="flex justify-between items-center mb-6">
        <livewire:layout.breadcrumbs :title="trans_choice('general.employee', 2)" :crumbs="[
            ['label' => trans_choice('general.employee-management', 2)],
            ['label' => trans_choice('general.employee', 2)],
        ]" :showButton="true" />
        @activerole('HR', 'super-admin')
        <div class="flex items-center gap-2">
            <x-excel-export-button data-tour="list.export" wire:click="$dispatchTo('employee.employee-table', 'exportExcel')" />
            <x-primary-button data-tour="list.add" :href="route('employees.create')">
                @lang('general.add-new')
            </x-primary-button>

        </div>
        @endactiverole
    </div>

    {{-- Keeps the name column pinned while the rest of this wide table
         scrolls sideways — see .table-frozen-employee in app.css. --}}
    <div class="table-frozen-employee">
        <livewire:employee.employee-table />
    </div>
</div>
