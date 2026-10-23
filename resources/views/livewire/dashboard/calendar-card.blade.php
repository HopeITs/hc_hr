<div class="mx-auto h-full w-full max-w-3xl rounded-surface bg-white shadow-[0px_0px_25px_-14px_rgba(88,_89,_91,_0.30)]">
    <x-dashboard-cards.card-header :title="__('general.calendar')">
        <x-slot:icon>
            <svg class="block size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2z"/>
            </svg>
        </x-slot:icon>
    </x-dashboard-cards.card-header>

    <div class="mx-7 my-1 flex items-center justify-between px-3">
        <p class="text-[13px] font-normal text-secondary-dark">
            {{ $current->copy()->locale(app()->getLocale())->translatedFormat('F Y') }}
        </p>

        <div class="flex gap-1 text-body font-light text-secondary-dark">
            {{-- "<" and ">" are bidi-mirrored characters, so in RTL they already
                 point the right way without any rotation. --}}
            <button type="button" wire:click="prev" aria-label="{{ __('dashboard.previous-month') }}" title="{{ __('dashboard.previous-month') }}">&lt;</button>
            <button type="button" wire:click="next" aria-label="{{ __('dashboard.next-month') }}" title="{{ __('dashboard.next-month') }}">&gt;</button>
        </div>
    </div>

    <div class="mx-7 mb-2 border-t border-secondary-medium"></div>

    <div class="mx-7 mb-1 grid grid-cols-7 text-center text-[12px] font-medium tracking-wide text-secondary-dark">
        {{-- Weeks start on Monday, matching the ISO weekday offset CalendarCard
             uses to lay out the grid. --}}
        @foreach ($weekdays as $weekday)
            <abbr title="{{ $weekday['name'] }}" class="no-underline">{{ $weekday['short'] }}</abbr>
        @endforeach
    </div>

    <div class="mx-7 grid grid-cols-7 gap-y-1 text-center text-[11px] font-light">

        @foreach ($calendar as $day)
            @php
                $wrapperClasses = "size-5 relative flex items-center justify-center transition";

                if ($day['circleFill']) {
                    $wrapperClasses .= " rounded-full {$day['circleFill']}";
                }

                if (count($day['tooltip']) > 0) {
                    $wrapperClasses .= " cursor-pointer";
                }

                $numberClasses = "{$day['numberColor']} {$day['fontWeight']}";
            @endphp

            <div class="relative group flex justify-center">

                <div class="{{ $wrapperClasses }}">
                    <span class="{{ $numberClasses }} leading-none translate-y-[1px]">
                        {{ $day['day'] }}
                    </span>

                    @if($day['underline'])
                        <span class="absolute bottom-[3px] left-1/2 w-2.5 -translate-x-1/2 border-b {{ $day['underlineColor'] }}"></span>
                    @endif
                </div>

                @if(count($day['tooltip']) > 0)
                    <div class="absolute bottom-7 left-1/2 -translate-x-1/2 bg-white text-secondary-dark text-caption px-2 py-1 rounded shadow hidden group-hover:block whitespace-nowrap z-20">
                        @foreach($day['tooltip'] as $t)
                            <div>{{ $t }}</div>
                        @endforeach
                    </div>
                @endif

            </div>
        @endforeach

    </div>

</div>
