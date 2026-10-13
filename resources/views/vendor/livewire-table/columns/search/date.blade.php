<div class="px-3 py-1">
    <input type="date"
           class="min-w-full border border-neutral-200 rounded-inset shadow-e1 outline-hidden bg-white text-black focus:border-secondary-medium me-auto px-2 py-1 font-normal transition ease-in-out dark:bg-neutral-900 dark:border-neutral-700 dark:focus:border-neutral-600 dark:text-white h-8"
           wire:model.live.debounce.500ms="search.{{ $column->code() }}">
</div>
