@props(['tabs'])

<div class="space-y-2">
    @foreach ($tabs as $tab)
        <a href="{{ route($tab['route']) }}" wire:navigate
           class="block text-center px-4 py-2 rounded-control text-body font-medium transition
           {{ request()->routeIs($tab['route'])
               ? 'bg-neutral-200 text-neutral-900 font-semibold'
               : 'text-neutral-800 hover:bg-neutral-100' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
