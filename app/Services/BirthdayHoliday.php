<?php

namespace App\Services;

use App\Models\BirthdayHolidayChoice;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The birthday holiday (team-coverage-contract §6.1, H-4 … H-6).
 *
 * A Holiday row of kind "birthday" is only a validity window; each volunteer's
 * day is their own birthday (actual_dob, else dob — day and month only, 29 Feb
 * → 28 Feb in other years) inside that window and inside their contract.
 * A birthday on a weekend or another holiday is not a day off by itself: the
 * volunteer picks a working day in the same ISO week, and it counts once their
 * direct manager approves it. Service agreements get none (H-5).
 *
 * The date of birth is read here and nowhere else; callers get dates only.
 */
class BirthdayHoliday
{
    public function __construct(private readonly LeaveRequestPricing $pricing) {}

    /**
     * The holder's birthday holiday dates between $from and $to, as Y-m-d.
     *
     * @return list<string>
     */
    public function datesFor(Contract $contract, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];

        foreach ($this->occurrencesFor($contract, $from->subDays(7), $to->addDays(7)) as $occurrence) {
            $day = $occurrence['conflict'] ? $occurrence['approved'] : $occurrence['date'];

            if ($day !== null && $day->betweenIncluded($from, $to)) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }

    /**
     * Each birthday occurrence near the range, with whether it needs a choice.
     *
     * @return list<array{holiday: Holiday, year: int, date: CarbonImmutable, conflict: bool, choice: ?BirthdayHolidayChoice, approved: ?CarbonImmutable}>
     */
    public function occurrencesFor(Contract $contract, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $employee = $contract->employee;
        $dob = $employee?->actual_dob ?? $employee?->dob;

        if (! $dob || $this->pricing->isServiceAgreement($contract) || $from->gt($to)) {
            return [];
        }

        $position = $contract->position;
        $cityId = $position?->center?->city_id ?? $position?->city_id;

        $holidays = Holiday::query()
            ->where('kind', Holiday::KIND_BIRTHDAY)
            ->applicableTo($position?->program_id ? (int) $position->program_id : null, $cityId !== null ? (int) $cityId : null)
            ->occurringBetween($from, $to)
            ->get();

        if ($holidays->isEmpty()) {
            return [];
        }

        $choices = BirthdayHolidayChoice::query()
            ->where('employee_id', $employee->id)
            ->whereIn('holiday_id', $holidays->modelKeys())
            ->get()
            ->keyBy(fn ($c) => $c->holiday_id.':'.$c->year);

        $contractStart = $contract->start_date ? CarbonImmutable::parse($contract->start_date)->startOfDay() : null;
        $contractEnd = $this->contractEnd($contract);
        $weekend = $this->pricing->weekendFor($contract)[0];
        $occurrences = [];

        foreach ($holidays as $holiday) {
            for ($year = $from->year; $year <= $to->year; $year++) {
                $date = $this->birthdayIn(CarbonImmutable::parse($dob), $year);

                if (! $date->betweenIncluded($from, $to)
                    || ! $this->inWindow($holiday, $date)
                    || ($contractStart && $date->lt($contractStart))
                    || ($contractEnd && $date->gt($contractEnd))) {
                    continue;
                }

                $choice = $choices->get($holiday->id.':'.$year);

                $occurrences[] = [
                    'holiday' => $holiday,
                    'year' => $year,
                    'date' => $date,
                    'conflict' => ! $this->isWorkingDay($contract, $date, $weekend),
                    'choice' => $choice,
                    'approved' => $choice?->status === BirthdayHolidayChoice::APPROVED ? CarbonImmutable::parse($choice->chosen_date) : null,
                ];
            }
        }

        return $occurrences;
    }

    /**
     * Record (or replace, while undecided) the day taken instead of a
     * birthday that falls on a weekend or holiday.
     *
     * @throws ValidationException
     */
    public function choose(Contract $contract, Holiday $holiday, int $year, CarbonImmutable $day): BirthdayHolidayChoice
    {
        $occurrence = collect($this->occurrencesFor($contract, CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31)))
            ->first(fn ($o) => $o['holiday']->is($holiday) && $o['year'] === $year);

        $fail = fn (string $key) => throw ValidationException::withMessages(['chosen_date' => __('holidays.birthday.'.$key)]);

