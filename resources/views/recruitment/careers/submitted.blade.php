@extends('layouts.public-recruitment')

@section('title', __('careers.submitted_title'))
@section('meta_description', __('careers.submitted_message'))
@section('robots', 'noindex, nofollow')

@section('content')
<main dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}" class="flex min-h-screen items-center justify-center px-4 py-10 sm:px-6">
    <section class="flex min-h-[292px] w-full max-w-[540px] flex-col items-center rounded-[16px] border border-neutral-200 bg-white p-10 text-center shadow-e1">
        <x-recruitment.public-brand-logo />
        <h1 class="mt-7 text-title font-bold text-neutral-900">{{ __('careers.submitted_title') }}</h1>
        <p class="mt-1 max-w-md text-body leading-5 text-neutral-600">{{ __('careers.submitted_message') }}</p>
        <div class="mt-7 flex size-11 items-center justify-center rounded-full bg-primary text-white" aria-hidden="true">
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6" />
            </svg>
        </div>
    </section>
</main>
@endsection
