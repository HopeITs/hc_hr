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
            decorative icon font, which gives a screen reader nothing to
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
            <i id="{{ $inputId }}_icon" aria-hidden="true" class="fa-regular fa-eye-slash text-body pointer-events-none"></i>
        </button>
    </div>

    {{-- Error --}}
    @if ($hasModel)
        <x-forms.input-error :field="$errorField" :input-id="$inputId" />
    @endif
</div>
