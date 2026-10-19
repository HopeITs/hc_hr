<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
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
