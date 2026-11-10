@props(['active' => false, 'activeTab' => 'all', 'action' => 'setActiveTab', 'compact' => false])

<li class="me-2">
    <button type="button"
        {{ $attributes->merge([
            'class' => $active
                ? ($compact ? 'inline-flex h-7 cursor-pointer items-center rounded-control bg-primary px-3 py-0 text-sm text-on-brand' : 'inline-block cursor-pointer rounded-control bg-primary px-4 py-3 text-on-brand')
                : ($compact ? 'inline-flex h-7 cursor-pointer items-center rounded-control px-3 py-0 text-sm hover:bg-hover hover:text-ink' : 'inline-block cursor-pointer rounded-control px-4 py-3 hover:bg-hover hover:text-ink'),
        ]) }}
        wire:click="{{ $action }}('{{ $activeTab }}')">
        {{ $slot }}
    </button>
</li>
