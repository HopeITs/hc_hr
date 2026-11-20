<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Employee;
use App\Models\OnboardingJourney;
use App\Models\User;
use App\Notifications\ManagerOrientationOverdue;
use App\Notifications\OnboardingStepOverdue;
use App\Services\NotificationDeliveryLedger;
use App\Services\OnboardingJourneys;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Track 7: a step of a new volunteer's onboarding journey that is past its
 * deadline reminds the person who owns it, once a week until it is done:
 *
 *  - login account, ID card   -> HR of the volunteer's city (HR-SYR when none);
 *  - electronics              -> whoever the city names as deliverer of the
 *                                items still missing (HR when nobody is named);
 *  - the manager's session    -> the direct manager (HR when nobody runs the
 *                                manager's position).
 *
 * The ledger key is the journey, the step and the week (the due date plus
 * whole weeks), so a retry or a second worker never sends the same week twice.
 * Finished journeys and former staff are skipped.
 */
class SendOnboardingStepReminders extends Command
{
    protected $signature = 'onboarding:send-step-reminders';

    protected $description = 'Remind the owners of overdue onboarding steps (account, electronics, ID card, manager session), weekly until done';

    public function handle(NotificationDeliveryLedger $ledger, OnboardingJourneys $journeys): int
    {
        $today = $ledger->today();
        $sent = 0;

        OnboardingJourney::query()
            ->whereNull('completed_at')
            ->whereHas('employee', fn (Builder $query) => $query->where('is_active', true))
            ->with(['employee.city', 'employee.account', 'contract'])
            ->each(function (OnboardingJourney $journey) use ($ledger, $journeys, $today, &$sent): void {
                $employee = $journey->employee;
                $steps = collect($journeys->steps($employee) ?? [])->keyBy('key');

                foreach (['account', 'electronics', 'card'] as $key) {
                    $step = $steps->get($key);

                    if ($step === null || $step['state'] === OnboardingJourneys::STATE_DONE || ! $step['late']) {
                        continue;
                    }

                    $recipients = match ($key) {
                        'electronics' => $this->deliverers($journeys, $employee)->whenEmpty(fn () => $this->hr($employee)),
                        default => $this->hr($employee),
                    };

                    $sent += $this->send($ledger, "onboarding-{$key}-overdue", $journey, $step['due'], $recipients, fn () => new OnboardingStepOverdue($journey, $key), $today);
                }

                $manager = $steps->get('manager_orientation');

                if ($manager !== null && $manager['state'] !== OnboardingJourneys::STATE_DONE && $manager['late']) {
                    $recipients = $journeys->managerEmployees($employee)->map(fn (Employee $e) => $e->account)->filter()->unique('id')->values();
                    $recipients = $recipients->isNotEmpty() ? $recipients : $this->hr($employee);

                    $sent += $this->send($ledger, 'onboarding-manager-orientation-overdue', $journey, $manager['due'], $recipients, fn () => new ManagerOrientationOverdue($journey), $today);
                }
            });

        $this->info("Sent {$sent} onboarding reminder(s).");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @param  callable(): Notification  $make
     */
    private function send(NotificationDeliveryLedger $ledger, string $kind, OnboardingJourney $journey, ?CarbonInterface $due, Collection $recipients, callable $make, CarbonInterface $today): int
    {
        $day = OnboardingJourneys::reminderDay($due, $today);
        $sent = 0;

        foreach ($recipients as $user) {
            if (empty($ledger->claim($kind, $user->id, [$journey->id], $day))) {
                continue;
            }

            try {
                $user->notify($make());
                $ledger->markDelivered($kind, $user->id, [$journey->id], $day);
                $sent++;
            } catch (Throwable $e) {
                $ledger->release($kind, $user->id, [$journey->id], $day);
                report($e);
            }
        }

        return $sent;
    }

    /** @return Collection<int, User> */
    private function deliverers(OnboardingJourneys $journeys, Employee $employee): Collection
    {
        return app(\App\Services\OnboardingDeliveries::class)
            ->deliverersOfMissing($employee, \App\Models\ChecklistItem::GROUP_ELECTRONICS)
            ->map(fn (Employee $deliverer) => $deliverer->account)
            ->filter()
            ->unique('id')
            ->values();
    }

    /** @return Collection<int, User> */
    private function hr(Employee $employee): Collection
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
