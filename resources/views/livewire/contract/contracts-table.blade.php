{{--
    Wraps the package's own table view with the named-view (preset) bar, so the
    vendor override stays shared and untouched. Everything the package view
    needs ($paginator, $table, ...) is passed straight through by @include.
    A preset only narrows what Contract::visibleTo() allows (server-side).
--}}
<div class="flex flex-col gap-3">
    @php($presets = $this->presetOptions())
    @if(count($presets) > 1)
        <div role="group" aria-label="{{ __('contracts.views-label') }}" class="flex flex-wrap gap-2" data-tour="list.views">
            @foreach($presets as $key => $label)
                <button type="button" wire:key="preset-{{ $key }}" wire:click="setPreset('{{ $key }}')"
                    aria-pressed="{{ $this->preset === $key ? 'true' : 'false' }}"
                    @class([
                        'inline-flex min-h-11 items-center rounded-control border-[1.5px] px-4 text-small font-semibold',
                        'border-ink bg-surface text-ink' => $this->preset === $key,
                        'border-line bg-card text-ink hover:bg-hover' => $this->preset !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>
    @endif

    @include('livewire-table::livewire.livewire-table')
</div>
