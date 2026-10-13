<button
    type="submit"
    {{ $attributes->merge([
        'class' => 'flex items-center gap-2 px-3 py-1 text-white bg-[#E63946] hover:bg-[#d92f3c] rounded-control shadow-e1 font-medium transition-all focus:ring-2 focus:ring-[#E63946] focus:ring-offset-1'
    ]) }}>
    {{ $slot }}
</button>
