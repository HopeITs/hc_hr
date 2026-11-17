<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An internal label HR puts on applications. Never shown to an applicant.
 *
 * Soft-deleted rather than removed: its assignments stay in the pivot, hidden
 * with it, and come back with the original author and date on restore. Every
 * write goes through RecruitmentTagService.
 */
class RecruitmentTag extends Model
{
    use SoftDeletes;

    public const KIND_NEUTRAL = 'neutral';

    public const KIND_GREEN_FLAG = 'green_flag';

    public const KIND_RED_FLAG = 'red_flag';

    protected $guarded = ['id'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RecruitmentApplicationTag::class, 'tag_id');
    }

    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(
            RecruitmentApplication::class,
            'recruitment_application_tags',
            'tag_id',
            'application_id',
        )->using(RecruitmentApplicationTag::class)
            ->withPivot(['id', 'created_by_user_id'])
            ->withTimestamps();
    }

    /** Whole class names from a closed palette, so Tailwind's scanner finds them. */
    public function classes(): string
    {
        if ($this->kind === self::KIND_GREEN_FLAG) {
            return 'bg-white text-secondary-dark ring-1 ring-inset ring-secondary-light';
        }

        if ($this->kind === self::KIND_RED_FLAG) {
            return 'bg-white text-primary ring-1 ring-inset ring-primary';
        }

        $palette = config('recruitment.tags.palette', []);

        return $palette[$this->color] ?? $palette['neutral'] ?? 'bg-neutral-100 text-neutral-800';
    }

    /** @return array<int, string> */
    public static function kinds(): array
    {
        return [self::KIND_NEUTRAL, self::KIND_GREEN_FLAG, self::KIND_RED_FLAG];
    }

    public function kindLabel(): string
    {
        return __('recruitment-applicant.tags.kinds.'.($this->kind ?: self::KIND_NEUTRAL));
    }
}
