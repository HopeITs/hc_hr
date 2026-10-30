<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    @include('partials.head', ['title' => $title ?? config('app.name', 'Hope HR')])
    @stack('styles')
</head>
<body class="min-h-screen bg-base antialiased">
    {{ $slot }}
</body>
</html>
