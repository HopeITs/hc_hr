@props([
    'id',
    'model', // Alpine x-model target for the checkbox, e.g. 'flexDaysPerWeekOn'
])

{{--
    One bordered, tappable card per flexible-schedule/pay option. Replaces a
    handful of near-identical `border + p-3 + checkbox` divs that used to be
    copy-pasted per option (and each nested a plain <label> around
    <x-forms.input type="checkbox">, which already renders its own <label> —
    two <label> elements wrapping one input isn't valid HTML and confuses
    screen readers about the control's name). The slot is the checkbox's own
    label text; `hint` and `fields` are optional extras that only make sense
    once the box is checked. A card whose box is conditionally required marks
    that inline in its own label slot (the same asterisk <x-forms.input-label
    required> uses elsewhere) rather than through a prop here — a highlighted
    background/border for "required" reads as an error at a glance, which it
    is not.
--}}
<div {{ $attributes->class('hc-choice p-3') }}>
    <x-forms.input type="checkbox" x-model="{{ $model }}" id="{{ $id }}" label-class="font-semibold text-body text-ink">
        {{ $slot }}
    </x-forms.input>

    @isset($hint)
        {{ $hint }}
    @endisset

    @isset($fields)
        {{ $fields }}
    @endisset
</div>
