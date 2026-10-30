{{--
    Product tour overlay. Rendered once per full page by the app layout; the
    steps for the current route come from config/tours.php. Behaviour lives in
    resources/js/product-tour.js — see the notes there.
--}}
@php
    $tour = \App\Support\ProductTour::forRequest(request());
@endphp

<div x-data="productTour(@js($tour), @js((string) auth()->id()))"
     x-on:tour-start.window="start()"
     x-on:tour-reflow.window="scheduleMeasure()"
     x-on:keydown.window="onKeydown($event)">
    <template x-if="open">
        <div class="fixed inset-0 z-[1000]">
            {{-- Swallows clicks everywhere, including through the spotlight hole,
                 so the page underneath cannot be operated mid-tour. --}}
            <div class="absolute inset-0" aria-hidden="true"></div>

            {{-- Dimmed, lightly blurred backdrop with the target cut out. --}}
            <div class="absolute inset-0 bg-backdrop backdrop-blur-[2px] transition-[clip-path] duration-300 ease-fluid motion-reduce:transition-none"
                 :style="`clip-path: ${clipPath}`"
                 aria-hidden="true"></div>

            {{-- Brand-coloured ring around the spotlight. --}}
            <div class="pointer-events-none absolute ring-2 ring-primary ring-offset-2 ring-offset-white/60
                        transition-all duration-300 ease-fluid motion-reduce:transition-none"
                 :style="ringStyle"
                 aria-hidden="true"></div>

            {{-- Focused from its own x-init: on the first step it does not exist
                 yet when start() runs, so a $nextTick from there finds nothing. --}}
            <div data-tour-card
                 x-init="$nextTick(() => $el.focus({ preventScroll: true }))"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="tour-step-title"
                 aria-describedby="tour-step-body"
                 tabindex="-1"
                 x-transition:enter="transition duration-200 ease-out motion-reduce:transition-none"
                 x-transition:enter-start="opacity-0 translate-y-1"
                 class="absolute w-[min(22rem,calc(100vw-1.5rem))] rounded-surface bg-card p-5 shadow-e3 ring-1 ring-line-soft
                        duration-300 ease-fluid motion-reduce:transition-none focus:outline-none"
                 :class="settled ? 'transition-[top,left]' : 'opacity-0'"
                 :style="cardStyle">

                <span class="absolute size-3 rotate-45 bg-card" :style="arrowStyle" aria-hidden="true"></span>

                <div class="relative flex items-start justify-between gap-3">
                    <p class="text-caption font-semibold uppercase tracking-wide text-ink-2" aria-live="polite"
                       x-text="@js(__('tour.progress', ['current' => '__C__', 'total' => '__T__'])).replace('__C__', index + 1).replace('__T__', steps.length)"></p>

                    <button type="button" x-on:click="close()"
                            class="-m-1.5 inline-flex size-8 shrink-0 items-center justify-center rounded-full text-ink-3 transition hover:bg-hover hover:text-ink"
                            aria-label="{{ __('tour.close') }}">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path d="M6 6l12 12M18 6L6 18"/>
                        </svg>
                    </button>
                </div>

                <h2 id="tour-step-title" class="relative mt-1 text-h4 font-bold text-ink" x-text="step?.title"></h2>
                <p id="tour-step-body" class="relative mt-1.5 text-body leading-relaxed text-ink-2" x-text="step?.body"></p>

                {{-- A bar rather than one dot per step: the dashboard tour grows with
                     the sidebar and can run past twenty steps for an admin. --}}
                <div class="relative mt-4 h-1 overflow-hidden rounded-full bg-surface" aria-hidden="true">
                    <div class="absolute inset-y-0 start-0 rounded-full bg-primary transition-[width] duration-300 ease-fluid motion-reduce:transition-none"
                         :style="`width: ${progress}%`"></div>
                </div>

                <div class="relative mt-4 flex items-center justify-between gap-3">
                    <x-button variant="link" size="sm" :loading="false" x-show="!isLast" x-on:click="close()">
                        {{ __('tour.skip') }}
                    </x-button>

                    <div class="ms-auto flex items-center gap-2">
                        <x-button variant="quiet" size="sm" :loading="false" x-show="!isFirst" x-on:click="previous()">
                            {{ __('tour.previous') }}
                        </x-button>

                        <x-button size="sm" :loading="false" x-on:click="next()"
                                x-text="isLast ? {{ \Illuminate\Support\Js::from(__('tour.finish')) }} : {{ \Illuminate\Support\Js::from(__('tour.next')) }}"></x-button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
