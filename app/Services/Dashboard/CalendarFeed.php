<?php

namespace App\Services\Dashboard;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\LeaveRequestStatus;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\News;
use App\Models\Program;
use App\Models\User;
use App\Services\BirthdayHoliday;
use App\Services\LeaveRequestPricing;
use App\Services\TeamCoverage;
use App\Support\DirectoryAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the dashboard's calendar tile shows: the viewer's HR events, day by
 * day, each type behind the same permission and scope as the page it comes
 * from. A type the viewer may not see is not queried at all; every other
 * type is read once for the whole range, so the query count does not grow
 * with the number of events.
 *
 *   holiday       the viewer's contract: national + project (LeaveRequestPricing), and their birthday holiday
 *   my-leave      own accepted and pending requests            leaves.view-own (the My leaves page)
 *   team-leave    the viewer's direct reports                  leaves.view-own + the `reports` scope (Team\Calendar)
 *   birthday      self; HR also the active employees of its cities (access-policy §4.4: birthday is Private)
 *   contract-end  contracts ending, Contract::visibleTo         city HR with contracts.view (the contracts page)
 *   news          events addressed to everyone or to the viewer's record, city, center or program (UpcomingEventsCard)
 *
 * Recruitment interviews are not here: the recruitment module does not exist
 * on this branch.
 *
 * Projections are plain arrays: a name, a short detail and a link the viewer
 * may open. Never a reason, a balance, a pay figure or a year of birth.
 */
final class CalendarFeed
{
    /** In the order a day lists them. */
    public const TYPES = ['holiday', 'my-leave', 'team-leave', 'birthday', 'contract-end', 'news'];

    public function __construct(
        private readonly LeaveRequestPricing $pricing,
        private readonly TeamCoverage $coverage,
    ) {}

    /** The six-week grid (Monday first) that shows $month: what the tile reads. */
    public static function gridOf(CarbonImmutable $month): array
    {
        $first = $month->startOfMonth()->startOfDay();
        $start = $first->subDays($first->dayOfWeekIso - 1);

        return [$start, $start->addDays(41)];
    }

    /**
     * @return array<string, list<array{type: string, key: string, title: string, detail: ?string, url: ?string}>> keyed by Y-m-d
     */
    public function forMonth(User $user, CarbonImmutable $month): array
    {
        return $this->forRange($user, ...self::gridOf($month));
    }

    /**
     * @return array<string, list<array{type: string, key: string, title: string, detail: ?string, url: ?string}>> keyed by Y-m-d
     */
    public function forRange(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $days = [];

        foreach ($this->typesFor($user) as $type) {
            $events = match ($type) {
                'holiday' => $this->holidays($user, $from, $to),
                'my-leave' => $this->myLeaves($user, $from, $to),
                'team-leave' => $this->teamLeaves($user, $from, $to),
                'birthday' => $this->birthdays($user, $from, $to),
                'contract-end' => $this->contractEnds($user, $from, $to),
                'news' => $this->news($user, $from, $to),
            };

            foreach ($events as [$date, $event]) {
                $days[$date][] = ['type' => $type, ...$event];
            }
        }

        ksort($days);

        return $days;
    }

    /**
     * The types this viewer may see (the legend shows these).
     *
     * @return list<string>
     */
    public function typesFor(User $user): array
    {
        return array_values(array_filter(self::TYPES, fn (string $type): bool => match ($type) {
            'holiday', 'news', 'birthday' => true,
            'my-leave' => $user->employee_id !== null && $user->hasActivePermission('leaves.view-own'),
            'team-leave' => $user->hasActivePermission('leaves.view-own') && in_array('reports', $this->coverage->scopesFor($user), true),
            'contract-end' => $user->activeRoleIs('HR') && $user->hasActivePermission('contracts.view'),
        }));
    }

    /** May this person start a leave request from the calendar (the New menu's rule)? */
    public function canRequestLeave(User $user): bool
    {
        return $user->employee_id !== null
            && $user->hasActivePermission('leaves.create')
            && $user->hasActivePermission('leaves.view-own');
    }

    // ── One reader per type ──────────────────────────────────────────────

