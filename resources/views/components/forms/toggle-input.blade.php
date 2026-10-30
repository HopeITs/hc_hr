@props([
    'active' => false,
    'wireEvent' => null,
    'modelId' => null,
])

<input
    {{ $attributes->merge([
        'type' => 'checkbox',
        'role' => 'switch',
        'class' => 'hc-check rounded-inset'
    ]) }}
    @checked((bool) $active)
    @if($wireEvent && $modelId)
        wire:click="{{ $wireEvent }}({{ $modelId }})"
    @endif
/>
