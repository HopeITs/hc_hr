<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A serial number that called the receiver but is not registered: HR can add it from here. */
class AttendanceDeviceSighting extends Model
{
    protected $fillable = ['serial', 'last_ip', 'attempts', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
