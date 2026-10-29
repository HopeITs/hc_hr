{{--
    A small square tag beside a status or a title.

    variant: act  (solid red — waits on the viewer)
             late (solid red — overdue)
             rush (red outline — urgent, soon)
             soft (surface fill — neutral facts: "Auto-renew", a category)
    icon: an <x-ic> name; act/late/rush default to alert / flame / clock, soft has none.
    label: the words; the slot is used when no label is given.
--}}
@props(['variant' => 'soft', 'icon' => null, 'label' => null])

@php
    $variant = in_array($variant, ['act', 'late', 'rush', 'soft'], true) ? $variant : 'soft';
    $icon ??= ['act' => 'alert', 'late' => 'flame', 'rush' => 'clock'][$variant] ?? null;
@endphp

<span {{ $attributes->class(['hc-tag', 'hc-tag--'.$variant]) }} data-variant="{{ $variant }}">@if ($icon)<x-ic :name="$icon" />@endif<span>{{ $label ?? $slot }}</span></span>
