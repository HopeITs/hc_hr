{{--
    The assistant's floating button and its small chat panel, on every
    authenticated page (mounted once in layouts/app inside @persist, so it
    survives wire:navigate with its panel open or closed).

    Nothing heavy loads with the page: the panel's Livewire component
    (App\Livewire\Assistant\Chat in 'panel' mode) is lazy and only mounts the
    first time the panel is shown. The conversation is kept in the session, so
    "expand" opens the full page on the same conversation, and the panel re-reads
    it each time it opens.

    The button sits at the end corner (it mirrors in Arabic), above the phone
    bottom bar and above a page's sticky save bar. It hides on the assistant
    page itself, where the conversation is already on screen.
--}}
@if (app(\App\Services\Assistant\Assistant::class)->isAvailable())
    <div
        x-data="{
            open: false,
            onPage: false,
            check() {
                this.onPage = !! document.querySelector('[data-assistant-page]');
                if (this.onPage) this.open = false;
            },
            show() {
                this.open = true;
                this.$nextTick(() => {
                    const field = this.$refs.panel.querySelector('textarea');
                    (field ?? this.$refs.panel).focus({ preventScroll: true });
                    window.dispatchEvent(new CustomEvent('assistant-panel-opened'));
                });
            },
            hide(returnFocus = true) {
                if (! this.open) return;
                this.open = false;
                if (returnFocus) this.$nextTick(() => this.$refs.launcher?.focus());
            },
            toggle() { this.open ? this.hide() : this.show() },
        }"
        x-init="check()"
        x-on:livewire:navigated.document="check()"
        x-on:keydown.escape.window="hide()"
        x-show="! onPage"
        data-testid="assistant-widget"
        data-assistant-widget
    >
        {{-- The panel: a non-modal dialog, so the page behind stays usable. --}}
        <section
            x-ref="panel"
            x-show="open"
            x-cloak
            x-transition:enter="transition duration-200 ease-fluid"
            x-transition:enter-start="opacity-0 translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition duration-150 ease-in"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            role="dialog"
            aria-modal="false"
            aria-labelledby="assistant-panel-title"
            tabindex="-1"
            class="fixed inset-x-2 z-40 flex flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-e3 focus:outline-none
                   bottom-[calc(9rem+env(safe-area-inset-bottom,0px))] h-[min(34rem,calc(100dvh-11rem))]
                   md:inset-x-auto md:end-6 md:bottom-24 md:w-[24rem] md:h-[min(36rem,calc(100dvh-8rem))]"
            data-testid="assistant-panel"
        >
            <header class="flex items-center gap-2 border-b border-line-soft px-3 py-2">
                <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-soft text-ink-2" aria-hidden="true">
                    <x-icons.assistant class="size-4" />
                </span>
                <h2 id="assistant-panel-title" class="min-w-0 flex-1 truncate text-body font-semibold text-ink">{{ __('assistant.title') }}</h2>

                <a
                    href="{{ route('assistant') }}"
                    wire:navigate
                    x-on:click="hide(false)"
                    class="inline-flex size-11 items-center justify-center rounded-control text-ink-2 transition-colors hover:bg-hover hover:text-ink"
                    aria-label="{{ __('assistant.widget.expand') }}"
                    title="{{ __('assistant.widget.expand') }}"
                    data-testid="assistant-expand"
                >
                    {{-- Expand: arrows out to the corners (mirrors with the page). --}}
                    <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7" />
                    </svg>
                </a>
                <button
                    type="button"
                    x-on:click="hide()"
                    class="inline-flex size-11 items-center justify-center rounded-control text-ink-2 transition-colors hover:bg-hover hover:text-ink"
                    aria-label="{{ __('assistant.widget.close') }}"
                    title="{{ __('assistant.widget.close') }}"
                >
                    <x-ic name="x" />
                </button>
            </header>

            {{-- Lazy: mounts when the panel is first shown (a hidden panel never intersects). --}}
            <div class="flex min-h-0 flex-1 flex-col">
                <livewire:assistant.chat mode="panel" lazy />
            </div>
        </section>

        {{-- The launcher. --}}
        <button
            type="button"
            x-ref="launcher"
            x-on:click="toggle()"
            x-bind:aria-expanded="open.toString()"
            aria-expanded="false"
            aria-haspopup="dialog"
            aria-label="{{ __('assistant.widget.open') }}"
            title="{{ __('assistant.widget.open') }}"
            class="fixed end-4 z-40 inline-flex size-14 items-center justify-center rounded-full border border-line-soft bg-ink text-on-ink shadow-e3 transition-transform hover:scale-105
                   bottom-[calc(5rem+env(safe-area-inset-bottom,0px))] md:end-6 md:bottom-6
                   max-lg:[html:has(.m1-action-bar)_&]:bottom-[calc(6.5rem+env(safe-area-inset-bottom,0px))]"
            data-testid="assistant-launcher"
        >
            <x-icons.assistant class="size-6" x-show="! open" />
            <x-ic name="x" class="size-6" x-show="open" x-cloak />
        </button>
    </div>
@endif
