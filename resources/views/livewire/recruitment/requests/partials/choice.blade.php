{{--
    A short closed list (two or three answers) as pressable segments rather
    than a dropdown: every answer is visible at once, one tap picks it.

    The radios stay real inputs inside a fieldset, so arrow keys move between
    them and a screen reader announces the question before each answer. The
    chosen segment is ink + surface (.hc-choice), never red.

    $field, $label (lang key), $options [value => label], optional $live,
    $required (defaults on, like every field here).
--}}
@php
    $isLocked = in_array($field, $lockedFields ?? [], true);
    $isChangeRequested = ! $isLocked && in_array($field, $requestedFields ?? [], true);
    $hasError = $errors->has('data.'.$field);
    $modifier = ($live ?? false) ? 'wire:model.live' : 'wire:model';
@endphp
<fieldset wire:key="field-{{ $field }}" @class([
    'min-w-0 rounded-control transition',
    'border-s-2 border-primary ps-3' => $isChangeRequested,
]) @if ($hasError) aria-describedby="{{ $field }}-error" @endif>
    {{-- The legend stays the fieldset's first child (that is what names the
         group); a "changes requested" tag inside it is announced with it. --}}
    <legend class="mb-1 flex flex-wrap items-center gap-2 text-body font-bold text-ink">
        <span>
            {{ __('recruitment.'.$label) }}
            @if ($required ?? true)
                <span class="text-primary ms-1" aria-hidden="true">*</span>
                <span class="sr-only">({{ __('general.a11y.required-field') }})</span>
            @endif
        </span>
        @include('livewire.recruitment.requests.partials.field-state', ['isChangeRequested' => $isChangeRequested, 'isLocked' => false])
    </legend>
    <div @class(['mt-1.5 grid gap-2', 'grid-cols-2' => count($options) === 2, 'grid-cols-3' => count($options) >= 3])>
        @foreach ($options as $value => $option)
            <label @class(['min-w-0', 'cursor-pointer' => ! $isLocked, 'cursor-not-allowed' => $isLocked])>
                <input type="radio" class="peer sr-only" id="{{ $field }}-{{ $value }}" name="data_{{ $field }}"
                    value="{{ $value }}" {{ $modifier }}="data.{{ $field }}"
                    @checked((string) ($data[$field] ?? '') === (string) $value)
                    @disabled($isLocked)
                    @if ($hasError) aria-invalid="true" @endif>
                <span class="hc-choice flex min-h-11 items-center justify-center px-3 py-2 text-center text-body text-ink peer-disabled:opacity-70">
                    <span class="truncate font-semibold">{{ $option }}</span>
                </span>
            </label>
        @endforeach
    </div>
    @include('livewire.recruitment.requests.partials.field-state', ['isChangeRequested' => false, 'isLocked' => $isLocked])
    <x-forms.input-error :field="'data.'.$field" :input-id="$field" />
</fieldset>
