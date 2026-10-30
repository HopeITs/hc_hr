<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">

<head>
    @include('partials.head')

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
</head>

<body class="antialiased min-h-screen bg-base"
      x-data="{ sidebarCollapsed: localStorage.getItem('sidebar-collapsed') === 'true' }"
      x-on:sidebar-collapse-toggle.window="
          sidebarCollapsed = !sidebarCollapsed;
          localStorage.setItem('sidebar-collapsed', sidebarCollapsed);
      ">

{{--
    The skip link is the first thing in the tab order. Without it a keyboard
    user has to tab through the entire sidebar on every single page before
    reaching the content. It stays off-screen until focused.
--}}
<a href="#main-content"
   class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:start-4 focus:z-[10000]
          focus:rounded-control focus:bg-ink focus:px-4 focus:py-2 focus:text-on-ink">
    {{ __('general.a11y.skip-to-content') }}
</a>

{{--
    No page-covering loader (A7). A full-screen animation used to cover every
    page load and wire:navigate for at least 550 ms after the content was ready.
    Progress now comes from Livewire's navigate bar (config/livewire.php,
    --livewire-progress-bar-color) and from each control's own pending state.
--}}
<livewire:layout.sidebar/>


<div class="flex flex-col min-h-screen transition-[margin] duration-[400ms] ease-fluid md:ms-60"
     :class="sidebarCollapsed ? 'md:!ms-24' : 'md:!ms-60'">



    <livewire:layout.header/>

    <main id="main-content"
          tabindex="-1"
          aria-label="{{ __('general.a11y.main-content') }}"
          class="{{ View::hasSection('greeting') ? '' : 'pt-[120px] ' }} bg-app-surface flex-grow flex flex-col items-center focus:outline-none">


        <div class="px-4 pb-18 max-lg:w-full md:w-11/12  min-h-full">
            @yield('greeting')
            {{ $slot }}
        </div>
    </main>

    <x-layouts.app.footer class="bg-app-surface"/>
</div>

{{-- D2: phone bottom navigation (below md) and a connection notice. Nothing is
     cached for offline use: without a connection the app says so, and entries
     stay on the page until a save can reach the server (G-B-2). --}}
<livewire:layout.bottom-nav/>

<div x-data="{ online: navigator.onLine }"
     x-on:online.window="online = true" x-on:offline.window="online = false"
     x-show="!online" x-cloak role="status" data-testid="offline-notice"
     class="app-offline-notice fixed inset-x-0 z-[9400] mx-auto max-w-lg px-4">
    <p class="rounded-control bg-ink px-4 py-2 text-center text-small font-semibold text-on-ink shadow-e3">
        {{ __('general.connection.offline') }}
    </p>
</div>

<x-tour.overlay/>

{{-- D3: page-context help, opened from the header and from "?" beside difficult fields. Client-side only. --}}
@auth
    <x-help.panel/>
@endauth

{{-- Prompt 10: "g" then a letter opens a page; "?" lists the keys. Client-side only. --}}
@auth
    <x-keyboard-shortcuts/>
@endauth

{{-- D3: "Send feedback to the IT team", opened from the user menu. The page's route
     name is passed from here, on the server; the form never reads a URL. --}}
@auth
    <livewire:feedback.feedback-form :page-route="request()->route()?->getName()" />
@endauth

{{-- Outcome toasts (session('status') included) and delete confirmations, for every page. --}}
<x-toasts/>
<x-confirm-dialog/>

@include('partials.scripts')
@stack('scripts')





<script nonce="{{ Vite::cspNonce() }}">
    window.CustomScrollbarConfig = {
        trackWidth: 8,
        offset: 5,
        padY: 10,
        padBottom: 16,
        headerSelector: '#app-navbar',
        exclude: ['[data-csb-exclude]'],
    };
</script>
{{-- Served as immutable for a year: the mtime query is what makes an edit reach browsers. --}}
<script src="{{ asset('js/custom-scrollbar.js') }}?v={{ filemtime(public_path('js/custom-scrollbar.js')) }}" defer></script>
</body>

</html>
