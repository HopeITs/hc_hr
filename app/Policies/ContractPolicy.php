<?php

namespace App\Policies;

use App\Enums\ContractAmendmentStatus;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\User;

class ContractPolicy
{
    public function view(User $user, Contract $contract): bool
    {
        return $user->hasActivePermission('contracts.view') && $this->isVisibleTo($user, $contract);
    }

    public function update(User $user, Contract $contract): bool
    {
        return $user->hasActivePermission('contracts.update') && $this->isVisibleTo($user, $contract);
    }

    public function manageAutoRenew(User $user, Contract $contract): bool
    {
        return $user->activeRoleIs('HR')
            && $user->hasActivePermission('contracts.update')
            && $this->isVisibleTo($user, $contract);
    }

    /**
     * A renewal creates a contract, so it is gated on `contracts.create` rather
     * than on update. A terminated or already-deactivated contract has no term
     * left to carry forward and is never renewable — the successor would have
     * to be created from scratch.
     */
    public function renew(User $user, Contract $contract): bool
    {
        return $user->hasActivePermission('contracts.create')
            && $contract->is_active
            && $contract->terminated_at === null
            && $this->isVisibleTo($user, $contract);
    }

    public function delete(User $user, Contract $contract): bool
    {
        return $user->hasActivePermission('contracts.delete') && $this->isVisibleTo($user, $contract);
    }

    /**
     * The exported PDF *is* the contract, pay clause and all, so there is no
     * version of it to hand someone who may not see the figures: a copy with
     * the stipend blanked out is not the document, and one with the stipend in
     * it defeats the restriction. A role barred from pay figures is therefore
     * barred from the export, whatever permissions it otherwise carries.
     *
     * @see User::canViewSalary()
     */
    public function export(User $user, Contract $contract): bool
    {
        return $user->hasActivePermission('contracts.export')
            && $user->canViewSalary()
            && $this->isVisibleTo($user, $contract);
    }

    /**
     * Draft an amendment (ملحق عقد) of a contract in force. HR only — HR-SYR
     * across every city, a city's HR on its own through isVisibleTo() — and
     * one amendment at a time: while one is waiting, it is approved or
     * discarded before the next is drafted.
     */
    public function amend(User $user, Contract $contract): bool
    {
        return $this->mayAmend($user, $contract)
            && $contract->canBeAmended()
            && ! $contract->amendments()
                ->whereIn('status', [ContractAmendmentStatus::Draft, ContractAmendmentStatus::Approved])
                ->exists();
    }

    /**
     * See a contract's work days & hours card: whoever may read the contract,
     * the employee it is for, and their direct or technical manager.
     */
    public function viewWorkDays(User $user, Contract $contract): bool
    {
        return $this->view($user, $contract)
            || ($user->employee_id !== null && (int) $user->employee_id === (int) $contract->employee_id)
            || $this->managesContract($user, $contract);
    }

    /**
     * Move a contract's working days from a date on (ContractWorkPatterns),
     * while it is in force: HR of the contract's city and HR Syria (who edit
     * the contract), and the employee's direct or technical manager, who
     * hears of the change first. Not an amendment: which days is not a
     * printed term, and the number of days cannot change here.
     */
    public function changeWorkDays(User $user, Contract $contract): bool
    {
        // A termination dated later leaves it in force until then (R2-3b).
        if (! $contract->is_active || $contract->hasEndedByTermination()) {
            return false;
        }

        if ($user->activeRoleIs('HR')) {
            return $user->hasActivePermission('contracts.update') && $this->isVisibleTo($user, $contract);
        }

        return $this->managesContract($user, $contract);
    }

    /**
     * Approve a draft once signed, or change it before: the people who may
     * draft one, while the contract is still in force. A draft left open on a
     * contract that has since ended can only be deleted.
     */
    public function decideAmendment(User $user, ContractAmendment $amendment): bool
    {
        return $amendment->isDraft()
            && $amendment->contract->canBeAmended()
            && $this->mayAmend($user, $amendment->contract);
    }

    /**
     * Delete an amendment that has not changed the contract yet — a draft, or
     * one approved for a date still to come. One in effect is history.
     */
    public function discardAmendment(User $user, ContractAmendment $amendment): bool
    {
        return ! $amendment->isApplied() && $this->mayAmend($user, $amendment->contract);
    }

    /** Attach the signed sheet, at any point after it was drafted. */
    public function attachSignedAmendment(User $user, ContractAmendment $amendment): bool
    {
        return $this->mayAmend($user, $amendment->contract);
    }

    /**
     * Print the amendment sheet or open its signed copy. Both carry the pay
     * clause whenever pay changed, so the rule is the contract export's.
     */
    public function exportAmendment(User $user, ContractAmendment $amendment): bool
    {
        return $this->export($user, $amendment->contract);
    }

    private function mayAmend(User $user, Contract $contract): bool
    {
        return $user->activeRoleIs('HR')
            && $user->hasActivePermission('contracts.amend')
            && $this->isVisibleTo($user, $contract);
    }

    /**
     * The user, as a manager (active role), holds today a position the
     * contract's position reports to, directly or technically. Never their
     * own contract.
     */
    private function managesContract(User $user, Contract $contract): bool
    {
        if (! $user->activeRoleIs('manager') || ! $user->employee_id || (int) $user->employee_id === (int) $contract->employee_id) {
            return false;
        }

        $held = Contract::query()->activeNow()->where('employee_id', $user->employee_id)->pluck('position_id')->all();
        $position = $contract->position;

        return $held !== [] && $position !== null
            && (in_array((int) $position->direct_manager_id, array_map('intval', $held), true)
                || in_array((int) $position->tech_manager_id, array_map('intval', $held), true));
    }

    /**
     * Every ability starts from visibility: a role may only act on contracts it
     * can see, through the same scope ContractsTable lists with — city for
     * regional HR, direct/technical reports for a manager.
     *
     * @see Contract::scopeVisibleTo()
     */
    private function isVisibleTo(User $user, Contract $contract): bool
    {
        return Contract::query()->whereKey($contract->getKey())->visibleTo($user)->exists();
    }
}
