<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Contract;
use App\Models\User;
use App\Notifications\ContractsAutoRenewedSummary;
use App\Notifications\ContractsAwaitingNewYearRenewal;
use App\Services\ContractAutoRenewalService;
use App\Services\NotificationDeliveryLedger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProcessContractAutoRenewals extends Command
{
    protected $signature = 'contracts:process-auto-renewals';

    protected $description = 'Create successor contracts for expired inactive auto-renewable contracts';

    public function handle(ContractAutoRenewalService $renewals, NotificationDeliveryLedger $ledger): int
    {
        $contracts = $renewals->process();

        $this->notifyGovernorateHr($contracts);

        $waiting = $this->notifyAwaitingNewYear($renewals->waitingForNewYear(), $ledger);

        $this->info('Renewed '.$contracts->count().' contract(s); told HR of '.$waiting.' newly waiting for the new year.');

        return self::SUCCESS;
    }

    /**
     * Tell city HR, once per contract, which contracts ended in October to
     * December and will only be renewed on 1 January. The ledger is keyed on
     * the contract's end date, so a re-run or a later night sends nothing new.
     *
     * @param  Collection<int, Contract>  $contracts
     */
    private function notifyAwaitingNewYear(Collection $contracts, NotificationDeliveryLedger $ledger): int
    {
        $told = [];

        $contracts
            ->groupBy(fn (Contract $contract): ?int => $contract->employee?->city_id)
            ->filter(fn (Collection $group, mixed $cityId): bool => $cityId !== null && $cityId !== '')
            ->each(function (Collection $group) use ($ledger, &$told): void {
                foreach ($this->hrUsersForCity($group->first()) as $recipient) {
                    $new = $group->filter(fn (Contract $contract): bool => $ledger->claim(
                        'contract_renews_new_year', $recipient->id, [$contract->id], $contract->end_date,
                    ) !== [])->values();

                    if ($new->isEmpty()) {
                        continue;
                    }

                    $recipient->notify(new ContractsAwaitingNewYearRenewal($new));

                    foreach ($new as $contract) {
                        $ledger->markDelivered('contract_renews_new_year', $recipient->id, [$contract->id], $contract->end_date);
                        $told[$contract->id] = true;
                    }
                }
            });

        return count($told);
    }

    /**
     * @param  Collection<int, Contract>  $contracts
     */
    private function notifyGovernorateHr(Collection $contracts): void
    {
        if ($contracts->isEmpty()) {
            return;
        }

        $contracts
            ->each(fn (Contract $contract): Contract => $contract->loadMissing('employee.city'))
            ->groupBy(fn (Contract $contract): ?int => $contract->employee?->city_id)
            ->filter(fn (Collection $group, mixed $cityId): bool => $cityId !== null && $group->isNotEmpty())
            ->each(function (Collection $group): void {
                $recipients = $this->hrUsersForCity($group->first());

                if ($recipients->isEmpty()) {
                    return;
                }

                $notification = new ContractsAutoRenewedSummary($group);

                foreach ($recipients as $recipient) {
                    $recipient->notify($notification);
                }
            });
    }

    /**
     * @return Collection<int, User>
     */
    private function hrUsersForCity(Contract $contract): Collection
    {
        $slug = $contract->employee?->city?->slug;

        if (! $slug) {
            return collect();
        }

        $users = $this->hrUsersForRoles(City::hrRolesForSlug($slug));

        return $users->isNotEmpty() ? $users : $this->hrUsersForRoles(['HR-SYR']);
    }

    /**
     * @param  array<int, string>  $roles
     * @return Collection<int, User>
     */
    private function hrUsersForRoles(array $roles): Collection
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query): Builder => $query->whereIn('name', $roles))
            ->whereHas('profile', fn (Builder $query): Builder => $query->where('is_active', true))
            ->get();
    }
}
