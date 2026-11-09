{{-- Every field on this form is required to save a draft (D55); the
     centre is the one exception and passes its own flag. So the star
     defaults to on, and a field that is genuinely optional says so.

     Optional: $rows, $placeholder, $hint. --}}
@php
    $isLocked = in_array($field, $lockedFields ?? [], true);
    $isChangeRequested = ! $isLocked && in_array($field, $requestedFields ?? [], true);
    $hasError = $errors->has('data.'.$field);
@endphp
<div wire:key="field-{{ $field }}" @class([
    'min-w-0 rounded-control transition',
    'border-s-2 border-primary ps-3' => $isChangeRequested,
])>
    <div class="mb-1 flex flex-wrap items-center gap-2">
        <x-forms.input-label :for="$field" :required="$required ?? true">{{ __('recruitment.'.$field) }}</x-forms.input-label>
        @include('livewire.recruitment.requests.partials.field-state', ['isChangeRequested' => $isChangeRequested, 'isLocked' => false])
    </div>
    <x-forms.textarea :id="$field" wire:model="data.{{ $field }}" dir="auto"
        :rows="$rows ?? 3"
        :hint="$hint ?? null"
        :placeholder="$placeholder ?? null"
        :disabled="$isLocked"
        :class="$isLocked ? 'cursor-not-allowed border-secondary-light bg-white text-secondary-dark shadow-none' : ''"
        :aria-describedby="$hasError ? $field.'-error' : null"
        aria-invalid="{{ $hasError ? 'true' : 'false' }}" />
    @include('livewire.recruitment.requests.partials.field-state', ['isChangeRequested' => false, 'isLocked' => $isLocked])
    <x-forms.input-error :field="'data.'.$field" :input-id="$field" />
</div>
