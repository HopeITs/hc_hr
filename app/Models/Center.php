<?php

namespace App\Models;

use App\Traits\HasTranslation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
        // A center may have no hours yet, or hours stored without seconds.
        return $this->open_at ? Carbon::parse($this->open_at)->format('H:i') : '—';
    }

    public function getCloseAtFormattedAttribute(): string
    {
        return $this->close_at ? Carbon::parse($this->close_at)->format('H:i') : '—';
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
     * The programs this center serves, as HR set them on the center (a center
     * may serve several). Read only for positions without a center of their
     * own: see LeaveRequestPricing::programCenters() and
     * programManagerContracts().
     */
    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class);
    }

    /**
     * The current contracts of this center's managers: anyone holding an active
     * contract on a Project Manager position located at the center.
     */
    public function managerContracts(): HasManyThrough
    {
        return $this->contracts()
            ->activeNow()
            ->whereIn(
                'positions.job_level_id',
                JobLevel::query()->where('grade', JobLevel::PROJECT_MANAGER_GRADE)->select('id'),
            );
    }

    /**
     * The current contracts of the project managers of the programs this
     * center serves, in its city, whose position has no center of its own
     * (Study Zone: one manager per city runs every center of the program).
     * Empty for a center that serves no program.
     */
    public function programManagerContracts(): Builder
    {
        $programIds = $this->programs->modelKeys();

        return Contract::query()
            ->activeNow()
            ->whereHas('position', fn (Builder $position): Builder => self::programManagerPositions($position, $programIds, (int) $this->city_id))
            ->when($programIds === [], fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    /**
     * Constrain $positions to project-manager positions without a center,
     * optionally of $programIds in $cityId.
     *
     * @param  array<int, int>|null  $programIds  null: any program
     */
    public static function programManagerPositions(Builder $positions, ?array $programIds = null, ?int $cityId = null): Builder
    {
        return $positions
            ->whereNull($positions->qualifyColumn('center_id'))
            ->whereIn($positions->qualifyColumn('job_level_id'), JobLevel::query()->where('grade', JobLevel::PROJECT_MANAGER_GRADE)->select('id'))
            ->when($programIds !== null, fn (Builder $q) => $q->whereIn($positions->qualifyColumn('program_id'), $programIds))
            ->when($cityId !== null, fn (Builder $q) => $q->where($positions->qualifyColumn('city_id'), $cityId));
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

    /**
     * Names of the people currently managing the center, or a dash when none.
     * An employee holding two manager positions here is listed once.
     */
    public function managerNames(): string
    {
        $contracts = $this->managerContracts;

        if ($this->programs->isNotEmpty()) {
            $contracts = $contracts->concat($this->programManagerContracts()->with('employee')->get());
        }

        $names = $contracts
            ->pluck('employee')
            ->filter()
            ->unique('id')
            ->map(fn (Employee $employee): string => $employee->name);

        return $names->isEmpty() ? '—' : $names->implode(', ');
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
