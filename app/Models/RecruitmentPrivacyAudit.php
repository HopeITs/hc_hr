<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row per sensitive act on applicant data: who did it, when, under which
 * scope and filter, and to how many records.
 *
 * Written once and never changed. update() and delete() are refused here so no
 * code path can quietly rewrite what was done; the rows hold identifiers and
 * counts, never the data that was exported.
 */
class RecruitmentPrivacyAudit extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_EXPORT = 'export';

    public const ACTION_BULK_DOWNLOAD = 'bulk_download';

    public const ACTION_ERASURE_REQUEST = 'erasure_request';

    public const ACTION_ANONYMIZATION = 'anonymization';

    public const OUTCOME_SUCCESS = 'success';

    /** Attempted and refused: permission, scope, size or rate. */
    public const OUTCOME_REFUSED = 'refused';

    /** Recorded but not carried out yet, because something stands in the way. */
    public const OUTCOME_BLOCKED = 'blocked';

    /** The actor of an act nobody performed by hand: the retention sweep. */
    public const SCOPE_SYSTEM = 'system';

    protected $guarded = ['id'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Privacy audit rows are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Privacy audit rows are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'scope_city_ids' => 'array',
            'filters_json' => 'array',
            'details_json' => 'array',
            'record_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
