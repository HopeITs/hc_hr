<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A device number tied to an employee by hand (when it is not the employee's ID). */
class AttendanceDeviceLink extends Model
{
    protected $fillable = ['device_no', 'employee_id', 'created_by'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
