<x-slot:title>
    {{ __('general.general-statistics') }}
</x-slot:title>

@php
    // Each card here is also a dashboard tile: Add to dashboard sits in its top inline-end
    // corner (Dashboard\AddToDashboard; it draws nothing for a tile the person may not have).
    $cards = [
        ['stats.departments', 'h-68 md:col-span-2', 'dashboard.department-employee-statistics', 'stats-department'],
        ['stats.payment-type', 'h-68 md:col-start-3', 'dashboard.salary-per-employee-statistics', 'stats-salary'],
        ['stats.personnel', 'h-68 md:col-start-1 md:row-start-2', 'dashboard.employee-chart-statistics', 'stats-personnel'],
        ['stats.job-levels', 'h-68 md:col-span-2 md:col-start-2 md:row-start-2', 'dashboard.job-level-employee-statistics', 'stats-job-level'],
        ['stats.expiring', 'h-68 md:col-start-1 md:row-start-3', 'dashboard.soon-ending-contracts-card', 'soon-ending-contracts'],
        ['stats.no-leaves', 'max-h-68 md:col-start-2 md:row-start-3', 'dashboard.no-leaves-employees-statistics', 'stats-no-leaves'],
        ['stats.contract-types', 'h-68 md:col-start-3 md:row-start-3', 'dashboard.contract-type-employees-statistics', 'stats-contract-type'],
    ];
@endphp

<div class="space-y-12">

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        @foreach ($cards as [$tour, $class, $component, $tile])
            <div data-tour="{{ $tour }}" class="relative {{ $class }}">
                @livewire($component, [], key('stat-'.$tile))
                <div class="absolute top-3 end-3 z-10">
                    <livewire:dashboard.add-to-dashboard :tile="$tile" :compact="true" :key="'atd-'.$tile" />
                </div>
            </div>
        @endforeach
    </div>

</div>
