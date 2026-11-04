<?php

namespace App\Livewire\News;

use App\Enums\News\NewsState;
use App\Enums\News\NewsStatus;
use App\Livewire\Concerns\RemembersReturnTo;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\NewsForm;
use App\Models\News;
use App\Support\News\NewsAccess;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Create / edit a News & Events item. Two writes (save as draft, publish
 * or schedule) and a preview that validates and renders the item through
 * <x-news.article>, the same body the details page uses. The preview never
 * writes anything.
 */
class NewsCreate extends Component
{
    use RemembersReturnTo;
    use WithFeedback;
    use WithFileUploads;

    public NewsForm $form;

    public ?News $news = null;

    public bool $previewing = false;

    public function mount(?News $news = null): void
    {
        if ($news?->exists) {
            abort_unless(NewsAccess::canManage(auth()->user(), $news), 403);

            $this->news = $news;
            $this->form->fillFromNews($news);

            // The list's "Schedule" action opens the editor with ?mode=schedule.
            if (request()->query('mode') === NewsForm::MODE_SCHEDULE && ! $this->form->isScheduled()) {
                $this->form->publish_mode = NewsForm::MODE_SCHEDULE;
                $this->form->publish_at = null;
            }

            return;
        }

        abort_unless(NewsAccess::canCreate(auth()->user()), 403);
    }

    // ------------------------------------------------------------------
    // Dependent selections (no reads of records, no writes)
    // ------------------------------------------------------------------

    public function updatedFormRelatedTo(): void
    {
        $this->form->updatedRelatedTo();
        $this->resetErrorBag(['form.related_id']);
    }

    public function updatedFormAudienceType(): void
    {
        $this->form->updatedAudienceType();
        $this->resetErrorBag(['form.audience_ids', 'form.audience_ids.*']);
    }

    public function updatedFormType(): void
    {
        $this->form->updatedType();
        $this->resetErrorBag(['form.event_starts_at', 'form.event_ends_at']);
    }

