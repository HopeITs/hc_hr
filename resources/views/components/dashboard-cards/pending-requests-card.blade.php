<div class="flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    <x-dashboard-cards.card-header :title="__('general.pending-delivers')" :count="$pendingRequests->count()">
        <x-slot:icon>
            <img class="block size-full object-contain" src="{{ asset('assets/icons/pending-delivers.svg') }}" alt="">
        </x-slot:icon>
    </x-dashboard-cards.card-header>

    <!-- Scrollable Content -->
    <div class="flex-1 overflow-y-auto
                px-4 md:px-6 pb-4 space-y-2
                scrollbar-thin scrollbar-thumb-[var(--color-secondary-medium)]
                scrollbar-track-[var(--color-neutral-100)]
                hover:scrollbar-thumb-[var(--color-secondary-medium)]">

        @forelse ($pendingRequests as $req)
            <div class="flex justify-between items-center pb-2 border-b border-b-line-soft"
                 wire:key="pending-request-{{ $req['id'] }}">

                <p class="text-small font-bold text-ink">
                    {{ ucfirst($req['item']) }}
                </p>

                <span class="text-caption text-ink-2">
                    <bdi>{{ $req['request_date'] }}</bdi>
                </span>
            </div>
        @empty
            <div data-tile-empty class="flex flex-col items-center justify-center  text-body font-light text-ink-3 h-full">
                <span class="text-small">@lang('general.no-available-data')</span>
            </div>
        @endforelse
    </div>
</div>
