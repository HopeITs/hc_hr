<?php

namespace App\Livewire\News;

use App\Enums\News\NewsState;
use App\Models\Employee;
use App\Models\News;
use App\Services\News\NewsFeed;
use App\Services\News\Publishing\ExternalPublishing;
use App\Support\News\NewsAccess;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The details page of a News & Events item (news.show) and its preview for a
 * manager of the item (news.preview). Read-only: nothing here changes data.
 *
 * $locale only chooses which language the article is shown in (the preview's
 * EN/AR switch); it never changes the app locale and grants nothing, because
 * every value it selects was authorized in mount().
 */
class NewsShow extends Component
{
    #[Locked]
    public News $news;

    #[Locked]
    public bool $previewRoute = false;

    #[Locked]
    public bool $canManage = false;

    /** The language the article is rendered in. */
    public string $locale = 'en';

    public function mount(News $news): void
    {
        $user = auth()->user();
        $this->previewRoute = request()->routeIs('news.preview');

        if ($this->previewRoute) {
            abort_unless(NewsAccess::canManage($user, $news), 403);
        } else {
            abort_unless(NewsAccess::canView($user, $news), 404);
        }

        $this->canManage = NewsAccess::canManage($user, $news);
        $this->locale = $this->normalizeLocale(app()->getLocale());

        $news->loadMissing(['tags', 'creator.profile', 'media', 'audienceTargets']);
        $this->news = $news;
    }

    /** The preview's language switch: only 'en' or 'ar', nothing else is read. */
    public function updatedLocale(): void
    {
        $this->locale = $this->normalizeLocale($this->locale);
    }

    public function render()
    {
        $this->locale = $this->normalizeLocale($this->locale);
        $this->news->loadMissing(['tags', 'creator.profile', 'media', 'audienceTargets']);

        $state = $this->news->state();
        $isReleased = in_array($state, [NewsState::Published, NewsState::Expired], true);
        $showPreview = $this->canManage && ($this->previewRoute || ! $isReleased);

        return view('livewire.news.show', [
            'state' => $state,
            'showPreview' => $showPreview,
            'article' => $this->article($this->locale),
            'related' => $this->related(),
            'audienceSummary' => $this->canManage ? $this->news->audienceSummary() : null,
            'externalAvailable' => $this->canManage && app(ExternalPublishing::class)->isAvailable(),
            'externalPublications' => $this->canManage ? $this->news->externalPublications()->get() : collect(),
            'backUrl' => $this->backUrl(),
            'backLabel' => $this->backLabel(),
            'scheduledFor' => $state === NewsState::Scheduled && $this->news->published_at
                ? $this->news->published_at->copy()->locale(app()->getLocale())->translatedFormat('j F Y, H:i')
                : null,
        ]);
    }

    /** Everything the article shows, in $locale. */
    private function article(string $locale): array
    {
        $news = $this->news;
        $trans = fn (string $key, array $replace = []) => __($key, $replace, $locale);
        $timing = $news->eventTiming();
        $author = $news->creator?->profile instanceof Employee
            ? $news->creator->profile->nameForLocale($locale)
            : ($news->creator?->name);

        return [
            'locale' => $locale,
            'pageDir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'title' => $news->localizedTitle($locale),
            'description' => $news->localizedDescription($locale),
            'dir' => $news->textDirection($locale),
            'coverUrl' => $news->coverUrl(),
            'typeLabel' => $trans('news.types.'.$news->type->value),
            'typeIcon' => $news->type->icon(),
            'isEvent' => $news->isEvent(),
            'timing' => $timing,
            'timingLabel' => $timing ? $trans('news.timing.'.$timing) : null,
            'publishedLabel' => $news->published_at
                ? $news->published_at->copy()->locale($locale)->translatedFormat('j F Y')
                : null,
            'publishedIso' => $news->published_at?->toIso8601String(),
            'eventLabel' => $this->eventRange($locale),
            'eventStart' => $this->formatMoment($news->event_starts_at, $locale),
            'eventEnd' => $this->formatMoment($news->event_ends_at, $locale),
            'eventIso' => $news->event_starts_at?->toIso8601String(),
            'tags' => $news->tagNames(),
            'authorName' => filled($author) ? $author : null,
            't' => $trans,
        ];
    }

