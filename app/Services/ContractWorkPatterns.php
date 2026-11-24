<?php

namespace App\Services;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\LeaveRequestStatus;
use App\Models\AttendanceMonth;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractWorkPattern;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place that writes contract_work_patterns: the days a contract
 * works from a date on (Contract::workWeekdaysOn() reads them back).
 *
 * - The composer's picked days are the contract's first pattern, from its
 *   start date (syncInitial()).
 * - HR moves the day off on a date without an amendment (change()): the
 *   number of days is a contract term, which days is not, and is not printed.
 * - An amendment that changes the days adds a pattern from its own date
 *   (fromAmendment()), so the months before keep the days worked then.
 */
class ContractWorkPatterns
{
    public function __construct(private LeaveRequestPricing $pricing) {}

    /**
     * The composer saved the contract: its picked days are the pattern from
     * its start date. Rows from a later date (a day off moved since) stay.
     */
    public function syncInitial(Contract $contract): void
    {
        if ($contract->start_date === null) {
            return;
        }

        $start = $contract->start_date->toDateString();

        DB::transaction(function () use ($contract, $start): void {
            $contract->workPatterns()->whereDate('effective_from', '<=', $start)->delete();

            if ($contract->workWeekdays() !== null) {
                $contract->workPatterns()->create([
                    'weekdays' => implode(',', $contract->workWeekdays()),
                    'effective_from' => $start,
                    'created_by' => auth()->id(),
                ]);
            }
        });

        $contract->unsetRelation('workPatterns');
    }

    /**
     * An amendment changed the contract's days: they apply from its date.
     */
    public function fromAmendment(ContractAmendment $amendment): void
    {
        $contract = $amendment->contract;
        $days = Contract::parseWeekdays($amendment->terms_after['work_weekdays'] ?? null);

        $contract->workPatterns()->updateOrCreate(
            ['effective_from' => $amendment->effective_from->toDateString()],
            ['weekdays' => $days === [] ? null : implode(',', $days), 'contract_amendment_id' => $amendment->id, 'created_by' => $amendment->approved_by ?? auth()->id(), 'note' => null],
        );

        $contract->unsetRelation('workPatterns');
    }

    /**
     * Move the day(s) off from $from on: the same number of working days, none
     * of them the place's day off, inside the contract's term and outside any
     * attendance month HR has locked.
     *
     * @param  array<int, int>  $days
     *
     * @throws ValidationException
     */
    public function change(Contract $contract, array $days, string $from, ?string $note = null, ?User $by = null): ContractWorkPattern
    {
        $days = Contract::parseWeekdays($days);
        $date = CarbonImmutable::parse($from)->startOfDay();

        $this->assertChangeable($contract, $days, $date);

        $pattern = DB::transaction(fn () => $contract->workPatterns()->updateOrCreate(
            ['effective_from' => $date->toDateString()],
            ['weekdays' => implode(',', $days), 'contract_amendment_id' => null, 'created_by' => $by?->id, 'note' => filled($note) ? trim($note) : null],
        ));

        $contract->unsetRelation('workPatterns');

        app(ActivityLogger::class)->log(
            'work-days-changed',
            subject: $contract,
            causer: $by,
            properties: ['work_weekdays' => implode(',', $days), 'effective_from' => $date->toDateString()],
            logName: 'contract',
        );

        return $pattern;
    }

    /**
     * How many days a week the contract states — the number a moved day off
     * must keep. Null when it states none (nothing to hold it to).
     */
    public function daysPerWeek(Contract $contract): ?int
    {
        if ($contract->workWeekdays() !== null) {
            return count($contract->workWeekdays());
        }

        if ($contract->work_day_from !== null && $contract->work_day_to !== null && $contract->work_day_to >= $contract->work_day_from) {
            return (int) $contract->work_day_to - (int) $contract->work_day_from + 1;
        }

        return null;
    }

    /**
     * Accepted leave from $from on: priced with the days in force when it was
     * decided, so HR sees them before moving a day off under them.
     *
     * @return Collection<int, LeaveRequest>
     */
    public function acceptedLeavesFrom(Contract $contract, string $from): Collection
    {
        return LeaveRequest::query()
            ->whereHas('leaveBalance', fn ($q) => $q->where('contract_id', $contract->id))
            ->where('status', LeaveRequestStatus::Accepted)
            ->whereDate('end_at', '>=', CarbonImmutable::parse($from)->toDateString())
            ->orderBy('start_at')
            ->get();
    }

    /**
     * @param  array<int, int>  $days
     *
     * @throws ValidationException
     */
    private function assertChangeable(Contract $contract, array $days, CarbonImmutable $date): void
    {
        $fail = fn (string $field, string $key, array $params = []) => throw ValidationException::withMessages([
            $field => __('contracts.work-days.errors.'.$key, $params),
        ]);

        if (! $contract->is_active || $contract->isTerminated() || (int) $contract->type === ContractTypeEnum::Draft) {
            $fail('days', 'contract-not-in-force');
        }

        if ($days === []) {
            $fail('days', 'none');
        }

        [$placeWeekend, $source] = $this->pricing->placeWeekendFor($contract);

        if ($source !== 'default' && array_intersect($days, $placeWeekend) !== []) {
            $fail('days', 'center-day-off');
        }

        $expected = $this->daysPerWeek($contract);

        if ($expected !== null && count($days) !== $expected) {
            $fail('days', 'count', ['count' => $expected]);
        }

        if ($contract->start_date === null || $date->lt($contract->start_date->copy()->startOfDay())
            || ($contract->end_date !== null && $date->gt($contract->end_date->copy()->startOfDay()))) {
            $fail('effective_from', 'outside-term');
        }

        $locked = AttendanceMonth::query()
            ->where('employee_id', $contract->employee_id)
            ->whereNotNull('locked_at')
            ->whereDate('month', '>=', $date->startOfMonth()->toDateString())
            ->orderBy('month')
            ->first();

        if ($locked !== null) {
            $fail('effective_from', 'month-locked', ['month' => $locked->month->format('m/Y')]);
        }
    }
}
