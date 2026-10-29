@props([
    'disabled' => false,
    'options' => [],
    'invalid' => false,
    'placeholder' => null,
])

<select
    {{ $disabled ? 'disabled' : '' }}
    {!! $attributes->merge([
        'class' => 'hc-field',
    ] + ($invalid ? ['aria-invalid' => 'true'] : [])) !!}
>
    @if ($placeholder)
        <option value="" disabled selected>{{ $placeholder }}</option>
    @endif

    @foreach ($options as $value => $label)
        <option value="{{ $value }}">{{ $label }}</option>
    @endforeach
</select>
