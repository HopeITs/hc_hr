{{--
    A News & Events item as an employee reads it: the body of the details
    page (news.show) and of the editor's preview (NewsCreate). Plain props
    only, so both callers build the values themselves and the two can never
    drift apart.

      <x-news.article
          :title="$news->localizedTitle()" :description="$news->localizedDescription()"
          :dir="$news->textDirection()" :cover-url="$news->coverUrl()"
          :type-label="$news->type->label()" :type-icon="$news->type->icon()"
          :state="$news->state()" :published-label="…" :event-label="…"
          :related-label="$news->newsable_name" :audience-label="$news->audienceSummary()"
          :author-name="…" />

    dir:       direction of the title/description text (they may fall back to
               the other language); the chrome around it follows the page.
    state:     any App\Support\Status\HasStatusTone (NewsState) for <x-status>;
               or stateLabel (+ stateTone / stateIcon) for a plain status.
    locale:    language of the labels inside the article (defaults to the app's);
               the editor's preview renders both languages side by side.
    Optional:  relatedIcon, audienceIcon, timingLabel (Upcoming/Today/Past tag),
               and the slots `actions` (under the header) and `aside` (extra facts).
    Empty values are left out, never shown as blanks.
--}}
@props([
    'title' => '',
    'description' => null,
    'dir' => null,
    'coverUrl' => null,
    'typeLabel' => null,
    'typeIcon' => 'file',
    'state' => null,
    'stateLabel' => null,
    'stateTone' => null,
    'stateIcon' => null,
    'publishedLabel' => null,
    'eventLabel' => null,
    'timingLabel' => null,
    'relatedLabel' => null,
    'relatedIcon' => 'building',
    'audienceLabel' => null,
    'audienceIcon' => 'users',
    'authorName' => null,
    'locale' => null,
])

@php
    $locale ??= app()->getLocale();
    $t = fn (string $key) => __('news.form.article.'.$key, [], $locale);
    $hasStatus = $state !== null || filled($stateLabel);
    $hasFacts = filled($eventLabel) || filled($relatedLabel) || filled($audienceLabel) || isset($aside);
    $titleId = 'news-article-title-'.substr(md5($title.$locale), 0, 8);
@endphp

<article {{ $attributes->class('text-ink') }} aria-labelledby="{{ $titleId }}" lang="{{ $locale }}"
         dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
    @if ($coverUrl)
        <figure class="mb-6 overflow-hidden rounded-surface border border-line-soft bg-soft">
            <img src="{{ $coverUrl }}" alt="" class="aspect-[16/7] w-full object-cover" loading="lazy" decoding="async">
        </figure>
    @endif

    <header class="mb-6">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            @if ($typeLabel)
                <x-tag variant="soft" :icon="$typeIcon" :label="$typeLabel" />
            @endif
            @if ($timingLabel)
                <x-tag variant="soft" icon="clock" :label="$timingLabel" />
            @endif
            @if ($hasStatus)
                <x-status :status="$state" :tone="$stateTone" :icon="$stateIcon" :label="$stateLabel" />
            @endif
        </div>

        <h1 id="{{ $titleId }}" dir="{{ $dir ?? 'auto' }}"
            class="max-w-[40ch] text-start text-h1 font-extrabold text-balance text-ink">{{ $title }}</h1>

        @if (filled($publishedLabel) || filled($authorName))
            <dl class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1.5 text-small text-ink-2">
                @if (filled($publishedLabel))
                    <div class="inline-flex items-center gap-1.5">
                        <dt class="sr-only">{{ $t('publication') }}</dt>
                        <x-ic name="clock" class="shrink-0 text-ink-3" />
                        <dd class="num">{{ $publishedLabel }}</dd>
                    </div>
                @endif
                @if (filled($authorName))
                    <div class="inline-flex items-center gap-1.5">
                        <dt class="sr-only">{{ $t('author') }}</dt>
                        <x-ic name="user" class="shrink-0 text-ink-3" />
                        <dd><bdi>{{ $authorName }}</bdi></dd>
                    </div>
                @endif
            </dl>
        @endif

        @isset($actions)
            <div class="mt-4 flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </header>

    <div @class(['grid gap-6', 'lg:grid-cols-[minmax(0,1fr)_18rem]' => $hasFacts])>
        <div class="min-w-0">
            @if (filled($description))
                <div dir="{{ $dir ?? 'auto' }}"
                     class="max-w-[70ch] text-start text-body leading-relaxed whitespace-pre-line break-words text-ink">{{ $description }}</div>
            @endif
        </div>

        @if ($hasFacts)
            <aside class="self-start rounded-surface border border-line-soft bg-soft p-5 lg:sticky lg:top-24"
                   aria-label="{{ $t('facts') }}">
                <dl class="space-y-4">
                    @if (filled($eventLabel))
                        <div class="flex items-start gap-3">
                            <x-ic name="calendar" class="mt-1 shrink-0 text-ink-2" />
                            <div class="min-w-0">
                                <dt class="text-caption font-bold uppercase tracking-wide text-ink-3">{{ $t('event') }}</dt>
                                <dd class="num mt-0.5 text-body font-semibold text-ink">{{ $eventLabel }}</dd>
                            </div>
                        </div>
                    @endif
                    @if (filled($relatedLabel))
                        <div class="flex items-start gap-3">
                            <x-ic :name="$relatedIcon" class="mt-1 shrink-0 text-ink-2" />
                            <div class="min-w-0">
                                <dt class="text-caption font-bold uppercase tracking-wide text-ink-3">{{ $t('related') }}</dt>
                                <dd class="mt-0.5 text-body text-ink break-words"><bdi>{{ $relatedLabel }}</bdi></dd>
                            </div>
                        </div>
                    @endif
                    @if (filled($audienceLabel))
                        <div class="flex items-start gap-3">
                            <x-ic :name="$audienceIcon" class="mt-1 shrink-0 text-ink-2" />
                            <div class="min-w-0">
                                <dt class="text-caption font-bold uppercase tracking-wide text-ink-3">{{ $t('audience') }}</dt>
                                <dd class="mt-0.5 text-body text-ink break-words"><bdi>{{ $audienceLabel }}</bdi></dd>
                            </div>
                        </div>
                    @endif
                    @isset($aside)
                        {{ $aside }}
                    @endisset
                </dl>
            </aside>
        @endif
    </div>
</article>
