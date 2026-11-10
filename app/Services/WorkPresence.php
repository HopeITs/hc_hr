<?php

namespace App\Services;

use App\Enums\LeaveRequestStatus;
use App\Enums\ScheduleMode;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Dashboard\WorkdayClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The presence dot beside a person (DESIGN.md "Presence dot"): is this person
 * at work right now, by their contract, not by whether they opened the app
 * (that is App\Support\Dashboard\Presence, the clock's "online now").
 *
 *   in working hours, no leave      filled green    "Online"           for everyone
 *   in working hours, on leave      hollow          "On leave"         / "Offline"
 *   outside hours, weekend, holiday hollow          "Outside hours"    / "Offline"
 *   flexible schedule               filled silver   "Variable hours"   for everyone
 *   flexible schedule, on leave     hollow          "On leave"         / "Offline"
 *   no running contract             nothing
 *
 * The reason ("On leave", "Outside hours") is told only to a viewer who may
 * see this person's absences on the team calendar (TeamCoverage::
 * canSeeAbsencesOf); everyone else reads "Offline". Only an accepted leave
 * counts; an hourly leave makes the person offline during its hours only. The
 * lunch break between the two periods of a split day stays online.
 *
 * A fixed contract whose hours cannot be read (no hours, or no day range
 * either in numbers or in its "From Monday Till Friday" text) gets no dot:
 * claiming "Online" or "Offline" would be a guess.
 *
 * Only the tone and the words leave this class, never the state behind
 * "Offline", so the page carries nothing the viewer may not read.
 */
final class WorkPresence
{
    public const ONLINE = 'online';

    public const AWAY = 'away';

    public const FLEXIBLE = 'flexible';

    public function __construct(
        private readonly WorkdayClock $clock,
        private readonly TeamCoverage $coverage,
    ) {}

    /** One person; null when they get no dot. @return array{tone: string, label: string}|null */
    public function forOne(User $viewer, Employee|int|null $employee, ?CarbonInterface $now = null): ?array
    {
        if ($employee === null) {
            return null;
        }

        $id = $employee instanceof Employee ? (int) $employee->getKey() : $employee;

        return $this->for($viewer, [$id], $now)[$id] ?? null;
    }

    /**
     * Many people at once (a list, a tile): three queries whatever the count.
     *
     * @param  iterable<Employee|int|null>  $employees
     * @return array<int, array{tone: string, label: string}> keyed by employee id; a person who gets no dot is missing
     */
    public function for(User $viewer, iterable $employees, ?CarbonInterface $now = null): array
    {
        $ids = collect($employees)
            ->map(fn ($e) => $e instanceof Employee ? $e->getKey() : $e)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $now = CarbonImmutable::instance($now ?? now());
        $contracts = $this->runningContracts($ids);

        if ($contracts->isEmpty()) {
            return [];
        }

        $onLeave = $this->onLeave($contracts->keys()->all(), $now);
        $damascus = $now->setTimezone(WorkdayClock::TIMEZONE);
        $minute = $damascus->hour * 60 + $damascus->minute;
        $result = [];

        foreach ($contracts as $employeeId => $contract) {
            $state = $this->state($contract, isset($onLeave[$employeeId]), $now, $minute);

            if ($state === null) {
                continue;
            }

            $result[$employeeId] = match ($state) {
                'online' => ['tone' => self::ONLINE, 'label' => __('presence.online')],
                'flexible' => ['tone' => self::FLEXIBLE, 'label' => __('presence.flexible')],
                default => ['tone' => self::AWAY, 'label' => $this->coverage->canSeeAbsencesOf($viewer, $contract)
                    ? __('presence.'.$state)
                    : __('presence.offline')],
            };
        }

        return $result;
    }

    /** online | leave | off_hours | flexible, or null for a schedule that cannot be read. */
    private function state(Contract $contract, bool $onLeave, CarbonImmutable $now, int $minute): ?string
    {
        if ((int) $contract->schedule_mode === ScheduleMode::Flexible) {
            return $onLeave ? 'leave' : 'flexible';
        }

        $day = $this->clock->today($contract, $now, readDayText: true);

        if ($day === null) {
            return null;
        }

        $periods = $day['periods'];
        // From the first period's start to the last one's end: the break between them counts as at work.
        $inHours = ! $day['off'] && $minute >= $periods[0][0] && $minute < $periods[count($periods) - 1][1];

        if (! $inHours) {
            return 'off_hours';
        }

        return $onLeave ? 'leave' : 'online';
    }

    /**
     * The running contract of each active person, keyed by employee id: the
     * latest one when several run at once, with what the clock and the team
     * calendar rule read.
     *
     * @param  array<int, int>  $ids
     * @return \Illuminate\Support\Collection<int, Contract>
     */
    private function runningContracts(array $ids)
    {
        return Contract::query()->activeNow()
            ->whereIn('employee_id', $ids)
            ->whereHas('employee', fn (Builder $e) => $e->where('is_active', true))
            ->with([
                'employee:id,city_id,is_active',
                'position.center',
                'position.jobLevel',
                'position.department',
            ])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id');
    }

    /**
     * Who is on an accepted leave right now, as employee id => true. A day
     * leave covers its whole dates; an hourly one its own hours only.
     *
     * @param  array<int, int>  $ids
     * @return array<int, true>
     */
    private function onLeave(array $ids, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();

        return LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Accepted)
            ->where('start_at', '<', $today->addDay()->toDateTimeString())
            ->where('end_at', '>=', $today->toDateTimeString())
            ->whereHas('leaveBalance.contract', fn (Builder $c) => $c->whereIn('employee_id', $ids))
            ->with(['leaveBalance:id,contract_id', 'leaveBalance.contract:id,employee_id'])
            ->get(['id', 'leave_balance_id', 'type', 'start_at', 'end_at'])
            ->filter(fn (LeaveRequest $leave) => $leave->type !== 'hour'
                || ($leave->start_at->lte($now) && $leave->end_at->gt($now)))
            ->mapWithKeys(fn (LeaveRequest $leave) => [(int) $leave->leaveBalance?->contract?->employee_id => true])
            ->forget(0)
            ->all();
    }
}
