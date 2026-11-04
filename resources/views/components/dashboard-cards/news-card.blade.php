{{-- newsItems: the latest live news and announcements for the viewer, loaded
     by Dashboard\NewsCard (NewsFeed::latestNews). Each row links to the
     details page; events live in the today / upcoming tiles. --}}
@props(['newsItems' => collect(), 'canViewAll' => false])

<div class="relative flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    <x-dashboard-cards.card-header :title="__('general.news')" :count="$newsItems->count()">
        <x-slot:icon>
            <img class="block size-full object-contain" src="{{ asset('assets/icons/news.svg') }}" alt="">
        </x-slot:icon>
    </x-dashboard-cards.card-header>

    <div class="flex-1 min-h-0 px-6 py-4 space-y-5 overflow-y-auto custom-scrollbar">
        @forelse($newsItems as $news)
            @php
                $title = $news->localizedTitle();
                $excerpt = trim(strip_tags((string) $news->localizedDescription()));
                $url = route('news.show', $news);
            @endphp
            <article class="pb-3 border-b border-b-line-soft last:border-b-0 last:pb-0" wire:key="dashboard-news-{{ $news->id }}">
                <div class="flex flex-wrap items-center gap-2">
                    <x-tag variant="soft" :icon="$news->type->icon()" :label="$news->type->label()" />
                    <time class="text-caption font-light text-ink-3"
                        datetime="{{ $news->published_at?->toIso8601String() }}"
                        title="{{ $news->published_at?->translatedFormat('j F Y, H:i') }}">
                        {{ $news->published_at?->diffForHumans() ?? __('dashboard.just-now') }}
                    </time>
                </div>
                <h3 class="mt-1 text-sm font-bold text-ink" dir="{{ $news->textDirection() }}">
                    <a href="{{ $url }}" wire:navigate class="hover:underline underline-offset-4">{{ $title }}</a>
                </h3>
                @if(filled($excerpt))
                    <p class="mt-1 text-xs font-light text-ink-2 line-clamp-2" dir="{{ $news->textDirection() }}">
                        {{ \Illuminate\Support\Str::limit($excerpt, 240) }}
                    </p>
                @endif
                <a href="{{ $url }}" wire:navigate
                    class="flex w-fit mt-2 text-xs font-semibold underline ms-auto text-ink hover:text-ink-2"
                    aria-label="{{ __('news.dashboard.read_more_about', ['title' => $title]) }}">
                    {{ __('news.dashboard.read_more') }} <span aria-hidden="true" class="inline-block ms-1 rtl:rotate-180">&rarr;</span>
                </a>
            </article>
        @empty
            <div data-tile-empty class="flex flex-col items-center justify-center h-full text-sm font-light text-ink-3">
                <span class="text-small">{{ __('news.dashboard.no_news') }}</span>
            </div>
        @endforelse
    </div>

    @if($canViewAll)
        <div class="mt-auto border-t border-line-soft bg-card px-6 py-3 text-center">
            <a href="{{ route('news.index') }}" wire:navigate
                class="text-small font-semibold text-ink underline-offset-4 hover:underline">
                {{ __('news.dashboard.all_news') }} <span aria-hidden="true" class="inline-block rtl:rotate-180">→</span>
            </a>
        </div>
    @endif
</div>

<style>
    .custom-scrollbar::-webkit-scrollbar {
        width: 4px;
    }
    .custom-scrollbar {
        scrollbar-width: thin;
        scrollbar-color: rgba(0, 0, 0, 0.22) transparent;
        scrollbar-gutter: stable;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: rgba(0, 0, 0, 0.22);
        border-radius: 999px;
    }
</style>