        if (! $occurrence || ! $occurrence['conflict']) {
            $fail('no-choice-needed');
        }

        if ($occurrence['choice'] && $occurrence['choice']->status !== BirthdayHolidayChoice::PENDING) {
            $fail('already-decided');
        }

        $birthday = $occurrence['date'];

        if ($day->isoWeek() !== $birthday->isoWeek() || $day->isoWeekYear() !== $birthday->isoWeekYear()) {
            $fail('same-week');
        }

        if (! $this->isWorkingDay($contract, $day, $this->pricing->weekendFor($contract)[0])) {
            $fail('working-day');
        }

        return BirthdayHolidayChoice::query()->updateOrCreate(
            ['holiday_id' => $holiday->id, 'employee_id' => $contract->employee_id, 'year' => $year],
            ['chosen_date' => $day->toDateString(), 'status' => BirthdayHolidayChoice::PENDING, 'decided_by' => null, 'decided_at' => null],
        );
    }

    /** H-4: only the direct manager decides — whoever holds the position the volunteer's position reports to. */
    public function canDecide(User $user, BirthdayHolidayChoice $choice): bool
    {
        $managerPositionId = $this->activeContractOf($choice->employee)?->position?->direct_manager_id;

        return $managerPositionId !== null
            && $user->employee_id !== null
            && Contract::query()->activeNow()
                ->where('employee_id', $user->employee_id)
                ->where('position_id', $managerPositionId)
                ->exists();
    }

    public function decide(User $user, BirthdayHolidayChoice $choice, bool $approve): void
    {
        abort_unless($this->canDecide($user, $choice) && $choice->status === BirthdayHolidayChoice::PENDING, 403);

        $choice->forceFill([
            'status' => $approve ? BirthdayHolidayChoice::APPROVED : BirthdayHolidayChoice::REJECTED,
            'decided_by' => $user->id,
            'decided_at' => now(),
        ])->save();
    }

    /**
     * Pending choices of the volunteers whose position reports to one of
     * $user's positions.
     *
     * @return Collection<int, BirthdayHolidayChoice>
     */
    public function pendingFor(User $user): Collection
    {
        if (! $user->employee_id) {
            return collect();
        }

        $ownPositionIds = Contract::query()->activeNow()->where('employee_id', $user->employee_id)->pluck('position_id')->filter()->all();

        if ($ownPositionIds === []) {
            return collect();
        }

        $reportIds = Contract::query()->activeNow()
            ->whereHas('position', fn ($p) => $p->whereIn('direct_manager_id', $ownPositionIds))
            ->pluck('employee_id')
            ->all();

        return BirthdayHolidayChoice::query()
            ->with(['employee', 'holiday'])
            ->where('status', BirthdayHolidayChoice::PENDING)
            ->whereIn('employee_id', $reportIds ?: [0])
            ->orderBy('chosen_date')
            ->get();
    }

    public function activeContractOf(?Employee $employee): ?Contract
    {
        return $employee?->latestActiveContract();
    }

    private function birthdayIn(CarbonImmutable $dob, int $year): CarbonImmutable
    {
        $day = $dob->month === 2 && $dob->day === 29 && ! CarbonImmutable::create($year)->isLeapYear() ? 28 : $dob->day;

        return CarbonImmutable::create($year, $dob->month, $day)->startOfDay();
    }

    /** A recurring window repeats yearly from its first year; otherwise its own dates. */
    private function inWindow(Holiday $holiday, CarbonImmutable $date): bool
    {
        $start = CarbonImmutable::parse($holiday->start_date)->startOfDay();

        if ($holiday->is_recurring) {
            return $date->gte($start);
        }

        return $date->betweenIncluded($start, CarbonImmutable::parse($holiday->end_date)->startOfDay());
    }

    /** @param  array<int, int>  $weekend */
    private function isWorkingDay(Contract $contract, CarbonImmutable $date, array $weekend): bool
    {
        return ! in_array($date->dayOfWeek, $weekend, true)
            && $this->pricing->officialHolidayDates($contract, $date, $date) === [];
    }

    private function contractEnd(Contract $contract): ?CarbonImmutable
    {
        $ends = array_filter([$contract->end_date, $contract->terminated_at]);

        return $ends === [] ? null : collect($ends)->map(fn ($d) => CarbonImmutable::parse($d)->startOfDay())->min();
    }
}
