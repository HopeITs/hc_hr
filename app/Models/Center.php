<?php

namespace App\Models;

use App\Traits\HasTranslation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Center extends Model
{
    use HasFactory, HasTranslation, LogsActivity;

    protected $fillable = [
        'name_en', 'name_ar', 'weekends', 'open_at', 'close_at', 'city_id',
        'address_en', 'address_ar', 'is_active',
    ];

    public function getOpenAtFormattedAttribute(): string
    {
        return Carbon::createFromFormat('H:i:s', $this->open_at)->format('H:i');
    }

    public function getCloseAtFormattedAttribute(): string
    {
        return Carbon::createFromFormat('H:i:s', $this->close_at)->format('H:i');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function positions()
    {
        return $this->hasMany(Position::class);
    }

    public function contracts()
    {
        return $this->hasManyThrough(Contract::class, Position::class);
    }

    /**
     * The distinct people with a current contract assignment at this center.
     *
     * Both the center headline and its employee directory use this query so a
     * person holding two positions is displayed and counted exactly once.
     */
    public function activeEmployeesQuery(): Builder
    {
        $centerId = (int) $this->getKey();

        return Employee::query()->whereHas(
            'allContracts',
            fn (Builder $contracts): Builder => $contracts
                ->activeNow()
                ->atCenter($centerId),
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('center')
            ->logOnly([
                'name_en',
                'name_ar',
                'city_id',
                'open_at',
                'close_at',
                'address',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getTranslatableAttribute('name'),
            set: fn ($value) => $this->setTranslatableAttribute('name', $value),
        );
    }

    protected function address(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getTranslatableAttribute('address'),
            set: fn ($value) => $this->setTranslatableAttribute('address', $value),
        );
    }

    // Add this accessor to your model
    protected function getWorkHoursAttribute()
    {
        // 1. Carbon::parse works perfectly with 'HH:MM:SS' strings
        // It defaults the date to the current day.
        $open = Carbon::parse($this->open_at);
        $close = Carbon::parse($this->close_at);

        // 2. Fix Overnight Shifts
        // Since we only have TIME, '02:00:00' looks like it happened
        // earlier in the day than '22:00:00'. We must add a day to fix the math.
        if ($close->lte($open)) {
            $close->addDay();
        }

        // 3. Calculate difference in decimal hours
        // Using floatDiffInHours is cleaner than calculating minutes manually (Carbon 2+)
        return $open->floatDiffInHours($close);
    }
}
