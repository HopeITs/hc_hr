<?php

namespace App\Livewire\Forms;

use App\Enums\News\AudienceType;
use App\Enums\News\NewsState;
use App\Enums\News\NewsStatus;
use App\Enums\News\NewsType;
use App\Models\Center;
use App\Models\City;
use App\Models\Department;
use App\Models\Employee;
use App\Models\News;
use App\Models\Program;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;
use Throwable;

/**
 * The News & Events create/edit form. Six concepts, kept apart (see News):
 * type, tags (what it is about, free text, optional), Audience (who may see
 * it), Publish At, the event's own dates and Display until. Every audience
 * option comes from audienceOptions(), and validation accepts only those
 * ids, so the browser can never widen the list. Tags are free text:
 * tagSuggestions() only helps typing them.
 *
 * Date fields hold the browser's datetime-local strings (Y-m-d\TH:i), read
 * in config('app.timezone').
 */
class NewsForm extends Form
{
    public const MODE_NOW = 'now';

    public const MODE_SCHEDULE = 'schedule';

    public const INTENT_DRAFT = 'draft';

    public const INTENT_PUBLISH = 'publish';

    public string $type = 'news';

    public string $title_ar = '';

    public ?string $title_en = null;

    public string $description_ar = '';

    public ?string $description_en = null;

    public mixed $photo = null;

    /** @var list<string> free labels, see News::normalizeTags() */
    public array $tags = [];

    public string $audience_type = 'all';

    /** @var list<int|string> */
    public array $audience_ids = [];

    public string $publish_mode = self::MODE_NOW;

    public ?string $publish_at = null;

    public ?string $event_starts_at = null;

    public ?string $event_ends_at = null;

    public ?string $display_until = null;

    /** The item being edited, for the rules that depend on it. Never sent to the browser. */
    protected ?News $subject = null;

    // ------------------------------------------------------------------
    // Enum views of the string properties
    // ------------------------------------------------------------------

    public function typeEnum(): NewsType
    {
        return NewsType::tryFrom($this->type) ?? NewsType::News;
    }

    public function audienceEnum(): AudienceType
    {
        return AudienceType::tryFrom($this->audience_type) ?? AudienceType::All;
    }

