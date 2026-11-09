@php
    // One row in five groups — what the cards show, what is filtered, how much
    // of the tree is open, where the canvas looks, and find — separated by
    // hairlines instead of a border on every button. Active = ink fill (never
    // red). Navigation and find drive staffingTree (x-data on the section around
    // this toolbar and the canvas); they are wire:ignore so a morph never resets
    // the zoom readout or the query, and hidden on phones, where the outline
    // replaces the canvas.
    $tbFocus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink';
    $tbControl = 'inline-flex min-h-11 items-center rounded-control text-small transition duration-150 motion-reduce:transition-none sm:min-h-9 '.$tbFocus;
    $tbIcon = 'inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-control text-ink-2 transition duration-150 hover:bg-hover hover:text-ink motion-reduce:transition-none '.$tbFocus;
@endphp

<div class="sticky top-0 z-20 flex flex-wrap items-center gap-x-2 gap-y-2 rounded-t-surface border-b border-line-soft bg-card/95 px-3 py-2 backdrop-blur">
    {{-- 1. View --}}
    <div role="group" aria-label="{{ __('recruitment.team_staffing.view_label') }}"
         class="inline-flex items-center gap-0.5 rounded-control bg-soft p-0.5">
        @foreach (['staff' => 'users', 'positions' => 'layout'] as $mode => $icon)
            <button type="button" wire:click="setView('{{ $mode }}')"
                    aria-pressed="{{ $view === $mode ? 'true' : 'false' }}"
                    @class([
                        $tbControl,
                        'cursor-pointer gap-1.5 px-3 font-semibold',
                        'bg-ink text-on-ink shadow-e1' => $view === $mode,
                        'text-ink-2 hover:bg-hover hover:text-ink' => $view !== $mode,
                    ])>
                <x-ic :name="$icon" class="size-4 shrink-0" />
                {{ __('recruitment.team_staffing.views.'.$mode) }}
            </button>
        @endforeach
    </div>

    <span aria-hidden="true" class="mx-1 hidden h-6 w-px bg-line-soft sm:block"></span>

    {{-- 2. Filter: a real switch — it is on/off, not an action — and it stays
         ink so it does not compete with the open seats it reveals. --}}
    <label class="{{ $tbControl }} cursor-pointer gap-2.5 px-2 font-medium text-ink has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-ink">
        <input type="checkbox" role="switch" class="peer sr-only" wire:click="toggleVacantOnly" @checked($vacantOnly)>
        <span aria-hidden="true"
              class="relative h-5 w-9 shrink-0 rounded-full bg-line transition-colors duration-150 peer-checked:bg-ink motion-reduce:transition-none
                     after:absolute after:top-0.5 after:start-0.5 after:size-4 after:rounded-full after:bg-card after:shadow-e1 after:transition-all after:duration-150 peer-checked:after:start-4.5 motion-reduce:after:transition-none"></span>
        {{ __('recruitment.team_staffing.vacant_only') }}
        @if ($vacantOnly && $hidden > 0)
            <span class="inline-flex items-center gap-1 text-caption font-normal tabular-nums text-ink-2">
                <x-ic name="eye" class="size-3.5 shrink-0" />
                {{ __('recruitment.team_staffing.hidden_positions', ['count' => $hidden]) }}
            </span>
        @endif
    </label>

    <span aria-hidden="true" class="mx-1 hidden h-6 w-px bg-line-soft sm:block"></span>

    {{-- 3. Hierarchy: quiet, icon + words. --}}
    <div class="inline-flex items-center gap-0.5">
        <button type="button" wire:click="expandAll"
                class="{{ $tbControl }} cursor-pointer gap-1.5 px-2.5 font-semibold text-ink-2 hover:bg-hover hover:text-ink">
            <flux:icon.arrows-pointing-out variant="micro" class="size-4 shrink-0" aria-hidden="true" />
            {{ __('general.expand-all') }}
        </button>
        <button type="button" wire:click="collapseAll"
                class="{{ $tbControl }} cursor-pointer gap-1.5 px-2.5 font-semibold text-ink-2 hover:bg-hover hover:text-ink">
            <flux:icon.arrows-pointing-in variant="micro" class="size-4 shrink-0" aria-hidden="true" />
            {{ __('general.collapse-all') }}
        </button>
    </div>
    <span aria-hidden="true" class="mx-1 hidden h-6 w-px bg-line-soft sm:block"></span>

    {{-- 4. Navigation: zoom, the zoom level, fit, actual size. --}}
    <div wire:ignore role="toolbar" aria-label="{{ __('recruitment.team_staffing.canvas.controls') }}"
         class="hidden items-center gap-0.5 sm:inline-flex">
        <button type="button" x-on:click="zoomBy(1 / 1.2)" class="{{ $tbIcon }}"
                aria-label="{{ __('recruitment.team_staffing.canvas.zoom_out') }}" title="{{ __('recruitment.team_staffing.canvas.zoom_out') }}">
            <flux:icon.magnifying-glass-minus variant="micro" class="size-4" aria-hidden="true" />
        </button>
        <span class="min-w-11 text-center text-caption font-semibold tabular-nums text-ink" role="status"
              aria-label="{{ __('recruitment.team_staffing.canvas.zoom_level') }}" x-text="percent">100%</span>
        <button type="button" x-on:click="zoomBy(1.2)" class="{{ $tbIcon }}"
                aria-label="{{ __('recruitment.team_staffing.canvas.zoom_in') }}" title="{{ __('recruitment.team_staffing.canvas.zoom_in') }}">
            <flux:icon.magnifying-glass-plus variant="micro" class="size-4" aria-hidden="true" />
        </button>
        <button type="button" x-on:click="fit()" class="{{ $tbIcon }}"
                aria-label="{{ __('recruitment.team_staffing.canvas.fit') }}" title="{{ __('recruitment.team_staffing.canvas.fit') }}">
            <flux:icon.viewfinder-circle variant="micro" class="size-4" aria-hidden="true" />
        </button>
        <button type="button" x-on:click="reset()" class="{{ $tbIcon }} w-auto px-2 text-caption font-semibold tabular-nums"
                aria-label="{{ __('recruitment.team_staffing.canvas.reset') }}" title="{{ __('recruitment.team_staffing.canvas.reset') }}">
            1:1
        </button>
    </div>

    {{-- 5. Find: at the far end; it highlights and centres a card on the canvas. --}}
    <form wire:ignore role="search" x-on:submit.prevent="findNext(1)"
          class="ms-auto hidden h-9 items-center gap-0.5 rounded-control border border-line-soft bg-card ps-2 pe-0.5 focus-within:border-ink sm:flex">
        <label for="staffing-find" class="sr-only">{{ __('recruitment.team_staffing.canvas.find_label') }}</label>
        <flux:icon.magnifying-glass variant="micro" class="size-4 shrink-0 text-ink-3" aria-hidden="true" />
        <input id="staffing-find" type="search" x-model="query" autocomplete="off"
               x-on:input.debounce.200ms="search()"
               x-on:keydown.escape.prevent="clearSearch()"
               x-on:keydown.enter.prevent="$event.shiftKey ? findNext(-1) : findNext(1)"
               placeholder="{{ __('recruitment.team_staffing.canvas.find_placeholder') }}"
               class="h-8 w-40 min-w-0 border-0 bg-transparent px-1 text-small text-ink shadow-none outline-none ring-0 placeholder:text-ink-3 focus:shadow-none focus:outline-none focus:ring-0 lg:w-56" />
        <span x-show="searched" x-cloak role="status" aria-live="polite"
              class="whitespace-nowrap px-1 text-caption tabular-nums text-ink-2"
              x-text="matchCount ? @js(__('recruitment.team_staffing.canvas.find_count')).replace(':current', matchIndex + 1).replace(':total', matchCount) : @js(__('recruitment.team_staffing.canvas.find_none'))"></span>
        <button type="button" x-show="matchCount > 1" x-cloak x-on:click="findNext(-1)" class="{{ $tbIcon }} size-8"
                aria-label="{{ __('recruitment.team_staffing.canvas.find_previous') }}" title="{{ __('recruitment.team_staffing.canvas.find_previous') }}">
            <flux:icon.chevron-up variant="micro" class="size-4" aria-hidden="true" />
        </button>
        <button type="button" x-show="matchCount > 1" x-cloak x-on:click="findNext(1)" class="{{ $tbIcon }} size-8"
                aria-label="{{ __('recruitment.team_staffing.canvas.find_next') }}" title="{{ __('recruitment.team_staffing.canvas.find_next') }}">
            <flux:icon.chevron-down variant="micro" class="size-4" aria-hidden="true" />
        </button>
        <button type="button" x-show="query" x-cloak x-on:click="clearSearch()" class="{{ $tbIcon }} size-8"
                aria-label="{{ __('recruitment.team_staffing.canvas.find_clear') }}" title="{{ __('recruitment.team_staffing.canvas.find_clear') }}">
            <flux:icon.x-mark variant="micro" class="size-4" aria-hidden="true" />
        </button>
    </form>
</div>
