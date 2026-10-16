{{-- Internal links swap pages via wire:navigate; pass :wire:navigate="false" to opt out. --}}
<a

    wire:loading.attr="disabled"
    {{ $attributes->merge([
        'class' =>
            'w-full py-2 px-4 text-body font-semibold text-white bg-primary cursor-pointer rounded-control shadow-e1 transition duration-150 ease-in-out flex justify-center items-center',
        'wire:navigate' => true,
    ]) }}
>
    {{ $slot }}
    </a>
