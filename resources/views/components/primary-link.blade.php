{{-- Internal links swap pages via wire:navigate; pass :wire:navigate="false" to opt out. --}}
<a
    {{ $attributes->merge([
        'class' => 'hc-btn hc-btn--primary flex w-full',
        'wire:navigate' => true,
    ]) }}
>
    {{ $slot }}
</a>
