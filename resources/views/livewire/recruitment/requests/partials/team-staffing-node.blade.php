@php
    $children = collect($node['children']);
    $positionId = $node['id'];
    $hasChildren = $children->isNotEmpty();
    $startsOpen = ! ($this->collapsed[$positionId] ?? false);
    // A subtree can be worth opening even when its own card is quiet, so the
    // chip carries the open seats waiting below it rather than just a count.
    $waiting = $node['subtreeOpenSeats'] - $node['openSeats'];

    // Posts flow, they are not laid on a rail.
    //
    // A level of sibling posts is one wrapping row: as many across as the canvas
    // is wide, the rest folding onto the next line by themselves. Nothing here
    // counts columns or fixes a width, so the same markup is one row for four
    // posts on a wide screen and two rows on a narrow one. The connectors are
    // gone for the same reason — a rung and its stems only hold while a level is
    // a single line. Containment carries the hierarchy instead: a manager's
    // reports sit inside that manager's box, on a quiet tint of their own.
    $gap = $compact ? 'gap-2' : 'gap-3';

    // Managers before individual contributors.
    $ordered = $children
        ->filter(fn (array $child): bool => count($child['children']) > 0)
        ->concat($children->reject(fn (array $child): bool => count($child['children']) > 0))
        ->values();
@endphp

<section wire:key="staffing-node-{{ $positionId }}"
         x-data="{ open: @js($startsOpen) }"
         x-on:staffing-expand-all.window="open = true"
         x-on:staffing-collapse-all.window="open = false"
         class="flex min-w-0 max-w-full flex-col items-stretch">

    <x-staffing-card :node="$node" :compact="$compact" :view="$view">
        @if ($hasChildren)
            <button type="button" wire:click="toggle({{ $positionId }})" x-on:click="open = ! open"
                    :aria-expanded="open" data-no-pan
                    :title="open ? @js(__('general.collapse')) : @js(__('general.expand'))"
                    class="mt-2 flex w-full items-center justify-between gap-2 rounded-control bg-neutral-100 px-2 py-1.5 text-caption font-bold text-secondary-medium transition hover:bg-secondary-soft hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1">
                <span class="flex items-center gap-1.5">
                    {{ trans_choice('recruitment.team_staffing.reports_count', $children->count(), ['count' => $children->count()]) }}
                    @if ($waiting > 0)
                        <span class="flex min-w-4 items-center justify-center rounded-full bg-primary px-1 text-caption font-bold text-white"
                              title="{{ __('recruitment.team_staffing.open_seats_below', ['count' => $waiting]) }}">
                            {{ $waiting }}
                        </span>
                    @endif
                </span>
                <svg class="size-4 shrink-0 transition-transform duration-200" :class="open ? 'rotate-180' : ''"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                </svg>
            </button>

            {{-- Children live inside their manager's box, which is what makes the
                 containment of a subtree visible without a single extra line. --}}
            <div x-show="open" x-cloak x-collapse>
                <div class="mt-3 flex flex-wrap items-start justify-center rounded-inset bg-neutral-50 {{ $gap }} {{ $compact ? 'p-2' : 'p-3' }}">
                    @foreach ($ordered as $child)
                        @include('livewire.recruitment.requests.partials.team-staffing-node', [
                            'node' => $child,
                            'compact' => $compact,
                            'view' => $view,
                        ])
                    @endforeach
                </div>
            </div>
        @endif
    </x-staffing-card>
</section>
