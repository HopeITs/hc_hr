@props([
    'legend',
    'error' => null,   // validation key, e.g. "form.type"
    'required' => false,
])

@php
    use Illuminate\Support\Str;

    $errorId = $error ? 'radio-group-'.Str::slug($error).'-error' : null;
    $hasError = $error !== null && $errors->has($error);
@endphp

{{--
    A set of <x-forms.radio-input> options under one name. A fieldset/legend is
    the native way to give the group its question ("Leave type"), which a
    loose <label> without a control cannot do: it is announced on entering the
    group, before each option's own name.
--}}
<fieldset {{ $attributes->merge(['class' => 'min-w-0']) }}
          @if($hasError) aria-describedby="{{ $errorId }}" @endif>
    <legend class="mb-1 block text-body font-medium text-secondary-medium">
        {{ $legend }}
        @if ($required)
            <span class="text-primary ltr:ml-1 rtl:mr-1" aria-hidden="true">*</span>
            <span class="sr-only">({{ __('general.a11y.required-field') }})</span>
        @endif
    </legend>

    <div class="flex flex-wrap gap-x-4">
        {{ $slot }}
    </div>

    @if ($error)
        <x-error-span id="{{ $errorId }}">{{ $errors->first($error) }}</x-error-span>
    @endif
</fieldset>
