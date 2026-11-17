<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One punch on a device: who (by device number, and the employee once known) and when. */
class AttendancePunch extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['import_id', 'device_id', 'device_no', 'employee_id', 'punched_at'];

    protected $casts = [
        'punched_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
