<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{app()->getLocale() === 'en' ? 'ltr' : 'rtl'}}">
<head>
    @include('partials.head')
</head>
<body class="font-SomarSans h-full bg-surface">

<main class=" flex items-center justify-center px-4">

    {{-- max-w-sm for every sign-in page; the login page asks for 20% more (data-auth-wide):
         a 422px card instead of 352px, with the page's 16px sides. --}}
    <div class="flex w-full max-w-sm flex-col gap-6 has-[[data-auth-wide]]:max-w-[28.4rem]">
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
