@props([
    // Quiet hint under the field (x-forms.hint), linked by aria-describedby.
    'hint' => null,
    // false: the hint shows only while the field has focus.
    'hintAlways' => true,
    // Typed examples in the empty field's placeholder (resources/js/typewriter.js):
    // a list of strings, or a lang key that resolves to one ('examples.news.description').
    'examples' => null,
])

@php
    use Illuminate\Support\Str;

    $boundModel = $attributes->wire('model')->value() ?: null;
    $fieldId = $attributes->get('id') ?? ($boundModel ? Str::slug($boundModel) : null);
    $hintId = filled($hint) && $fieldId ? $fieldId.'-hint' : null;

    $extra = [];

    if ($hintId) {
        // The id is needed for the hint link; a caller's aria-describedby is extended.
        $extra['id'] = $fieldId;
        $extra['aria-describedby'] = implode(' ', array_filter([$attributes->get('aria-describedby'), $hintId]));
    }

    $exampleList = is_string($examples) ? __($examples) : $examples;
    $exampleList = is_array($exampleList)
        ? array_values(array_filter($exampleList, fn ($example) => is_string($example) && trim($example) !== ''))
        : [];

    if ($exampleList !== []) {
        // Without JS, or with reduced motion, the first example stands still.
        $extra['placeholder'] = $attributes->get('placeholder') ?? $exampleList[0];
        $extra['data-typewriter'] = json_encode($exampleList, JSON_UNESCAPED_UNICODE);
    }
@endphp

@if ($hintId)
<div class="group/field w-full">
@endif
<textarea
    {{ $attributes->except(array_keys($extra))->merge(array_merge([
    'class' => 'hc-field',
    'rows' => '3'
], $extra)) }}
    >{{$slot}}
</textarea>
@if ($hintId)
    <x-forms.hint :id="$hintId" :always="$hintAlways">{{ $hint }}</x-forms.hint>
</div>
@endif
