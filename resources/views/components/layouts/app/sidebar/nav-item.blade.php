{{-- Sidebar links swap pages through wire:navigate instead of a full reload.
     Current page: ink text, semibold, on the hover fill (no red mark). --}}
@props(['active' => false, 'favourite' => null])


<li x-show="!sidebarCollapsed || open" class="relative group/item">
    {{-- Not wire:navigate, so the link can be dragged onto the dashboard (single-item.blade.php says why). --}}
    <a x-on:click="if ($event.button === 0 && ! $event.ctrlKey && ! $event.metaKey && ! $event.shiftKey && ! $event.altKey) { $event.preventDefault(); Livewire.navigate($el.href) }" draggable="true" x-on:dragstart="$event.dataTransfer.setData('application/x-hc-page', $el.dataset.nav || '')" @if ($active) aria-current="page" @endif {{ $attributes->merge([
        'class' => 'nav-press group relative flex items-center w-full min-h-11 ps-6 py-2 text-small font-light
         border-s-2 border-line-soft rounded-e-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus ' . ($active
                ? ' text-ink font-semibold bg-hover'
                : ' text-ink-2 hover:text-ink hover:bg-hover')
    ]) }}>

            {{$slot}}


    </a>

    @if ($favourite)
        <x-layouts.app.sidebar.favourite-star :id="$favourite['id']" :label="trim(strip_tags($slot))"
            :starred="$favourite['starred']" :full="$favourite['full']" :max="$favourite['max']" />
    @endif
</li>