    /** "Monday 4 November 2026 · 10:00 – 12:00", or a range across days. */
    private function eventRange(string $locale): ?string
    {
        $start = $this->news->event_starts_at?->copy()->locale($locale);
        $end = $this->news->event_ends_at?->copy()->locale($locale);

        if (! $start) {
            return null;
        }

        $hasTime = ! ($start->format('H:i') === '00:00' && (! $end || $end->format('H:i') === '00:00'));

        if (! $end || $end->equalTo($start)) {
            return $hasTime ? $start->translatedFormat('l j F Y · H:i') : $start->translatedFormat('l j F Y');
        }

        if ($start->isSameDay($end)) {
            return $hasTime
                ? $start->translatedFormat('l j F Y · H:i').' – '.$end->translatedFormat('H:i')
                : $start->translatedFormat('l j F Y');
        }

        $format = $hasTime ? 'j F Y, H:i' : 'j F Y';

        return $start->translatedFormat($format).' – '.$end->translatedFormat($format);
    }

    private function formatMoment(?CarbonInterface $moment, string $locale): ?string
    {
        if (! $moment) {
            return null;
        }

        $moment = $moment->copy()->locale($locale);

        return $moment->format('H:i') === '00:00'
            ? $moment->translatedFormat('l j F Y')
            : $moment->translatedFormat('l j F Y, H:i');
    }

    /**
     * Up to three other live items the viewer may see: those sharing a tag
     * with this one first (most shared tags, then newest), then upcoming
     * events, then the latest news.
     */
    private function related(): Collection
    {
        $user = auth()->user();
        $feed = app(NewsFeed::class);
        $exclude = [$this->news->getKey()];
        $items = collect();

        $tags = array_map('mb_strtolower', $this->news->tagNames());

        if ($tags !== []) {
            $placeholders = implode(', ', array_fill(0, count($tags), '?'));

            $items = $feed->live($user)
                ->whereKeyNot($exclude)
                ->whereExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('news_tags')
                    ->whereColumn('news_tags.news_id', 'news.id')
                    ->whereRaw('LOWER(news_tags.tag) IN ('.$placeholders.')', $tags))
                ->orderByDesc(fn ($sub) => $sub->selectRaw('COUNT(*)')
                    ->from('news_tags')
                    ->whereColumn('news_tags.news_id', 'news.id')
                    ->whereRaw('LOWER(news_tags.tag) IN ('.$placeholders.')', $tags))
                ->orderByDesc('news.published_at')
                ->limit(3)
                ->get();
        }

        if ($items->count() < 3) {
            $items = $items->concat($feed->live($user)
                ->whereKeyNot(array_merge($exclude, $items->pluck('id')->all()))
                ->upcomingEvents()
                ->limit(3 - $items->count())
                ->get());
        }

        if ($items->count() < 3) {
            $items = $items->concat($feed->live($user)
                ->whereKeyNot(array_merge($exclude, $items->pluck('id')->all()))
                ->orderByDesc('news.published_at')
                ->limit(3 - $items->count())
                ->get());
        }

        return $items->values();
    }

    private function backUrl(): string
    {
        return auth()->user()?->hasActivePermission('news.view')
            ? route('news.index')
            : route('dashboard');
    }

    private function backLabel(): string
    {
        return auth()->user()?->hasActivePermission('news.view')
            ? __('news.show.back_to_list')
            : __('news.show.back_to_dashboard');
    }

    private function normalizeLocale(?string $locale): string
    {
        return in_array($locale, ['en', 'ar'], true) ? $locale : 'en';
    }
}
