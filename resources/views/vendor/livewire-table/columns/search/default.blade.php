<div class="px-3 py-1">
    <input type="search"
           class="min-w-full border border-neutral-200 rounded-inset shadow-e1 outline-hidden bg-white text-black focus:border-secondary-medium me-auto px-2 py-1 font-normal transition ease-in-out h-8"
           placeholder="@lang('general.search')"
           wire:model.live.debounce.500ms="search.{{ $column->code() }}">
</div>
