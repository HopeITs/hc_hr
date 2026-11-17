<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\OnboardingJourney;
use App\Models\User;
use App\Notifications\OrientationOverdue;
use App\Services\NotificationDeliveryLedger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * D4-I (docs/ux/journeys.md): a new volunteer whose orientation session is
 * past its due date gets ONE in-app reminder to HR in their city (HR-SYR when
 * that city has no HR). The ledger claim is keyed to the journey and its start
 * day, so a later run, a retry or a second worker never reminds again.
 * Former staff (inactive) are skipped: their journey no longer waits on anyone.
 */
class SendOrientationReminders extends Command
{
    public const LEDGER_KIND = 'orientation-overdue';

    protected $signature = 'onboarding:send-orientation-reminders';

    protected $description = 'Remind city HR once about new volunteers whose orientation session is overdue';

    public function handle(NotificationDeliveryLedger $ledger): int
    {
        $today = $ledger->today();
        $sent = 0;

        OnboardingJourney::query()
            ->whereNull('orientation_completed_at')
            ->whereDate('orientation_due_on', '<', $today->toDateString())
            ->whereHas('employee', fn (Builder $query) => $query->where('is_active', true))
            ->with('employee.city')
            ->each(function (OnboardingJourney $journey) use ($ledger, &$sent): void {
                $day = $journey->started_at->copy()->timezone(config('app.timezone'))->startOfDay();

                foreach ($this->hrFor($journey) as $user) {
                    if (! $ledger->claim(self::LEDGER_KIND, $user->id, [$journey->id], $day)) {
                        continue;
                    }

                    try {
                        $user->notify(new OrientationOverdue($journey));
                        $ledger->markDelivered(self::LEDGER_KIND, $user->id, [$journey->id], $day);
                        $sent++;
                    } catch (Throwable $e) {
                        $ledger->release(self::LEDGER_KIND, $user->id, [$journey->id], $day);
                        report($e);
                    }
                }
            });

        $this->info("Sent {$sent} orientation reminder(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, User> */
    private function hrFor(OnboardingJourney $journey): Collection
    {
        $slug = $journey->employee?->city?->slug;
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
