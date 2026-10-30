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
    'labelClass' => 'text-body text-ink',
    // Quiet hint under the field (x-forms.hint), linked by aria-describedby.
    'hint' => null,
    // false: the hint shows only while the field has focus.
    'hintAlways' => true,
    // Typed examples in the empty field's placeholder (resources/js/typewriter.js):
    // a list of strings, or a lang key that resolves to one ('examples.phone').
    'examples' => null,
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
    $hintId = filled($hint) ? $inputId.'-hint' : null;

    // Hint first, then the error: read in that order. A caller's own
    // aria-describedby is kept and extended rather than replaced.
    $describedBy = array_filter([
        $attributes->get('aria-describedby'),
        $hintId,
        $hasError ? $inputId.'-error' : null,
    ]);

    if ($hasError) {
        $aria['aria-invalid'] = 'true';
    }

    if ($describedBy !== []) {
        $aria['aria-describedby'] = implode(' ', $describedBy);
    }

    $exampleList = is_string($examples) ? __($examples) : $examples;
    $exampleList = is_array($exampleList)
        ? array_values(array_filter($exampleList, fn ($example) => is_string($example) && trim($example) !== ''))
        : [];

    if ($required) {
        // `required` drives native browser validation; aria-required keeps the
        // state exposed to assistive tech even when that validation is bypassed.
        $aria['required'] = true;
        $aria['aria-required'] = 'true';
    }
@endphp

@if ($type === 'checkbox')
    <label for="{{ $inputId }}" class="flex min-h-11 cursor-pointer items-center gap-2.5 {{ $labelClass }}">
        <input
            @php
                $base = [
                    'id' => $inputId,
                    'type' => 'checkbox',
                    'class' => 'hc-check rounded-inset',
                ];
                if ($wireAttr) { $base[$wireAttr] = $model; }
                $base = array_merge($base, $aria);
            @endphp
            {{ $attributes->except('aria-describedby')->merge($base) }}
        >
        {{ $slot }}
    </label>
@else
    @php
        $isTime = $type === 'time';
        $inputClass = 'hc-field '
            . ($isTime ? 'pe-12 time-input-native' : '');
    @endphp

    @if ($hintId)
        <div class="group/field w-full">
    @endif
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
                if ($exampleList !== []) {
                    // Without JS, or with reduced motion, the first example stands still.
                    $base['placeholder'] ??= $exampleList[0];
                    $base['data-typewriter'] = json_encode($exampleList, JSON_UNESCAPED_UNICODE);
                }
                $base = array_merge($base, $aria);
            @endphp
            {{ $attributes->except('aria-describedby')->merge($base) }}
        />

        @if ($isTime)
            <div class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-3">
                <x-icons.clock class="size-4 text-ink-3" />
            </div>
        @endif
    </div>
    @if ($hintId)
            <x-forms.hint :id="$hintId" :always="$hintAlways">{{ $hint }}</x-forms.hint>
        </div>
    @endif
@endif
