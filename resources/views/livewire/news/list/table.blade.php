{{--
    News & Events list: the state tabs, then the package's own table view
    (shared vendor override, untouched; @include passes $paginator, $table...).
    A tab only narrows NewsTable::query() on the server.
--}}
<div class="flex flex-col gap-3">
    @php($tabs = $this->stateTabs())
    @php($counts = $this->stateCounts())
    <div role="group" aria-label="{{ __('news.list.tabs_label') }}"
        class="flex gap-2 overflow-x-auto pb-1 sm:flex-wrap sm:overflow-visible">
        @foreach($tabs as $key => $label)
            @php($current = $this->stateFilter === $key)
            <button type="button" wire:key="news-tab-{{ $key }}" wire:click="setStateFilter('{{ $key }}')"
                aria-pressed="{{ $current ? 'true' : 'false' }}"
                @class([
                    'inline-flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border-[1.5px] px-4 text-small font-semibold',
                    'border-ink bg-surface text-ink' => $current,
                    'border-line bg-card text-ink hover:bg-hover' => ! $current,
                ])>
                <span>{{ $label }}</span>
                <span class="num text-caption text-ink-2">{{ $counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    @include('livewire-table::livewire.livewire-table')
</div>
