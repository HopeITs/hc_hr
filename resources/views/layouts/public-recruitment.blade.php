<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    @php
        $publicPageTitle = trim($__env->yieldContent('title', __('careers.title')));
        $publicDescription = \Illuminate\Support\Str::limit(
            trim(strip_tags($__env->yieldContent('meta_description', __('careers.meta_description')))),
            160,
        );
        $publicRobots = trim($__env->yieldContent('robots', 'noindex, nofollow'));
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $publicDescription }}">
    <meta name="robots" content="{{ $publicRobots }}">
    <title>{{ $publicPageTitle }} - {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicons/favicon.ico') }}" sizes="any">
    <link rel="preload" href="{{ asset('fonts/SomarSans/SomarSans-Regular.otf') }}" as="font" type="font/otf" crossorigin>
    <link rel="preload" href="{{ asset('fonts/SomarSans/SOMARSANS-MEDIUM.OTF') }}" as="font" type="font/otf" crossorigin>
    @vite(['resources/js/app.js'])
</head>
<body class="min-h-screen bg-neutral-50 font-SomarSans text-neutral-900 antialiased">
    @yield('content')
    @stack('scripts')
</body>
</html>
