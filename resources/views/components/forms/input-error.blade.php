@props([
    'field' => null,
    'theme' => 'default',
    'inputId' => null,
])

@php
    use Illuminate\Support\Str;

    $baseClass = match($theme) {
        'settings' => 'text-body text-primary dark:text-primary mt-1',
        default    => 'text-primary text-body mt-1',
    };

    /**
     * The id must match the aria-describedby that <x-forms.input> emits, so it
     * is derived the same way: the caller's explicit id when there is one,
     * otherwise the slugged model path. Mismatched ids break the link silently,
     * which is why both components share this single derivation.
     */
    $errorId = ($inputId ?? Str::slug($field ?? 'input')).'-error';
@endphp

@if(is_string($field))
    @error($field)
        {{--
            role="alert" makes the message announced the moment validation
            fails, rather than only when the user happens to focus the field.
        --}}
        <p id="{{ $errorId }}"
           role="alert"
           {{ $attributes->merge(['class' => $baseClass]) }}>
            <span class="sr-only">{{ __('general.a11y.error-prefix') }}</span>
            {{ $message }}
        </p>
    @enderror
@endif
