@props(['active' => false, 'activeTab' => 'all', 'action' => 'setActiveTab', 'compact' => false])

<li class="min-w-0">
    <button type="button"
        aria-pressed="{{ $active ? 'true' : 'false' }}"
        {{ $attributes->merge([
            'class' => ($compact
                ? 'inline-flex h-7 cursor-pointer items-center rounded-control px-3 py-0 text-sm '
                : 'inline-flex min-h-11 w-full cursor-pointer items-center justify-center break-words rounded-control px-4 py-2 sm:w-auto ')
                .($active ? 'bg-primary text-on-brand' : 'hover:bg-hover hover:text-ink'),
        ]) }}
        wire:click="{{ $action }}('{{ $activeTab }}')">
        {{ $slot }}
    </button>
</li>
