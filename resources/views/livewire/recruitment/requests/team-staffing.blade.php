@php
    $compact = $density === 'compact';
    $card = 'rounded-surface border border-line-soft bg-card shadow-e1';

    // The visible tree as a flat, depth-first list: managers before individual
    // contributors, and nothing below a collapsed position (collapsed state is
    // the server's, in $collapsed). Both the canvas and the phone outline read
    // this one list; the canvas script lays it out as a tree.
    $flat = [];
    $walk = function (array $node, ?int $parent, int $depth) use (&$walk, &$flat, $collapsed): void {
        $children = collect($node['children']);
        $ordered = $children
            ->filter(fn (array $child): bool => count($child['children']) > 0)
            ->concat($children->reject(fn (array $child): bool => count($child['children']) > 0))
            ->values();
        $expanded = $children->isNotEmpty() && ! ($collapsed[$node['id']] ?? false);

        $flat[] = ['node' => $node, 'parent' => $parent, 'depth' => $depth, 'expanded' => $expanded];

        if ($expanded) {
            foreach ($ordered as $child) {
                $walk($child, $node['id'], $depth + 1);
            }
        }
    };

    if ($root) {
        $walk($root, null, 0);
    }
@endphp

<x-slot:title>{{ __('recruitment.team_staffing.title') }}</x-slot:title>

<div class="space-y-6 pb-8">
    <x-recruitment.requests-header
        current="team"
        :title="__('recruitment.team_staffing.title')" />

    @if ($root)
        @include('livewire.recruitment.requests.partials.team-staffing-summary')

        {{-- x-data lives here, not on the canvas, so the toolbar's zoom / fit /
             find controls reach the same staffingTree as the canvas. --}}
        <section x-data="staffingTree" class="isolate {{ $card }}">
            @include('livewire.recruitment.requests.partials.team-staffing-toolbar')

            <div class="relative">
                <div wire:loading.flex wire:target="toggleVacantOnly,setView,expandAll,collapseAll,toggle"
                     class="absolute inset-0 z-30 items-center justify-center bg-card/75 backdrop-blur-[1px]">
                    <div class="flex items-center gap-2 rounded-control bg-card px-4 py-3 text-small font-semibold text-ink shadow-e2">
                        <x-icons.spinner class="size-5 animate-spin text-ink-2" />
                        {{ __('general.updating-chart') }}
                    </div>
                </div>

                {{-- Tablet and desktop: the tidy-tree canvas. --}}
                @include('livewire.recruitment.requests.partials.team-staffing-canvas')

                {{-- Phone: the same tree as an indented outline. --}}
                @include('livewire.recruitment.requests.partials.team-staffing-outline')
            </div>

            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 rounded-b-surface border-t border-line-soft bg-soft px-4 py-3 text-caption text-ink-2">
                <p class="flex max-w-2xl items-start gap-2">
                    <flux:icon.information-circle variant="micro" class="mt-px size-4 shrink-0" aria-hidden="true" />
                    {{ __('recruitment.team_staffing.tree_help') }}
                </p>
                {{-- Key for the seat dots, which only the positions view draws. --}}
                @if ($view === 'positions')
                    <p class="flex items-center gap-4" aria-hidden="true">
                        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-ink-2"></span>{{ __('recruitment.team_staffing.capacity') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full border border-dashed border-ink-3"></span>{{ __('recruitment.team_staffing.open_capacity') }}</span>
                    </p>
                @endif
            </div>
        </section>
    @else
        <section class="{{ $card }} px-6 py-16 text-center">
            <span class="mx-auto flex size-12 items-center justify-center rounded-full border border-line-soft bg-soft text-ink" aria-hidden="true">
                <x-icons.code-fork class="size-6" />
            </span>
            <h2 class="mt-4 text-h4 font-semibold text-ink">{{ __('recruitment.team_staffing.no_position') }}</h2>
            <p class="mx-auto mt-2 max-w-sm text-body text-pretty text-ink-2">{{ __('recruitment.team_staffing.no_position_help') }}</p>
        </section>
    @endif

    <livewire:position.profile-drawer />
</div>
