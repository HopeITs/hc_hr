<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\User;
use App\Notifications\ContractsExpiringAutoRenewWarning;
use App\Services\NotificationDeliveryLedger;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class SendContractExpirationWarnings extends Command
{
    /** Read by the renewal journey (R1) as the record of a warning sent. */
    public const LEDGER_KIND = 'contract-expiry-warning';

    /**
     * Exact reminder anchors, in days before the contract ends.
     *
     * The command runs daily, so matching exact dates sends weekly reminders
     * during the last 30 days with NotificationDeliveryLedger making each contract/manager/day claim atomic.
     *
     * @var array<int, int>
     */
    private const REMINDER_DAYS = [30, 21, 14, 7];

    protected $signature = 'contracts:send-expiration-warnings';

    protected $description = 'Send direct managers scheduled warnings for auto-renewable contracts nearing expiration';

    public function handle(NotificationDeliveryLedger $ledger): int
    {
        $day = $ledger->today();
        $sent = 0;

        $this->expiringContracts($day)
            ->groupBy(fn (Contract $contract): string => implode(':', [
                $this->directManagerUser($contract)?->id ?? 'none',
                (int) $day->diffInDays($contract->end_date->startOfDay()),
            ]))
            ->filter(fn (Collection $group): bool => $this->directManagerUser($group->first()) !== null)
            ->each(function (Collection $group) use (&$sent, $ledger, $day): void {
                $manager = $this->directManagerUser($group->first());
                $daysUntilExpiration = (int) $day->diffInDays($group->first()->end_date->startOfDay());

                // The claim is per contract, so a contract already reported today
                // stays out of any later group, however the groups are formed.
                $claimed = $ledger->claim(self::LEDGER_KIND, $manager->id, $group->pluck('id')->all(), $day);
                $fresh = $group->whereIn('id', $claimed)->values();

                if ($fresh->isEmpty()) {
                    return;
                }

                try {
                    $manager->notify(new ContractsExpiringAutoRenewWarning($fresh, $daysUntilExpiration));
                } catch (Throwable $e) {
                    $ledger->release(self::LEDGER_KIND, $manager->id, $claimed, $day);

                    throw $e;
                }

                $ledger->markDelivered(self::LEDGER_KIND, $manager->id, $claimed, $day);
                $sent++;
            });

        $this->info("Sent {$sent} contract expiration warning notification(s).");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Contract>
     */
    private function expiringContracts(CarbonInterface $day): Collection
    {
        $renewableTypeIds = collect(config('contracts.map_by_id', []))
            ->filter(fn (string $category): bool => in_array($category, ['annual', 'service'], true))
            ->keys()
            ->all();
        $reminderDates = collect(self::REMINDER_DAYS)
            ->map(fn (int $days): string => $day->copy()->addDays($days)->toDateString())
            ->all();

        return Contract::query()
            ->with([
                'employee.city',
                'position.directManager.activeContracts.employee.account',
            ])
            ->where('auto_renew', true)
            ->where('is_active', true)
            ->whereIn('contract_type_id', $renewableTypeIds)
            ->where(function (Builder $query) use ($reminderDates): void {
                foreach ($reminderDates as $date) {
                    $query->orWhereDate('end_date', $date);
                }
            })
            ->orderBy('end_date')
            ->orderBy('id')
            ->get();
    }

    private function directManagerUser(Contract $contract): ?User
    {
        return $contract->position?->directManager?->employee()?->account;
    }
}
