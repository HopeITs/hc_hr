@php($columns = $this->resolveColumns())

<table @class(['w-full relative text-table', 'hc-tbl' => ! ($cardView ?? null)]) x-data="{ selected: @entangle('selected') }">
    <thead class="border-b border-line-soft">
    <tr class="group">
        @if($this->canSelect())
            <th class="p-0 text-start text-ink-2 bg-soft">
                <input type="checkbox" wire:model.live="selectedPage" class="size-4 mx-3">
            </th>
        @endif
        @foreach($columns as $column)
            @continue(! in_array($column->code(), $this->columns))
            <th wire:key="{{ $column->code() }}" class="p-0 text-start text-ink-2 bg-soft">
                {{ $column->renderHeader() }}
            </th>
        @endforeach
    </tr>
    <tr class="group" data-tour="table.column-search">
        @if($this->canSelect())
            <th class="p-0 text-start text-ink-2 bg-soft"></th>
        @endif
        @foreach($columns as $column)
            @continue(! in_array($column->code(), $this->columns))
            <th wire:key="{{ $column->code() }}" class="p-0 text-start text-ink-2 bg-soft">
                @if($column->isSearchable())
                    {{ $column->renderSearch() }}
                @endif
            </th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @if($this->deferLoading && ! $this->initialized)
        <tr class="group">
            <td class="p-0" colspan="{{ $columns->count() + 1 }}">
                <span class="block text-h4 text-center py-24 bg-card text-ink-2">
                    @lang('Fetching records...')
                </span>
            </td>
        </tr>
    @else
        @forelse($paginator->items() as $item)
            <tr class="group" data-tour="table.row"
                x-data="{ item: '{{ $item->getKey() }}' }"
                wire:key="row-{{ $item->getKey() }}"

                @if($this->isReordering())
                    draggable="true"
                    x-on:dragstart="e => e.dataTransfer.setData('key', '{{ $item->getKey() }}')"
                    x-on:dragover.prevent=""
                    x-on:drop="e => {
                        $wire.call(
                            'reorderItem',
                            e.dataTransfer.getData('key'),
                            '{{ $item->getKey() }}',
                            e.target.offsetHeight / 2 > e.offsetY
                        )
                    }"
                @endif
            >
                @if($this->canSelect())
                    <td class="p-0"
                        x-bind:class="~selected.indexOf('{{ $item->getKey() }}')
                                ? 'bg-surface'
                                : 'bg-card group-hover:bg-hover'">
                        <div class="mx-3">
                            <input type="checkbox" wire:model.live="selected" value="{{ $item->getKey() }}" class="size-4">
                        </div>
                    </td>
                @endif
                @foreach($columns as $column)
                    @continue(! in_array($column->code(), $this->columns))
                    <td
                        wire:key="{{ $column->code() }}"
                        data-label="{{ $column->label() }}"
                        x-data=""
                        @class([
                            'p-0 group/column relative border-t border-line-soft' => true,
                            'select-none cursor-pointer' => $column->isClickable() || $this->isReordering(),
                        ])
                        @if($column->isClickable() && ! $this->isReordering())
                            @if(($link = $this->link($item)) !== null)
                                x-on:click.prevent="Livewire.navigate('{{ $link }}')"
                            @elseif(method_exists($this, 'rowClickAction') && ($rowClick = $this->rowClickAction($item)) !== null)
                                x-on:click.prevent="{{ $rowClick }}"
                            @elseif($this->canSelect())
                                x-on:click="$wire.selectItem('{{ $item->getKey() }}')"
                            @endif
                        @endif
                        x-bind:class="~selected.indexOf('{{ $item->getKey() }}')
                                ? 'bg-surface'
                                : 'bg-card group-hover:bg-hover'"
                    >
                        @includeWhen($column->isCopyable(), 'livewire-table::columns.buttons.copy')
                        <div x-ref="content">
                            {{ $column->render($item) }}
                        </div>
                    </td>
                @endforeach
            </tr>
        @empty
            <tr class="group">
                <td class="p-0" colspan="{{ $columns->count() + 1 }}">
                    @if(method_exists($this, 'renderEmptyState'))
                        {{-- Tables using App\Livewire\Concerns\HasListStates: empty / filtered / error. --}}
                        <div class="sticky start-0 w-[min(100%,100vw)] bg-card p-4">{!! $this->renderEmptyState() !!}</div>
                    @else
                        <span class="block text-h4 text-center py-24 bg-card text-ink-2">
                            @lang('No results')
                        </span>
                    @endif
                </td>
            </tr>
        @endforelse
    @endif
    </tbody>
    <tfoot class="border-t border-line-soft">
    <tr class="group">
        @if($this->canSelect())
            <th class="p-0 text-start text-ink-2 bg-soft"></th>
        @endif
        @foreach($columns as $column)
            @continue(! in_array($column->code(), $this->columns))
            <th wire:key="{{ $column->code() }}" class="p-0 text-start text-ink-2 bg-soft">
                {{ $column->renderFooter() }}
            </th>
        @endforeach
    </tr>
    </tfoot>
</table>
