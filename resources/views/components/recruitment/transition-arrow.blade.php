{{--
    The arrow between two states, as in "Shortlisted → Rejected". A Unicode
    arrow does not mirror in Arabic, where it would point from the new state
    back to the old one; this one flips with the text direction, and a screen
    reader hears the word instead of "rightwards arrow".
--}}
<svg {{ $attributes->merge(['class' => 'inline-block size-3.5 align-[-0.125em] rtl:-scale-x-100']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg><span class="sr-only">{{ __('recruitment-applicant.transition_to') }}</span>
