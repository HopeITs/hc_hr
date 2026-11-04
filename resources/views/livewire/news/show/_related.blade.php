{{-- Other live items this viewer may see: same subject first, then upcoming
     events, then the latest news (NewsShow::related()). --}}
<section class="mt-10" aria-labelledby="news-related-heading">
    <h2 id="news-related-heading" class="mb-4 text-h4 font-extrabold text-ink">{{ __('news.show.more') }}</h2>

    <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3" role="list">
        @foreach ($related as $item)
            @php
                $cover = $item->coverUrl();
                $timing = $item->eventTiming();
                $date = $item->isEvent() && $item->event_starts_at ? $item->event_starts_at : $item->published_at;
            @endphp
            <li wire:key="related-{{ $item->id }}">
                <a href="{{ route('news.show', $item) }}" wire:navigate
                    class="group flex h-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-e1 hover:border-line hover:bg-hover">
                    @if ($cover)
                        <img src="{{ $cover }}" alt="" loading="lazy" class="block aspect-[16/9] w-full bg-surface object-cover" />
                    @else
                        <div class="flex aspect-[16/9] w-full items-center justify-center bg-surface text-[2rem] text-ink-3" aria-hidden="true">
                            <x-ic :name="$item->type->icon()" />
                        </div>
                    @endif
                    <div class="flex flex-1 flex-col gap-2 p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-tag variant="soft" :icon="$item->type->icon()" :label="$item->type->label()" />
                            @if ($timing === 'today')
                                <x-tag variant="act" icon="calendar" :label="__('news.timing.today')" />
                            @endif
                        </div>
                        <h3 dir="{{ $item->textDirection() }}" class="line-clamp-2 font-bold text-ink group-hover:underline group-hover:underline-offset-2">
                            {{ $item->title }}
                        </h3>
                        <p class="mt-auto flex items-center justify-between gap-2 text-small text-ink-2">
                            @if ($date)
                                <time datetime="{{ $date->toIso8601String() }}" class="num inline-flex items-center gap-1.5">
                                    <x-ic :name="$item->isEvent() ? 'calendar' : 'clock'" class="text-ink-3" />
                                    {{ $date->translatedFormat('j M Y') }}
                                </time>
                            @endif
                            <x-ic name="arrow" class="ms-auto text-ink-3 rtl:-scale-x-100" />
                        </p>
                    </div>
                </a>
            </li>
        @endforeach
    </ul>
</section>
