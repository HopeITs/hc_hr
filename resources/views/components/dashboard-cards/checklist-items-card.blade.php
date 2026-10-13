@php
    $locale = app()->getLocale();
    $nameCol = "name_{$locale}";
@endphp

<div class="flex h-full w-full flex-col overflow-hidden rounded-surface bg-white shadow-[0px_0px_25px_-14px_rgba(0,_0,_0,_0.30)]">
    <x-dashboard-cards.card-header :title="__('general.personal-inventory')" :count="$deliveredItems->count()">
        <x-slot:icon>
            <img class="block size-full object-contain" src="{{ asset('assets/icons/personal-inventory.svg') }}" alt="">
        </x-slot:icon>
    </x-dashboard-cards.card-header>

    <!-- Scrollable Content -->
    <div class="flex-1 overflow-y-auto
                px-4 md:px-6 pb-4 space-y-2
                scrollbar-thin scrollbar-thumb-[var(--color-secondary-medium)]
                scrollbar-track-[var(--color-neutral-100)]
                hover:scrollbar-thumb-[var(--color-secondary-medium)]">

        @forelse ($deliveredItems as $item)
            <div class="pb-2 border-b border-b-secondary-light">

                <div class="flex justify-between items-center">
                    <p class="text-[13px] font-bold text-[#58595B]">
                        {{ $item->item?->name ?? 'Item #'.$item->item_id }}
                    </p>

                    <span class="text-[11px] text-[#58595B]">
                        {{ optional($item->delivered_at)->format('d/m/Y') }}
                    </span>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center  text-body font-light text-secondary-light h-full">
                <span class="text-[10.5px]">@lang('general.no-available-data')</span>
            </div>
        @endforelse

    </div>
</div>
