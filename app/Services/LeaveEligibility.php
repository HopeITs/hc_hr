<?php

namespace App\Services;

use App\Enums\Gender;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Enums\MaritalStatus;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Illuminate\Support\Collection;

/**
 * The single answer to "may this employee request this leave type now?",
 * read by the request form's option list and by every save path
 * (LeaveRequestForm::store/update), so the two never disagree.
 *
 * Everything is read from the server: the employee's own gender and marital
 * status, the active contract's balances and the request history. A balance
 * existing on the contract is necessary but not sufficient — the contract
 * gets its balances when it is created, and the employee may have married,
 * or taken a leave, since.
 *
 * "Remaining" is the same figure the pricing service calls available:
 * eligible-to-date − used for annual (LeaveBalance::eligibleAnnualLeaveHours),
 * deserved − used for the others. Pending requests are not subtracted here:
 * a pending annual request can still be refused, so it does not exhaust the
 * balance. A taken leave is an Accepted request; pending is not taken.
 */
class LeaveEligibility
{
    /** Hours below this count as zero (the quote rounds to 2 decimals). */
    public const EPSILON = 0.005;

    /** Why a type is refused; also the translation key under leaves.eligibility. */
    public const REASON_NO_BALANCE = 'no-balance';

    public const REASON_ANNUAL_REMAINS = 'annual-remains';

    public const REASON_FULL_SICK_REMAINS = 'full-sick-remains';

    public const REASON_MARRIED = 'already-married';

    public const REASON_NOT_MARRIED_MALE = 'not-married-male';

    public const REASON_NOT_MARRIED_FEMALE = 'not-married-female';

    public const REASON_FIRST_MATERNITY_NOT_TAKEN = 'first-maternity-not-taken';

    public const REASON_MATERNITY_TAKEN = 'maternity-taken';

    /**
     * The employee's active-contract balances they may request now, with the
     * leave type loaded, in leave-type order.
     *
     * @return Collection<int, LeaveBalance>
     */
    public function requestableBalances(Employee $employee): Collection
    {
        $balances = $this->balances($employee);

        return $balances
            ->filter(fn (LeaveBalance $balance) => $this->reasonAgainst($employee, $balance, $balances) === null)
            ->sortBy('type_id')
            ->values();
    }

    /**
     * Null when $employee may request $balance now, else one of the REASON_*
     * codes. A balance that is not on the employee's active contract is
     * always refused.
     */
    public function reasonAgainst(Employee $employee, LeaveBalance $balance, ?Collection $balances = null): ?string
    {
        $balances ??= $this->balances($employee);

        if (! $balances->contains(fn (LeaveBalance $own) => $own->getKey() === $balance->getKey())) {
            return self::REASON_NO_BALANCE;
        }

        // Read the stored row, never a value the caller may have changed.
        $balance = $balances->first(fn (LeaveBalance $own) => $own->getKey() === $balance->getKey());

        if ($this->remainingHours($balance) <= self::EPSILON) {
            return self::REASON_NO_BALANCE;
        }

        return match ((int) $balance->type_id) {
            LeaveTypeCases::Unpaid => $this->hasRemaining($balances, LeaveTypeCases::Annual)
                ? self::REASON_ANNUAL_REMAINS : null,
            LeaveTypeCases::HalfSick => $this->hasRemaining($balances, LeaveTypeCases::FullSick)
                ? self::REASON_FULL_SICK_REMAINS : null,
            LeaveTypeCases::Marriage => $this->isMarried($employee) ? self::REASON_MARRIED : null,
            LeaveTypeCases::Paternity => $this->isMarried($employee) && (int) $employee->gender === Gender::Male
                ? null : self::REASON_NOT_MARRIED_MALE,
            LeaveTypeCases::MaternityFirst => $this->maternityReason($employee, LeaveTypeCases::MaternityFirst),
            LeaveTypeCases::MaternitySecond => $this->maternityReason($employee, LeaveTypeCases::MaternitySecond),
            default => null,
        };
    }

    /** The translated sentence for a REASON_* code. */
    public function message(string $reason): string
    {
        return __('leaves.eligibility.'.$reason);
    }

    /**
     * Remaining hours on a balance: the pricing service's "available"
     * (eligible-to-date − used for annual, deserved − used otherwise).
     * Negative when more was used than is eligible; callers treat ≤ 0 as none.
     */
    public function remainingHours(LeaveBalance $balance): float
    {
        $base = (int) $balance->type_id === LeaveTypeCases::Annual
            ? (float) $balance->eligibleAnnualLeaveHours()
            : (float) ($balance->deserved ?? 0);

        return round($base - (float) ($balance->used ?? 0), 2);
    }

    /** True once the employee has an Accepted request on a balance of $typeId, on any contract. */
    public function hasTaken(Employee $employee, int $typeId): bool
    {
        return LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Accepted)
            ->whereHas('leaveBalance', fn ($q) => $q
                ->where('type_id', $typeId)
                ->whereHas('contract', fn ($c) => $c->where('employee_id', $employee->getKey())))
            ->exists();
    }

    /** @return Collection<int, LeaveBalance> */
    private function balances(Employee $employee): Collection
    {
        return $employee->latestActiveContract()
            ?->leaveBalances()
            ->with(['leaveType', 'contract'])
            ->get() ?? collect();
    }

    private function hasRemaining(Collection $balances, int $typeId): bool
    {
        $balance = $balances->first(fn (LeaveBalance $b) => (int) $b->type_id === $typeId);

        return $balance !== null && $this->remainingHours($balance) > self::EPSILON;
    }

    private function isMarried(Employee $employee): bool
    {
        return (int) $employee->marital_status === MaritalStatus::Married;
    }

    private function maternityReason(Employee $employee, int $typeId): ?string
    {
        if (! $this->isMarried($employee) || (int) $employee->gender !== Gender::Female) {
            return self::REASON_NOT_MARRIED_FEMALE;
        }

        if ($this->hasTaken($employee, $typeId)) {
            return self::REASON_MATERNITY_TAKEN;
        }

        if ($typeId === LeaveTypeCases::MaternitySecond && ! $this->hasTaken($employee, LeaveTypeCases::MaternityFirst)) {
            return self::REASON_FIRST_MATERNITY_NOT_TAKEN;
        }

        return null;
    }
}
