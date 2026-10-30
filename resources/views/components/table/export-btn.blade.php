@if($attributes->has('href'))
    <a {{
    $attributes->merge([
        'class' => 'cursor-pointer p-2 text-ink-2 inline-flex items-center max-sm:min-h-11 max-sm:min-w-11 justify-center',
        'aria-label' => __('general.export'),
        'target' => '_blank',
        'title' => __('general.export'),
    ])
}}>
        <x-icons.export/>
    </a>
@else

    <button {{
    $attributes->merge([
        'type' => 'button',
        'class' => 'cursor-pointer text-ink-2 p-2 inline-flex items-center max-sm:min-h-11 max-sm:min-w-11 justify-center',
        'aria-label' => __('general.export'),
        'title' => __('general.export'),
    ])
}}>
        <x-icons.export/>
    </button>
@endif
