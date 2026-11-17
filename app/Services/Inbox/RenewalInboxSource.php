<?php

namespace App\Services\Inbox;

use App\Models\RenewalJourney;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Renewal decisions waiting on HR (DEC-09 increment 2): a contract ends
 * within 30 days and nobody has recorded "renew" or "stop" yet. Due on the
 * contract's end date, Late from RenewalJourney::decisionIsLate().
 *
 * Narrower than the other journey sources: the decision is a contract write
 * (ContractPolicy::manageAutoRenew), so the viewer also needs
 * contracts.update. The row only links to the employee page, whose renewal
 * panel re-runs manageAutoRenew on the contract before any decision.
 */
class RenewalInboxSource extends JourneyInboxSource
{
    public function name(): string
    {
        return 'renewal';
    }

    public function visibleTo(User $user): bool
    {
        return parent::visibleTo($user) && $user->hasActivePermission('contracts.update');
    }

    protected function pending(): Builder
    {
        return RenewalJourney::query()
            ->whereNull('completed_at')
            ->whereNull('decision')
            ->with(['employee.city', 'contract'])
            ->orderBy('started_at')
            ->orderBy('id');
    }

    /** @param  RenewalJourney  $journey */
    protected function steps(Model $journey): array
    {
        return [['key' => 'decide', 'state' => self::STATE_TODO]];
    }

    /** @param  RenewalJourney  $journey */
    protected function due(Model $journey): ?CarbonInterface
    {
        return $journey->contract?->end_date;
    }

    /** @param  RenewalJourney  $journey */
    protected function late(Model $journey): bool
    {
        return $journey->decisionIsLate();
    }
}
