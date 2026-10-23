{{--
    Phone card for one contract (table-contract.md §6). The whole card is the
    "view" button (opens the read-only drawer); the More menu sits beside it.
    Receives $item (Contract), $link (always null here) and $table (the component).
--}}
@php
    $name = $item->employee?->name ?? __('general.na');
    $title = app()->getLocale() === 'ar' ? 'title_ar' : 'title_en';
    [$statusLabel, $statusColor] = \App\Livewire\Contract\ContractsTable::statusOf($item);
    $days = \App\Livewire\Contract\ContractsTable::endsInDays($item);
@endphp
<div class="flex items-start gap-2" data-card="{{ $item->getKey() }}">
    <button type="button"
        class="min-h-11 min-w-0 flex-1 text-start focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        data-contract-view="{{ $item->getKey() }}"
        aria-label="{{ __('contracts.view-named', ['name' => $name]) }}"
        x-on:click="{{ $table->openDrawerJs($item->getKey()) }}">
        <span class="block break-words font-semibold" dir="auto">{{ $name }}</span>
        <span class="block break-words text-small text-neutral-600" dir="auto">{{ $item->contractType?->name }} · <bdi>{{ $item->start_date?->format('Y-m-d') }} – {{ $item->end_date?->format('Y-m-d') ?? __('general.present') }}</bdi></span>
        <span class="mt-1 flex flex-wrap items-center gap-2">
            <x-badge :color="$statusColor">{{ $statusLabel }}</x-badge>
            @if($days !== null)
                <span class="text-small font-medium text-amber-800">{{ trans_choice('contracts.ends-in-days', $days, ['count' => $days]) }}</span>
            @endif
        </span>
    </button>
    {!! $table->rowActions($item, 'card') !!}
</div>
