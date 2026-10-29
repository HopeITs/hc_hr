@props([
    'id' => null,
    'name' => null,
])

@php
    use Illuminate\Support\Str;

    /**
     * A stable, unique id: the caller's, else one derived from the group and
     * the option value. A shared default id ("btn" until A5) linked every
     * label on the page to the first radio, so clicking "Hour" checked "Day".
     * Deriving it (not randomising it) keeps it identical across Livewire
     * re-renders, so morphing never re-points a label mid-interaction.
     */
    $model = $attributes->wire('model')->value();
    $value = $attributes->get('value');
    $value = $value instanceof \BackedEnum ? $value->value : $value;

    // Radios with one wire:model are one group: a shared name gives the
    // native single tab stop and arrow-key movement between options.
    $name ??= $model ?: null;
    $id ??= 'radio-'.Str::slug(($name ?? 'option').'-'.$value);
@endphp
<div class="flex items-center">
    <input {{$attributes->merge(array_filter([
    'id' => $id,
    'name' => $name,
    'type' => 'radio',
    'class' => 'hc-check focus:ring-0 focus-visible:outline-2 focus-visible:outline-offset-2',
]))}}
    >
    {{-- min-h-11: the label is the touch target, 44px tall (product target). --}}
    <label for="{{$id}}" class="inline-flex min-h-11 cursor-pointer items-center ps-2 pe-1 text-body font-medium text-ink">{{$slot}}</label>
</div>
