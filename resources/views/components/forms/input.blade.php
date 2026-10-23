@props([
    'id' => null,
    'type' => 'text',
    'model' => null,
    'placeholder' => '',
    'autocomplete' => '',
    'showIcon' => true,
    'update' => 'defer',
    'required' => false,
    'errorKey' => null,
    'labelClass' => 'text-body text-neutral-800',
])

@php
    use Illuminate\Support\Str;

    // A plain wire:model="..." (instead of the model prop) names the field just
    // as well; without it every such input on a page shared id="input".
    $boundModel = $model ?? ($attributes->wire('model')->value() ?: null);

    $inputId = $id ?? Str::slug($boundModel ?? 'input');
    $wireAttr = $model ? "wire:model.$update" : null;

    /**
     * Wire the field into the accessibility tree.
     *
     * A validation message rendered next to an input is invisible to a screen
     * reader unless the input points at it: aria-describedby makes the message
     * read out when focus lands on the field, and aria-invalid marks the field
     * as failing rather than leaving it announced as ordinary text.
     *
     * The error key defaults to the wire:model path, which is already the key
     * Livewire validates under, so existing call sites gain this for free.
     */
    $errorField = $errorKey ?? $boundModel;
    $hasError = $errorField !== null && $errors->has($errorField);

    $aria = [];

    if ($hasError) {
        $aria['aria-invalid'] = 'true';
        $aria['aria-describedby'] = $inputId.'-error';
    }

    if ($required) {
        // `required` drives native browser validation; aria-required keeps the
        // state exposed to assistive tech even when that validation is bypassed.
        $aria['required'] = true;
        $aria['aria-required'] = 'true';
    }
@endphp

@if ($type === 'checkbox')
    <label for="{{ $inputId }}" class="flex items-center gap-2 {{ $labelClass }}">
        <input
            @php
                $base = [
                    'id' => $inputId,
                    'type' => 'checkbox',
                    'class' => 'size-4 bg-white border border-primary text-primary rounded-inset focus:ring-primary focus:ring-0',
                ];
                if ($wireAttr) { $base[$wireAttr] = $model; }
                $base = array_merge($base, $aria);
            @endphp
            {{ $attributes->merge($base) }}
        >
        {{ $slot }}
    </label>
@else
    @php
        $isTime = $type === 'time';
        $inputClass = 'w-full py-2 text-body text-neutral-900 placeholder-neutral-400 rounded-control border border-neutral-300 bg-white shadow-e1 focus:outline-none focus:ring-2 focus:ring-secondary-light focus:border-primary transition '
            . ($isTime ? 'ps-3 pe-12 time-input-native' : 'px-3');
    @endphp

    <div class="relative w-full">
        <input
            @php
                $base = [
                    'id' => $inputId,
                    'type' => $type,
                    'class' => $inputClass,
                ];
                if ($wireAttr) { $base[$wireAttr] = $model; }
                // `placeholder` is a declared prop, so it is not in $attributes.
                if (filled($placeholder)) { $base['placeholder'] = $placeholder; }
                $base = array_merge($base, $aria);
            @endphp
            {{ $attributes->merge($base) }}
        />

        @if ($isTime)
            <div class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-3">
                <x-icons.clock class="size-4 text-secondary-light" />
            </div>
        @endif
    </div>
@endif
