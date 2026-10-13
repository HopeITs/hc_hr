<?php

namespace App\Models;

use App\Services\RecruitmentScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecruitmentApplicant extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['identity_key_hash'];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** Set only when the applicant signed in as staff to apply. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isInternal(): bool
    {
        return $this->employee_id !== null;
    }

    public function applications(): HasMany
    {
        return $this->hasMany(RecruitmentApplication::class, 'applicant_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(RecruitmentApplicantFile::class, 'applicant_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(RecruitmentApplicantNote::class, 'applicant_id');
    }

    public function hiringConversions(): HasMany
    {
        return $this->hasMany(RecruitmentHiringConversion::class, 'applicant_id');
    }

    /** Known sources are translated; anything else is shown as stored, made readable. */
    public static function sourceLabel(?string $source): string
    {
        if (blank($source)) {
            return '—';
        }

        $key = 'recruitment-applicant.sources.'.$source;

        return __($key) === $key ? str($source)->headline()->toString() : __($key);
    }

    /** The earlier applicant sharing this phone under another email — a hint, never a merge. */
    public function possibleDuplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'possible_duplicate_of_applicant_id');
    }

    public function possibleDuplicates(): HasMany
    {
        return $this->hasMany(self::class, 'possible_duplicate_of_applicant_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(RecruitmentScopeResolver::class)->scopeApplicants($query, $user);
    }

    protected function casts(): array
    {
        return [
            'identity_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'retention_until' => 'datetime',
            'anonymized_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'erasure_requested_at' => 'datetime',
        ];
    }
}
