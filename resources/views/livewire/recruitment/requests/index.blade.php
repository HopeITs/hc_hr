<x-slot:title>{{ __('recruitment.requests') }}</x-slot:title>

@php
    use App\Services\RecruitmentRequestListQuery;

    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
@endphp

<div class="space-y-6">
    <x-recruitment.requests-header
        current="requests"
        :title="__('recruitment.requests')" />

    @if (count($views) > 1)
        {{-- Who the request belongs to, as count tiles. "Needs my review" is the only one that asks something of the viewer, so it alone gets a red dot when it is not empty. --}}
        <nav aria-label="{{ __('recruitment.request_view') }}">
            <ul class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                @foreach ($views as $view)
                    @php
                        $isActive = $viewFilter === $view;
                        $count = (int) ($viewCounts[$view] ?? 0);
                        $waiting = $view === RecruitmentRequestListQuery::NEEDS_REVIEW && $count > 0;
                    @endphp
                    <li wire:key="request-view-{{ $view }}">
                        <button type="button" wire:click="setRequestView(@js($view))"
                                aria-current="{{ $isActive ? 'true' : 'false' }}"
                                @class([
                                    'flex min-h-16 w-full cursor-pointer flex-col items-start justify-between gap-1 rounded-control border px-4 py-2.5 text-start shadow-e1 transition duration-150 motion-reduce:transition-none sm:min-w-40',
                                    $focus,
                                    'border-neutral-300 bg-selection' => $isActive,
                                    'border-neutral-200 bg-white hover:border-neutral-300' => ! $isActive,
                                ])>
                            <span class="flex w-full items-center justify-between gap-2">
                                <span @class(['text-h3 font-semibold tabular-nums', 'text-neutral-900' => $count > 0, 'text-secondary-light' => $count === 0])>{{ $count }}</span>
                                @if ($waiting)
                                    <span class="size-2 rounded-full bg-primary" aria-hidden="true"></span>
                                @endif
                            </span>
                            <span class="text-small {{ $isActive ? 'font-semibold text-secondary-dark' : 'text-secondary-medium' }}">
                                {{ __('recruitment.request_views.'.$view) }}
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    <livewire:recruitment.requests.request-table
        :viewFilter="$viewFilter"
        :key="'requests-'.$viewFilter" />
</div>
