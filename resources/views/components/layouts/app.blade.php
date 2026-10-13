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

<body class="antialiased min-h-screen bg-white"
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
          focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-white
          focus:outline-none focus:ring-2 focus:ring-white">
    {{ __('general.a11y.skip-to-content') }}
</a>

{{--
    Decorative brand animation. It is hidden from assistive technology entirely:
    it carries no information, and without aria-hidden a screen reader would
    land on an unlabelled <video> element on every navigation.
--}}
<div id="page-gif-overlay"
     aria-hidden="true"
     style="position:fixed; inset:0; display:flex; align-items:center; justify-content:center; background:#ffffff; z-index:9999;">
    <video autoplay loop muted playsinline tabindex="-1" style="width:18.5rem; height:18.5rem; object-fit:contain;">
        <source src="{{ asset('assets/GIF/hr-website-2.mp4') }}" type="video/mp4">
    </video>
</div>
<livewire:layout.sidebar/>


<div class="flex flex-col min-h-screen transition-[margin] duration-[400ms] ease-fluid md:ms-60"
     :class="sidebarCollapsed ? 'md:!ms-24' : 'md:!ms-60'">



    <livewire:layout.header/>

    <main id="main-content"
          tabindex="-1"
          aria-label="{{ __('general.a11y.main-content') }}"
          class="{{ View::hasSection('greeting') ? '' : 'pt-[120px] ' }} bg-app-surface flex-grow flex flex-col items-center focus:outline-none">


        {{--
            role="status" makes the flash message announced when it appears.
            Without it the text is painted silently and a screen reader user
            never learns the action succeeded.
        --}}
        @if (session('status'))
            <div class="alert alert-success" role="status" aria-live="polite">
                {{ session('status') }}
            </div>
        @endif
        <div class="px-4 pb-18 max-lg:w-full md:w-11/12  min-h-full">
            @yield('greeting')
            {{ $slot }}
        </div>
    </main>

    <x-layouts.app.footer class="bg-app-surface"/>
</div>

<x-tour.overlay/>

@include('partials.scripts')
@stack('scripts')
<script nonce="{{ Vite::cspNonce() }}">
    function hidePreloader() {
        const overlay = document.getElementById('page-gif-overlay');
        if (!overlay) return;

        overlay.style.transition = "opacity 0.8s ease";
        overlay.style.opacity = "0";

        setTimeout(() => {
            overlay.style.display = "none";
        }, 100);
    }

    function showPreloader() {
        const overlay = document.getElementById('page-gif-overlay');
        if (!overlay) return;

        overlay.style.display = "flex";
        overlay.style.opacity = "1";
    }

    const MIN_TIME = 1500;
    let startTime = performance.now();

    function scheduleHide() {
        const elapsed = performance.now() - startTime;
        const remaining = MIN_TIME - elapsed;

        setTimeout(hidePreloader, remaining > 0 ? remaining : 0);
    }

    document.addEventListener('DOMContentLoaded', () => {
        startTime = performance.now();
        scheduleHide();
    });

    document.addEventListener('livewire:navigated', () => {
        startTime = performance.now();
        scheduleHide();
    });

    setTimeout(hidePreloader, 2000);
</script>




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
<script src="{{ asset('js/custom-scrollbar.js') }}" defer></script>
</body>

</html>
