<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One uploaded device report. */
class AttendanceImport extends Model
{
    protected $fillable = [
        'user_id', 'file_name', 'period_start', 'period_end',
        'punches_total', 'punches_new', 'device_numbers',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class, 'import_id');
    }
}
