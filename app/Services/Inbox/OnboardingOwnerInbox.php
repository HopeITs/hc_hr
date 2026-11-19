<?php

namespace App\Services\Inbox;

use App\Models\ChecklistItem;
use App\Models\DefaultDeliverable;
use App\Models\OnboardingJourney;
use App\Models\User;
use App\Services\OnboardingDeliveries;
use App\Services\OnboardingJourneys;
use App\Services\PolicyAcknowledgements;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Onboarding work that waits on people other than HR (track 7), for the work
 * inbox:
 *
 *  - the direct manager's orientation session with a new volunteer (the row
 *    opens the manager's own page, which re-authorizes);
 *  - the deliverer's items: electronics and "other" items of a journey that is
 *    still open, and the Mug once the volunteer's trial is over. The row opens
 *    the deliveries page, where the item is handed over.
 *
 *  - the volunteer's own policies to read and agree to (opens their page).
 *
 * A row lists, it never decides. A step that is past its deadline carries the
 * Late flag. A volunteer nobody manages (the head of the organization) is not
 * listed here: HR records that session from the journey panel.
 */
class OnboardingOwnerInbox
{
    /** Newest journeys scanned for a deliverer; the rows are listed up to the caller's limit. */
    private const SCAN = 200;

    public function __construct(private OnboardingDeliveries $deliveries) {}

    /** @return array{0: Collection<int, array<string, mixed>>, 1: int} */
    public function collect(User $user, int $limit): array
    {
        if ($user->employee_id === null) {
            return [collect(), 0];
        }

        $rows = $this->managerRows($user)->merge($this->delivererRows($user))->merge($this->policyRows($user));

        return [$rows->take($limit)->values(), $rows->count()];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function managerRows(User $user): Collection
    {
        return OnboardingJourney::query()
            ->whereNull('completed_at')
            ->whereNull('manager_orientation_completed_at')
            ->whereHas('employee', fn (Builder $employee) => $employee->where('is_active', true))
            ->whereHas('contract.position.directManager.contracts', fn (Builder $running) => $running
                ->activeNow()
                ->where('employee_id', (int) $user->employee_id))
            ->with(['employee.city', 'contract'])
            ->orderBy('orientation_due_on')
            ->get()
            ->toBase()
            ->map(fn (OnboardingJourney $journey) => $this->row(
                $journey,
                $journey->manager_orientation_scheduled_for === null ? 'manager-schedule' : 'manager-record',
                $journey->orientation_due_on,
                $journey->managerOrientationIsLate(),
                route('onboarding.manager-session', $journey->employee),
                'manager',
            ));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function delivererRows(User $user): Collection
    {
        $mine = DefaultDeliverable::query()
            ->where('employee_id', $user->employee_id)
            ->with('item')
            ->get();

        if ($mine->isEmpty()) {
            return collect();
        }

        $today = now(config('app.timezone'))->startOfDay();
        $rows = collect();

        OnboardingJourney::query()
            ->whereHas('employee', fn (Builder $employee) => $employee
                ->where('is_active', true)
                ->whereIn('city_id', $mine->pluck('city_id')->unique()))
            ->with(['employee.city', 'contract'])
            ->latest('started_at')
            ->limit(self::SCAN)
            ->get()
            ->each(function (OnboardingJourney $journey) use ($user, $today, &$rows): void {
                $employee = $journey->employee;

                $groups = $journey->completed_at === null
                    ? [ChecklistItem::GROUP_ELECTRONICS => 'electronics', ChecklistItem::GROUP_OTHER => 'other_items']
                    : [];

                foreach ($groups as $group => $key) {
                    $due = $group === ChecklistItem::GROUP_ELECTRONICS ? $journey->dueAfterStart(OnboardingJourneys::ELECTRONICS_DAYS) : null;

                    if ($this->mineMissing($user, $employee, $group)) {
                        $rows->push($this->row($journey, $key, $due, $due !== null && $due->lt($today), route('checklist-items.pending-delivers'), 'deliverer'));
                    }
                }

                $mug = $this->deliveries->mug($employee);

                if ($mug['due'] && ! $mug['delivered'] && $this->mineMissing($user, $employee, ChecklistItem::GROUP_AFTER_PROBATION)) {
                    $rows->push($this->row($journey, 'mug', null, false, route('checklist-items.pending-delivers'), 'deliverer'));
                }
            });

        return $rows;
    }

    /** The volunteer's own policies to read and agree to, while their journey is open. */
    private function policyRows(User $user): Collection
    {
        $journey = OnboardingJourney::query()
            ->where('employee_id', $user->employee_id)
            ->whereNull('completed_at')
            ->with(['employee.city', 'contract'])
            ->first();

        if ($journey === null || app(PolicyAcknowledgements::class)->status($journey->employee)['pending']->isEmpty()) {
            return collect();
        }

        return collect([$this->row($journey, 'policies', null, false, route('onboarding.policies'), 'volunteer')]);
    }

    private function mineMissing(User $user, $employee, string $group): bool
    {
        return $this->deliveries->group($employee, $group)['missing']
            ->contains(fn (array $item) => $item['deliverer']?->id === (int) $user->employee_id);
    }

    /** @return array<string, mixed> */
    private function row(OnboardingJourney $journey, string $step, ?CarbonInterface $due, bool $late, string $url, string $state): array
    {
        $employee = $journey->employee;
        $label = __("journey_inbox.onboarding.step.{$step}");

        $row = [
            'key' => "onboarding-{$step}:{$journey->id}:journey",
            'source' => 'onboarding',
            'action' => 'journey',
            'id' => $journey->id,
            'subject' => $employee,
            'former' => false,
            'label' => __('journey_inbox.onboarding.title'),
            'item' => $label,
            'steps' => [['key' => $step, 'state' => 'manager', 'label' => $label]],
            'due' => $due,
            'late' => $late,
            'url' => $url,
            'city' => $employee->city?->name,
            'waiting_since' => $journey->started_at,
            'urgent' => false,
            'version' => null,
            'bulk' => false,
        ];

        // The manager's row opens their own page, so it says so.
        return $state === 'manager' ? $row + ['open_label' => __('journey_inbox.onboarding.open-manager')] : $row;
    }
}
