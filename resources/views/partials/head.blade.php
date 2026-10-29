<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content" />
{{-- Light or dark as the person last chose on this device (else the system's), applied
     before the first paint so nothing flashes. wire:navigate copies the new page's <html>
     attributes over the old ones, which drops data-theme; the observer puts it back in
     the same task, before anything paints, and livewire:navigated re-applies it too.
     The switch is in the header (livewire/layout/header.blade.php). --}}
<meta name="color-scheme" content="light dark" />
<script nonce="{{ Vite::cspNonce() }}">
    (function () {
        var root = document.documentElement;
        var stored = function () { try { var t = localStorage.getItem('hopehr-theme'); return t === 'light' || t === 'dark' ? t : null; } catch (e) { return null; } };
        var apply = function () { var t = stored(); if (t && root.dataset.theme !== t) root.dataset.theme = t; };
        apply();
        new MutationObserver(apply).observe(root, { attributes: true, attributeFilter: ['data-theme'] });
        document.addEventListener('livewire:navigated', apply);
    })();
</script>
<meta name="description" content="{{ $description ?? __('Secure human resources management for Christian Hope Center teams across Syria.') }}" />
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet" />

@php
    $pageTitle = isset($title) ? "{$title} - " . config('app.name') : config('app.name');
    $pageDescription = $description ?? __('general.meta-description');
    $pageImage = asset('assets/img/001_HC.png');
@endphp

<title>{{ $pageTitle }}</title>
<meta name="description" content="{{ $pageDescription }}" />

{{-- Internal HR system: nothing here belongs in a search index. A layout may pass $robots to override. --}}
<meta name="robots" content="{{ $robots ?? 'noindex, nofollow' }}" />

{{-- select2's built-in messages in the page language; read by resources/js/app.js. --}}
<meta name="hc-select2-i18n" content="{{ json_encode(__('general.select2'), JSON_UNESCAPED_UNICODE) }}" />

<link rel="canonical" href="{{ url()->current() }}" />

{{-- Link previews for staff sharing a URL in chat. Inert for search while noindex is set. --}}
<meta property="og:type" content="website" />
<meta property="og:site_name" content="{{ config('app.name') }}" />
<meta property="og:locale" content="{{ app()->getLocale() === 'ar' ? 'ar_AR' : 'en_US' }}" />
<meta property="og:title" content="{{ $pageTitle }}" />
<meta property="og:description" content="{{ $pageDescription }}" />
<meta property="og:url" content="{{ url()->current() }}" />
<meta property="og:image" content="{{ $pageImage }}" />
<meta name="twitter:card" content="summary" />
<meta name="twitter:title" content="{{ $pageTitle }}" />
<meta name="twitter:description" content="{{ $pageDescription }}" />
<meta name="twitter:image" content="{{ $pageImage }}" />

<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('favicons/apple-touch-icon.png') }}">
<link rel="icon" href="{{ asset('favicons/favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicons/favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicons/favicon-16x16.png') }}">
<link rel="manifest" href="{{ asset('favicons/site.webmanifest') }}">
{{-- D2-PWA (DEC-07): installable. The service worker stores only the offline
     page, icons and fingerprinted build files (public/sw.js); never a page,
     data, a photo or a file. On the sign-in page, and whenever the signed-in
     user or active role differs from the last one seen on this device, it is
     told to start from a clean cache. --}}
<meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#161617" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-title" content="HopeHR">
<meta name="mobile-web-app-capable" content="yes">
@php($pwaSession = auth()->check() ? hash('sha256', auth()->id().'|'.auth()->user()->myRole().'|'.config('app.key')) : 'signed-out')
{{-- The CSP allows inline scripts only with its nonce (SecurityHeaders). --}}
<script nonce="{{ Vite::cspNonce() }}">
    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register('/sw.js').then(() => navigator.serviceWorker.ready).then((registration) => {
            const session = @js($pwaSession);
            let last = null;
            try { last = localStorage.getItem('hopehr-sw-session'); } catch (e) {}
            if (session === 'signed-out' || last !== session) {
                registration.active?.postMessage('hopehr:clear');
            }
            try { localStorage.setItem('hopehr-sw-session', session); } catch (e) {}
        }).catch(() => {});
    }
</script>

{{-- Preload the critical brand font weights so text paints without a flash.
     Vite::asset() resolves the same fingerprinted URL app.css uses, so the preload is never a wasted second download. --}}
<link rel="preload" href="{{ Vite::asset('resources/fonts/SomarSans/SomarSans-Regular.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ Vite::asset('resources/fonts/SomarSans/SomarSans-Medium.woff2') }}" as="font" type="font/woff2" crossorigin>

{{-- Warm the cdnjs connection before the render-blocking Font Awesome stylesheet asks for it --}}
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css" integrity="sha512-DxV+EoADOkOygM4IR9yXP8Sb2qwgidEmeqAEmDKIOfPRQZOWbXCzLC6vjbZyy0vPisbH2SyW27+ddLVCN+OMzQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />

@yield('css')
@livewireStyles
@vite(['resources/js/app.js'])
