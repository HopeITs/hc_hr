<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One person's month of attendance and how far it has got: open, confirmed by the employee,
 * approved by the manager, locked by HR. The signed paper is kept with it.
 */
class AttendanceMonth extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const OPEN = 'open';

    public const CONFIRMED = 'confirmed';

    public const APPROVED = 'approved';

    public const LOCKED = 'locked';

    protected $fillable = [
        'employee_id', 'month',
        'employee_confirmed_at', 'employee_confirmed_by',
        'approved_at', 'approved_by', 'overtime_hours_approved',
        'locked_at', 'locked_by',
        'credited_hours', 'credited_balance_id', 'credited_at',
    ];

    protected $casts = [
        'month' => 'date',
        'employee_confirmed_at' => 'datetime',
        'approved_at' => 'datetime',
        'locked_at' => 'datetime',
        'credited_at' => 'datetime',
        'overtime_hours_approved' => 'float',
        'credited_hours' => 'float',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** Where the month stands. */
    public function state(): string
    {
        return match (true) {
            $this->locked_at !== null => self::LOCKED,
            $this->approved_at !== null => self::APPROVED,
            $this->employee_confirmed_at !== null => self::CONFIRMED,
            default => self::OPEN,
        };
    }

    /** The month's record, not yet saved when nothing has happened to it. */
    public static function for(Employee|int $employee, CarbonImmutable $month): self
    {
        $id = $employee instanceof Employee ? $employee->id : $employee;
        $first = $month->startOfMonth()->toDateString();

        // whereDate: the column is a date, but some databases keep it with a time of day.
        return self::query()->where('employee_id', $id)->whereDate('month', $first)->first()
            ?? new self(['employee_id' => $id, 'month' => $first]);
    }

    public function registerMediaCollections(): void
    {
        // The signed timesheet carries a person's attendance: private, read back through
        // AttendanceSignedController, which applies the same scope as the page.
        $this->addMediaCollection('signed')->useDisk('local')->singleFile();
    }
}
