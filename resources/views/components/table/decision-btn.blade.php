@if($attributes->has('href'))
    <a {{
    $attributes->merge([
        'wire:navigate' => true,
        'aria-label' => __('general.leave-decision'),
        'class' => 'cursor-pointer p-2 inline-flex items-center max-sm:min-h-11 max-sm:min-w-11 justify-center'
    ])
}}>
        <x-icons.check-plus/>
    </a>
@else

    <button {{
    $attributes->merge([
        'type' => 'button',
        'aria-label' => __('general.leave-decision'),
        'class' => 'cursor-pointer p-2 inline-flex items-center max-sm:min-h-11 max-sm:min-w-11 justify-center'
    ])
}}>
        <x-icons.check-plus/>
    </button>
@endif
