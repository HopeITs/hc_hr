{{--
    Row actions: the main action (View profile) plus a "More" menu. Every control has an
    accessible name that includes the employee. The menu is position: fixed so the table's
    horizontal scroll container cannot clip it; Escape closes it and returns focus.
    Edit / Activate / Delete only appear for holders of the permission, and the actions they
    call authorize again on the server.
--}}
@php($name = $employee->name)
@php($hasMore = $canUpdate || $canDelete)
<div class="flex items-center gap-1" data-row-actions>
    @if($employee->username)
        <a href="{{ route('employees.show', ['employee' => $employee->username]) }}" wire:navigate
            title="{{ __('general.view-profile') }}"
            aria-label="{{ __('general.emp-view-profile-of', ['name' => $name]) }}"
            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg p-2 text-secondary-medium hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
            <x-icons.eye class="size-5" aria-hidden="true" />
        </a>
    @endif

    @if($hasMore)
        <div class="relative"
            x-data="{
                open: false,
                top: 0,
                left: 0,
                toggle() {
                    this.open = ! this.open;
                    if (! this.open) return;
                    const r = this.$refs.trigger.getBoundingClientRect();
                    const w = 208;
                    this.left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8));
                    this.top = r.bottom + 4;
                    this.$nextTick(() => {
                        const h = this.$refs.menu.offsetHeight;
                        if (this.top + h > window.innerHeight - 8) this.top = Math.max(8, r.top - h - 4);
                        this.$refs.menu.querySelector('[role=menuitem]')?.focus();
                    });
                },
                close(refocus = true) {
                    this.open = false;
                    if (refocus) this.$nextTick(() => this.$refs.trigger.focus());
                },
            }"
            x-on:keydown.escape.stop="open && close()"
            x-on:scroll.window.passive="open = false"
            x-on:resize.window="open = false">
            <button type="button" x-ref="trigger" x-on:click.stop="toggle()"
                aria-haspopup="menu" x-bind:aria-expanded="open"
                aria-label="{{ __('general.emp-more-actions', ['name' => $name]) }}"
                class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg p-2 text-secondary-medium hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <svg class="size-5" aria-hidden="true" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
            </button>

            <div x-ref="menu" x-show="open" x-cloak role="menu"
                aria-label="{{ __('general.emp-more-actions', ['name' => $name]) }}"
                x-on:click.outside="close(false)"
                x-on:keydown.arrow-down.prevent="$focus.within($refs.menu).wrap().next()"
                x-on:keydown.arrow-up.prevent="$focus.within($refs.menu).wrap().previous()"
                x-on:keydown.tab="close(false)"
                x-bind:style="`top:${top}px;left:${left}px`"
                class="fixed z-40 w-52 rounded-lg border border-[var(--line-hairline)] bg-white py-1 text-start font-normal shadow-e2">
                @if($canUpdate)
                    <a role="menuitem" href="{{ route('employees.edit', $employee->getKey()) }}" wire:navigate
                        class="flex min-h-11 items-center px-4 text-black hover:bg-neutral-50 focus-visible:bg-neutral-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">{{ __('general.edit') }}</a>
                    {{-- B5: a draft, or a volunteer awaiting their first contract, is activated by that contract, never by hand (HR, 2026-10-23). --}}
                    @unless($employee->isDraft() || $employee->isAwaitingContract())
                    <button type="button" role="menuitem" wire:click="confirmToggleItemStatus({{ (int) $employee->getKey() }})" x-on:click="close(false)"
                        class="flex min-h-11 w-full items-center px-4 text-start text-black hover:bg-neutral-50 focus-visible:bg-neutral-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">{{ $employee->is_active ? __('general.emp-deactivate') : __('general.emp-activate') }}</button>
                    @endunless
                @endif
                @if($canDelete)
                    <button type="button" role="menuitem" wire:click="confirmDelete({{ (int) $employee->getKey() }})" x-on:click="close(false)"
                        class="flex min-h-11 w-full items-center px-4 text-start text-primary hover:bg-neutral-50 focus-visible:bg-neutral-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary">{{ __('general.delete') }}</button>
                @endif
            </div>
        </div>
    @endif
</div>
