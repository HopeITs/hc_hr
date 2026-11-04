{{-- upcomingEvents: live events after today for the viewer, loaded by
     Dashboard\UpcomingEventsCard (event_title, event_description,
     event_timestamp, event_has_time, daysLeftText, url are set there). --}}
@props(['upcomingEvents' => collect()])

<div class="flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    <x-dashboard-cards.card-header :title="__('general.upcoming-events')" :count="$upcomingEvents->count()">
        <x-slot:icon>
            <x-icons.event class="size-full"/>
        </x-slot:icon>
    </x-dashboard-cards.card-header>

    <div class="flex-1 overflow-y-auto px-6 pb-4 space-y-4 scrollbar-thin scrollbar-thumb-[var(--color-secondary-medium)] scrollbar-track-[var(--color-neutral-100)] hover:scrollbar-thumb-[var(--color-secondary-medium)]">
        @forelse ($upcomingEvents as $event)
            <article class="pb-3 border-b border-b-line-soft" wire:key="dashboard-upcoming-{{ $event->id }}">
                <div class="flex justify-between items-start gap-3">
                    <h3 class="text-body font-bold text-ink min-w-0" dir="{{ $event->textDirection() }}">
                        <a href="{{ $event->url }}" wire:navigate class="hover:underline underline-offset-4">{{ $event->event_title }}</a>
                    </h3>
                    <time class="shrink-0 text-caption font-medium tracking-wide text-end text-ink-3 num"
                        datetime="{{ $event->event_timestamp->toIso8601String() }}">
                        <bdi>{{ $event->event_timestamp->translatedFormat('j/n/Y') }}</bdi>
                        @if ($event->event_has_time)
                            <bdi class="block">{{ $event->event_timestamp->translatedFormat('H:i') }}</bdi>
                        @endif
                    </time>
                </div>
                <p class="text-caption font-semibold text-ink-2">{{ $event->daysLeftText }}</p>
                @if (filled($event->event_description))
                    <p class="text-caption font-light text-ink-2 line-clamp-2" dir="{{ $event->textDirection() }}">
                        {{ \Illuminate\Support\Str::limit(strip_tags($event->event_description ?? ''), 120) }}
                    </p>
                @endif
            </article>
        @empty
            <div data-tile-empty class="flex flex-col items-center justify-center  text-body font-light text-ink-3 h-full">
                <span class="text-small">{{ __('news.dashboard.no_upcoming') }}</span>
            </div>
        @endforelse
    </div>
</div>
