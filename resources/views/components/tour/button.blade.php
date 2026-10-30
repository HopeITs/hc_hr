{{--
    "Take a tour" trigger for the navbar. Hidden on pages that have no tour in
    config/tours.php; shows a dot until the user has opened this page's tour.
--}}
<button type="button"
        x-data
        x-cloak
        x-show="$store.tour.available"
        x-on:click="$dispatch('tour-start')"
        data-tour="tour.button"
        {{ $attributes->merge(['class' => 'relative inline-flex items-center justify-center transition rounded-[14px] size-11 sm:size-9 text-ink-3 hover:bg-card hover:text-ink hover:shadow-e1']) }}
        title="{{ __('tour.button') }}"
        aria-label="{{ __('tour.button') }}">
    <svg class="size-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="10"/>
        <path d="m16.24 7.76-2.12 6.36-6.36 2.12 2.12-6.36 6.36-2.12z"/>
    </svg>

    <span x-show="!$store.tour.seen"
          class="absolute top-1.5 end-1.5 size-2 rounded-full bg-primary ring-2 ring-base"
          aria-hidden="true"></span>
</button>
