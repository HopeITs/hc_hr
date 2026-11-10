<x-slot:title>{{ __('recruitment.requests') }}</x-slot:title>

<div class="space-y-6">
    <x-recruitment.requests-header
        current="requests"
        :title="__('recruitment.requests')"
        :description="__('recruitment.list_description')" />

    @if (count($views) > 1)
        <nav aria-label="{{ __('recruitment.request_view') }}">
            <x-tabs-list>
                @foreach ($views as $view)
                    <x-tabs-list-item
                        wire:key="request-view-{{ $view }}"
                        action="setRequestView"
                        :active="$viewFilter === $view"
                        :active-tab="$view"
                        compact>
                        {{ __('recruitment.request_views.'.$view) }}
                        ({{ $viewCounts[$view] ?? 0 }})
                    </x-tabs-list-item>
                @endforeach
            </x-tabs-list>
        </nav>
    @endif

    <livewire:recruitment.requests.request-table
        :viewFilter="$viewFilter"
        :key="'requests-'.$viewFilter" />
</div>
