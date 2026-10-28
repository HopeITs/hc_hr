@props([
    'legend',
    'error' => null,   // validation key, e.g. "form.type"
    'required' => false,
    'help' => null,    // D3: a HelpTopics key; adds a "?" beside the legend
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
    {{-- With help, the legend floats so the "?" can sit beside it while staying
         outside it (inside, the button's name would join the group's name). --}}
    <legend class="mb-1 {{ $help ? 'float-start' : 'block' }} text-body font-medium text-secondary-medium">
        {{ $legend }}
        @if ($required)
            <span class="text-primary ltr:ml-1 rtl:mr-1" aria-hidden="true">*</span>
            <span class="sr-only">({{ __('general.a11y.required-field') }})</span>
        @endif
    </legend>
    @if ($help)
        <x-help.trigger :topic="$help" :label="$legend" class="ms-1"/>
        <div class="clear-both"></div>
    @endif

    <div class="flex flex-wrap gap-x-4">
        {{ $slot }}
    </div>

    @if ($error)
        <x-error-span id="{{ $errorId }}">{{ $errors->first($error) }}</x-error-span>
    @endif
</fieldset>
