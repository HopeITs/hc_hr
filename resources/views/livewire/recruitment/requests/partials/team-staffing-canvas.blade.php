{{--
    The staffing chart on tablet and desktop: a pannable, zoomable canvas.

    The positions arrive as a flat list ($flat, built in team-staffing.blade.php);
    staffingTree (resources/js/staffing-tree.js, x-data on the section around the
    toolbar and this canvas) lays them out, draws each team's box and the
    connectors into the SVG and owns pan / zoom / fit / find. Its controls live
    in the toolbar. The viewport and the world carry wire:ignore.self so a
    Livewire morph keeps the size and transform the script set; the SVG is
    wire:ignore entirely.
--}}
<div class="relative hidden sm:block" data-staffing-canvas>
    <div x-ref="viewport" wire:ignore.self
         role="group" tabindex="0"
         aria-label="{{ __('recruitment.team_staffing.canvas.label') }}"
         aria-describedby="staffing-canvas-hint"
         x-on:pointerdown="onPointerDown($event)"
         x-on:pointermove="onPointerMove($event)"
         x-on:pointerup="onPointerUp($event)"
         x-on:pointercancel="onPointerUp($event)"
         x-on:keydown="onKeydown($event)"
         class="relative h-[min(78vh,900px)] min-h-[420px] cursor-grab touch-none overflow-hidden bg-card focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ink data-panning:cursor-grabbing data-panning:select-none">
        <div x-ref="world" wire:ignore.self class="absolute left-0 top-0 origin-top-left">
            <svg x-ref="links" wire:ignore class="pointer-events-none absolute left-0 top-0 overflow-visible" aria-hidden="true" focusable="false"></svg>

            @foreach ($flat as $entry)
                @include('livewire.recruitment.requests.partials.team-staffing-node', [
                    'entry' => $entry,
                    'compact' => $compact,
                    'view' => $view,
                ])
            @endforeach
        </div>
    </div>

    <p id="staffing-canvas-hint" class="sr-only">{{ __('recruitment.team_staffing.canvas.hint') }}</p>
</div>
