<?php

namespace App\Enums\News;

use App\Models\News;
use App\Support\Status\HasStatusTone;
use App\Support\Status\StatusTone;
use Carbon\CarbonInterface;

/**
 * Where an item is in its lifecycle right now, derived from the stored
 * status and the dates, never stored. The backend is the only place that
 * decides it (News::scopeInState mirrors this in SQL).
 */
enum NewsState: string implements HasStatusTone
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Expired = 'expired';
    case Archived = 'archived';

    public static function of(News $news, ?CarbonInterface $now = null): self
    {
        $now ??= now();

        return match (true) {
            $news->status === NewsStatus::Archived => self::Archived,
            $news->status === NewsStatus::Draft => self::Draft,
            $news->published_at === null || $news->published_at->gt($now) => self::Scheduled,
            $news->display_until !== null && $news->display_until->lte($now) => self::Expired,
            default => self::Published,
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Draft => StatusTone::Attn,
            self::Scheduled => StatusTone::Wait,
            self::Published => StatusTone::Work,
            self::Expired => StatusTone::Done,
            self::Archived => StatusTone::Stop,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'pen',
            self::Scheduled => 'clock',
            self::Published => 'check',
            self::Expired => 'ban',
            self::Archived => 'folder',
        };
    }

    public function label(): string
    {
        return __('news.states.'.$this->value);
    }
}