    public function updatedFormPublishMode(): void
    {
        $this->resetErrorBag(['form.publish_at']);
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    public function saveDraft()
    {
        return $this->store(NewsForm::INTENT_DRAFT);
    }

    public function publish()
    {
        return $this->store(NewsForm::INTENT_PUBLISH);
    }

    private function store(string $intent)
    {
        $this->authorizeWrite();

        $this->previewing = false;
        $this->news = $this->form->persist($this->news, $intent);

        $this->toast(match (true) {
            $intent === NewsForm::INTENT_DRAFT => __('news.form.saved_draft'),
            $this->news->state() === NewsState::Scheduled => __('news.form.saved_scheduled', [
                'date' => $this->news->published_at->translatedFormat('j F Y, H:i'),
            ]),
            default => __('news.form.saved_published'),
        });

        return $this->redirectRoute('news.index', navigate: true);
    }

    private function authorizeWrite(): void
    {
        abort_unless(
            $this->news?->exists
                ? NewsAccess::canManage(auth()->user(), $this->news)
                : NewsAccess::canCreate(auth()->user()),
            403,
        );
    }

    // ------------------------------------------------------------------
    // Preview (validates, never writes)
    // ------------------------------------------------------------------

    public function openPreview(): void
    {
        $this->authorizeWrite();

        $this->form->check($this->news);
        $this->previewing = true;
    }

    public function closePreview(): void
    {
        $this->previewing = false;
    }

    public function render()
    {
        return view('livewire.news.news-create', [
            'relatedOptions' => $this->form->relatedEnum()->needsEntity() ? $this->form->entities() : collect(),
            'audienceOptions' => $this->form->audienceEnum()->needsTargets() ? $this->form->audienceOptions() : collect(),
            'currentState' => $this->news?->exists ? $this->news->state() : null,
            'preview' => $this->previewing ? $this->previewProps() : null,
        ]);
    }

    /**
     * The props of <x-news.article> for each language, as the item would
     * read once saved with the current settings ("Publish" intent).
     *
     * @return array<string, array<string, mixed>>
     */
    private function previewProps(): array
    {
        $form = $this->form;
        $type = $form->typeEnum();
        $related = $form->relatedEnum();
        $audience = $form->audienceEnum();
        $publishAt = $form->isScheduled() ? $form->parseMoment($form->publish_at) : null;
        $alreadyOut = $this->news?->exists
            && $this->news->status === NewsStatus::Published
            && $this->news->published_at?->lte(now());
        $publishedAt = $publishAt ?? ($alreadyOut ? $this->news->published_at : now());
        $scheduled = $publishedAt->isFuture();

        $relatedEntity = $related->needsEntity()
            ? $form->entities()->firstWhere('id', (int) $form->related_id)
            : null;
        $audienceNames = $audience->needsTargets()
            ? $form->audienceOptions()->whereIn('id', array_map('intval', $form->audience_ids))->pluck('name')->filter()->values()
            : collect();

        $coverUrl = null;
        if ($form->photo instanceof TemporaryUploadedFile) {
            try {
                $coverUrl = $form->photo->temporaryUrl();
            } catch (\Throwable) {
                $coverUrl = null;
            }
        }
        $coverUrl ??= $this->news?->coverUrl();

        $author = $this->news?->creator ?? auth()->user();

        $props = [];

        foreach (['en', 'ar'] as $locale) {
            $props[$locale] = $this->inLocale($locale, function () use (
                $locale, $form, $type, $related, $audience, $publishedAt, $scheduled, $relatedEntity, $audienceNames, $coverUrl, $author
            ) {
                $title = $locale === 'ar' ? ($form->title_ar ?: $form->title_en) : ($form->title_en ?: $form->title_ar);
                $description = $locale === 'ar' ? ($form->description_ar ?: $form->description_en) : ($form->description_en ?: $form->description_ar);
                $usesArabic = $locale === 'ar' ? filled($form->title_ar) : blank($form->title_en);

                $shown = $audienceNames->take(3)->implode($locale === 'ar' ? '، ' : ', ');
                $more = $audienceNames->count() - min(3, $audienceNames->count());

                return [
                    'locale' => $locale,
                    'title' => (string) $title,
                    'description' => (string) $description,
                    'dir' => $usesArabic ? 'rtl' : 'ltr',
                    'coverUrl' => $coverUrl,
                    'typeLabel' => $type->label(),
                    'typeIcon' => $type->icon(),
                    'state' => $scheduled ? NewsState::Scheduled : NewsState::Published,
                    'stateLabel' => ($scheduled ? NewsState::Scheduled : NewsState::Published)->label(),
                    'publishedLabel' => $scheduled
                        ? __('news.form.article.scheduled_for', ['date' => $this->formatMoment($publishedAt)])
                        : __('news.form.article.published_on', ['date' => $this->formatMoment($publishedAt)]),
                    'eventLabel' => $type->isEvent()
                        ? self::eventLabel($form->parseMoment($form->event_starts_at), $form->parseMoment($form->event_ends_at))
                        : null,
                    'relatedLabel' => $relatedEntity
                        ? $related->label().': '.$relatedEntity->name
                        : $related->label(),
                    'relatedIcon' => $related->icon(),
                    'audienceLabel' => $audience->needsTargets()
                        ? $audience->label().': '.$shown.($more > 0 ? ' +'.$more : '')
                        : $audience->label(),
                    'audienceIcon' => $audience->icon(),
                    'authorName' => $author?->employee?->name ?? $author?->email,
                ];
            });
        }

        return $props;
    }

    /** Run $callback with the app (and Carbon) in $locale, then restore both. */
    private function inLocale(string $locale, \Closure $callback): mixed
    {
        $previous = app()->getLocale();
        $previousCarbon = Carbon::getLocale();

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
            Carbon::setLocale($previousCarbon);
        }
    }

    private function formatMoment(CarbonInterface $moment): string
    {
        return $moment->copy()->locale(app()->getLocale())->translatedFormat('j F Y, H:i');
    }

    /** "Tue 10 November 2026, 10:00 – 12:00", or two full moments across days. */
    private static function eventLabel(?CarbonInterface $start, ?CarbonInterface $end): ?string
    {
        if ($start === null) {
            return null;
        }

        $locale = app()->getLocale();
        $from = $start->copy()->locale($locale)->translatedFormat('l j F Y, H:i');

        if ($end === null) {
            return $from;
        }

        $to = $end->isSameDay($start)
            ? $end->copy()->locale($locale)->translatedFormat('H:i')
            : $end->copy()->locale($locale)->translatedFormat('l j F Y, H:i');

        return $from.' – '.$to;
    }
}
