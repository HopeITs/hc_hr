@props(['menu' => false])

{{-- Light / dark switch. Remembered on this device (localStorage "hopehr-theme"); until the
     person picks one, the page follows the system. partials/head.blade.php applies the
     stored choice before first paint. aria-pressed = dark mode is on.
     `menu` renders it as an account-menu row (phones), otherwise as a 44px header button. --}}
<button type="button"
    @if ($menu) role="menuitemcheckbox" :aria-checked="dark.toString()" @else :aria-pressed="dark.toString()" @endif
    x-data="{
        dark: false,
        init() {
            const t = document.documentElement.dataset.theme;
            this.dark = t ? t === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
        },
        toggle() {
            this.dark = ! this.dark;
            const t = this.dark ? 'dark' : 'light';
            try { localStorage.setItem('hopehr-theme', t); } catch (e) {}
            document.documentElement.dataset.theme = t;
            window.dispatchEvent(new CustomEvent('hopehr-theme', { detail: t }));
        },
    }"
    x-on:hopehr-theme.window="dark = $event.detail === 'dark'"
    x-on:click="toggle()"
    @if ($menu)
        {{ $attributes->class('flex w-full min-h-11 items-center gap-3 px-3 py-2 text-start text-body font-medium text-ink transition rounded-surface hover:bg-hover') }}
    @else
        aria-label="{{ __('theme.dark') }}" title="{{ __('theme.dark') }}"
        {{ $attributes->class('inline-grid size-11 shrink-0 place-items-center rounded-control text-ink-2 transition hover:bg-hover hover:text-ink') }}
    @endif
>
    {{-- Moon while light (switches to dark), sun while dark. --}}
    <svg x-show="! dark" class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11z" />
    </svg>
    <svg x-show="dark" x-cloak class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4" />
    </svg>
    @if ($menu)
        <span>{{ __('theme.dark') }}</span>
    @endif
</button>
