<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One attempt at one of the four X2 tasks (X2-I). Anonymous by construction:
 * the table has no column that points at a person or an HR record.
 */
class TaskAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'had_validation_error' => 'boolean',
        ];
    }
}
