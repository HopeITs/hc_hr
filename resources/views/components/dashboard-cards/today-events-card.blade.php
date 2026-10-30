<div
    class="relative flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    <div class="js-today-events flex h-full min-h-0 flex-col">
        <x-dashboard-cards.card-header :title="__('general.today-events')" :count="now()->format('j/n/Y')" />

        @if(count($events) > 0)
            <div class="swiper w-full flex-1 min-h-0">
                <div class="swiper-wrapper">

                    @foreach($events as $event)
                        <div class="swiper-slide">
                            <div class="relative flex h-full min-h-0 flex-col items-center justify-center">
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
                                    class="pointer-events-none absolute {{ $event['type'] === 'birthday' ? 'end-0 bottom-0' : 'end-1 -bottom-3' }} w-20 h-20 opacity-10 select-none"
                                    alt=""
                                    aria-hidden="true">
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="swiper-pagination !bottom-5"></div>
            </div>

        @else

            <div data-tile-empty class="flex flex-col items-center justify-center  text-body font-light text-secondary-light h-full">
                <span class="text-small">@lang('general.no-available-data')</span>
            </div>
        @endif
    </div>
    <style>
        .js-today-events .swiper-pagination-bullet {
            width: 6px;
            height: 6px;
            background: var(--color-line);
            opacity: 1;
        }

        .js-today-events .swiper-pagination-bullet-active {
            background: var(--color-ink);
        }
    </style>
</div>

{{-- The card is a lazy dashboard tile: it arrives in a Livewire update, where a
     plain <script> would not run. @assets loads Swiper once per page (Livewire
     waits for it), @script then starts the slider. --}}
@assets
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
    <script nonce="{{ Vite::cspNonce() }}" src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
@endassets

@script
<script>
    const init = () => {
        const el = document.querySelector('.js-today-events .swiper');
        if (! el || el.swiper || ! window.Swiper) return;

        // wire:navigate drops this element without stopping the autoplay timer.
        document.addEventListener('livewire:navigating', () => el.swiper?.destroy(true, true), { once: true });

        new Swiper(el, {
            slidesPerView: 1,
            loop: true,
            autoplay: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : {
                delay: 4500,
                disableOnInteraction: false,
            },
            pagination: {
                el: '.js-today-events .swiper-pagination',
                clickable: true,
            },
        });
    };

    // Runs just before the card replaces its placeholder: wait a frame for the slides.
    requestAnimationFrame(init);
</script>
@endscript
