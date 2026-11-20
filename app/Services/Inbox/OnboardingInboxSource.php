<?php

namespace App\Services\Inbox;

use App\Models\OnboardingJourney;
use App\Services\OnboardingJourneys;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Onboarding steps waiting on HR (D4-I, DEC-09 increment 2), the same steps
 * OnboardingJourneys::steps() marks as HR's or as blocked, read in one query:
 *
 *  - card         printed ID card not handed over yet;
 *  - orientation  session not held: to schedule (no date) or to record;
 *  - account      the volunteer has no login account (a problem: HR creates
 *                 it in Access Management).
 *
 * The record and the contract are always done once a journey exists (the
 * first contract starts it), so they never wait here. Whether the digital
 * card is valid is not read (it needs the card contract, position and
 * program per row): the edit page shows it, and refuses the hand-over until
 * it is.
 */
class OnboardingInboxSource extends JourneyInboxSource
{
    public function name(): string
    {
        return 'onboarding';
    }

    protected function pending(): Builder
    {
        return OnboardingJourney::query()
            ->whereNull('completed_at')
            // completed_at is only settled by a journey action, so a journey
            // whose account was created later can be done and still open: it
            // is selected only while a step really waits.
            ->where(fn (Builder $waiting) => $waiting
                ->whereNull('card_handed_over_at')
                ->orWhereNull('orientation_completed_at')
                ->orWhereDoesntHave('employee.account'))
            ->with(['contract', 'employee' => fn ($employee) => $employee->with('city')->withExists('account')])
            ->orderBy('orientation_due_on')
            ->orderBy('id');
    }

    /** @param  OnboardingJourney  $journey */
    protected function steps(Model $journey): array
    {
        $steps = [];

        if (! $journey->employee->account_exists) {
            $steps[] = ['key' => 'account', 'state' => self::STATE_PROBLEM];
        }

        if ($journey->card_handed_over_at === null) {
            $steps[] = ['key' => 'card', 'state' => self::STATE_TODO];
        }

        if ($journey->orientation_completed_at === null) {
            $steps[] = [
                'key' => $journey->orientation_scheduled_for === null ? 'orientation-schedule' : 'orientation-record',
                'state' => self::STATE_TODO,
            ];
        }

        return $steps;
    }

    /**
     * The earliest deadline among the steps still waiting on HR: the account
     * and the card (2 days from the contract start) and HR's session (14 days).
     *
     * @param  OnboardingJourney  $journey
     */
    protected function due(Model $journey): ?CarbonInterface
    {
        return collect($this->deadlines($journey))->sort()->first();
    }

    /** @param  OnboardingJourney  $journey */
    protected function late(Model $journey): bool
    {
        $today = now(config('app.timezone'))->startOfDay();

        return collect($this->deadlines($journey))->contains(fn (CarbonInterface $due) => $due->lt($today));
    }

    /** @return list<CarbonInterface> */
    private function deadlines(OnboardingJourney $journey): array
    {
        return array_values(array_filter([
            $journey->employee->account_exists ? null : $journey->dueAfterStart(OnboardingJourneys::ACCOUNT_DAYS),
            $journey->card_handed_over_at === null ? $journey->dueAfterStart(OnboardingJourneys::CARD_DAYS) : null,
            $journey->orientation_completed_at === null ? $journey->orientation_due_on : null,
        ]));
    }
}
