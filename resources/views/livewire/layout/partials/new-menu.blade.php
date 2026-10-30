{{--
    The header's New button (C2, C0-Q3): only what the active role may start
    (Navigation::newActions(); each screen checks the permission again).
    Links marked `returns` carry ?from=<this page> so Cancel can come back here
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
                 if (event.currentTarget.dataset.returns !== undefined) {
                     const here = new URLSearchParams(location.search);
                     here.delete('from');
                     const query = here.toString();
                     url.searchParams.set('from', location.pathname + (query ? '?' + query : ''));
                 }
                 this.open = false;
                 Livewire.navigate(url.toString());
             },
         }"
         x-on:click.outside="hide(false)"
         x-on:keydown.escape="hide()">
        {{-- The header's one primary action. x-bind:, not :, so Blade leaves it to Alpine. --}}
        <x-button size="sm" icon="plus" :loading="false" x-ref="trigger" data-testid="new-menu-button"
                  class="min-w-11 shrink-0"
                  aria-haspopup="menu" aria-controls="new-menu" x-bind:aria-expanded="open.toString()"
                  x-on:click="open ? hide() : show()" x-on:keydown.arrow-down.prevent="show()">
            <span class="max-sm:sr-only">{{ __('general.new-menu.label') }}</span>
        </x-button>

        <div id="new-menu" role="menu" x-ref="menu" x-show="open" x-cloak
             aria-label="{{ __('general.new-menu.label') }}"
             x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)"
             x-on:keydown.tab="hide(false)"
             class="absolute end-0 top-full z-50 mt-2 min-w-56 rounded-surface border border-line-soft bg-card p-1.5 shadow-e3">
            @foreach ($actions as $action)
                <a role="menuitem" href="{{ route($action['route'], $action['params']) }}" data-new="{{ $action['id'] }}" @if ($action['returns']) data-returns @endif
                   x-on:click.prevent="go($event)"
                   class="flex min-h-11 items-center rounded-control px-3 text-body font-medium text-ink hover:bg-hover focus-visible:bg-hover">
                    {{ $action['label'] }}
                </a>
            @endforeach
        </div>
    </div>
@endif
