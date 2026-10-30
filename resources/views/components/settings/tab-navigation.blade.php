@props(['tabs'])

<div class="space-y-2">
    @foreach ($tabs as $tab)
        <a href="{{ route($tab['route']) }}" wire:navigate
           class="block text-center px-4 py-2 rounded-control text-body font-medium transition
           {{ request()->routeIs($tab['route'])
               ? 'bg-surface text-ink font-semibold'
               : 'text-ink hover:bg-hover' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
