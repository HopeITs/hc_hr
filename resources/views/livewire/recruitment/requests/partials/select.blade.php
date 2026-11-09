{{--
    A picker on the request form.

    Every field on this form is required to save a draft (D55); the centre is
    the one exception and passes its own flag, so the star defaults to on.

    The control is the searchable select the rest of the application uses. A
    plain dropdown of two hundred positions is a scroll, not a choice — and a
    form that looks different from every other form in the system reads as a
    different system.

    A locked field is shown and disabled rather than hidden: after an approver
    asks for changes, only what they named may be edited, and a field that
    silently vanished would leave the requester wondering what happened to it.

    Optional: $live (true when another part of the form reacts to the answer;
    otherwise the value travels with the next request), $hint.
--}}
@php
    $isLocked = in_array($field, $lockedFields ?? [], true);
    $isChangeRequested = ! $isLocked && in_array($field, $requestedFields ?? [], true);

    // The searchable select keeps its own DOM under wire:ignore, so Livewire
    // never rewrites the options inside it. Lists that depend on another
    // answer — centres on the chosen city, vacancies on the chosen post —
    // would go on offering the old ones for ever. Folding the options into the
    // key makes Livewire replace the whole control when they change, which
    // re-initialises the select against the new list.
    $optionsKey = md5(json_encode(array_keys($options)));
    $hasError = $errors->has('data.'.$field);
    $describedBy = implode(' ', array_filter([
        filled($hint ?? null) ? $field.'-hint' : null,
        $hasError ? $field.'-error' : null,
    ]));
    // The binding is spread as an attribute bag because its name changes with
    // $live, and Blade only spreads a bag called $attributes into a component.
    $attributes = new \Illuminate\View\ComponentAttributeBag([
        (($live ?? false) ? 'wire:model.live' : 'wire:model') => 'data.'.$field,
    ]);
@endphp
<div wire:key="field-{{ $field }}-{{ $optionsKey }}" @class([
    'min-w-0 rounded-control transition',
    'request-field--locked' => $isLocked,
    'border-s-2 border-primary ps-3' => $isChangeRequested,
])>
    <div class="mb-1 flex flex-wrap items-center gap-2">
        <x-forms.input-label :for="$field" :required="$required ?? true">{{ __('recruitment.'.$label) }}</x-forms.input-label>
        @include('livewire.recruitment.requests.partials.field-state', ['isChangeRequested' => $isChangeRequested, 'isLocked' => false])
    </div>

    <x-forms.select2-input
        :id="$field"
        {{ $attributes }}
        :disabled="$isLocked"
        aria-invalid="{{ $hasError ? 'true' : 'false' }}"
        :aria-describedby="$describedBy ?: null">
        <option value="">{{ __('recruitment.choose') }}</option>
        @foreach ($options as $value => $option)
            <option value="{{ $value }}">{{ $option }}</option>
        @endforeach
    </x-forms.select2-input>

    @if (filled($hint ?? null))
        <x-forms.hint :id="$field.'-hint'">{{ $hint }}</x-forms.hint>
    @endif
    @include('livewire.recruitment.requests.partials.field-state', ['isChangeRequested' => false, 'isLocked' => $isLocked])
    <x-forms.input-error :field="'data.'.$field" :input-id="$field" />
</div>