    /** @return iterable<array{0: string, 1: array<string, mixed>}> */
    private function holidays(User $user, CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $contract = $user->profile?->latestActiveContract();

        if (! $contract) {
            return [];
        }

        $position = $contract->position;
        $cityId = $position?->center?->city_id ?? $position?->city_id;
        $byDate = $this->pricing->holidaysOn($position?->program_id ? (int) $position->program_id : null, $cityId !== null ? (int) $cityId : null, $from, $to);
        $events = [];

        foreach ($byDate as $date => $holidays) {
            foreach (collect($holidays)->unique('id') as $holiday) {
                /** @var Holiday $holiday */
                $events[] = [$date, [
                    'key' => 'holiday-'.$holiday->getKey().'-'.$date,
                    'title' => (string) $holiday->name,
                    'detail' => __($holiday->isNational() ? 'dashboard_calendar.detail.national' : 'dashboard_calendar.detail.project'),
                    'url' => null,
                ]];
            }
        }

        foreach (app(BirthdayHoliday::class)->datesFor($contract, $from, $to) as $date) {
            $events[] = [$date, [
                'key' => 'birthday-holiday-'.$date,
                'title' => __('dashboard_calendar.birthday-holiday'),
                'detail' => null,
                'url' => null,
            ]];
        }

        return $events;
    }

    /** @return iterable<array{0: string, 1: array<string, mixed>}> */
    private function myLeaves(User $user, CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $leaves = LeaveRequest::query()
            ->whereIn('status', [LeaveRequestStatus::Accepted, LeaveRequestStatus::Pending])
            ->whereHas('leaveBalance.contract', fn (Builder $c) => $c->where('employee_id', $user->employee_id))
            ->where('start_at', '<', $to->addDay()->toDateTimeString())
            ->where('end_at', '>=', $from->toDateTimeString())
            ->with('leaveBalance:id,type_id', 'leaveBalance.leaveType:id,name_en,name_ar')
            ->orderBy('start_at')
            ->get(['id', 'leave_balance_id', 'type', 'start_at', 'end_at', 'status']);

        $events = [];

        foreach ($leaves as $leave) {
            $pending = (int) $leave->status === LeaveRequestStatus::Pending;
            $type = (string) ($leave->leaveBalance?->leaveType?->name ?? __('team_calendar.on_leave'));

            foreach ($this->datesBetween($leave->start_at, $leave->end_at, $from, $to) as $date) {
                $events[] = [$date, [
                    'key' => 'my-leave-'.$leave->getKey().'-'.$date,
                    'title' => $type,
                    'detail' => __($pending ? 'dashboard_calendar.status.pending' : 'dashboard_calendar.status.accepted'),
                    'url' => route('leaves.index', ['leave' => $leave->getKey()]),
                ]];
            }
        }

        return $events;
    }

    /**
     * The viewer's direct reports, read through TeamCoverage exactly as the
     * team calendar's "reports" scope reads them (manager level: pending
     * included, real type). The viewer's own leave is "my-leave".
     *
     * @return iterable<array{0: string, 1: array<string, mixed>}>
     */
    private function teamLeaves(User $user, CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $events = [];

        foreach ($this->coverage->absences($user, 'reports', $from, $to) as $entry) {
            if ($entry['is_self']) {
                continue;
            }

            $detail = $entry['type'].($entry['status'] === 'pending' ? ' · '.__('dashboard_calendar.status.pending') : '');

            foreach ($entry['dates'] as $date) {
                $events[] = [$date, [
                    'key' => $entry['key'].'-'.$date,
                    'title' => $entry['name'],
                    'detail' => $detail,
                    'url' => route('team.calendar', ['view' => 'day', 'date' => $date]),
                ]];
            }
        }

        return $events;
    }

    /**
     * Day and month only. Birthday is Private (access-policy §4.4): the
     * viewer's own, and for an HR role the active employees of its cities.
     *
     * @return iterable<array{0: string, 1: array<string, mixed>}>
     */
    private function birthdays(User $user, CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $hr = $user->activeRoleIs('HR');

        if (! $hr && ! $user->employee_id) {
            return [];
        }

        $cityIds = $hr ? $user->activeCityIds() : null;

        $employees = Employee::query()
            ->whereNotNull('actual_dob')
            ->when(! $hr, fn (Builder $q) => $q->whereKey($user->employee_id))
            ->when($hr, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where(fn (Builder $active) => $active
                    ->where('is_active', true)
                    ->when($cityIds !== null, fn (Builder $c) => $c->whereIn('city_id', $cityIds ?: [0])))
                ->when($user->employee_id, fn (Builder $self) => $self->orWhereKey($user->employee_id))))
            ->get([...DirectoryAccess::COLUMNS, 'actual_dob']);

