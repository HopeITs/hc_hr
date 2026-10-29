<?php

namespace App\Models;

use App\Traits\HasTranslation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An official holiday, a one-off grant, or the birthday holiday
 * (team-coverage-contract §6.1).
 *
 * scope national → everyone; scope projects → the program × governorate
 * pairs in holiday_project. A birthday row is only a validity window: each
 * volunteer's day is computed from their own date of birth (BirthdayHoliday).
 */
class Holiday extends Model
{
    use HasTranslation;

    public const SCOPE_NATIONAL = 'national';

    public const SCOPE_PROJECTS = 'projects';

    public const KIND_OFFICIAL = 'official';

    public const KIND_GRANT = 'grant';

    public const KIND_BIRTHDAY = 'birthday';

    protected $fillable = [
        'name_en', 'name_ar', 'start_date', 'end_date', 'is_recurring',
        'scope', 'kind', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_recurring' => 'boolean',
    ];

    /** @return HasMany<HolidayProject, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(HolidayProject::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isNational(): bool
    {
        return $this->scope === self::SCOPE_NATIONAL;
    }

    /** Holidays that reach a volunteer in this program × governorate. */
    public function scopeApplicableTo(Builder $query, ?int $programId, ?int $cityId): Builder
    {
        return $query->where(function (Builder $q) use ($programId, $cityId) {
            $q->where('scope', self::SCOPE_NATIONAL);

            if ($programId && $cityId) {
                $q->orWhere(fn (Builder $p) => $p
                    ->where('scope', self::SCOPE_PROJECTS)
                    ->whereHas('projects', fn (Builder $link) => $link
                        ->where('program_id', $programId)
                        ->where('city_id', $cityId)));
            }
        });
    }

    /** Rows that may have a date between $from and $to (recurring rows always may). */
    public function scopeOccurringBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('is_recurring', true)
            ->orWhere(fn (Builder $r) => $r
                ->whereDate('start_date', '<=', $to->toDateString())
                ->whereDate('end_date', '>=', $from->toDateString())));
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getTranslatableAttribute('name'),
            set: fn ($value) => $this->setTranslatableAttribute('name', $value),
        );
    }
}
