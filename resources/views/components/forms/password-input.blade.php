@props([
    'id' => null,
    'label' => '',
    'model' => null,
    'placeholder' => '',
    'autocomplete' => 'new-password',
    'required' => false,
    'errorKey' => null,
])

@php
    use Illuminate\Support\Str;

    $inputId = $id ?? Str::slug($model ?? 'password');
    $hasModel = !empty($attributes->wire('model'));

    // Mirrors <x-forms.input>: point the field at its message so the failure is
    // announced on focus rather than only painted underneath.
    $errorField = $errorKey ?? ($attributes->wire('model')->value() ?: $model);
    $hasError = $errorField && $errors->has($errorField);
@endphp

<div class="space-y-1">

    {{-- Label --}}
    @if ($label)
        <x-forms.input-label for="{{ $inputId }}" :value="$label" :required="$required" />
    @endif

    {{-- Input + Toggle --}}
    <div class="relative">
        <input
            id="{{ $inputId }}"
            type="password"
            placeholder="{{ $placeholder }}"
            autocomplete="{{ $autocomplete }}"
            @if ($required) required aria-required="true" @endif
            @if ($hasError) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif
            {{ $hasModel ? $attributes->merge(['wire:model' => $model]) : $attributes }}
            class="hc-field pe-11"
        />

        {{--
            Toggle Button using data attribute.

            aria-label is required here: the button's only content is a
            decorative icon, which gives a screen reader nothing to
            announce. aria-controls ties it to the field it acts on.
        --}}
        <button
            type="button"
            tabindex="-1"
            data-password-toggle="{{ $inputId }}"
            aria-label="{{ __('general.a11y.toggle-password') }}"
            aria-controls="{{ $inputId }}"
            class="absolute inset-y-0 end-0 flex w-11 cursor-pointer items-center justify-center text-ink-3 hover:text-ink-2"
        >
            <svg data-password-icon="hidden" class="size-5 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.4 5.5A9.5 9.5 0 0 1 12 5c5 0 8.5 4.5 9.5 7a13 13 0 0 1-2.8 3.9M6.2 6.9A13 13 0 0 0 2.5 12c1 2.5 4.5 7 9.5 7a9.6 9.6 0 0 0 4.4-1.1"/>
            </svg>
            <svg data-password-icon="shown" class="hidden size-5 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <path d="M2.5 12c1-2.5 4.5-7 9.5-7s8.5 4.5 9.5 7c-1 2.5-4.5 7-9.5 7s-8.5-4.5-9.5-7z"/>
                <circle cx="12" cy="12" r="3"/>
            </svg>
        </button>
    </div>

    {{-- Error --}}
    @if ($hasModel)
        <x-forms.input-error :field="$errorField" :input-id="$inputId" />
    @endif
</div>
