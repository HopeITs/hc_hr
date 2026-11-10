<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class News extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity;

    protected $table = 'news';

    protected $fillable = [
        'title_ar',
        'title_en',
        'description_ar',
        'description_en',
        'photo',
        'display_until',
        'published_at',
        'event_date',
        'newsable_id',
        'newsable_type',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'display_until' => 'datetime',
        'event_date' => 'date',
    ];

    public function newsable(): MorphTo
    {
        return $this->morphTo();
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

    public function getIsExpiredAttribute(): bool
    {
        return $this->display_until && $this->display_until->isPast();
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

    public function getNewsableNameAttribute(): string
    {
        if (! $this->newsable) {
            return __('general.all_org');
        }

        $relation = $this->newsable;
        $type = class_basename($this->newsable_type);

        $name = match ($type) {
            'City' => trans_choice('general.city', 1),
            'Center' => trans_choice('general.center', 1),
            'Program' => trans_choice('general.program', 1),
            'Employee' => trans_choice('general.employee', 1),
            default => $relation->name ?? __('general.na'),
        };

        if (in_array($type, ['City', 'Center', 'Program', 'Employee'])) {
            return $name.': '.$relation->name;
        }

        return $name;
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
                'newsable_id',
                'newsable_type',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
