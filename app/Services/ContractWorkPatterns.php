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
 * works from a date on (Contract::workWeekdaysOn() reads them back). With no
 * row, the contract's own terms say it (its picked days, or its day range).
 *
 * - The composer's picked days are the contract's first row, from its start
 *   date (syncInitial()).
 * - HR moves the days on a date without an amendment (change()): the number
 *   of days is a contract term, which days is not, and is not printed.
 * - An amendment that changes the week (picked days, day range, fixed or
 *   flexible) adds a row from its own date (fromAmendment()).
 * - Whatever writes the first change also writes the days the contract had
 *   from its start (ensureStart()), so the months before keep them.
 */
class ContractWorkPatterns
{
    /** The amendable terms that say which days are worked. */
    public const DAY_TERMS = ['work_weekdays', 'work_day_from', 'work_day_to', 'schedule_mode'];

    public function __construct(private LeaveRequestPricing $pricing) {}

    /**
     * The composer saved the contract: the days it states are its row from
     * its start date: picked days always, a day range only when later rows
     * exist (otherwise its terms already say it). Later rows stay.
     */
    public function syncInitial(Contract $contract): void
    {
        if ($contract->start_date === null) {
            return;
        }

        $start = $contract->start_date->toDateString();

        DB::transaction(function () use ($contract, $start): void {
            $contract->workPatterns()->whereDate('effective_from', '<=', $start)->delete();

            $days = Contract::termsDays($contract->getAttributes());
            $later = $contract->workPatterns()->whereDate('effective_from', '>', $start)->exists();

            if ($days !== null && ($contract->workWeekdays() !== null || $later)) {
                $contract->workPatterns()->create([
                    'weekdays' => implode(',', $days),
                    'effective_from' => $start,
                    'created_by' => auth()->id(),
                ]);
            }
        });

        $contract->unsetRelation('workPatterns');
    }

    /**
     * An amendment changed the contract's week (its picked days, its day
     * range, or fixed/flexible): from its date the week is the amendment's —
     * its picked days, or none (the range and the center's weekend).
     */
    public function fromAmendment(ContractAmendment $amendment): void
    {
        $contract = $amendment->contract;
        $from = $amendment->effective_from->toDateString();
        $days = Contract::termsDays($amendment->terms_after ?? []);

        // The days before it are the ones it replaces.
        $this->ensureStart($contract, Contract::termsDays($amendment->terms_before ?? []));

        // The signed sheet decides the week from its date: a day off HR moved
        // by hand on or after it was about the old week, and goes.
        $contract->workPatterns()
            ->whereNull('contract_amendment_id')
            ->whereDate('effective_from', '>=', $from)
            ->delete();

        $this->putOn($contract, $from, [
            'weekdays' => $days === null ? null : implode(',', $days),
            'contract_amendment_id' => $amendment->id,
            'created_by' => $amendment->approved_by ?? auth()->id(),
            'note' => null,
        ]);

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

        // A day range has no row yet: the days before the change are its range.
        $this->ensureStart($contract, $contract->workWeekdaysOn($date->subDay()));

        $pattern = DB::transaction(fn () => $this->putOn($contract, $date->toDateString(), [
            'weekdays' => implode(',', $days),
            'contract_amendment_id' => null,
            'created_by' => $by?->id,
            'note' => filled($note) ? trim($note) : null,
        ]));

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
        $days = Contract::termsDays($contract->getAttributes());

        return $days === null ? null : count($days);
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
     * The row from the contract's start, when none covers it yet: the days it
     * worked before its first change. Nothing for a flexible week.
     *
     * @param  array<int, int>|null  $days
     */
    private function ensureStart(Contract $contract, ?array $days): void
    {
        if ($contract->start_date === null || $days === null) {
            return;
        }

        $start = $contract->start_date->toDateString();

        if (! $contract->workPatterns()->whereDate('effective_from', '<=', $start)->exists()) {
            $contract->workPatterns()->create(['weekdays' => implode(',', $days), 'effective_from' => $start]);
        }
    }

    /**
     * The row from $date, replaced or created. Found by whereDate, not by the
     * string: a date column comes back with a time of day on some databases
     * (SQLite), and the unique key would then refuse a second row.
     */
    private function putOn(Contract $contract, string $date, array $values): ContractWorkPattern
    {
        $row = $contract->workPatterns()->whereDate('effective_from', $date)->first();

        if ($row) {
            $row->update($values);

            return $row;
        }

        return $contract->workPatterns()->create($values + ['effective_from' => $date]);
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

        if (! $contract->is_active || $contract->hasEndedByTermination() || (int) $contract->type === ContractTypeEnum::Draft) {
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

        // In force until a termination dated later (R2-3b), not past it.
        if ($contract->hasPendingTermination() && $date->gt($contract->terminated_at->copy()->startOfDay())) {
            $fail('effective_from', 'after-termination', ['date' => $contract->terminated_at->format('d-m-Y')]);
        }

        // The days an amendment set on its date are the signed terms: a change
        // on that very date would replace them and drop the amendment from the
        // history. Another date, or another amendment.
        if ($contract->workPatterns()->whereDate('effective_from', $date->toDateString())->whereNotNull('contract_amendment_id')->exists()) {
            $fail('effective_from', 'amendment-date');
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
