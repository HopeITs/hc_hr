@props([
    'theme' => 'default',
])

@php
    // Deliberately mirrors <x-forms.input-label> so read-only detail views keep
    // their existing look.
    $baseClass = match($theme) {
        'settings' => 'block text-sm mb-3 font-medium text-ink-2',
        default    => 'block mb-1 text-sm font-medium text-ink-2',
    };
@endphp

{{--
    Caption for a read-only value in a detail view.

    This is NOT <x-forms.input-label>: a <label> is only valid when it labels a
    form control, and a label pointing at nothing is announced by screen readers
    as an orphaned control. Static "field: value" pairs use this instead.
--}}
<span {{ $attributes->merge(['class' => $baseClass]) }}>
    {{ $slot }}
</span>
