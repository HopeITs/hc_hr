@props(['active' => false, 'activeTab' => 'all', 'action' => 'setActiveTab', 'compact' => false])

<li class="me-2">
    <button type="button"
        {{ $attributes->merge([
            'class' => $active
                ? ($compact ? 'inline-flex h-7 cursor-pointer items-center rounded-control bg-primary px-3 py-0 text-sm text-white' : 'inline-block cursor-pointer rounded-control bg-primary px-4 py-3 text-white')
                : ($compact ? 'inline-flex h-7 cursor-pointer items-center rounded-control px-3 py-0 text-sm hover:bg-neutral-100 hover:text-neutral-900' : 'inline-block cursor-pointer rounded-control px-4 py-3 hover:bg-neutral-100 hover:text-neutral-900'),
        ]) }}
        wire:click="{{ $action }}('{{ $activeTab }}')">
        {{ $slot }}
    </button>
</li>
