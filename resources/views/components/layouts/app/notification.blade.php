<div
    x-data="{ open: false, ring: false }"
    @keydown.escape.window="open = false"
    @notif-arrived.window="ring = false; $nextTick(() => { ring = true; setTimeout(() => ring = false, 850) })"
    class="relative">

    <!-- Bell Button -->
    <button
        type="button"
        @click="open = !open; if (open) $dispatch('notification-panel-opened')"
        :aria-expanded="open"
        aria-haspopup="menu"
        class="relative inline-flex items-center justify-center transition rounded-[14px] cursor-pointer size-11 sm:size-9 text-secondary-light hover:bg-white hover:text-secondary-dark hover:shadow-e1 focus:outline-none focus:ring-2 focus:ring-neutral-200">

        <span :class="ring && 'animate-notif-shake'" class="inline-flex">
            <svg width="15" height="15" viewBox="0 0 28.55 29.92" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M15.04,29.92h-1.38c-2.69-.32-4.88-2.29-5.43-4.96H3.77c-1.16,0-2.21-.51-2.9-1.35-.74-.89-1.04-2.05-.78-3.2l2.53-11.37C3.73,4.06,8.02.41,13.1.03c5.44-.41,10.56,3.11,12.01,8.35l3.31,11.93c.32,1.15,0,2.36-.73,3.27-.68.84-1.72,1.38-2.87,1.38h-4.35c-.54,2.67-2.74,4.65-5.42,4.97ZM24.84,22.46c.45,0,.78-.26.97-.52.24-.34.29-.7.18-1.11l-3.25-11.7c-1.13-4.08-4.97-6.79-9.14-6.62s-7.65,3.1-8.56,7.19l-2.49,11.22c-.17.77.38,1.53,1.19,1.53h21.11ZM17.85,24.96h-7c.54,1.52,1.97,2.5,3.54,2.49s2.98-1,3.47-2.49Z"/></svg>
        </span>

        <!-- Unread Counter -->
        <span class="pointer-events-none absolute -top-0.5 -end-0.5">
            <livewire:notifications.counter />
        </span>
    </button>

    <!-- Dropdown Panel -->
    <div
        x-cloak
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 -translate-y-1 scale-95"
        @click.outside="open = false"
        x-ref="panel"
        x-effect="if (open) { $nextTick(() => {
            $refs.panel.querySelectorAll('.notif-row').forEach((el, i) => {
                el.animate(
                    [{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }],
                    { duration: 260, delay: i * 45, easing: 'cubic-bezier(0.22, 1, 0.36, 1)', fill: 'backwards' }
                );
            });
        }) }"
        class="max-sm:fixed max-sm:inset-x-3 max-sm:top-[4.5rem] max-sm:mt-0 max-sm:w-auto sm:absolute sm:end-0 sm:top-full z-50 mt-2 w-80 sm:max-w-[calc(100vw-1.5rem)] origin-top overflow-hidden
               rounded-surface border border-neutral-100 bg-white shadow-e3 ring-1 ring-black/5
"
        role="menu">

        <!-- Livewire component renders its own header + list -->
        <div class="max-h-[min(22rem,calc(100dvh-10rem))] overflow-y-auto overscroll-contain">
            <livewire:notifications.dropdown />
        </div>

        <!-- Footer -->
        <a href="{{ route('notifications.index') }}" wire:navigate
           class="flex items-center justify-center gap-2 border-t border-neutral-100 bg-neutral-50 py-2 text-body font-semibold
                  text-neutral-900 transition hover:bg-neutral-100">
            @lang('general.view-all')
        </a>
    </div>
</div>
