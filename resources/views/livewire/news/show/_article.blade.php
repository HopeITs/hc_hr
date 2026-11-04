{{--
    The article itself, rendered in $article['locale'] (the preview's EN/AR
    switch) without changing the app locale. The wrapper carries that
    language's direction; the title and body carry the direction of the text
    actually shown ($news->textDirection), since a missing translation falls
    back to the other language. No cover image means no image area at all:
    the card simply starts with its text.
--}}
@php($t = $article['t'])

<article lang="{{ $article['locale'] }}" dir="{{ $article['pageDir'] }}" wire:key="news-article-{{ $article['locale'] }}"
    class="overflow-hidden rounded-surface border border-line-soft bg-card shadow-e1">
    @if ($article['coverUrl'])
        <img src="{{ $article['coverUrl'] }}" alt="" loading="eager"
            class="block aspect-[16/9] w-full bg-surface object-cover" />
    @endif

    <div class="p-5 sm:p-8">
        <div class="flex flex-wrap items-center gap-2">
            <x-tag variant="soft" :icon="$article['typeIcon']" :label="$article['typeLabel']" />
            @if ($article['timing'] === 'today')
                <x-tag variant="act" icon="calendar" :label="$article['timingLabel']" />
            @elseif ($article['timing'])
                <x-tag variant="soft" :label="$article['timingLabel']" />
            @endif
            @if ($canManage)
                <x-status :status="$state" />
            @endif
        </div>

        <h1 dir="{{ $article['dir'] }}" class="mt-4 text-h1 font-extrabold text-ink text-balance">
            {{ $article['title'] }}
        </h1>

        {{-- Separator dots are drawn before every item but the first, so a
             missing item never leaves a stray dot, in either direction. --}}
        <ul class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-small text-ink-2 [&>li+li]:before:me-1.5 [&>li+li]:before:size-1 [&>li+li]:before:rounded-full [&>li+li]:before:bg-line"
            role="list">
            @if ($article['publishedLabel'])
                <li class="inline-flex items-center gap-1.5">
                    <x-ic name="clock" class="text-ink-3" />
                    <span>{{ $t('news.show.published_on') }}</span>
                    <time datetime="{{ $article['publishedIso'] }}" class="num font-semibold text-ink">{{ $article['publishedLabel'] }}</time>
                </li>
            @endif
            @if ($article['isEvent'] && $article['eventLabel'])
                <li class="inline-flex items-center gap-1.5">
                    <x-ic name="calendar" class="text-ink-3" />
                    <time datetime="{{ $article['eventIso'] }}" class="num font-semibold text-ink">{{ $article['eventLabel'] }}</time>
                </li>
            @endif
            @if ($article['authorName'])
                <li class="inline-flex items-center gap-1.5">
                    <x-ic name="user" class="text-ink-3" />
                    <span>{{ $t('news.show.by') }}</span>
                    <bdi class="font-semibold text-ink">{{ $article['authorName'] }}</bdi>
                </li>
            @endif
        </ul>

        @if ($article['tags'] !== [])
            <ul class="mt-3 flex flex-wrap items-center gap-1.5" role="list" aria-label="{{ $t('news.show.tags') }}">
                @foreach ($article['tags'] as $tag)
                    <li class="max-w-full">
                        <x-tag variant="soft" icon="tag" class="max-w-full whitespace-normal break-words"><bdi>{{ $tag }}</bdi></x-tag>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-6 border-t border-line-soft pt-6">
            @if (filled($article['description']))
                <div dir="{{ $article['dir'] }}" class="max-w-prose whitespace-pre-line break-words text-body text-ink">{{ $article['description'] }}</div>
            @else
                <p class="text-body text-ink-3">{{ $t('news.show.no_details') }}</p>
            @endif
        </div>
    </div>
</article>
