@if($attributes->has('href'))
    <a {{
    $attributes->merge([
        'wire:navigate' => true,
        'class' => 'cursor-pointer p-2 inline-flex items-center'
    ])
}}>
        <x-icons.check-plus/>
    </a>
@else

    <button {{
    $attributes->merge([
        'class' => 'cursor-pointer p-2 inline-flex items-center'
    ])
}}>
        <x-icons.check-plus/>
    </button>
@endif
