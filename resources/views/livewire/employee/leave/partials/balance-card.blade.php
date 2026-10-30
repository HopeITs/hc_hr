{{--
    Phone card for one employee's annual balance (B4-D §6, G-B-4): name, years
    of service, then deserved · used · remaining. The name opens the balance
    editor, as a row click does. Receives $item (Employee), $link and $table.
--}}
@php
    $balance = $table->cardBalance($item);
    $open = $table->rowOpenAction($item);
@endphp
<div class="flex min-w-0 flex-col gap-2" data-card="{{ $item->getKey() }}">
    <div class="flex min-w-0 items-start justify-between gap-2">
        <div class="min-w-0">
            @if($open)
                <button type="button" x-on:click="{{ $open }}" aria-haspopup="dialog"
                    aria-label="{{ __('general.edit') }}: {{ $item->name }}"
                    class="block min-h-11 max-w-full text-start font-semibold text-ink"><bdi>{{ $item->name }}</bdi></button>
            @else
                <p class="font-semibold text-ink"><bdi>{{ $item->name }}</bdi></p>
            @endif
            <p class="text-small text-ink-2">{{ __('general.years-of-service') }}: <bdi>{{ $item->years_of_service_formatted }}</bdi></p>
        </div>
        @if($balance)
            <x-table.edit-btn wire:click="$dispatch('openEdit', { id: {{ (int) $balance->id }} })" />
        @endif
    </div>
    @if($balance)
        {{-- One row per figure: "41 days, 1 hour and 52 minutes" does not fit a third of a phone.
             The figures are the same server-formatted HTML the table prints (asHtml). --}}
        <dl class="flex flex-col gap-1 text-small">
            @foreach([
                __('general.deserved') => $balance->deserved_formatted,
                __('general.used') => $balance->used_formatted,
                __('general.remaining') => $balance->remaining,
            ] as $label => $figure)
                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                    <dt class="text-ink-2">{{ $label }}</dt>
                    <dd class="text-ink">{!! $figure !!}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>
