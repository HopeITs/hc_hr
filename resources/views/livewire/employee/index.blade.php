<x-slot:title>
    {{ trans_choice('general.employee', 2) }}
</x-slot:title>

@php
    $canSeeInactive = auth()->user()?->activeRoleIs('HR', 'general-manager', 'super-admin') ?? false;
    // Drafts are HR's work in progress (B5-D §5.4).
    $canSeeIncomplete = auth()->user()?->activeRoleIs('HR', 'super-admin') ?? false;
    $views = ['active' => __('general.active')];
    if ($canSeeInactive) {
        $views += ['inactive' => __('general.inactive'), 'awaiting' => __('employees.onboarding.view-awaiting')];
    }
    if ($canSeeIncomplete) {
        $views['incomplete'] = __('employees.onboarding.view-incomplete');
    }
    $initialView = array_key_exists((string) request()->query('view'), $views) ? request()->query('view') : 'active';
@endphp

<div>
    <div class="flex flex-wrap justify-between items-center gap-3 mb-6">
        <livewire:layout.breadcrumbs :title="trans_choice('general.employee', 2)" :crumbs="[
            ['label' => trans_choice('general.employee', 2)],
        ]" :showButton="true" />
        @activerole('HR', 'super-admin')
        <div class="flex items-center gap-2">
            {{-- B4D-Q6: the export follows the columns shown, and says so. --}}
            <span id="employee-export-note" class="sr-only sm:not-sr-only text-small text-secondary-medium">{{ __('general.emp-export-note') }}</span>
            <x-excel-export-button data-tour="list.export" aria-describedby="employee-export-note" :title="__('general.export-excel').'. '.__('general.emp-export-note')" aria-label="{{ __('general.export-excel') }}" wire:click="$dispatchTo('employee.employee-table', 'exportExcel')" />
            <x-primary-button data-tour="list.add" :href="route('employees.create')">
                @lang('general.add-new')
            </x-primary-button>

        </div>
        @endactiverole
    </div>

    @if($canSeeInactive)
        {{-- Named views (?view=): Active is the default; Inactive is HR / general manager only. --}}
        <div class="mb-3" x-data="{ view: @js($initialView) }" x-on:employee-view-changed.window="view = $event.detail.view">
            <div role="group" aria-label="{{ __('general.emp-views') }}" class="inline-flex rounded-lg border border-[var(--line-hairline)] bg-white p-0.5">
                @foreach ($views as $key => $label)
                    <button type="button" data-employee-view="{{ $key }}"
                        x-on:click="view = '{{ $key }}'; Livewire.dispatch('employee-set-view', { view: '{{ $key }}' })"
                        x-bind:aria-pressed="view === '{{ $key }}'"
                        x-bind:class="view === '{{ $key }}' ? 'bg-primary text-white' : 'text-black hover:bg-neutral-50'"
                        class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md px-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ $label }}</button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Keeps the name column pinned while the rest of this wide table
         scrolls sideways — see .table-frozen-employee in app.css. --}}
    <div class="table-frozen-employee">
        <livewire:employee.employee-table />
    </div>

    <livewire:employee.employee-drawer />
</div>
