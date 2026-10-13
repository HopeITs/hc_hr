<div
    class="w-full h-full bg-white rounded-surface shadow-e1 ring-1 ring-neutral-200 overflow-hidden relative flex flex-col">
    <div class="js-today-events flex h-full min-h-0 flex-col">
        <x-dashboard-cards.card-header :title="__('general.today-events')" :count="now()->format('j/n/Y')" />

        @if(count($events) > 0)
            <div class="swiper w-full flex-1 min-h-0">
                <div class="swiper-wrapper">

                    @foreach($events as $event)
                        <div class="swiper-slide">
                            <div class="relative h-full min-h-[240px] flex flex-col items-center justify-center">
                                @if($event['type'] === 'birthday')
                                    {{-- Decorative celebration animation: the birthday is
                                         already stated in the text below, so an empty alt
                                         keeps a screen reader from announcing it twice. --}}
                                    <img src="/assets/GIF/01-Confetti.gif"
                                         alt=""
                                         aria-hidden="true"
                                         class="absolute inset-0 w-full h-full pe-2 pb-12 object-cover pointer-events-none opacity-100 z-0">
                                @endif

                                <div class="flex flex-col h-full w-full justify-center z-30 items-center -translate-y-[15px]">
                                    {{-- Avatar --}}
                                    @php $isDefaultIcon = str_ends_with($event['photo'], '.svg'); @endphp
                                    @if($isDefaultIcon)
                                        <div class="relative z-20 flex items-center justify-center bg-white border border-primary w-18 h-18 rounded-full">
                                            <img src="{{ $event['photo'] }}" alt="{{ $event['name'] }}"
                                                 class="w-10 h-10 opacity-70">
                                        </div>
                                    @else
                                        <img src="{{ $event['photo'] }}" alt="{{ $event['name'] }}"
                                             class="relative z-20 bg-white border border-primary w-18 h-18 rounded-full object-cover">
                                    @endif

                                    {{-- Info --}}
                                    <div class="mt-2 z-20 text-center w-full">
                                        <p class="font-semibold text-body mt-2 text-[#58595B]">{{ $event['name'] }}</p>

                                        @php
                                            $details = trim(
                                                $event['position'] . ($event['department'] ? ' • ' . $event['department'] : ''),
                                            );
                                        @endphp

                                        @if ($details)
                                            <p class="text-small tracking-wider font-light text-[#58595B]">{{ $details }}</p>
                                        @endif

                                        <p class="text-small text-secondary-light mt-1">{{ $event['message'] }}</p>
                                    </div>
                                </div>

                                {{-- Watermark --}}
                                <img
                                    src="{{ $event['type'] === 'birthday' ? '/assets/icons/birthday-cake.svg' : '/assets/icons/event.svg' }}"
                                    class="pointer-events-none absolute {{ $event['type'] === 'birthday' ? 'right-0 bottom-0' : 'right-1 -bottom-3' }} w-20 h-20 opacity-10 select-none"
                                    alt="{{ ucfirst($event['type']) }} watermark">
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="swiper-pagination !bottom-5"></div>
            </div>

        @else

            <div class="flex flex-col items-center justify-center  text-body font-light text-secondary-light h-full">
                <span class="text-[10.5px]">@lang('general.no-available-data')</span>
            </div>
        @endif
    </div>
    {{-- Swiper Assets --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
    <script nonce="{{ Vite::cspNonce() }}" src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    <script nonce="{{ Vite::cspNonce() }}">
        document.addEventListener("DOMContentLoaded", () => {
            new Swiper(".js-today-events .swiper", {
                slidesPerView: 1,
                loop: true,
                autoplay: {
                    delay: 4500,
                    disableOnInteraction: false
                },
                pagination: {
                    el: ".js-today-events .swiper-pagination",
                    clickable: true
                },
            });
        });
    </script>

    <style>
        .js-today-events .swiper-pagination-bullet {
            width: 6px;
            height: 6px;
            background: #d1d5db;
            opacity: 1;
        }

        .js-today-events .swiper-pagination-bullet-active {
            background: #6b7280;
        }
    </style>
</div>
