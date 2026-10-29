<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One project (program × governorate) a holiday applies to. */
class HolidayProject extends Model
{
    public $timestamps = false;

    protected $table = 'holiday_project';

    protected $fillable = ['holiday_id', 'program_id', 'city_id'];

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
