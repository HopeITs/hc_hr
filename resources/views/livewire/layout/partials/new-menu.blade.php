{{--
    The header's New button (C2, C0-Q3): only what the active role may start
    (Navigation::newActions(); each screen checks the permission again).
    Each link carries ?from=<this page> so Cancel can come back here
    (SafeReturn decides whether that page is allowed). A WAI-ARIA menu button.
--}}
@if ($actions !== [])
    <div class="relative shrink-0"
         x-data="{
             open: false,
             items() { return [...this.$refs.menu.querySelectorAll('[role=menuitem]')]; },
             show() { this.open = true; this.$nextTick(() => this.items()[0]?.focus()); },
             hide(refocus = true) { if (!this.open) return; this.open = false; if (refocus) this.$refs.trigger.focus(); },
             move(step) { const all = this.items(); all[(all.indexOf(document.activeElement) + step + all.length) % all.length]?.focus(); },
             go(event) {
                 const url = new URL(event.currentTarget.href);
                 url.searchParams.set('from', location.pathname + location.search);
                 this.open = false;
                 Livewire.navigate(url.toString());
             },
         }"
         x-on:click.outside="hide(false)"
         x-on:keydown.escape="hide()">
        <button type="button" x-ref="trigger" data-testid="new-menu-button"
                class="inline-flex h-11 min-w-11 items-center justify-center gap-1.5 rounded-[14px] bg-primary px-3 text-body font-bold text-white hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-primary sm:h-10"
                aria-haspopup="menu" aria-controls="new-menu" :aria-expanded="open.toString()"
                x-on:click="open ? hide() : show()" x-on:keydown.arrow-down.prevent="show()">
            <x-icons.plus class="size-4" />
            <span class="max-sm:sr-only">{{ __('general.new-menu.label') }}</span>
        </button>

        <div id="new-menu" role="menu" x-ref="menu" x-show="open" x-cloak
             aria-label="{{ __('general.new-menu.label') }}"
             x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)"
             x-on:keydown.tab="hide(false)"
             class="absolute end-0 top-full z-50 mt-2 min-w-56 rounded-surface border border-[var(--line-hairline)] bg-white p-1.5 shadow-e3">
            @foreach ($actions as $action)
                <a role="menuitem" href="{{ route($action['route'], $action['params']) }}" data-new="{{ $action['id'] }}"
                   x-on:click.prevent="go($event)"
                   class="flex min-h-11 items-center rounded-control px-3 text-body font-medium text-neutral-800 hover:bg-secondary-soft focus:bg-secondary-soft focus:outline-none">
                    {{ $action['label'] }}
                </a>
            @endforeach
        </div>
    </div>
@endif
