@if($attributes->has('href'))
    <a {{
    $attributes->merge([
        'wire:navigate' => true,
        'class' => 'cursor-pointer p-2 text-secondary-medium inline-flex items-center'
    ])
}}>
        <x-icons.edit/>
    </a>
@else

    <button {{
    $attributes->merge([
        'class' => 'cursor-pointer text-secondary-medium p-2 inline-flex items-center'
    ])
}}>
        <x-icons.edit/>
    </button>
@endif
