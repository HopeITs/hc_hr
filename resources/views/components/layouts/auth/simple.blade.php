<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{app()->getLocale() === 'en' ? 'ltr' : 'rtl'}}">
<head>
    @include('partials.head')
</head>
<body class="font-SomarSans h-full bg-surface">

<main class=" flex items-center justify-center px-4">

    <div class="flex w-full max-w-sm flex-col gap-6">
        <header>
            <a href="{{ route('dashboard') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
            </a>
        </header>

        <div>
            {{ $slot }}
        </div>
    </div>

</main>

<x-layouts.app.footer  class="absolute bottom-0"/>

{{--@fluxScripts--}}
</body>
</html>
