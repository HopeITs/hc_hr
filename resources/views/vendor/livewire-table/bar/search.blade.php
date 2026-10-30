<div class="flex gap-3" data-tour="table.search">
    <input type="search"
        placeholder="@lang('general.search-all-columns')"
        class="w-full md:w-64 border border-neutral-200 shadow-e1 rounded-control outline-hidden focus:border-secondary-medium px-3 py-2 bg-white text-black transition ease-in-out"
        wire:model.live.debounce.500ms="globalSearch">
    @includeWhen($this->canClearSearch(), 'livewire-table::bar.buttons.clear-search')
</div>
