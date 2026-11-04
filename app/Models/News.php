<?php

namespace App\Models;

use App\Enums\News\AudienceType;
use App\Enums\News\NewsState;
use App\Enums\News\NewsStatus;
use App\Enums\News\NewsType;
use App\Enums\News\RelatedTo;
use App\Support\News\NewsViewer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A piece of News & Events content. Six concepts, kept apart:
 *
 *   type            NewsType       what it is (news, announcement, event)
 *   newsable_*      RelatedTo      what it is ABOUT; never restricts who sees it
 *   audience_type + audienceTargets  AudienceType  who may see it (scopeVisibleTo)
 *   status + published_at           NewsStatus     draft / published / archived, and Publish At
 *   event_starts_at / event_ends_at              when an event happens (event_date = its local day)
 *   display_until                                 expiration of the item in active feeds
 *
 * Every date is stored and compared in config('app.timezone'). Scheduled,
 * expired, upcoming, today and past are derived here in SQL, never stored and
 * never decided in Blade.
 */
class News extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity;

    protected $table = 'news';

    protected $fillable = [
        'type',
        'status',
        'title_ar',
        'title_en',
        'description_ar',
        'description_en',
        'photo',
        'display_until',
        'published_at',
        'event_date',
        'event_starts_at',
        'event_ends_at',
        'archived_at',
        'newsable_id',
        'newsable_type',
        'audience_type',
    ];

    protected $attributes = [
        'type' => 'news',
        'status' => 'published',
        'audience_type' => 'all',
    ];

    protected function casts(): array
    {
        return [
            'type' => NewsType::class,
            'status' => NewsStatus::class,
            'audience_type' => AudienceType::class,
            'published_at' => 'datetime',
            'display_until' => 'datetime',
            'archived_at' => 'datetime',
            'event_date' => 'date',
            'event_starts_at' => 'datetime',
            'event_ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // event_date is the event's local day: kept for date-indexed queries
        // and for the code that has always read it.
        static::saving(function (News $news) {
            if ($news->type === NewsType::Event && $news->event_starts_at) {
                $news->event_date = $news->event_starts_at->copy()->startOfDay();
            }
        });
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    /** Related To: the subject of the item. */
    public function newsable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The ids of the cities/centers/... in the audience (kind = audience_type). */
    public function audienceTargets(): HasMany
    {
        return $this->hasMany(NewsAudienceTarget::class);
    }

    public function externalPublications(): HasMany
    {
        return $this->hasMany(NewsExternalPublication::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->creator();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->updater();
    }

    // ------------------------------------------------------------------
    // Lifecycle scopes (the SQL twin of NewsState::of)
    // ------------------------------------------------------------------

    /** Published, its Publish At reached, not expired: what feeds may show. */
    public function scopeLive(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query
            ->where('news.status', NewsStatus::Published->value)
            ->whereNotNull('news.published_at')
            ->where('news.published_at', '<=', $now)
            ->where(fn (Builder $q) => $q->whereNull('news.display_until')->orWhere('news.display_until', '>', $now));
    }

    /**
     * Published and its Publish At reached, expired or not: what the
     * News & Events page and archive may show (past events stay reachable).
     */
    public function scopeReleased(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query
            ->where('news.status', NewsStatus::Published->value)
            ->whereNotNull('news.published_at')
            ->where('news.published_at', '<=', $now);
    }

    public function scopeInState(Builder $query, NewsState|string $state, ?CarbonInterface $now = null): Builder
    {
        $state = $state instanceof NewsState ? $state : NewsState::from($state);
        $now ??= now();

        return match ($state) {
            NewsState::Draft => $query->where('news.status', NewsStatus::Draft->value),
            NewsState::Archived => $query->where('news.status', NewsStatus::Archived->value),
            NewsState::Scheduled => $query->where('news.status', NewsStatus::Published->value)
                ->where(fn (Builder $q) => $q->whereNull('news.published_at')->orWhere('news.published_at', '>', $now)),
            NewsState::Expired => $query->released($now)
                ->whereNotNull('news.display_until')->where('news.display_until', '<=', $now),
            NewsState::Published => $query->live($now),
        };
    }

    public function scopeOfType(Builder $query, NewsType|string $type): Builder
    {
        return $query->where('news.type', $type instanceof NewsType ? $type->value : $type);
    }

    /** News articles and announcements: everything that is not an event. */
    public function scopeArticles(Builder $query): Builder
    {
        return $query->where('news.type', '!=', NewsType::Event->value);
    }

    // ------------------------------------------------------------------
    // Event categorization (by the app timezone's calendar day)
    // ------------------------------------------------------------------

    /** Events starting after today. */
    public function scopeUpcomingEvents(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $endOfToday = ($now ?? now())->copy()->endOfDay();

        return $query->ofType(NewsType::Event)
            ->where('news.event_starts_at', '>', $endOfToday)
            ->orderBy('news.event_starts_at');
    }

    /** Events taking place today: starting today, or running across today. */
    public function scopeTodayEvents(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();
        $start = $now->copy()->startOfDay();
        $end = $now->copy()->endOfDay();

        return $query->ofType(NewsType::Event)
            ->where('news.event_starts_at', '<=', $end)
            ->where(fn (Builder $q) => $q
                ->where('news.event_starts_at', '>=', $start)
                ->orWhere('news.event_ends_at', '>=', $start))
            ->orderBy('news.event_starts_at');
    }

    /** Events that ended before today. */
    public function scopePastEvents(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $start = ($now ?? now())->copy()->startOfDay();

        return $query->ofType(NewsType::Event)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $one) => $one->whereNull('news.event_ends_at')->where('news.event_starts_at', '<', $start))
                ->orWhere(fn (Builder $one) => $one->whereNotNull('news.event_ends_at')->where('news.event_ends_at', '<', $start)))
            ->orderByDesc('news.event_starts_at');
    }

    // ------------------------------------------------------------------
    // Audience (backend-enforced visibility)
    // ------------------------------------------------------------------

    /**
     * Items whose audience includes $viewer. Related To plays no part.
     * A viewer with no employee record only sees organization-wide items.
     */
    public function scopeVisibleTo(Builder $query, NewsViewer|Employee|User|null $viewer): Builder
    {
        $viewer = NewsViewer::from($viewer);

        return $query->where(function (Builder $q) use ($viewer) {
            $q->where('news.audience_type', AudienceType::All->value);

            foreach ($viewer->targets() as $type => $ids) {
                if ($ids === []) {
                    continue;
                }

                $q->orWhere(fn (Builder $one) => $one
                    ->where('news.audience_type', $type)
                    ->whereExists(fn ($sub) => $sub->selectRaw('1')
                        ->from('news_audience_targets')
                        ->whereColumn('news_audience_targets.news_id', 'news.id')
                        ->whereIn('news_audience_targets.target_id', $ids)));
            }
        });
    }

    public function isVisibleTo(NewsViewer|Employee|User|null $viewer): bool
    {
        return static::query()->whereKey($this->getKey())->visibleTo($viewer)->exists();
    }

    // ------------------------------------------------------------------
    // Derived values
    // ------------------------------------------------------------------

    public function state(?CarbonInterface $now = null): NewsState
    {
        return NewsState::of($this, $now);
    }

    public function isEvent(): bool
    {
        return $this->type === NewsType::Event;
    }

    public function relatedTo(): RelatedTo
    {
        return RelatedTo::fromModelClass($this->newsable_type);
    }

    /** 'upcoming' | 'today' | 'past' for an event, null otherwise. */
    public function eventTiming(?CarbonInterface $now = null): ?string
    {
        if (! $this->isEvent() || ! $this->event_starts_at) {
            return null;
        }

        $now ??= now();
        $start = $now->copy()->startOfDay();
        $end = $now->copy()->endOfDay();
        $last = $this->event_ends_at ?? $this->event_starts_at;

        return match (true) {
            $this->event_starts_at->gt($end) => 'upcoming',
            $last->lt($start) => 'past',
            default => 'today',
        };
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->display_until !== null && $this->display_until->isPast();
    }

    public function coverUrl(): ?string
    {
        return $this->getFirstMediaUrl('news-image') ?: null;
    }

    public function getTitleAttribute(): string
    {
        return $this->localizedTitle();
    }

    public function getDescriptionAttribute(): ?string
    {
        return $this->localizedDescription();
    }

    public function localizedTitle(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ar'
            ? ($this->title_ar ?: ($this->title_en ?? ''))
            : ($this->title_en ?: ($this->title_ar ?? ''));
    }

    public function localizedDescription(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ar'
            ? ($this->description_ar ?: $this->description_en)
            : ($this->description_en ?: $this->description_ar);
    }

    /** The direction of the text actually shown for $locale (it may fall back). */
    public function textDirection(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $usesArabic = $locale === 'ar' ? filled($this->title_ar) : blank($this->title_en);

        return $usesArabic ? 'rtl' : 'ltr';
    }

    /** "City: Aleppo", or "Organization" — the Related To context, for display. */
    public function getNewsableNameAttribute(): string
    {
        $related = $this->relatedTo();

        if (! $related->needsEntity() || ! $this->newsable) {
            return RelatedTo::Organization->label();
        }

        return $related->label().': '.($this->newsable->name ?? __('general.na'));
    }

    /** "All employees", or "Centers: A, B" — the audience, for display. */
    public function audienceSummary(int $names = 3): string
    {
        if (! $this->audience_type->needsTargets()) {
            return $this->audience_type->label();
        }

        $entities = $this->audienceEntities();
        $shown = $entities->take($names)->pluck('name')->filter()->implode('، ');
        $more = $entities->count() - min($names, $entities->count());

        return $this->audience_type->label().': '.$shown.($more > 0 ? ' +'.$more : '');
    }

    /** The audience's cities/centers/... as models. */
    public function audienceEntities(): Collection
    {
        $class = $this->audience_type->modelClass();

        if ($class === null) {
            return collect();
        }

        $ids = $this->relationLoaded('audienceTargets')
            ? $this->audienceTargets->pluck('target_id')
            : $this->audienceTargets()->pluck('target_id');

        return $ids->isEmpty() ? collect() : $class::query()->whereIn('id', $ids)->get();
    }

    /** @param  iterable<int>  $ids */
    public function syncAudience(AudienceType $type, iterable $ids): void
    {
        $this->audience_type = $type;
        $this->save();

        $this->audienceTargets()->delete();

        if ($type->needsTargets()) {
            $rows = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()
                ->map(fn (int $id) => ['news_id' => $this->getKey(), 'target_id' => $id])->values()->all();

            if ($rows !== []) {
                NewsAudienceTarget::query()->insert($rows);
            }
        }

        $this->unsetRelation('audienceTargets');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('news-image')->singleFile();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('news')
            ->logOnly([
                'type',
                'status',
                'title_ar',
                'title_en',
                'created_by',
                'updated_by',
                'description_ar',
                'description_en',
                'photo',
                'display_until',
                'published_at',
                'event_date',
                'event_starts_at',
                'event_ends_at',
                'archived_at',
                'newsable_id',
                'newsable_type',
                'audience_type',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
