<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Employee;
use App\Models\OffboardingJourney;
use App\Models\User;
use App\Notifications\LastContractEnded;
use App\Services\NotificationDeliveryLedger;
use App\Services\OffboardingJourneys;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DEC-09 increment 2 (product owner, 2026-10-30): automatic deactivation of a
 * volunteer whose last real contract has ended.
 *
 * Daily, after `deactivate:contract` (21:30) and the auto-renewals (21:35), so
 * a contract that just rolled over already has its successor. For every
 * active, complete employee with real contracts and none current, future or
 * still due to auto-renew (OffboardingJourneys::departure):
 *
 *  - the exit journey is started if nothing started it before (the contract
 *    simply ran out with no successor);
 *  - before the grace period (config('journeys.exit_grace_days')) has passed,
 *    city HR gets ONE in-app heads-up, claimed in NotificationDeliveryLedger
 *    against the contract and its end date, so no later run repeats it;
 *  - once it has passed, the employee is deactivated through the same save
 *    manual deactivation uses (EmployeeObserver: card off, AccessRevoker),
 *    and the reason is logged under "offboarding".
 *
 * Open journeys are then checked for completion. The weekly
 * `deactivate:employee` (no contracts) is a separate command and unchanged.
 */
class DeactivateDepartedEmployees extends Command
{
    public const LEDGER_KIND = 'last-contract-ended';

    protected $signature = 'employees:deactivate-departed {--dry-run : List who would be deactivated or warned, change nothing}';

    protected $description = 'Deactivate volunteers whose last contract ended more than the grace period ago, and warn city HR once when it ends';

    public function handle(OffboardingJourneys $journeys, NotificationDeliveryLedger $ledger): int
    {
        $today = $ledger->today();
        $dryRun = (bool) $this->option('dry-run');
        $deactivated = 0;
        $warned = 0;

        $this->departedCandidates($today)
            ->with('city')
            ->chunkById(100, function (Collection $employees) use ($journeys, $ledger, $today, $dryRun, &$deactivated, &$warned): void {
                foreach ($employees as $employee) {
                    $departure = $journeys->departure($employee, $today);

                    if ($departure === null) {
                        continue;
                    }

                    $due = $departure['deactivate_on']->toDateString() <= $today->toDateString();

                    if ($dryRun) {
                        $this->line(sprintf(
                            '%s #%d %s: last contract #%d ended %s, deactivation %s',
                            $due ? 'DEACTIVATE' : 'WARN',
                            $employee->id,
                            $employee->nameForLocale('en'),
                            $departure['contract']->id,
                            $departure['ended_on']->toDateString(),
                            $departure['deactivate_on']->toDateString(),
                        ));

                        continue;
                    }

                    $journey = $journeys->startForDeparture($departure['contract'], OffboardingJourney::TRIGGER_CONTRACT_ENDED);

                    if ($due) {
                        if ($journeys->deactivateDeparted($employee, $today)) {
                            $deactivated++;
                            Log::info('employees:deactivate-departed deactivated an employee', [
                                'employee_id' => $employee->id,
                                'reason' => 'last_contract_ended',
                                'contract_id' => $departure['contract']->id,
                                'ended_on' => $departure['ended_on']->toDateString(),
                            ]);
                        }

                        continue;
                    }

                    $warned += $this->headsUp($ledger, $employee, $departure, $journey);
                }
            });

        if (! $dryRun) {
            OffboardingJourney::query()
                ->whereNull('completed_at')
                ->each(fn (OffboardingJourney $journey) => $journeys->settle($journey));
        }

        $this->info("Deactivated {$deactivated} employee(s); sent {$warned} heads-up notice(s).");

        return self::SUCCESS;
    }

    /**
     * Active, complete, with real contracts and none current or future. The
     * pending-auto-renewal case is left to OffboardingJourneys::departure.
     */
    private function departedCandidates(CarbonInterface $today): Builder
    {
        $day = $today->toDateString();

        return Employee::query()
            ->completeRecords()
            ->where('employees.is_active', true)
            ->whereHas('realContracts')
            ->whereDoesntHave('realContracts', fn (Builder $q) => $q
                ->whereNull('terminated_at')
                ->whereNull('termination_reason_id')
                ->where(fn (Builder $w) => $w
                    ->whereDate('start_date', '>', $day)
                    ->orWhere(fn (Builder $running) => $running
                        ->where('is_active', true)
                        ->where(fn (Builder $e) => $e->whereNull('end_date')->orWhereDate('end_date', '>=', $day)))));
    }

    /**
     * @param  array{contract: \App\Models\Contract, ended_on: CarbonInterface, deactivate_on: CarbonInterface}  $departure
     */
    private function headsUp(NotificationDeliveryLedger $ledger, Employee $employee, array $departure, ?OffboardingJourney $journey): int
    {
        $sent = 0;
        $contractId = $departure['contract']->id;
        // Keyed to the end date, not today: one notice per departure, ever.
        $day = $departure['ended_on'];

        foreach ($this->hrFor($employee) as $user) {
            if (! $ledger->claim(self::LEDGER_KIND, $user->id, [$contractId], $day)) {
                continue;
            }

            try {
                $user->notify(new LastContractEnded($employee, $contractId, $departure['ended_on'], $departure['deactivate_on'], $journey?->id));
                $ledger->markDelivered(self::LEDGER_KIND, $user->id, [$contractId], $day);
                $sent++;
            } catch (Throwable $e) {
                $ledger->release(self::LEDGER_KIND, $user->id, [$contractId], $day);
                report($e);
            }
        }

        return $sent;
    }

    /** HR of the employee's city, or HR-SYR when that city has none (as the orientation reminder). */
    private function hrFor(Employee $employee): Collection
    {
        $slug = $employee->city?->slug;
        $users = $slug ? $this->usersWithRoles(City::hrRolesForSlug($slug)) : collect();

        return $users->isNotEmpty() ? $users : $this->usersWithRoles(['HR-SYR']);
    }

    /**
     * @param  array<int, string>  $roles
     * @return Collection<int, User>
     */
    private function usersWithRoles(array $roles): Collection
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query): Builder => $query->whereIn('name', $roles))
            ->whereHas('profile', fn (Builder $query): Builder => $query->where('is_active', true))
            ->get();
    }
}
