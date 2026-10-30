<x-slot:title>
    {{ __('general.general-statistics') }}
</x-slot:title>

<div class="space-y-12">

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <div data-tour="stats.departments" class="h-68 md:col-span-2">
            <livewire:dashboard.department-employee-statistics />
        </div>

        <div data-tour="stats.payment-type" class="h-68 md:col-start-3">
            <livewire:dashboard.salary-per-employee-statistics />
        </div>

        <div data-tour="stats.personnel" class="h-68 md:col-start-1 md:row-start-2">
            <x-dashboard-cards.employee-chart-statistics/>
        </div>

        <div data-tour="stats.job-levels" class="h-68 md:col-span-2 md:col-start-2 md:row-start-2">
            <livewire:dashboard.job-level-employee-statistics/>
        </div>

        <div data-tour="stats.expiring" class="h-68 md:col-start-1 md:row-start-3">
            <livewire:dashboard.soon-ending-contracts-card />
        </div>

        <div data-tour="stats.no-leaves" class="max-h-68 md:col-start-2 md:row-start-3">
            <livewire:dashboard.no-leaves-employees-statistics/>
        </div>

        <div data-tour="stats.contract-types" class="h-68 md:col-start-3 md:row-start-3">
            <livewire:dashboard.contract-type-employees-statistics/>
        </div>

    </div>

</div>
