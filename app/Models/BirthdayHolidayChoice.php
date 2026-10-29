<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The working day taken instead of a birthday that is not one (H-4). */
class BirthdayHolidayChoice extends Model
{
    public const PENDING = 0;

    public const APPROVED = 1;

    public const REJECTED = 2;

    protected $fillable = ['holiday_id', 'employee_id', 'year', 'chosen_date', 'status', 'decided_by', 'decided_at'];

    protected $casts = [
        'chosen_date' => 'date',
        'decided_at' => 'datetime',
        'status' => 'integer',
        'year' => 'integer',
    ];

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
