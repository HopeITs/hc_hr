{{--
    One position on the staffing canvas: a flat, absolutely positioned wrapper.

    The tree is not nested in the markup any more. Each visible position is a
    sibling of the others, says who its parent is, and resources/js/staffing-tree.js
    measures the card and places it (tidy-tree layout). A collapsed branch is not
    rendered at all, so it takes no room.

    wire:ignore.self keeps the transform the layout writes on this wrapper across
    Livewire morphs; its own attributes never change for a given position (the
    parent and depth of a position are fixed), while everything inside it is
    morphed as usual. `invisible` is removed by the layout once the card is placed.

    Expects: $entry (from the flat list built in team-staffing.blade.php), $compact, $view.
--}}
@php
    $node = $entry['node'];
    $positionId = $node['id'];
    $reports = count($node['children']);
    $waiting = $node['subtreeOpenSeats'] - $node['openSeats'];
    $expanded = $entry['expanded'];
@endphp

<div wire:key="staffing-node-{{ $positionId }}" wire:ignore.self
     data-tree-node data-node-id="{{ $positionId }}" data-parent-id="{{ $entry['parent'] }}" data-depth="{{ $entry['depth'] }}"
     class="staffing-tree-node invisible absolute left-0 top-0 w-max rounded-surface data-match:ring-2 data-match:ring-ink data-match:ring-offset-4 data-match:ring-offset-soft">
    <x-staffing-card :node="$node" :compact="$compact" :view="$view">
        @if ($reports > 0)
            {{-- A quiet text toggle. Open seats further down show as a small count with a red dot, not a red badge. --}}
            <button type="button" wire:click="toggle({{ $positionId }})" data-tree-toggle data-no-pan
                    aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                    class="mt-3 flex min-h-11 w-full cursor-pointer items-center justify-between gap-2 rounded-control border-t border-line-soft px-1 pt-2 text-caption font-semibold text-ink transition hover:text-ink sm:min-h-9 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink">
                <span class="flex items-center gap-2">
                    <flux:icon.user-group variant="micro" class="size-4 text-ink-2" aria-hidden="true" />
                    {{ trans_choice('recruitment.team_staffing.reports_count', $reports, ['count' => $reports]) }}
                    @if ($waiting > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-soft px-1.5 py-px tabular-nums text-ink"
                              title="{{ __('recruitment.team_staffing.open_seats_below', ['count' => $waiting]) }}">
                            <span class="size-1.5 rounded-full bg-primary" aria-hidden="true"></span>{{ $waiting }}
                            <span class="sr-only">{{ __('recruitment.team_staffing.open_seats_below', ['count' => $waiting]) }}</span>
                        </span>
                    @endif
                </span>
                <flux:icon.chevron-down variant="micro" @class(['size-4 shrink-0 text-ink-2 transition-transform duration-200 motion-reduce:transition-none', 'rotate-180' => $expanded]) aria-hidden="true" />
            </button>
        @endif
    </x-staffing-card>
</div>
