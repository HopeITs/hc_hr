<?php

namespace App\Models;

use App\Enums\LeaveTypeCases;
use App\Services\LeaveRequestPricing;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveBalance extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['contract_id', 'type_id', 'deserved', 'used', 'amendment_offset', 'overtime_credit'];

    protected $casts = ['overtime_credit' => 'float'];

    /** @var array{0: float|null}|null */
    private ?array $workHoursPerDay = null;

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function getIntTotalAttribute(): int
    {
        [$days] = $this->splitIntoDays(($this->deserved ?? 0) + $this->creditHours());

        return (int) $days;
    }

    public function getDeservedFormattedAttribute(): string
    {
        [$days, $remaining] = $this->splitIntoDays(($this->deserved ?? 0) + $this->creditHours());

        return $this->getFormattedLabel($days, $remaining);
    }

    public function getUsedFormattedAttribute(): string
    {
        [$days, $remaining] = $this->splitIntoDays($this->used ?? 0);

        return $this->getFormattedLabel($days, $remaining);
    }

    public function eligibleAnnualLeaveHours(?Carbon $contractStart = null, ?Carbon $contractEnd = null): int
    {
        $contract = $this->contract;
        if (! $contract) {
            return 0;
        }

        $start = Carbon::parse($contract->start_date);
        $end = Carbon::parse($contract->end_date);
        $now = now();

        // If contract hasn’t started yet, no leave
        if ($now->lt($start)) {
            return 0;
        }

        $totalHours = $this->deserved ?? 0; // e.g. 96 hours
        if ($totalHours <= 0) {
            return (int) round($this->creditHours());
        }

        // Contract duration in months
        $contractMonths = max(1, $start->diffInMonths($end));
        // Divide into 3 equal sections
        $sectionMonths = ceil($contractMonths / 3);
        $hoursPerSection = $totalHours / 3;

        // Figure out how many sections have passed
        $monthsPassed = $start->diffInMonths($now);
        $sectionsPassed = floor($monthsPassed / $sectionMonths) + 1;

        // Cap at total. Overtime given back as free days is not earned over the contract: it is
        // there in full from the moment it is credited.
        return (int) round(min($sectionsPassed * $hoursPerSection, $totalHours) + $this->creditHours());
    }

    /** Hours of overtime given back as free days (AppServicesAttendanceMonthWorkflow). */
    public function creditHours(): float
    {
        return (float) ($this->overtime_credit ?? 0);
    }

    /**
     * Whether employee can request specific number of hours right now.
     */
    public function canRequestAnnualLeave($requestedHours): bool
    {
        $eligible = $this->eligibleAnnualLeaveHours();

        $used = $this->used ?? 0;

        $remaining = $eligible - $used;

        return $requestedHours <= $remaining;
    }

    public function isAnnual(): bool
    {
        return $this->leaveType->id === LeaveTypeCases::Annual;
    }

    public function getIntRemainingAttribute(): int
    {
        [$days] = $this->splitIntoDays(($this->deserved ?? 0) + $this->creditHours() - ($this->used ?? 0));

        return (int) $days;
    }

    /**
     * What may be requested today, in hours: for annual leave only the share of
     * the entitlement earned so far (eligibleAnnualLeaveHours), for every other
     * type the whole of it. The one figure the leave form, its validation and
     * the balance cards all show. Pending requests are not taken off here; they
     * are listed beside it.
     */
    public function availableHours(): float
    {
        $base = (int) $this->type_id === LeaveTypeCases::Annual
            ? (float) $this->eligibleAnnualLeaveHours()
            : (float) ($this->deserved ?? 0);

        return round($base - (float) ($this->used ?? 0), 2);
    }

    public function getIntAvailableAttribute(): int
    {
        [$days] = $this->splitIntoDays(max(0, $this->availableHours()));

        return (int) $days;
    }

    public function getAvailableFormattedAttribute(): string
    {
        [$days, $remaining] = $this->splitIntoDays(max(0, $this->availableHours()));

        return $this->getFormattedLabel($days, $remaining);
    }

    /**
     * The same figures in whole hours, for the employee's own cards (my leaves,
     * the dashboard tile): "6 days and 1 hour" reads at a glance where "6 days,
     * 1 hour and 20 minutes" does not. Every figure rounds DOWN to the half hour
     * (roundedParts): what may still be taken never promises time the request
     * form would refuse, and an untouched balance shows the same figure as its total
     * (rounding them two ways made "14 days and 2 hours" left of "14 days and 3
     * hours"). Requests themselves still take any number of minutes. HR's tables and the exports keep the exact *_formatted.
     */
    public function getAvailableRoundedAttribute(): string
    {
        return $this->roundedLabel(max(0, $this->availableHours()));
    }

    public function getUsedRoundedAttribute(): string
    {
        return $this->roundedLabel((float) ($this->used ?? 0));
    }

    public function getDeservedRoundedAttribute(): string
    {
        return $this->roundedLabel((float) ($this->deserved ?? 0) + $this->creditHours());
    }

    /** pending_days / pending_hours are set by Employee::availableLeaves() (days, then the hours left over). */
    public function getPendingRoundedAttribute(): string
    {
        return $this->roundedParts((int) ($this->pending_days ?? 0), (float) ($this->pending_hours ?? 0));
    }

    public function roundedLabel(float $hours): string
    {
        [$days, $rest] = $this->splitIntoDays($hours);

        return $this->roundedParts((int) $days, (float) $rest);
    }

    public function getRemainingAttribute(): string
    {
        [$days, $remaining] = $this->splitIntoDays(($this->deserved ?? 0) + $this->creditHours() - ($this->used ?? 0));

        return $this->getFormattedLabel($days, $remaining);
    }

    public function getFormattedLabel($days, $remaining): string
    {
        // Split remaining into hours + minutes
        $hours = (int) $remaining;
        $minutes = (int) round(($remaining - $hours) * 60);

        // Normalize: 60 minutes → +1 hour
        if ($minutes >= 60) {
            $hours += intdiv($minutes, 60);
            $minutes = $minutes % 60;
        }

        // -------------------------------------------
        // Build the original text for days
        // -------------------------------------------
        $text = '';
        if ($days > 0) {
            $text = trans_choice('general.count.days', $days, ['count' => '<strong class="text-secondary-dark">'.$days.'</strong>']).' ';
        }

        $result = $text;

        // -------------------------------------------
        // Now handle hours + minutes
        // -------------------------------------------
        if ($hours > 0 || $minutes > 0) {

            $timeText = [];

            if ($hours > 0) {
                $timeText[] = trans_choice('general.count.hours', $hours, ['count' => '<strong class="text-secondary-dark">'.$hours.'</strong>']);
            }

            if ($minutes > 0) {
                $timeText[] = trans_choice('general.count.minutes', $minutes, ['count' => '<strong class="text-secondary-dark">'.$minutes.'</strong>']);
            }

            $finalTime = join_counts($timeText);

            if ($text !== '') {
                $result = __('general.and', [
                    'attr1' => $text,
                    'attr2' => $finalTime,
                ]);
            } else {
                $result = $finalTime;
            }
        }

        // Original fallback behavior
        if ($text === '' && $hours === 0 && $minutes === 0) {
            $result = trans_choice('general.count.days', 0, ['count' => '<strong class="text-secondary-dark">0</strong>']);
        }

        return $result;
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'type_id');
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('leave_balance')
            ->logOnly([
                'contract_id',
                'type_id',
                'deserved',
                'used',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Whole and half hours only: the minutes beyond the last half hour are left
     * out until they add up to one ("2 hours and 40 minutes" shows "2½ hours").
     * Display only: a request may still be for any number of minutes.
     */
    private function roundedParts(int $days, float $rest): string
    {
        // A hair under a half hour after float arithmetic is that half hour, not the one below.
        $halves = (int) floor(($rest + 0.001) * 2);
        $hours = intdiv($halves, 2);

        if ($halves % 2 === 0) {
            return $this->getFormattedLabel($days, $hours);
        }

        $time = trans_choice('general.count.hours-half', $hours, ['count' => '<strong class="text-secondary-dark">'.$hours.'</strong>']);

        if ($days === 0) {
            return $time;
        }

        // The same joining as getFormattedLabel(): "14 days and 2½ hours".
        return __('general.and', [
            'attr1' => trans_choice('general.count.days', $days, ['count' => '<strong class="text-secondary-dark">'.$days.'</strong>']).' ',
            'attr2' => $time,
        ]);
    }

    /**
     * Length of one working day of the contract, from the same pricing
     * service as the leave request preview (weekly hours ÷ working days of
     * the center's weekend), so the balance card and the preview word the
     * same hours as the same days. Null when unknown (no hours, irregular
     * schedule); the figures then stay in hours. Memoized: a card reads it
     * three times.
     */
    private function workHoursPerDay(): ?float
    {
        if (! $this->contract) {
            return null;
        }

        $this->workHoursPerDay ??= [app(LeaveRequestPricing::class)->hoursPerDayFor($this->contract)];

        return $this->workHoursPerDay[0];
    }

    /**
     * Split an amount of hours into [whole days, remaining hours].
     * When the daily work hours are unknown, everything stays as hours.
     */
    private function splitIntoDays(float $hours): array
    {
        return LeaveRequestPricing::splitDays($hours, $this->workHoursPerDay());
    }
}
