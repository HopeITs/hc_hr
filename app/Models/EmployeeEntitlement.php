<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's item of the Permissions Library: required (should be done),
 * granted (done outside the system) or revoked (taken back). `source` says
 * whether it came from the position's defaults or was added by hand.
 */
class EmployeeEntitlement extends Model
{
    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_MANUAL = 'manual';

    public const STATE_REQUIRED = 'required';

    public const STATE_GRANTED = 'granted';

    public const STATE_REVOKED = 'revoked';

    protected $fillable = [
        'employee_id', 'entitlement_id', 'source', 'state',
        'granted_by', 'granted_at', 'revoked_by', 'revoked_at', 'needs_review_at', 'note',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'needs_review_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** Not taken back: still to do, or done. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('state'), [self::STATE_REQUIRED, self::STATE_GRANTED]);
    }
}
