@props(['tabs'])

<div class="space-y-2">
    @foreach ($tabs as $tab)
        <a href="{{ route($tab['route']) }}" wire:navigate
           class="block text-center px-4 py-2 rounded-control text-body font-medium transition
           {{ request()->routeIs($tab['route'])
               ? 'bg-neutral-200 dark:bg-neutral-700 text-neutral-900 dark:text-white font-semibold'
               : 'text-neutral-800 dark:text-secondary-light hover:bg-neutral-100 dark:hover:bg-neutral-700' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
