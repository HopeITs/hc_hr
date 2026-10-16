@if($attributes->has('href'))
    <a {{
    $attributes->merge([
        'wire:navigate' => true,
        'class' => 'cursor-pointer p-2 inline-flex items-center'
    ])
}}>
        <x-icons.eye/>
    </a>
@else

    <button {{
    $attributes->merge([
        'class' => 'cursor-pointer p-2 inline-flex items-center'
    ])
}}>
        <x-icons.eye/>
    </button>
@endif
