<?php

namespace App\Models;

use App\Services\RecruitmentScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class RecruitmentApplication extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['submission_key_hash'];

    private static bool $applyingDecision = false;

    /**
     * @internal RecruitmentApplicationStatusService only, inside its
     *           transaction and after it has validated the transition.
     */
    public static function applyDecision(self $application, string $status): void
    {
        self::$applyingDecision = true;

        try {
            $application->forceFill(['status' => $status])->save();
        } finally {
            self::$applyingDecision = false;
        }
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplicant::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(HiringCampaign::class);
    }

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(ApplicationFormVersion::class, 'form_version_id');
    }

    public function publicLink(): BelongsTo
    {
        return $this->belongsTo(PublicRecruitmentLink::class, 'public_link_id');
    }

    public function campaignInvite(): BelongsTo
    {
        return $this->belongsTo(RecruitmentCampaignInvite::class, 'campaign_invite_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(RecruitmentApplicationAnswer::class, 'application_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(RecruitmentApplicantFile::class, 'application_id');
    }

    public function cvFiles(): HasMany
    {
        return $this->files()->where('file_type', RecruitmentApplicantFile::TYPE_CV);
    }

    public function tagAssignments(): HasMany
    {
        return $this->hasMany(RecruitmentApplicationTag::class, 'application_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            RecruitmentTag::class,
            'recruitment_application_tags',
            'application_id',
            'tag_id',
        )->using(RecruitmentApplicationTag::class)
            ->withPivot(['id', 'created_by_user_id'])
            ->withTimestamps();
    }

    public function indicatorSnapshot(): HasOne
    {
        return $this->hasOne(RecruitmentApplicationIndicatorSnapshot::class, 'application_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(RecruitmentApplicantNote::class, 'application_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(RecruitmentApplicationDecision::class, 'application_id');
    }

    public function rejectionNotice(): HasOne
    {
        return $this->hasOne(RecruitmentRejectionNotice::class, 'application_id');
    }

    public function endorsements(): HasMany
    {
        return $this->hasMany(RecruitmentApplicationEndorsement::class, 'application_id');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(RecruitmentInterview::class, 'application_id');
    }

    public function jobOffer(): HasOne
    {
        return $this->hasOne(RecruitmentJobOffer::class, 'application_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(RecruitmentScopeResolver::class)->scopeApplications($query, $user);
    }

    /**
     * Status is written in exactly one place. Any other save that changes it —
     * a mass assignment from request data, a stray forceFill — is refused
     * here, so a decision can never happen without its history row.
     */
    protected static function booted(): void
    {
        static::updating(function (self $application): void {
            if ($application->isDirty('status') && ! self::$applyingDecision) {
                throw new LogicException('An application status changes only through RecruitmentApplicationStatusService.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'consent_payload_json' => 'array',
            'privacy_accepted_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }
}
