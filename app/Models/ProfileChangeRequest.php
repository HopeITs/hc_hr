<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A change an employee proposed to their own profile, waiting for HR
 * (D1, profile-contract.md §5.1). Written only by ProfileChangeRequests.
 *
 * Files stay on the private disk: `file` (a certificate) and `photo` (already
 * re-encoded by SecureImageUploadService on submission). They are copied to
 * the employee's own record when HR approves, never re-encoded again.
 */
class ProfileChangeRequest extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity;

    public const PENDING = 0;

    public const APPROVED = 1;

    public const REJECTED = 2;

    public const REPLACED = 3;

    public const SECTIONS = ['education', 'course', 'experience', 'relative', 'photo'];

    protected $fillable = ['employee_id', 'section', 'proposed', 'status', 'requested_by', 'decided_by', 'decided_at', 'reason'];

    protected $casts = [
        'proposed' => 'array',
        'status' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->singleFile();
        $this->addMediaCollection('photo')->singleFile();
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Who proposed and who decided; the proposed values themselves may be
        // family details, so they are not copied into the log.
        return LogOptions::defaults()
            ->useLogName('profile_change')
            ->logOnly(['employee_id', 'section', 'status', 'decided_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
