{{-- Every field on this form is required to save a draft (D55); the
     centre is the one exception and passes its own flag. So the star
     defaults to on, and a field that is genuinely optional says so.

     Optional: $label (lang key under recruitment.), $hint, $update
     (defer|live|blur), $min, $max, $step, $inputmode, $placeholder, $dir. --}}
@php
    $isLocked = in_array($field, $lockedFields ?? [], true);
    $isChangeRequested = ! $isLocked && in_array($field, $requestedFields ?? [], true);
@endphp
<div wire:key="field-{{ $field }}" @class([
    'min-w-0 rounded-control transition',
    'border-s-2 border-primary ps-3' => $isChangeRequested,
])>
    <div class="mb-1 flex flex-wrap items-center gap-2">
        <x-forms.input-label :for="$field" :required="$required ?? true">{{ __('recruitment.'.($label ?? $field)) }}</x-forms.input-label>
        @include('livewire.recruitment.requests.partials.field-state', ['isChangeRequested' => $isChangeRequested, 'isLocked' => false])
    </div>
    <x-forms.input :id="$field" :type="$inputType" :model="'data.'.$field" :update="$update ?? 'defer'"
        :hint="$hint ?? null"
        :placeholder="$placeholder ?? ''"
        :min="$min ?? null" :max="$max ?? null" :step="$step ?? null" :inputmode="$inputmode ?? null" :dir="$dir ?? null"
        :disabled="$isLocked"
        @class([
            'num' => in_array($inputType, ['number', 'date'], true),
            'cursor-not-allowed border-secondary-light bg-white text-secondary-dark shadow-none' => $isLocked,
        ]) />
    @include('livewire.recruitment.requests.partials.field-state', ['isChangeRequested' => false, 'isLocked' => $isLocked])
    <x-forms.input-error :field="'data.'.$field" :input-id="$field" />
</div>
