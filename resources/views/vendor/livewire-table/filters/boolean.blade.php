<div class="px-3 py-2">
    <span class="block whitespace-nowrap truncate" title="{{ $filter->label() }}">
        {{ $filter->label() }}
    </span>
    <select class="w-full border border-neutral-200 rounded-md shadow-xs outline-hidden bg-white text-black focus:border-secondary-medium me-auto px-3 py-2 font-normal transition ease-in-out"
            wire:model.live="filters.{{ $filter->code() }}">
        <option value="">&mdash;</option>
        <option value="1">@lang('Yes')</option>
        <option value="0">@lang('No')</option>
    </select>
</div>
