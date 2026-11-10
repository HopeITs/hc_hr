{{--
    The layout of a card taken out of the browser (Dashboard\Window): the
    page's head, theme and scripts, and nothing else. No sidebar, no top bar,
    no bottom bar: the window is the card.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">

<head>
    @include('partials.head')
    {{-- The home screen's entry brings clock.js (hcClock), which the clock cards need. --}}
    @vite('resources/js/dashboard/home-screen.js')

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
</head>

<body class="antialiased bg-base">
    {{ $slot }}

    <x-toasts/>
    <x-confirm-dialog/>

    @include('partials.scripts')
    @stack('scripts')
</body>

</html>
