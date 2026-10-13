<?php

namespace App\Models;

use App\Services\RecruitmentScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * An internal HR note on an applicant, or on one of their applications.
 *
 * Internal means exactly that: nothing on the public side reads this model,
 * and HR sees a note only through the scope of what it is attached to (see
 * RecruitmentScopeResolver::scopeApplicantNotes()).
 *
 * A note is written once. Its author (user_id) and date are set by
 * RecruitmentApplicantNoteService and cannot change afterwards, and removal is
 * a soft delete made by that service alongside an audit entry — calling
 * delete() directly is refused, so no code path can drop a note silently.
 */
class RecruitmentApplicantNote extends Model
{
    use SoftDeletes;

    public const UPDATED_AT = null;

    public const TYPE_GENERAL = 'general';

    /** The justification recorded when a CV is archived. Never deletable. */
    public const TYPE_CV_ARCHIVE = 'cv_archive';

    /** Written once; only the deletion columns may change after that. */
    private const IMMUTABLE = [
        'applicant_id', 'application_id', 'user_id', 'note_type', 'is_sensitive', 'body', 'created_at',
    ];

    protected $guarded = ['id'];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplicant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The author. Same column as user(); named for what it means here. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(RecruitmentScopeResolver::class)->scopeApplicantNotes($query, $user);
    }

    public function isDeletable(): bool
    {
        return $this->note_type !== self::TYPE_CV_ARCHIVE;
    }

    protected static function booted(): void
    {
        static::updating(function (self $note): void {
            if ($note->isDirty(self::IMMUTABLE)) {
                throw new LogicException('Internal notes cannot be edited.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Notes are removed through RecruitmentApplicantNoteService, which audits the removal.');
        });
    }

    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'created_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
