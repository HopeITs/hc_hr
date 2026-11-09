<x-slot:title>
    {{ __('general.basic-settings') }}
</x-slot:title>

<div>
    <livewire:layout.breadcrumbs :title="__('general.basic-settings')" :crumbs="[
        ['label' => __('general.basic-settings'), 'route' => 'basic-settings.index'],
    ]" />
    <p class="-mt-2 mb-4 max-w-2xl text-small text-ink-2">@lang('general.basic-settings-description')</p>

    {{-- One scrollable row on every width; the active tab is scrolled into view. --}}
    <div class="-mx-4 mb-6 overflow-x-auto overflow-y-hidden border-b border-line-soft px-4 [scrollbar-width:thin] sm:mx-0 sm:px-0"
        x-data
        x-init="$nextTick(() => $el.querySelector('[aria-selected=true]')?.scrollIntoView({ inline: 'center', block: 'nearest' }))">
        <div role="tablist" aria-label="{{ __('general.basic-settings') }}" class="flex min-w-max gap-1">
            @foreach ($tabs as $key => $label)
                <button type="button" role="tab" wire:key="tab-{{ $key }}" wire:click="setTab('{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class([
                        'relative -mb-px inline-flex min-h-11 cursor-pointer items-center whitespace-nowrap border-b-2 px-3 py-2 text-small font-medium',
                        'border-primary text-ink' => $tab === $key,
                        'border-transparent text-ink-2 hover:bg-hover hover:text-ink' => $tab !== $key,
                    ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div role="tabpanel" wire:loading.class="opacity-60" wire:target="setTab">
        <div wire:key="panel-{{ $tab }}">
            @livewire($component, ['inSettings' => true], $tab)
        </div>
    </div>
</div>
