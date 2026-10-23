@props([
    'id',
    'wireModel' => null,
    'alpineModel' => null,
    'format' => 'HH:mm',
    'disabled' => false,
    'min' => null,
    'max' => null,
    // Send each change to the server (debounced), e.g. for a live preview.
    'live' => false,
])

@php($pickerId = $id . '_picker')

<input
    id="{{ $id }}"
    type="hidden"
    @if($wireModel && $live) wire:model.live.debounce.400ms="{{ $wireModel }}" @elseif($wireModel) wire:model.defer="{{ $wireModel }}" @endif
    @if($alpineModel) x-model="{{ $alpineModel }}" @endif
/>

<div
    class="relative w-full"
    wire:ignore
    {{-- The <label for> points at the hidden input above; name the picker's own text box by that
         label (input-label gives it the id "{for}-label"). The element builds its input on upgrade. --}}
    x-data
    x-init="let name = (tries = 0) => {
        let box = document.querySelector('#{{ $pickerId }} input');
        if (box) return document.getElementById('{{ $id }}-label') && box.setAttribute('aria-labelledby', '{{ $id }}-label');
        if (tries < 20) setTimeout(() => name(tries + 1), 50);
    }; name()"
    @if($alpineModel)
        x-effect="$nextTick(() => {
            const picker = document.getElementById('{{ $pickerId }}');
            const value = {{ $alpineModel }} || '';
            if (picker && picker.value !== value) picker.value = value;
        })"
    @endif
>
    <clock-timepicker
        id="{{ $pickerId }}"
        data-bind-target="{{ $id }}"
        format="{{ $format }}"
        @if($min) minimum="{{ $min }}" @endif
        @if($max) maximum="{{ $max }}" @endif
        @disabled($disabled)
        class="block w-full min-h-[2.375rem]"
    ></clock-timepicker>

    <div class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-3">
        <x-icons.clock class="size-4 text-secondary-light" />
    </div>
</div>
