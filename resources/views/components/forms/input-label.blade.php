@props([

    'value' => null,
    'theme' => 'default', // 'default' for auth, 'settings' for profile/settings
    'required' => false,  // adds a red asterisk for required fields
])

@php
    $baseClass = match($theme) {
        'settings' => 'block text-body mb-3 font-bold text-ink',
        default    => 'block mb-1.5 text-body font-bold text-ink',
    };
@endphp

{{-- A server-rendered id lets a widget that replaces its control (select2) name itself by this label; one added in JS would be stripped by Livewire's morph. --}}
<label {{ $attributes->merge(array_filter(['class' => $baseClass, 'id' => $attributes->get('for') ? $attributes->get('for').'-label' : null])) }}>
    {{ $value ?? $slot }}
    @if ($required)
        {{--
            The asterisk is decorative — most screen readers either skip a bare
            "*" or read it as "star", neither of which conveys the requirement.
            The visually hidden word is what actually gets announced.
        --}}
        <span class="text-primary ltr:ml-1 rtl:mr-1" aria-hidden="true">*</span>
        <span class="sr-only">({{ __('general.a11y.required-field') }})</span>
    @endif
</label>
