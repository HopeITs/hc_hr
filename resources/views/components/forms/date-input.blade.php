@php
    $type = $attributes->get('type', 'date');
    $isTime = $type === 'time';
    $inputClass = 'hc-field '
        . ($isTime ? 'pe-12 time-input-native' : '');
@endphp

@if ($isTime)
    <div class="relative w-full">
        <input {{ $attributes->merge(['type' => 'time', 'class' => $inputClass]) }} />
        <div class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-3">
            <x-icons.clock class="size-4 text-ink-3" />
        </div>
    </div>
@else
    <input {{ $attributes->merge(['type' => 'date', 'class' => $inputClass]) }} />
@endif

