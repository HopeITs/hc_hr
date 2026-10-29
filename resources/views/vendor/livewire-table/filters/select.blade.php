<div class="px-3 py-2">
    <span class="block whitespace-nowrap truncate" title="{{ $filter->label() }}">
        {{ $filter->label() }}
    </span>
    <select class="w-full border border-neutral-200 rounded-md shadow-xs outline-hidden bg-white text-black focus:border-secondary-medium me-auto px-3 py-2 font-normal transition ease-in-out"
            wire:model.live="filters.{{ $filter->code() }}"

            @if($filter->isMultiple())
                multiple
            @endif
        >
        @if(! $filter->isMultiple())
            <option value="">&mdash;</option>
        @endif
        @foreach($filter->getOptions() as $key => $value)
            @if (is_array($value))
                <optgroup wire:key="{{ $key }}" label="{{ $key }}">
                    @foreach($value as $key => $value)
                        <option wire:key="{{ $key }}" value="{{ $key }}">{{ $value }}</option>
                    @endforeach
                </optgroup>
            @else
                <option wire:key="{{ $key }}" value="{{ $key }}">{{ $value }}</option>
            @endif
        @endforeach
    </select>
</div>
