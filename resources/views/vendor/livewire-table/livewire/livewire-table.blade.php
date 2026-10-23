@php($cardView = method_exists($this, 'cardView') ? $this->cardView() : null)
<div class="flex flex-col gap-3 relative"
    wire:init="init"
    {{-- The Clear filters button disappears with the empty state; hand focus to the search box so keyboard users keep their place. --}}
    x-on:list-filters-cleared="$nextTick(() => $el.querySelector('input[type=search]')?.focus())"
    @if(strlen($polling = $this->polling()) > 0) wire:poll.{{ $polling }} @endif
>
    @include('livewire-table::bar.bar')

    @if(method_exists($this, 'activeFilterChips'))
        {{-- RemembersTableState: removable chips, Clear all and the reset notice. --}}
        @php($chips = $this->activeFilterChips())
        @if($this->stateReset)
            <div role="status" class="flex items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                <span>{{ __('general.state-notice-filter-cleared') }}</span>
                <button type="button" wire:click="dismissStateNotice" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg px-3 font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('general.state-dismiss') }}</button>
            </div>
        @endif
        @if($chips !== [] || $this->canClearAll())
            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('general.state-active-filters') }}">
                @foreach($chips as $chip)
                    <span wire:key="chip-{{ $chip['group'] }}-{{ $chip['key'] }}" class="inline-flex min-h-11 max-w-full items-center gap-1 rounded-full border border-[var(--line-hairline)] bg-white ps-3 text-sm">
                        <span class="min-w-0 truncate"><span class="text-neutral-600">{{ $chip['label'] }}:</span> <bdi dir="auto" class="font-medium">{{ $chip['value'] }}</bdi></span>
                        <button type="button" wire:click="removeChip('{{ $chip['group'] }}', '{{ $chip['key'] }}')"
                            aria-label="{{ __('general.state-remove-filter', ['filter' => $chip['label'].': '.$chip['value']]) }}"
                            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </span>
                @endforeach
                @if($this->canClearAll())
                    <button type="button" wire:click="clearAll" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg px-3 text-sm font-semibold text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('general.state-clear-all') }}</button>
                @endif
            </div>
        @endif
    @endif

    @if(method_exists($this, 'stateAnnouncement'))
        {{-- Polite announcement of the result count / state after every search, filter or page change;
             wire:loading text covers the in-between so the change is never silent. --}}
        <p class="sr-only" role="status" aria-live="polite" aria-atomic="true">
            <span wire:loading.remove>{{ $this->stateAnnouncement($paginator->total()) }}</span>
            <span wire:loading>{{ __('general.loading') }}</span>
        </p>
    @endif

    @if($cardView)
        {{-- Phones: a list of cards instead of a table (nothing scrolls sideways). --}}
        {{-- The per-column search lives in the table header, which phones do not show: offer the select filters here. --}}
        @php($phoneFilters = $this->resolveColumns()->filter(fn ($c) => $c instanceof \RamonRietdijk\LivewireTables\Columns\SelectColumn && $c->isSearchable() && in_array($c->code(), $this->columns)))
        @if($phoneFilters->isNotEmpty())
            {{-- M1: collapsed by default, so the first result is on the first screen; the state lives in Alpine and survives Livewire morphs. --}}
            <div class="md:hidden" x-data="{ filtersOpen: false }">
                <button type="button" x-on:click="filtersOpen = ! filtersOpen" x-bind:aria-expanded="filtersOpen.toString()" aria-controls="phone-filters-{{ $this->getId() }}"
                    class="inline-flex min-h-11 w-full items-center justify-between rounded-control border border-neutral-300 bg-white px-4 text-sm font-semibold text-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    <span>{{ __('Filters') }}</span>
                    <svg class="size-5 transition-transform motion-reduce:transition-none" x-bind:class="filtersOpen && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                </button>
                <div id="phone-filters-{{ $this->getId() }}" x-show="filtersOpen" x-cloak class="mt-2 flex flex-col gap-2">
                    @foreach($phoneFilters as $column)
                        <div wire:key="phone-filter-{{ $column->code() }}" class="rounded-surface border border-[var(--line-hairline)] bg-white">
                            <span class="block px-3 pt-2 text-small font-semibold text-secondary-medium">{{ $column->label() }}</span>
                            {{ $column->renderSearch() }}
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="md:hidden"
            @if(method_exists($this, 'stateAnnouncement')) wire:loading.class="opacity-60" wire:target="globalSearch,search,filters,perPage,clearAll,removeChip,clearAllFilters,retryLoad,gotoPage,previousPage,nextPage,setPage" @endif>
            @if($paginator->count() > 0)
                <ul class="flex flex-col gap-3">
                    @foreach($paginator->items() as $item)
                        <li wire:key="card-{{ $item->getKey() }}" class="min-w-0 break-words rounded-surface border border-[var(--line-hairline)] bg-white p-4 shadow-e1">
                            @include($cardView, ['item' => $item, 'link' => $this->link($item), 'table' => $this])
                        </li>
                    @endforeach
                </ul>
            @elseif(! ($this->deferLoading && ! $this->initialized))
                <div class="rounded-surface border border-[var(--line-hairline)] bg-white p-4">
                    @if(method_exists($this, 'renderEmptyState')) {!! $this->renderEmptyState() !!} @else <p class="py-12 text-center">@lang('No results')</p> @endif
                </div>
            @endif
        </div>
    @endif
    <div
            @if(method_exists($this, 'stateAnnouncement')) wire:loading.class="opacity-60" wire:target="globalSearch,search,filters,perPage,clearAll,removeChip,clearAllFilters,retryLoad,gotoPage,previousPage,nextPage,setPage" @endif
            @class([
                'overflow-x-auto bg-white border border-[var(--line-hairline)] shadow-e1 rounded-surface overscroll-x-none',
                'hidden md:block' => $cardView,
            ])>
        @include('livewire-table::table.table')
    </div>
    {{ $paginator->links('livewire-table::pagination.pagination') }}


</div>
