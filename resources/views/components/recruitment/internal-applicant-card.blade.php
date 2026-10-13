@props(['internal'])

{{--
    What an internal (staff-only) vacancy shows above its form: who is
    applying, or the one step that stands between the visitor and applying.
--}}
@php $state = $internal['state']; @endphp

<section class="mt-4 rounded-[16px] border border-secondary-soft bg-white px-6 py-6 shadow-e1 sm:px-9" data-internal-state="{{ $state }}">
    @if ($state === 'ready')
        @php $profile = $internal['profile']; @endphp
        <div class="flex flex-wrap items-start gap-4">
            <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-primary text-subtitle font-bold text-white" aria-hidden="true">
                {{ mb_strtoupper(mb_substr($profile['name'], 0, 1)) }}
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-small font-semibold uppercase tracking-wide text-primary">{{ __('careers.internal.applying_as') }}</p>
                <p class="mt-1 text-subtitle font-bold text-secondary-dark">{{ $profile['name'] }}</p>
                @if ($role = collect([$profile['position'], $profile['center']])->filter()->implode(' · '))
                    <p class="mt-1 text-body text-secondary-medium">{{ $role }}</p>
                @endif
                <p class="mt-1 text-small text-secondary-medium" dir="ltr">{{ $profile['email'] }}</p>
            </div>
        </div>
        <p class="mt-5 border-t border-secondary-soft pt-4 text-body leading-7 text-secondary-dark">{{ __('careers.internal.ready_help') }}</p>
    @elseif ($state === 'applied')
        <h2 class="text-subtitle font-bold text-secondary-dark">{{ __('careers.internal.applied_title') }}</h2>
        <p class="mt-2 text-body leading-7 text-secondary-medium">{{ __('careers.internal.applied_message') }}</p>
    @elseif ($state === 'not_staff')
        <h2 class="text-subtitle font-bold text-secondary-dark">{{ __('careers.internal.not_staff_title') }}</h2>
        <p class="mt-2 text-body leading-7 text-secondary-medium">{{ __('careers.internal.not_staff_message') }}</p>
    @else
        <div class="flex flex-wrap items-center justify-between gap-5">
            <div class="max-w-xl">
                <h2 class="text-subtitle font-bold text-secondary-dark">{{ __('careers.internal.sign_in_title') }}</h2>
                <p class="mt-2 text-body leading-7 text-secondary-medium">{{ __('careers.internal.sign_in_message') }}</p>
            </div>
            <a
                href="{{ $internal['sign_in_url'] }}"
                class="inline-flex cursor-pointer items-center gap-2 rounded-control bg-primary px-6 py-3 font-semibold text-white hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
                data-internal-sign-in
            >
                <svg class="size-5 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3" />
                </svg>
                {{ __('careers.internal.sign_in') }}
            </a>
        </div>
    @endif
</section>
