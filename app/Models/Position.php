<?php

namespace App\Models;

use App\Traits\HasTranslation;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Position extends Model
{
    use HasFactory, HasTranslation, LogsActivity;

    protected $guarded = ['id'];

    protected $casts = [
        'max_emp' => 'integer',
        'exist_emp' => 'integer',
        'is_active' => 'boolean',
    ];

    // --------------------------------------------
    // Relationships
    // --------------------------------------------

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'position_id');
    }

    public function vacancies(): HasMany
    {
        return $this->hasMany(Vacancy::class);
    }

    public function recruitmentRequests(): HasMany
    {
        return $this->hasMany(RecruitmentRequest::class, 'target_position_id');
    }

    /**
     * Active contracts on this position. Employees may hold multiple positions
     * simultaneously; each position lists every active contract here. For the
     * employee's single "primary" contract elsewhere, use Employee::latestActiveContract().
     */
    public function activeContracts(): HasMany
    {
        return $this->contracts()
            ->activeNow()
            ->with('employee');
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class, 'center_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function jobLevel(): BelongsTo
    {
        return $this->belongsTo(JobLevel::class, 'job_level_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function directManager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'direct_manager_id');
    }

    public function techManager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'tech_manager_id');
    }

    public function directTeam(): HasMany
    {
        return $this->hasMany(self::class, 'direct_manager_id')->orderByGrade();
    }

    public function techTeam(): HasMany
    {
        return $this->hasMany(self::class, 'tech_manager_id')->orderByGrade();
    }

    /**
     * Recursive eager-load of the full direct-reporting subtree. Laravel resolves
     * a self-referencing `with()` one depth-level per query (NOT one per node), so
     * loading an entire branch costs O(depth) queries instead of O(nodes) — this is
     * the N+1-safe way to hydrate the whole tree. The card relations are bundled in
     * so every node arrives display-ready (employee, avatar, program, tech manager).
     */
    public function directTeamRecursive(): HasMany
    {
        return $this->directTeam()->with(['directTeamRecursive', ...self::cardRelations('direct')]);
    }

    public function techTeamRecursive(): HasMany
    {
        return $this->techTeam()->with(['techTeamRecursive', ...self::cardRelations('tech')]);
    }

    /**
     * Eager-load set every org-chart card needs: the holder + avatar, the lookups
     * shown on the card, and the *cross* reporting line (the dotted-line manager)
     * so the matrix badge can render without an extra query per node.
     */
    public static function cardRelations(string $line = 'direct'): array
    {
        $crossManager = $line === 'direct' ? 'techManager' : 'directManager';

        return [
            'activeContracts.employee.media',
            'city',
            'center.city',
            'center',
            'program',
            'department',
            'jobLevel',
            "{$crossManager}.activeContracts.employee",
            "{$crossManager}.jobLevel",
        ];
    }

    // --------------------------------------------
    // Query scopes
    // --------------------------------------------

    /**
     * Positions that manage at least one other position on the given reporting
     * line (direct or tech). Drives the "filter by manager" dropdown.
     */
    public function scopeManagersOnly($query, string $line = 'direct')
    {
        $relation = $line === 'tech' ? 'techTeam' : 'directTeam';

        return $query->whereHas($relation);
    }

    /**
     * Order positions by their job level's grade (grade 1 first), regardless of
     * the job_level_id sequence. Used so each org-chart row reads top grade first.
     */
    public function scopeOrderByGrade($query, string $direction = 'asc')
    {
        return $query->orderBy(
            JobLevel::query()
                ->select('grade')
                ->whereColumn('job_levels.id', $query->qualifyColumn('job_level_id')),
            $direction,
        );
    }

    /**
     * Only positions that should appear in the org chart / directory: the
     * position itself is active and it does not belong to a deactivated program.
     */
    public function scopeVisible($query)
    {
        return $query->where($query->qualifyColumn('is_active'), true)
            ->where(function ($q) {
                $q->whereNull($q->qualifyColumn('program_id'))
                    ->orWhereHas('program', fn ($p) => $p->where('is_active', true));
            });
    }

    /**
     * Positions reachable from the given city: either assigned directly to the
     * city, or assigned to a center that belongs to it. Single source of truth
     * for city scoping; keep contract create/edit filters aligned with it.
     *
     * @param  int|array<int, int>  $cityIds  one city, or every city a role covers
     */
    public function scopeInCity($query, int|array $cityIds)
    {
        $cityIds = (array) $cityIds;

        // Qualify the columns: callers such as PositionTable join tables
        // (centers, departments, ...) that also carry a city_id, which MySQL
        // rejects as ambiguous.
        return $query->where(function ($q) use ($cityIds) {
            $q->whereIn($q->qualifyColumn('city_id'), $cityIds)
                ->orWhereHas('center', fn ($c) => $c->whereIn($c->qualifyColumn('city_id'), $cityIds));
        });
    }

    /**
     * The same rule for several governorates at once. Delegates to inCity()
     * rather than restating it: a second copy of "city_id or the centre's
     * city_id" is how the two drift apart.
     *
     * @param  array<int, int>  $cityIds
     */
    public function scopeInCities($query, array $cityIds)
    {
        return $this->scopeInCity($query, $cityIds);
    }

    // --------------------------------------------
    // Helpers
    // --------------------------------------------

    public function employee(): ?Employee
    {
        // Reuse the eager-loaded relation when present to avoid one query per
        // position when this is called inside table/export loops.
        if ($this->relationLoaded('activeContracts')) {
            return $this->activeContracts->first()?->employee;
        }

        return $this->activeContracts()->first()?->employee;
    }

    public function canAssign(): bool
    {
        return $this->exist_emp < $this->max_emp;
    }

    /**
     * Does this position lead a team on the given reporting line? Relies on the
     * team relation already being loaded (org chart) and falls back to a count.
     */
    public function isManagerOnLine(string $line = 'direct'): bool
    {
        $relation = $line === 'tech' ? 'techTeam' : 'directTeam';

        return $this->relationLoaded($relation)
            ? $this->getRelation($relation)->isNotEmpty()
            : $this->{$relation}()->exists();
    }

    public function inc(): void
    {
        $this->increment('exist_emp');
    }

    public function dec(): void
    {
        if ($this->exist_emp > 0) {
            $this->decrement('exist_emp');
        }
    }

    // --------------------------------------------
    // Activity Logging
    // --------------------------------------------

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('position')
            ->logOnly([
                'title',              // translatable
                'center_id',
                'program_id',
                'city_id',
                'job_level_id',
                'department_id',
                'direct_manager_id',
                'tech_manager_id',
                'max_emp',
                'exist_emp',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // --------------------------------------------
    // Accessors & Mutators
    // --------------------------------------------

    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getTranslatableAttribute('title'),
            set: fn ($value) => $this->setTranslatableAttribute('title', $value),
        );
    }
}