    public function isScheduled(): bool
    {
        return $this->publish_mode === self::MODE_SCHEDULE;
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    public function rules(): array
    {
        $isEvent = $this->typeEnum()->isEvent();
        $audience = AudienceType::tryFrom($this->audience_type);

        return [
            'type' => ['required', Rule::enum(NewsType::class)],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'description_ar' => ['required', 'string'],
            'description_en' => ['nullable', 'string'],

            'tags' => ['nullable', 'array', 'max:'.News::MAX_TAGS],
            'tags.*' => ['string', 'max:'.News::MAX_TAG_LENGTH],

            'audience_type' => ['required', Rule::enum(AudienceType::class)],
            'audience_ids' => $audience?->needsTargets()
                ? ['required', 'array', 'min:1']
                : ['nullable', 'array'],
            'audience_ids.*' => $audience?->needsTargets()
                ? ['integer', Rule::in($this->audienceIds($audience))]
                : ['nullable'],

            'publish_mode' => ['required', Rule::in([self::MODE_NOW, self::MODE_SCHEDULE])],
            'publish_at' => $this->isScheduled()
                ? array_filter(['required', 'date', $this->mustBeFuture() ? 'after:now' : null])
                : ['nullable'],

            'event_starts_at' => $isEvent ? ['required', 'date'] : ['nullable'],
            'event_ends_at' => $isEvent ? ['nullable', 'date', 'after_or_equal:event_starts_at'] : ['nullable'],

            'display_until' => ['nullable', 'date', $this->displayUntilRule()],

            'photo' => [
                'nullable',
                Rule::when(
                    $this->photo instanceof TemporaryUploadedFile,
                    ['image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'tags.max' => __('news.form.errors.tags_max', ['max' => News::MAX_TAGS]),
            'tags.*.max' => __('news.form.errors.tag_length', ['max' => News::MAX_TAG_LENGTH]),
            'tags.*.string' => __('news.form.errors.tag_length', ['max' => News::MAX_TAG_LENGTH]),
            'audience_ids.required' => __('news.form.errors.audience_required'),
            'audience_ids.min' => __('news.form.errors.audience_required'),
            'audience_ids.*.in' => __('news.form.errors.audience_offered'),
            'publish_at.required' => __('news.form.errors.publish_at_required'),
            'publish_at.after' => __('news.form.errors.publish_at_future'),
            'event_starts_at.required' => __('news.form.errors.event_start_required'),
            'event_ends_at.after_or_equal' => __('news.form.errors.event_end_after_start'),
        ];
    }

    public function validationAttributes(): array
    {
        return [
            'type' => __('news.form.fields.type'),
            'title_ar' => __('news.form.fields.title_ar'),
            'title_en' => __('news.form.fields.title_en'),
            'description_ar' => __('news.form.fields.description_ar'),
            'description_en' => __('news.form.fields.description_en'),
            'tags' => __('news.form.fields.tags'),
            'audience_type' => __('news.form.fields.audience_type'),
            'audience_ids' => __('news.form.fields.audience_ids'),
            'publish_at' => __('news.form.fields.publish_at'),
            'event_starts_at' => __('news.form.fields.event_starts_at'),
            'event_ends_at' => __('news.form.fields.event_ends_at'),
            'display_until' => __('news.form.fields.display_until'),
            'photo' => __('news.form.fields.photo'),
        ];
    }

    /**
     * Validate against the item being edited ($news null = a new item).
     * The single entry point: the page's preview, both save actions and the
     * assistant all come through here.
     */
    public function check(?News $news = null, array $attributes = []): array
    {
        $this->subject = $news?->exists ? $news : null;
        $this->normalize();

        return $this->validate(null, [], $attributes);
    }

    /** When the item becomes visible, as the chosen settings would store it. */
    public function publishMoment(): ?CarbonInterface
    {
        if ($this->isScheduled()) {
            return $this->parseMoment($this->publish_at);
        }

        // Editing an item that is already out keeps its original moment.
        if ($this->subject?->status === NewsStatus::Published
            && $this->subject->published_at !== null
            && $this->subject->published_at->lte(now())) {
            return $this->subject->published_at;
        }

        return now();
    }

    // ------------------------------------------------------------------
    // Options
    // ------------------------------------------------------------------

    /**
     * What the tag input suggests: the most used tags, then the names of the
     * active cities, centers and programs. Suggestions only: any text is a
     * valid tag.
     *
     * @return list<string>
     */
    public static function tagSuggestions(int $popular = 30): array
    {
        $names = collect()
            ->concat(City::query()->where('is_active', true)->get())
            ->concat(Center::query()->where('is_active', true)->get())
            ->concat(Program::query()->where('is_active', true)->get())
            ->map(fn ($entity) => (string) ($entity->name ?? ''))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE);

        $seen = [];

        foreach ([...News::popularTags($popular), ...$names->all()] as $tag) {
            $tag = trim((string) preg_replace('/\s+/u', ' ', $tag));

            if ($tag !== '' && mb_strlen($tag) <= News::MAX_TAG_LENGTH) {
                $seen[mb_strtolower($tag)] ??= $tag;
            }
        }

        return array_values($seen);
    }

    /** What the audience list offers for $audience (default: the chosen one). */
    public function audienceOptions(?AudienceType $audience = null): Collection
    {
        return self::optionsFor(($audience ?? $this->audienceEnum())->modelClass());
    }

    /**
     * The selectable records of a kind, the same lists the old target
     * dropdown offered: active cities, centers and employees, every
     * program and department.
     *
     * @param  class-string|null  $class
     */
    public static function optionsFor(?string $class): Collection
    {
        return match ($class) {
            City::class => City::select('id', 'name_en', 'name_ar')->where('is_active', true)->orderBy('name_en')->get(),
            Center::class => Center::select('id', 'name_en', 'name_ar')->where('is_active', true)->orderBy(transColumn('name'))->get(),
            Program::class => Program::select('id', 'name')->orderBy('name')->get(),
            Department::class => Department::select('id', 'name')->orderBy('name')->get(),
            Employee::class => Employee::select('id', 'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar')
                ->where('is_active', true)
                ->orderBy('first_name_en')
                ->get(),
            default => collect(),
        };
    }

    // ------------------------------------------------------------------
    // Dependent selections
    // ------------------------------------------------------------------

    public function updatedAudienceType(): void
    {
        $this->audience_ids = [];
    }

    public function updatedType(): void
    {
        if (! $this->typeEnum()->isEvent()) {
            $this->event_starts_at = null;
            $this->event_ends_at = null;
        }
    }

    // ------------------------------------------------------------------
    // Load / save
    // ------------------------------------------------------------------

    public function fillFromNews(News $news): void
    {
        $scheduled = $news->published_at !== null && (
            $news->published_at->isFuture() || $news->status === NewsStatus::Draft
        );

        $this->fill([
            'type' => $news->type?->value ?? NewsType::News->value,
            'title_ar' => $news->title_ar ?? '',
            'title_en' => $news->title_en,
            'description_ar' => $news->description_ar ?? '',
            'description_en' => $news->description_en,
            'tags' => $news->tagNames(),
            'audience_type' => $news->audience_type?->value ?? AudienceType::All->value,
            'audience_ids' => $news->audienceTargets()->pluck('target_id')->map(fn ($id) => (string) $id)->all(),
            'publish_mode' => $scheduled ? self::MODE_SCHEDULE : self::MODE_NOW,
            'publish_at' => $scheduled ? $news->published_at->format('Y-m-d\TH:i') : null,
            'event_starts_at' => $news->event_starts_at?->format('Y-m-d\TH:i')
                ?? ($news->isEvent() ? $news->event_date?->format('Y-m-d\TH:i') : null),
            'event_ends_at' => $news->event_ends_at?->format('Y-m-d\TH:i'),
            'display_until' => $news->display_until?->format('Y-m-d\TH:i'),
            'photo' => null,
        ]);
    }

    /**
     * Validate and write. $intent 'draft' keeps the item out of every feed;
     * 'publish' releases it now or at the scheduled moment.
     */
    public function persist(?News $news = null, string $intent = self::INTENT_PUBLISH): News
    {
        $this->check($news);

        $isNew = ! $news?->exists;
        $news = $isNew ? new News : $news;
        $publishAt = $this->isScheduled() ? $this->parseMoment($this->publish_at) : null;

        if ($isNew) {
            $news->created_by = auth()->id();
        } else {
            $news->updated_by = auth()->id();
        }

        if ($intent === self::INTENT_DRAFT) {
            $news->status = NewsStatus::Draft;
            $news->published_at = $publishAt;
        } else {
            $alreadyOut = ! $isNew
                && $news->status === NewsStatus::Published
                && $news->published_at !== null
                && $news->published_at->lte(now());

            $news->status = NewsStatus::Published;
            $news->published_at = $publishAt ?? ($alreadyOut ? $news->published_at : now());
        }

        $isEvent = $this->typeEnum()->isEvent();

        $news->fill([
            'type' => $this->typeEnum(),
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en ?: null,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en ?: null,
            'event_starts_at' => $isEvent ? $this->parseMoment($this->event_starts_at) : null,
            'event_ends_at' => $isEvent ? $this->parseMoment($this->event_ends_at) : null,
            'display_until' => $this->parseDisplayUntil($this->display_until),
        ]);

        if (! $isEvent) {
            $news->event_date = null;
        }

        // The legacy Related To is no longer written: a new item has none,
        // and an old item keeps what it had (its tag was made from it).
        if ($isNew) {
            $news->newsable_type = null;
            $news->newsable_id = null;
        }

        // syncAudience() saves the item (with the audience type) first.
        $news->syncAudience($this->audienceEnum(), $this->audience_ids);
        $this->tags = $news->syncTags($this->tags);

        if ($this->photo instanceof TemporaryUploadedFile) {
            $news->clearMediaCollection('news-image');
            $news->addMedia($this->photo)->toMediaCollection('news-image');
        }

        return $news;
    }

    // ------------------------------------------------------------------
    // Dates
    // ------------------------------------------------------------------

    public function parseMoment(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /** A plain date (Y-m-d) means "until the end of that day". */
    public function parseDisplayUntil(mixed $value): ?CarbonInterface
    {
        $moment = $this->parseMoment($value);

        if ($moment !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $value))) {
            return $moment->endOfDay();
        }

        return $moment;
    }

    /** A future Publish At is required unless the item is already out. */
    private function mustBeFuture(): bool
    {
        if ($this->subject === null) {
            return true;
        }

        return in_array($this->subject->state(), [NewsState::Draft, NewsState::Scheduled], true);
    }

    /** Display until: after the publish moment, and for an event not before it starts. */
    private function displayUntilRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $until = $this->parseDisplayUntil($value);

            if ($until === null) {
                return;
            }

            $publishAt = $this->publishMoment();

            if ($publishAt !== null && $until->lte($publishAt)) {
                $fail(__('news.form.errors.display_until_after_publish'));

                return;
            }

            $start = $this->typeEnum()->isEvent() ? $this->parseMoment($this->event_starts_at) : null;

            if ($start !== null && $until->lt($start)) {
                $fail(__('news.form.errors.display_until_after_event'));
            }
        };
    }

