<button
    type="submit"
    {{ $attributes->merge([
        'class' => 'flex items-center gap-2 px-3 py-1 text-white bg-primary hover:bg-primary-hover rounded-control shadow-e1 font-medium transition-all focus:ring-2 focus:ring-primary focus:ring-offset-1'
    ]) }}>
    {{ $slot }}
</button>
