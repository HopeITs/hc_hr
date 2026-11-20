<x-slot:title>
    {{ __('general.activity-log') }}
</x-slot:title>

<div>
    <livewire:layout.breadcrumbs
        :title="__('general.activity-log')"
        :crumbs="[
            ['label' => __('general.activity-log')],
        ]"
    />

    <livewire:activity-log.activity-log-report />

    <livewire:activity-log.activity-log-table />
</div>
