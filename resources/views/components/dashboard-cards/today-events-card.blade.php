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
                                    {{-- Avatar (birthdays) or the event's cover. An event
                                         without a cover has no image: its text is centred alone. --}}
                                    @if(! empty($event['photo']))
                                        <img src="{{ $event['photo'] }}" alt="{{ $event['type'] === 'birthday' ? $event['name'] : '' }}"
                                             class="relative z-20 bg-card border border-primary w-18 h-18 rounded-full object-cover">
                                    @endif

                                    {{-- Info --}}
                                    <div @class(['z-20 text-center w-full', 'mt-2' => ! empty($event['photo'])])>
                                        <p class="font-semibold text-body mt-2 text-ink">
                                            @if (! empty($event['url']))
                                                <a href="{{ $event['url'] }}" wire:navigate class="hover:underline underline-offset-4">{{ $event['name'] }}</a>
                                            @else
                                                {{ $event['name'] }}
                                            @endif
                                        </p>

                                        @php
                                            $details = trim(
                                                $event['position'] . ($event['department'] ? ' • ' . $event['department'] : ''),
                                            );
                                        @endphp

                                        @if ($details)
                                            <p class="text-small tracking-wider font-light text-ink">{{ $details }}</p>
                                        @endif

                                        <p class="text-small text-ink-3 mt-1 line-clamp-2 px-4">{{ $event['message'] }}</p>
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

            <div data-tile-empty class="flex flex-col items-center justify-center  text-body font-light text-ink-3 h-full">
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
     plain <script> would not run. @script starts the slider; Swiper itself is
     fetched from our own build only when this tile is drawn (window.loadSwiper). --}}
@script
<script>
    const init = async () => {
        const el = document.querySelector('.js-today-events .swiper');
        if (! el || el.swiper || ! window.loadSwiper) return;

        const Swiper = await window.loadSwiper();
        if (! el.isConnected || el.swiper) return;

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
