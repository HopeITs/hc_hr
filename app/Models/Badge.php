<?php

namespace App\Models;

use App\Traits\RedactsSensitiveActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Badge extends Model
{
    use HasFactory, LogsActivity, RedactsSensitiveActivityLog;

    /**
     * Salary figures and the free-text reason/notes behind a promotion or
     * warning are logged by name only. @see RedactsSensitiveActivityLog
     */
    protected array $sensitiveLogAttributes = [
        'current_salary',
        'new_salary',
        'reason',
        'notes',
    ];

    protected $fillable = [
        'employee_id',
        'evaluation_id',
        'currency',
        'type',
        'subtype',
        'date',
        'reason',
        'amount',
        'new_position',
        'leave_unit',
        'current_salary',
        'new_salary',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'datetime:d-m-Y',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function evaluation()
    {
        return $this->belongsTo(Evaluation::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->hasActivePermission('badges.view')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('employee', fn (Builder $employee) => static::constrainEmployees($employee, $user));
    }

    /** Is $employee's record one whose badges the active role may handle? */
    public static function coversEmployee(User $user, Employee $employee): bool
    {
        return static::constrainEmployees(Employee::query()->whereKey($employee->getKey()), $user)->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('badge')
            ->logOnly([
                'employee_id',
                'evaluation_id',
                'type',
                'subtype',
                'currency',
                'date',
                'amount',
                'new_position',
                'leave_unit',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Whose badges the active role reaches, permissions aside.
     *
     * activeCityIds() answers "every city" for any role that is not a regional
     * HR role, so it cannot be the whole rule: a manager or employee granted
     * badges.view in Access Management would see the entire organisation.
     * Only the roles below are city-scoped; a manager follows the same line
     * as Contract::scopeVisibleTo() (their direct/technical reports) and any
     * other role reaches nobody.
     */
    protected static function constrainEmployees(Builder $employees, User $user): Builder
    {
        if ($user->activeRoleIs('HR', 'general-manager', 'super-admin')) {
            $cityIds = $user->activeCityIds();

            return $employees->when($cityIds !== null, fn (Builder $query) => $query->whereIn('city_id', $cityIds));
        }

        if ($user->activeRoleIs('manager')) {
            return $employees->whereHas('allContracts', fn (Builder $contract) => $contract->visibleTo($user));
        }

        return $employees->whereRaw('1 = 0');
    }
}
