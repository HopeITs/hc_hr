<x-slot:title>
    {{ __('general.positions') }}
</x-slot:title>

<div>
    @php
        use App\Models\Center;$center = null;
        if (request()->has('center')) {
            $center = Center::with('city')->find(request('center'));
        }
    @endphp

    {{-- Wraps on a phone: three actions beside the title pushed "Add New" off the screen. --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <livewire:layout.breadcrumbs
            :title="__('general.positions')"
            :crumbs="array_filter([
                ['label' => __('general.positions'), 'route' => route('positions.index')],
                $center ? ['label' => $center->name . ' (' . $center->city?->name . ')'] : null,
            ])"
            :showButton="true"
        />
           @activerole('HR' , 'super-admin' ,'general-manager')
        <div class="flex flex-wrap items-center gap-2">
            @if (auth()->user()?->hasActivePermission('positions.view-replacements'))
                <x-button variant="outline" :href="route('positions.critical')">@lang('critical-positions.title')</x-button>
            @endif
            <x-excel-export-button wire:click="$dispatchTo('position.position-table', 'exportExcel')" />

            <x-button :href="route('positions.create')">
                @lang('general.add-new')
            </x-button>
        </div>
        @endactiverole
    </div>

    <livewire:position.position-table
        :center-id="request()->get('center')"
        :city-id="request()->get('city')"
    />
</div>
