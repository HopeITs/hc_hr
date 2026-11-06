@php
    $children = collect($node['children']);
    $positionId = $node['id'];
    $hasChildren = $children->isNotEmpty();
    $waiting = $node['subtreeOpenSeats'] - $node['openSeats'];
    $gap = $compact ? 'gap-3' : 'gap-4';

    // Managers before individual contributors.
    $ordered = $children
        ->filter(fn (array $child): bool => count($child['children']) > 0)
        ->concat($children->reject(fn (array $child): bool => count($child['children']) > 0))
        ->values();
@endphp

<section wire:key="staffing-node-{{ $positionId }}"
         x-data="{}"
         class="flex min-w-0 max-w-full flex-col items-center">
    <x-staffing-card :node="$node" :compact="$compact" :view="$view">
        @if ($hasChildren)
            {{-- A quiet text toggle. Open seats further down show as a small count with a red dot, not a red badge. --}}
            <button type="button" wire:click="toggle({{ $positionId }})"
                    :aria-expanded="! $wire.collapsed[{{ $positionId }}]" data-no-pan
                    class="mt-3 flex min-h-11 w-full cursor-pointer items-center justify-between gap-2 rounded-control border-t border-neutral-100 px-1 pt-2 text-caption font-semibold text-secondary-dark transition hover:text-secondary-dark sm:min-h-9 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <span class="flex items-center gap-2">
                    <flux:icon.user-group variant="micro" class="size-4 text-secondary-medium" aria-hidden="true" />
                    {{ trans_choice('recruitment.team_staffing.reports_count', $children->count(), ['count' => $children->count()]) }}
                    @if ($waiting > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-neutral-100 px-1.5 py-px tabular-nums text-secondary-dark"
                              title="{{ __('recruitment.team_staffing.open_seats_below', ['count' => $waiting]) }}">
                            <span class="size-1.5 rounded-full bg-primary" aria-hidden="true"></span>{{ $waiting }}
                            <span class="sr-only">{{ __('recruitment.team_staffing.open_seats_below', ['count' => $waiting]) }}</span>
                        </span>
                    @endif
                </span>
                <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 text-secondary-medium transition-transform duration-200 motion-reduce:transition-none" ::class="! $wire.collapsed[{{ $positionId }}] ? 'rotate-180' : ''" aria-hidden="true" />
            </button>
        @endif
    </x-staffing-card>

    {{-- A reporting level stays on one rail so connector lines never imply a false second level. --}}
    @if ($hasChildren)
        {{-- Nested height animations measure partially hidden descendants during Expand all. Let the tree size itself naturally. --}}
        <div x-show="! $wire.collapsed[{{ $positionId }}]" x-cloak class="flex w-full flex-col items-center">
            <span aria-hidden="true" class="h-5 w-px bg-neutral-300"></span>
            <div class="relative flex w-full min-w-max flex-nowrap items-start justify-center overflow-visible border-t border-neutral-300 pt-5 {{ $gap }}">
                @foreach ($ordered as $child)
                    <div class="relative flex min-w-0 flex-col items-center">
                        <span aria-hidden="true" class="absolute -top-5 start-1/2 h-5 w-px bg-neutral-300"></span>
                        @include('livewire.recruitment.requests.partials.team-staffing-node', [
                            'node' => $child,
                            'compact' => $compact,
                            'view' => $view,
                        ])
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</section>
