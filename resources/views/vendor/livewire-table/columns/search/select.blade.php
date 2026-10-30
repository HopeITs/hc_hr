<div class="px-3 py-1">
    <select
        class="min-w-full border border-neutral-200 rounded-inset shadow-e1 outline-hidden bg-white text-black focus:border-secondary-medium me-auto px-2 py-1 font-normal transition ease-in-out h-8"
        wire:model.live="search.{{ $column->code() }}">
        <option value="">&mdash;</option>
        @foreach($column->getOptions() as $key => $value)
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
