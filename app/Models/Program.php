<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LaravelIdea\Helper\App\Models\_IH_Employee_QB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Program extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, LogsActivity;

    protected $fillable = [
        'name', 'description_en', 'description_ar', 'code', 'slug',
        'start_date', 'end_date', 'is_active',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function cities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'positions', 'program_id', 'city_id');
    }

    public function centers(): BelongsToMany
    {
        return $this->belongsToMany(Center::class, 'positions', 'program_id', 'center_id')->distinct();
    }

    public function jobLevels(): BelongsToMany
    {
        return $this->belongsToMany(JobLevel::class, 'positions', 'program_id', 'job_level_id');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'positions', 'program_id', 'department_id');
    }

    public function positions()
    {
        return $this->hasMany(Position::class);
    }

    /**
     * Add the aggregate values displayed on the program index cards.
     *
     * A program's cities are determined by its positions' centers. The direct
     * positions.city_id value may differ from the center's city, so it must not
     * be used for this count.
     */
    public function scopeWithCardCounts(Builder $query): Builder
    {
        return $query
            ->addSelect([
                'cities_count' => Center::query()
                    ->selectRaw('count(distinct city_id)')
                    ->whereExists(fn ($positions) => $positions
                        ->selectRaw('1')
                        ->from('positions')
                        ->whereColumn('positions.center_id', 'centers.id')
                        ->whereColumn('positions.program_id', 'programs.id')),
            ])
            ->withCount('contracts');
    }

    public function registerMediaCollections(): void
    {
        // Local fallback: the previous ui-avatars.com URL disclosed program
        // names to a third-party host and would need an img-src exception in
        // the content security policy.
        $this->addMediaCollection('logo')
            ->useFallbackUrl(asset('images/default-avatar.png'))
            ->useFallbackPath(public_path('images/default-avatar.png'))
            ->singleFile();
    }

    public function contracts()
    {
        return $this->hasManyThrough(Contract::class, Position::class)->where('contracts.is_active', true);
    }

    public function employees(): Builder|_IH_Employee_QB
    {
        return Employee::query()
            ->select('employees.*')
            ->distinct()
            ->whereHas('contracts.position', function ($q) {
                $q->where('program_id', $this->id);
            });
    }

    public function manager()
    {
        return $this->employees()
            ->whereHas('contracts', function ($q) {
                $q->where('is_active', true)
                    ->whereHas('position.jobLevel', function ($qq) {
                        $qq->where('grade', 1);
                    });
            })
            ->first();
    }

    public function getProgramManagerAttribute()
    {
        return $this->manager();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('program')
            ->logFillable()      // log everything except guarded fields
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
