{{--
    One contract's actions: the main action "View" (opens the read-only drawer)
    and a "More" menu with the rest. Each item only appears when the role may
    use it; the server re-authorizes every one when it is used.

    $context is "row" (View + More, in the table) or "card" (More only: the
    phone card itself is the View button). Ids carry the context because the
    table and the cards both exist in the DOM, one of them hidden.

    The menu is a WAI-ARIA menu button: Enter/Space/ArrowDown opens and focuses
    the first item, arrows/Home/End move, Escape closes and returns focus to the
    button, Tab or an outside click closes. It is positioned `fixed` so the
    table's horizontal scroll area cannot clip it.
--}}
@php
    $menuId = "contract-menu-{$context}-{$contract->getKey()}";
    $hasMenu = $canEdit || $canRenew || $canExport || $canAutoRenew || $canDelete;
@endphp
<div class="flex items-center justify-center gap-1"
    x-data="{
        open: false, top: 0, left: 0,
        items() { return [...this.$refs.menu.querySelectorAll('[role^=menuitem]')]; },
        show() { this.open = true; this.$nextTick(() => { this.place(); this.items()[0]?.focus(); }); },
        hide(refocus = true) { if (! this.open) return; this.open = false; if (refocus) this.$refs.trigger.focus(); },
        place() {
            const r = this.$refs.trigger.getBoundingClientRect();
            const w = this.$refs.menu.offsetWidth, h = this.$refs.menu.offsetHeight;
            this.left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8));
            this.top = r.bottom + h + 8 > window.innerHeight ? Math.max(8, r.top - h - 4) : r.bottom + 4;
        },
        move(step) { const all = this.items(); all[(all.indexOf(document.activeElement) + step + all.length) % all.length]?.focus(); },
    }"
    x-on:click.outside="hide(false)"
    x-on:scroll.window.capture="hide(false)"
    x-on:resize.window="hide(false)">
    @if($context === 'row')
        <button type="button"
            class="inline-flex min-h-11 items-center gap-1 rounded-control px-3 text-small font-semibold text-ink hover:bg-hover"
            data-contract-view="{{ $contract->getKey() }}"
            aria-label="{{ __('contracts.view-named', ['name' => $name]) }}"
            x-on:click.stop="{{ $openJs }}">
            <x-icons.eye />
            <span>{{ __('general.view') }}</span>
        </button>
    @endif

    @if($hasMenu)
        <button type="button" x-ref="trigger"
            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-ink hover:bg-hover"
            aria-haspopup="menu" aria-controls="{{ $menuId }}" x-bind:aria-expanded="open ? 'true' : 'false'"
            aria-label="{{ __('contracts.more-actions-for', ['name' => $name]) }}"
            x-on:click.stop="open ? hide() : show()"
            x-on:keydown.arrow-down.prevent="show()">
            <svg class="size-5" aria-hidden="true" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.75"/><circle cx="12" cy="12" r="1.75"/><circle cx="19" cy="12" r="1.75"/></svg>
        </button>

        <div id="{{ $menuId }}" role="menu" x-ref="menu" x-show="open" x-cloak
            aria-label="{{ __('contracts.more-actions-for', ['name' => $name]) }}"
            class="fixed z-40 min-w-52 overflow-hidden rounded-surface border border-line-soft bg-card py-1 text-start shadow-e2"
            x-bind:style="`top:${top}px;left:${left}px`"
            x-on:keydown.escape.prevent.stop="hide()"
            x-on:keydown.arrow-down.prevent="move(1)"
            x-on:keydown.arrow-up.prevent="move(-1)"
            x-on:keydown.home.prevent="items()[0]?.focus()"
            x-on:keydown.end.prevent="items().at(-1)?.focus()"
            x-on:keydown.tab="hide(false)">
            @php($itemClass = 'flex min-h-11 w-full items-center gap-2 px-4 text-start text-small hover:bg-hover focus-visible:bg-surface focus-visible:-outline-offset-2')
            @if($canEdit)
                <a role="menuitem" href="{{ route('contracts.edit', $contract->getKey()) }}" wire:navigate class="{{ $itemClass }}">{{ __('general.edit') }}</a>
            @endif
            @if($canRenew)
                <button type="button" role="menuitem" class="{{ $itemClass }}"
                    x-on:click="hide(false); Livewire.dispatch('open-renew-modal', { contractId: {{ (int) $contract->getKey() }} })">{{ __('general.renew') }}</button>
            @endif
            @if($canExport)
                {{-- Same fetch-in-place state as the details page (loading, error toast, retry). --}}
                <div role="none" x-data="@include('livewire.contract._export-pdf-state', ['contract' => $contract])">
                    <button type="button" role="menuitem" class="{{ $itemClass }}"
                        x-on:click="hide(false); exportPdf()"
                        x-bind:aria-disabled="exporting ? 'true' : 'false'">{{ __('general.export-pdf') }}</button>
                </div>
            @endif
            @if($canAutoRenew)
                <button type="button" role="menuitemcheckbox" aria-checked="{{ $contract->auto_renew ? 'true' : 'false' }}" class="{{ $itemClass }}"
                    wire:click="confirmToggleAutoRenew({{ (int) $contract->getKey() }})"
                    x-on:click="hide(false)">{{ $contract->auto_renew ? __('contracts.auto-renew-turn-off') : __('contracts.auto-renew-turn-on') }}</button>
            @endif
            @if($canDelete)
                <button type="button" role="menuitem" class="{{ $itemClass }}"
                    wire:click="confirmDelete({{ (int) $contract->getKey() }})"
                    x-on:click="hide(false)">{{ __('general.delete') }}</button>
            @endif
        </div>
    @endif
</div>