        $events = [];

        foreach ($employees as $employee) {
            $dob = $employee->actual_dob;

            // The day it falls on in each year the range touches (29 Feb is 1 Mar in other years).
            for ($year = $from->year; $year <= $to->year; $year++) {
                $day = CarbonImmutable::create($year, $dob->month, 1)->addDays($dob->day - 1);

                if ($day->betweenIncluded($from, $to)) {
                    $self = (int) $employee->getKey() === (int) $user->employee_id;

                    $events[] = [$day->toDateString(), [
                        'key' => 'birthday-'.$employee->getKey().'-'.$day->toDateString(),
                        'title' => $self ? __('dashboard_calendar.your-birthday') : (string) $employee->name,
                        'detail' => null,
                        'url' => $employee->username ? route('employees.show', ['employee' => $employee->username]) : null,
                    ]];
                }
            }
        }

        return $events;
    }

    /**
     * Contracts that end in the range, as the contracts page scopes them: a
     * regional HR role sees its own cities only. Never a pay figure.
     *
     * @return iterable<array{0: string, 1: array<string, mixed>}>
     */
    private function contractEnds(User $user, CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $contracts = Contract::query()
            ->visibleTo($user)
            ->where('contracts.is_active', true)
            ->whereNull('contracts.terminated_at')
            ->whereNull('contracts.termination_reason_id')
            ->where(fn (Builder $w) => $w->whereNull('contracts.type')->orWhere('contracts.type', '!=', ContractTypeEnum::Draft))
            ->whereDate('contracts.end_date', '>=', $from->toDateString())
            ->whereDate('contracts.end_date', '<=', $to->toDateString())
            ->with(['employee' => fn ($e) => $e->select(DirectoryAccess::COLUMNS)])
            ->orderBy('contracts.end_date')
            ->get(['contracts.id', 'contracts.employee_id', 'contracts.end_date']);

        return $contracts->map(fn (Contract $contract) => [$contract->end_date->toDateString(), [
            'key' => 'contract-end-'.$contract->getKey(),
            'title' => (string) ($contract->employee?->name ?? ''),
            'detail' => null,
            'url' => route('contracts.show', ['contract' => $contract->getKey()]),
        ]])->all();
    }

    /** @return iterable<array{0: string, 1: array<string, mixed>}> */
    private function news(User $user, CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $employee = $user->profile;
        $position = $employee?->latestActiveContract()?->position;

        $audience = [
            Employee::class => $employee?->getKey(),
            City::class => $employee?->city_id,
            Center::class => $position?->center_id,
            Program::class => $position?->program_id,
        ];

        $news = News::query()
            ->whereNotNull('event_date')
            ->whereDate('event_date', '>=', $from->toDateString())
            ->whereDate('event_date', '<=', $to->toDateString())
            ->where(function (Builder $q) use ($audience) {
                $q->whereNull('newsable_type');

                foreach ($audience as $type => $id) {
                    if ($id !== null) {
                        $q->orWhere(fn (Builder $one) => $one->where('newsable_type', $type)->where('newsable_id', $id));
                    }
                }
            })
            ->where(fn (Builder $q) => $q->whereNull('display_until')->orWhere('display_until', '>=', now()->startOfDay()))
            ->orderBy('event_date')
            ->get(['id', 'title_en', 'title_ar', 'event_date']);

        return $news->map(fn (News $item) => [$item->event_date->toDateString(), [
            'key' => 'news-'.$item->getKey(),
            'title' => $item->title,
            'detail' => null,
            'url' => null,
        ]])->all();
    }

    /** @return list<string> the dates a leave covers inside [$from, $to] */
    private function datesBetween($start, $end, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $day = CarbonImmutable::parse($start->toDateString());
        $last = CarbonImmutable::parse($end->toDateString());
        $day = $day->lt($from) ? $from : $day;
        $last = $last->gt($to) ? $to : $last;
        $dates = [];

        for (; $day->lte($last); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }
}
