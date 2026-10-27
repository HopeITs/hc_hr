{{--
    The links of one sidebar area (App\Support\Navigation): a single link, or a
    dropdown when the item has children. data-nav carries the stable menu id
    (C0 §7); data-tour keeps the guided tour's existing targets.
--}}
@foreach ($items as $item)
    @if (isset($item['children']))
        <x-layouts.app.sidebar.dropdown-nav-list
            :id="str_replace('.', '-', $item['id'])"
            :title="$item['label']"
            :active="collect($item['children'])->contains(fn ($child) => str((string) $currentRoute)->is($child['active']))"
            data-tour="{{ $item['tour'] ?? '' }}">
            <x-slot name="icon">
                <x-dynamic-component :component="'icons.'.$item['icon']" class="size-4" />
            </x-slot>
            <x-slot name="list">
                @foreach ($item['children'] as $child)
                    <x-layouts.app.sidebar.nav-item :href="route($child['route'])" :active="str((string) $currentRoute)->is($child['active'])" data-nav="{{ $child['id'] }}">
                        {{ $child['label'] }}
                    </x-layouts.app.sidebar.nav-item>
                @endforeach
            </x-slot>
        </x-layouts.app.sidebar.dropdown-nav-list>
    @else
        <x-layouts.app.sidebar.single-item :href="route($item['route'])" :active="str((string) $currentRoute)->is($item['active'])"
            data-nav="{{ $item['id'] }}" data-tour="{{ $item['tour'] ?? '' }}">
            <x-slot name="icon">
                <x-dynamic-component :component="'icons.'.$item['icon']" class="size-4" />
            </x-slot>
            {{ $item['label'] }}
            @if ($item['counter'])
                @livewire($item['counter'], key($item['id'].'-counter'))
            @endif
        </x-layouts.app.sidebar.single-item>
    @endif
@endforeach
