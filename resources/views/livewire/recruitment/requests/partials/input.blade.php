{{-- Every field on this form is required to save a draft (D55); the
     centre is the one exception and passes its own flag. So the star
     defaults to on, and a field that is genuinely optional says so. --}}
@php
    $isLocked = in_array($field, $lockedFields ?? [], true);
    $isChangeRequested = ! $isLocked && in_array($field, $requestedFields ?? [], true);
@endphp
<div wire:key="field-{{ $field }}" @class([
    'rounded-control transition',
    'border-s-2 border-primary ps-3' => $isChangeRequested,
])>
    <div class="mb-1 flex flex-wrap items-center gap-2">
        <x-forms.input-label :for="$field" :required="$required ?? true">{{ __('recruitment.'.$field) }}</x-forms.input-label>
        @include('livewire.recruitment.requests.partials.field-state', ['isChangeRequested' => $isChangeRequested, 'isLocked' => false])
    </div>
    <x-forms.input :id="$field" :type="$inputType" :model="'data.'.$field"
        :disabled="$isLocked"
        :class="$isLocked ? 'cursor-not-allowed border-secondary-light bg-white text-secondary-dark shadow-none' : ''" />
    @include('livewire.recruitment.requests.partials.field-state', ['isChangeRequested' => false, 'isLocked' => $isLocked])
    <p id="{{ $field }}-error" class="mt-1 text-sm text-primary">@error('data.'.$field){{ $message }}@enderror</p>
</div>
