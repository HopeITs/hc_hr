@php
    $locale = app()->getLocale();
    $nameCol = "name_{$locale}";

    // No hue per kind: the kind's name is written next to it. A warning keeps the red (it concerns the viewer).
    $badgeColors = [
        'unknown' => 'text-ink-2',
        'reward' => 'text-ink',
        'warning' => 'text-primary',
        'bonus' => 'text-ink',
    ];

    $badgeTypes = [
        0 => 'Unknown',
        1 => 'Warning',
        2 => 'Reward',
        3 => 'Bonus',
    ];
@endphp

<div class="flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    <x-dashboard-cards.card-header :title="__('general.badges-warnings')" :count="$myBadges->count()">
        <x-slot:icon>
            <img class="block size-full object-contain" src="{{ asset('assets/icons/badges.svg') }}" alt="">
        </x-slot:icon>
    </x-dashboard-cards.card-header>

    <!-- Scrollable Content -->
    <div class="flex-1 overflow-y-auto px-4 md:px-6 pb-4 space-y-2
                scrollbar-thin scrollbar-thumb-[var(--color-secondary-medium)]
                scrollbar-track-[var(--color-neutral-100)]
                hover:scrollbar-thumb-[var(--color-secondary-medium)]">

        @forelse ($myBadges as $badge)
            @php
                $typeName = $badgeTypes[$badge->type] ?? 'Unknown';
                $colorClass = $badgeColors[strtolower($typeName)] ?? 'text-primary';
            @endphp

            <div class="flex justify-between items-center pb-2 border-b border-b-secondary-light"
                 wire:key="badge-{{ $badge->id }}">

                <p class="text-[13px] font-bold uppercase {{ $colorClass }}">
                    {{ $badge->reason ?: \App\Enums\BadgeSubtype::getLocalizedName($badge->subtype) }}
                </p>

                <span class="text-[11px] text-[#888]">
                    <bdi>{{ optional($badge->date)->format('d/m/Y') }}</bdi>
                </span>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center  text-body font-light text-secondary-light h-full">
                <span class="text-[10.5px]">@lang('general.no-available-data')</span>
            </div>
        @endforelse
    </div>
</div>