    /** Values that do not apply to the chosen type/audience are cleared, never stored. */
    private function normalize(): void
    {
        if (! $this->typeEnum()->isEvent()) {
            $this->event_starts_at = null;
            $this->event_ends_at = null;
        }

        $this->tags = $this->cleanTags($this->tags);

        if (! AudienceType::tryFrom($this->audience_type)?->needsTargets()) {
            $this->audience_ids = [];
        }

        $this->audience_ids = array_values(array_unique(array_filter(
            $this->audience_ids,
            fn ($id) => is_scalar($id) && (string) $id !== '',
        )));

        if (! $this->isScheduled()) {
            $this->publish_at = null;
        }
    }

    /**
     * Trimmed, spaces collapsed, blanks and case-insensitive duplicates
     * dropped. Length and count are left for the rules to report.
     *
     * @return list<mixed>
     */
    private function cleanTags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return is_scalar($tags) && trim((string) $tags) !== '' ? [trim((string) $tags)] : [];
        }

        $clean = [];

        foreach ($tags as $tag) {
            if (! is_scalar($tag)) {
                // Left in place for the tags.* rule to refuse.
                $clean[] = $tag;

                continue;
            }

            $tag = trim((string) preg_replace('/\s+/u', ' ', (string) $tag));

            if ($tag !== '') {
                $clean[mb_strtolower($tag)] ??= $tag;
            }
        }

        return array_values($clean);
    }

    /** @return list<int> */
    private function audienceIds(AudienceType $audience): array
    {
        return $this->audienceOptions($audience)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
