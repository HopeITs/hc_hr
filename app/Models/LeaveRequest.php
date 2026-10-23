<?php

namespace App\Models;

use App\Enums\LeaveRequestStatus;
use App\Services\LeaveRequestPricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveRequest extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'leave_balance_id', 'type', 'start_at', 'end_at', 'reason',
        'approved_by', 'approved_at', 'hr_approved', 'reject_reason', 'status',
        'alternative_employee_id',
        // Pricing snapshot (A3-D D-5) and the December exception flag (D-7).
        'computed_hours', 'rule_version', 'is_exceptional',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'approved_at' => 'datetime',
        'hr_approved' => 'boolean',
        'status' => 'integer',
        'computed_hours' => 'float',
        'is_exceptional' => 'boolean',
    ];

    public function leaveBalance(): BelongsTo
    {
        return $this->belongsTo(LeaveBalance::class);
    }

    public function leaveType(): HasOneThrough|LeaveRequest
    {
        return $this->hasOneThrough(
            LeaveType::class,      // final model you want
            LeaveBalance::class,   // intermediate model
            'id',                  // LeaveBalance.id
            'id',                  // LeaveType.id
            'leave_balance_id',    // LeaveRequest.leave_balance_id
            'type_id',        // LeaveBalance.leave_type_id
        );
    }

    public function alternativeEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'alternative_employee_id');
    }

    public function getDurationAttribute(): string
    {
        // Priced by LeaveRequestPricing (A3-D D-5): show the stored amount, so
        // the list reads the same figure that was previewed and deducted.
        // Rows from before the snapshot keep the legacy wording below (§4.2).
        if ($this->computed_hours !== null) {
            return $this->snapshotDuration();
        }

        $weekends = array_map('intval', explode(',', $this->leaveBalance->contract->position->center->weekends ?? '0,6'));
        Carbon::setWeekendDays($weekends);

        // Hour-based leave: show hours + minutes
        if ($this->type === 'hour') {
            $floatHours = $this->start_at->floatDiffInRealHours($this->end_at);

            $hours = (int) $floatHours;
            $minutes = (int) round(($floatHours - $hours) * 60);

            // 60 minutes rolls into an extra hour
            if ($minutes >= 60) {
                $hours += intdiv($minutes, 60);
                $minutes = $minutes % 60;
            }

            $parts = [];

            if ($hours > 0) {
                $parts[] = trans_choice('general.count.hours', $hours);
            }

            if ($minutes > 0) {
                $parts[] = trans_choice('general.count.minutes', $minutes);
            }

            // Fallback if both are zero (unlikely but safe)
            return join_counts($parts) ?: trans_choice('general.count.minutes', 0);
        }

        // Day-based leave (unchanged)
        $start = $this->start_at;
        $end = $this->end_at;

        // Include the end date
        $days = $start->diffInWeekDays($end) + 1;

        return trans_choice('general.count.days', $days);
    }

    public function getDurationInHoursAttribute(): float
    {
        // Determine weekend days from the center
        $weekends = array_map('intval', explode(',', $this->leaveBalance->contract->position->center->weekends ?? '0,6'));
        Carbon::setWeekendDays($weekends);

        // For hour-type leave, we already have precise decimal hours
        if ($this->type === 'hour') {
            return $this->start_at->floatDiffInRealHours($this->end_at);
        }

        // For day-type leave: calculate days
        $start = $this->start_at;
        $end = $this->end_at;

        // Include the end date
        $days = $start->diffInWeekDays($end) + 1;
        $contract = $this->leaveBalance->contract;

        // Get weekly hours from contract
        $weeklyHours = (float) $contract->weekly_hours;

        // Hours per workday
        $hoursPerDay = $weeklyHours / 5;

        // Total hours for this leave
        return $days * $hoursPerDay;
    }

    public function scopePending($q)
    {
        return $q->where('status', LeaveRequestStatus::Pending);
    }

    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        $role = $user->myRole();

        if ($role === 'super-admin' || $role === 'HR-SYR') {
            return $query;
        }

        if ($role && str_starts_with($role, 'HR-')) {
            return $query->whereHas(
                'leaveBalance.contract.employee',
                fn (Builder $employeeQuery) => $employeeQuery->whereIn('city_id', $user->activeCityIds() ?? []),
            );
        }

        if (in_array($role, ['manager', 'general-manager'], true)) {
            $managerPositionIds = Contract::query()
                ->activeNow()
                ->where('employee_id', $user->employee_id)
                ->pluck('position_id');

            if ($managerPositionIds->isEmpty()) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas(
                'leaveBalance.contract.position',
                fn (Builder $positionQuery) => $positionQuery
                    ->whereIn('direct_manager_id', $managerPositionIds)
                    ->orWhereIn('tech_manager_id', $managerPositionIds),
            );
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopeUpcoming($query)
    {
        return $query->where(fn ($query) => $query
            ->whereDate('start_at', '>=', today())
            ->orWhereDate('end_at', '>=', today()));
    }

    /**
     * Upcoming leave requests visible on the dashboard card.
     *
     * - super-admin, general-manager, HR-SYR: all statuses, org-wide
     * - HR-{city}: all statuses, city-scoped
     * - manager: pending + accepted for direct/tech reports
     * - employee: accepted only, same department or center
     */
    public function scopeUpcomingVisibleTo($query, User $user)
    {
        $role = $user->myRole() ?? '';

        if (in_array($role, ['HR-SYR', 'super-admin', 'general-manager'], true)) {
            return $query;
        }

        if (str_starts_with($role, 'HR-')) {
            return $query->whereHas(
                'leaveBalance.contract.employee',
                fn ($q) => $q->whereIn('city_id', $user->activeCityIds() ?? []),
            );
        }

        if ($role === 'manager') {
            $positionIds = $user->profile?->positions->modelKeys() ?? [];

            if ($positionIds === []) {
                return $query->whereRaw('0 = 1');
            }

            return $query
                ->whereIn('status', [LeaveRequestStatus::Pending, LeaveRequestStatus::Accepted])
                ->whereHas(
                    'leaveBalance.contract.employee.positions',
                    fn ($q) => $q->whereIn('direct_manager_id', $positionIds)
                        ->orWhereIn('tech_manager_id', $positionIds),
                );
        }

        $positions = $user->profile?->positions;

        if (! $positions || $positions->isEmpty()) {
            return $query->whereRaw('0 = 1');
        }

        $departmentIds = $positions->pluck('department_id')->filter()->unique()->values();
        $centerIds = $positions->pluck('center_id')->filter()->unique()->values();

        if ($departmentIds->isEmpty() && $centerIds->isEmpty()) {
            return $query->whereRaw('0 = 1');
        }

        return $query
            ->where('status', LeaveRequestStatus::Accepted)
            ->whereHas(
                'leaveBalance.contract.position',
                fn ($q) => $q->where(function ($q) use ($departmentIds, $centerIds) {
                    if ($departmentIds->isNotEmpty()) {
                        $q->whereIn('department_id', $departmentIds);
                    }

                    if ($centerIds->isNotEmpty()) {
                        $departmentIds->isNotEmpty()
                            ? $q->orWhereIn('center_id', $centerIds)
                            : $q->whereIn('center_id', $centerIds);
                    }
                }),
            );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('leave_request')
            ->logOnly([
                'leave_balance_id',
                'start_at',
                'end_at',
                'type',
                'status',
                'alternative_employee_id',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * computed_hours worded as days + hours + minutes, with the contract's
     * own day length (weekly hours ÷ working days). An hourly request stays
     * in hours; so does a schedule without a day length.
     */
    private function snapshotDuration(): string
    {
        $contract = $this->leaveBalance?->contract;
        $perDay = $this->type !== 'hour' && $contract
            ? app(LeaveRequestPricing::class)->hoursPerDayFor($contract)
            : null;

        [$days, $hours] = LeaveRequestPricing::splitDays((float) $this->computed_hours, $perDay);

        $wholeHours = (int) floor($hours);
        $minutes = (int) round(($hours - $wholeHours) * 60);

        if ($minutes >= 60) {
            $wholeHours += intdiv($minutes, 60);
            $minutes %= 60;
        }

        $parts = [];

        if ($days > 0) {
            $parts[] = trans_choice('general.count.days', $days);
        }

        if ($wholeHours > 0) {
            $parts[] = trans_choice('general.count.hours', $wholeHours);
        }

        if ($minutes > 0) {
            $parts[] = trans_choice('general.count.minutes', $minutes);
        }

        return join_counts($parts) ?: trans_choice('general.count.'.($this->type === 'hour' ? 'minutes' : 'days'), 0);
    }
}
