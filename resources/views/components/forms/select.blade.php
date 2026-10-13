@props([
    'disabled' => false,
    'options' => [],
    'invalid' => false,
    'placeholder' => null,
])

<select
    {{ $disabled ? 'disabled' : '' }}
    {!! $attributes->merge([
        'class' => 'border-neutral-300 focus:border-primary focus:ring-secondary-light rounded-control shadow-e1 w-full ' .
                    ($invalid ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : '')
    ]) !!}
>
    @if ($placeholder)
        <option value="" disabled selected>{{ $placeholder }}</option>
    @endif

    @foreach ($options as $value => $label)
        <option value="{{ $value }}">{{ $label }}</option>
    @endforeach
</select>
