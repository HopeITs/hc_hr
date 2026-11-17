@extends('layouts.public-recruitment')

@section('title', __('careers.submission_error_title'))
@section('meta_description', __('careers.generic_submission_error'))
@section('robots', 'noindex, nofollow')

@section('content')
<main dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}" class="flex min-h-screen items-center justify-center px-4 py-10 sm:px-6">
    <section class="flex min-h-[292px] w-full max-w-[540px] flex-col items-center justify-center rounded-[16px] border border-neutral-200 bg-white p-10 text-center shadow-e1">
        <x-recruitment.public-brand-logo />
        <h1 class="mt-7 text-title font-bold text-neutral-900">{{ __('careers.submission_error_title') }}</h1>
        <p class="mt-1 max-w-md text-body leading-5 text-neutral-600">{{ $submissionError ?? __('careers.generic_submission_error') }}</p>
    </section>
</main>
@endsection
