<?php

namespace App\Models;

use App\Enums\RecruitmentJobOfferStatus;
use App\Support\Recruitment\PositionRequester;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * The job offer for one selected application (Phase 2 Task 9). One per
 * application (unique key): a revised offer is a new version of it, never a
 * second offer, so an application can never have two live offers.
 *
 * Written only by RecruitmentJobOfferService, which validates each status
 * move, keeps every version, and hands an accepted offer to the hiring
 * conversion exactly once. Never deleted: "gone" is Cancelled.
 */
class RecruitmentJobOffer extends Model
{
    public const CANCEL_APPLICATION_CLOSED = 'application_closed';

    protected $guarded = ['id'];

    protected $hidden = ['response_note', 'cancellation_note'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class);
    }

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(RecruitmentJobOfferVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecruitmentJobOfferVersion::class, 'job_offer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function responseRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'response_recorded_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function hiringConversion(): HasOne
    {
        return $this->hasOne(RecruitmentHiringConversion::class, 'job_offer_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, RecruitmentJobOfferStatus::editable(), true);
    }

    /**
     * Offers visible to a user: HR who can read offers, for applications in
     * their recruitment scope; and the manager who requested the position,
     * read-only. The recruitment scope alone is not enough — for a manager it
     * also covers his direct reports' requests.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $readsOffers = $user->hasActivePermission('recruitment-applicants.view-offers')
            && $user->hasActivePermission('recruitment-applicants.view');

        return $query->where(fn (Builder $visible): Builder => $visible
            ->when($readsOffers, fn (Builder $hr): Builder => $hr->whereHas('application', fn (Builder $applications): Builder => $applications->visibleTo($user)))
            ->orWhere(fn (Builder $requested): Builder => $requested->requestedBy($user)));
    }

    /** Offers of a position this user requested (PositionRequester). */
    public function scopeRequestedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('vacancy.createdFromRequest', fn (Builder $requests): Builder => PositionRequester::constrain($requests, $user));
    }

    public function isRequestedBy(User $user): bool
    {
        return static::query()->whereKey($this->getKey())->requestedBy($user)->exists();
    }

    /** A query-level delete skips model events, so the builder refuses it too. */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function delete()
            {
                throw new LogicException('Job offers are never deleted; cancel them through RecruitmentJobOfferService.');
            }

            public function forceDelete()
            {
                $this->delete();
            }

            public function truncate(): void
            {
                $this->delete();
            }
        };
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Job offers are never deleted; cancel them through RecruitmentJobOfferService.');
        });
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'expires_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
