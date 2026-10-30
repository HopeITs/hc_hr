<?php

namespace App\Services;

use App\Enums\LeaveRequestStatus;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\User;
use App\Support\DirectoryAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Whose absences a viewer may see, at which level, and what the team's
 * coverage is (C5; docs/ux/team-coverage-contract.md §3.1, §4).
 *
 * One reader for the team calendar, the requester's hint in the leave form
 * and the deciding manager's overlap warning, so the three can never
 * disagree (AC-C5-01). Reads only: it never writes and never touches
 * Carbon's global weekend; working days come from LeaveRequestPricing.
 *
 * Levels (§4.1): C colleague (accepted only, neutral type for sensitive
 * leave, no status, no request id), M manager (+ pending, real type, hours,
 * substitute, request id), H HR (as M on this surface). The highest level
 * that applies to a person wins. A scope the viewer may not use, or a person
 * outside every cell, gives the same empty result as an empty team: nothing
 * reveals that hidden absences exist (§4.4).
 *
 * Projections are plain arrays: no model, no reason, no reject reason, no
 * balance, no pay field ever leaves this class (§4.4 "never" list).
 */
final class TeamCoverage
{
    public const LEVEL_COLLEAGUE = 1;

    public const LEVEL_MANAGER = 2;

    public const LEVEL_HR = 3;

    public const SCOPES = ['colleagues', 'department', 'center', 'directManager', 'programManagers', 'serviceDepartments', 'reports', 'city', 'org'];

    /** @var array<int, array<string, mixed>> per viewer id */
    private array $contexts = [];

    /** @var array<string, array{0: array<int, int>, 1: array<string, true>}> */
    private array $calendars = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $memberSets = [];

    public function __construct(private readonly LeaveRequestPricing $pricing) {}

    /**
     * The scopes $viewer may choose, in menu order. Always includes
     * `colleagues`; the others depend on the active role and positions.
     *
     * @return array<int, string>
     */
    public function scopesFor(User $viewer): array
    {
        $context = $this->context($viewer);
        $scopes = ['colleagues'];

        if ($context['positions']->isNotEmpty()) {
            $scopes = [...$scopes, 'department', 'center', 'directManager'];
        }

        if ($context['pmPositions']->isNotEmpty()) {
            $scopes = [...$scopes, 'programManagers', 'serviceDepartments'];
        }

        if ($context['positions']->isNotEmpty()) {
            $scopes[] = 'reports';
        }

        if ($context['hrCityIds'] !== null) {
            $scopes[] = 'city';
        }

        if ($context['orgLevel'] !== null) {
            $scopes[] = 'org';
        }

        return $scopes;
    }

    /** The scope a viewer starts on: their own reports when they have any, else colleagues. */
    public function defaultScope(User $viewer): string
    {
        $context = $this->context($viewer);

        if ($context['orgLevel'] !== null) {
            return 'org';
        }

        if ($context['hrCityIds'] !== null) {
            return 'city';
        }

        return $this->hasReports($viewer) ? 'reports' : 'colleagues';
    }

    /** The highest level $viewer reaches anywhere: whether pending and real types can ever show. */
    public function maxLevel(User $viewer): int
    {
        $context = $this->context($viewer);

        if ($context['orgLevel'] !== null || $context['hrCityIds'] !== null) {
            return $context['orgLevel'] ?? self::LEVEL_HR;
        }

        return $this->hasReports($viewer) ? self::LEVEL_MANAGER : self::LEVEL_COLLEAGUE;
    }

    /**
     * The employee ids in $scope that $viewer may see, at any level: the same
     * people the calendar draws, for a surface that lists people rather than
     * their absences (the dashboard's birthdays tile). A scope the viewer may
     * not use gives no one, as everywhere else here.
     *
     * @return array<int, int>
     */
    public function memberIds(User $viewer, string $scope): array
    {
        return array_map('intval', array_keys($this->members($viewer, $scope)));
    }

    /**
     * Birthdays in [$from, $to] of the people in $scope that $viewer may see:
     * the team calendar's day labels, on the same rule as the dashboard's
     * birthdays tile. Only the day leaves this method, never the year or an
     * age, and the key is opaque. A 29 February birthday is kept on 1 March
     * in other years, as recurring holidays are.
     *
     * @return array<string, list<array{key: string, name: string, avatar: string, is_self: bool}>> keyed by Y-m-d
     */
    public function birthdays(User $viewer, string $scope, CarbonImmutable $from, CarbonImmutable $to, ?int $cityId = null): array
    {
        [$from, $to] = $this->bounded($from, $to);
        $members = $this->members($viewer, $scope, $cityId);

        if ($members === []) {
            return [];
        }

        // month-day => the dates it falls on in the range
        $dates = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $dates[$day->format('m-d')][] = $day->toDateString();

            if ($day->format('m-d') === '03-01' && ! $day->isLeapYear()) {
                $dates['02-29'][] = $day->toDateString();
            }
        }

        $byDate = [];

        Employee::query()
            ->select([...DirectoryAccess::COLUMNS, 'actual_dob'])
            ->whereIn('id', array_keys($members))
            ->where('is_active', true)
            ->whereNotNull('actual_dob')
            ->with('media')
            ->get()
            ->each(function (Employee $employee) use ($dates, $members, &$byDate) {
                foreach ($dates[$employee->actual_dob->format('m-d')] ?? [] as $date) {
                    $byDate[$date][] = [
                        'key' => 'b-'.substr(hash('sha256', $employee->getKey().'|'.$date.'|'.session()->getId()), 0, 12),
                        'name' => (string) $employee->name,
                        'avatar' => $employee->avatar_url,
                        'is_self' => $members[$employee->getKey()]['is_self'],
                    ];
                }
            });

        return array_map(fn (array $people) => collect($people)->sortBy('name')->values()->all(), $byDate);
    }

    /**
     * Absences in [$from, $to] (local dates, both inclusive) of the people in
     * $scope that $viewer may see, as projections (§4.4).
     *
     * @param  array{status?: string, city_id?: int|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function absences(User $viewer, string $scope, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): Collection
    {
        [$from, $to] = $this->bounded($from, $to);
        $members = $this->members($viewer, $scope, $filters['city_id'] ?? null);

        if ($members === []) {
            return collect();
        }

        $status = $filters['status'] ?? 'all';

        return $this->leavesOf(array_keys($members), $from, $to)
            ->map(fn (LeaveRequest $leave) => $this->project($viewer, $leave, $members[$leave->leaveBalance->contract->employee_id], $from, $to))
            ->filter()
            ->filter(fn (array $entry) => $status === 'all' || $entry['status'] === $status
                // A colleague-level entry has no status, and is always accepted.
                || ($entry['status'] === null && $status === 'accepted'))
            ->sortBy([['from', 'asc'], ['name', 'asc']])
            ->values();
    }

    /**
     * Day by day: who is away, and, for manager and HR scopes, how many were
     * expected. Every count is over the viewer's visible set only (§4.4).
     *
     * @param  array{status?: string, city_id?: int|null}  $filters
     * @return array<string, array<string, mixed>> keyed by Y-m-d
     */
    public function coverage(User $viewer, string $scope, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        [$from, $to] = $this->bounded($from, $to);
        $members = $this->members($viewer, $scope, $filters['city_id'] ?? null);
        $entries = $this->absences($viewer, $scope, $from, $to, $filters);
        $viewerCalendar = $this->viewerCalendar($viewer, $from, $to);
        $days = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $key = $day->toDateString();
            $expected = 0;

            foreach ($members as $member) {
                $contract = $member['contract'];

                if ($contract->start_date?->toDateString() <= $key
                    && ($contract->end_date === null || $contract->end_date->toDateString() >= $key)
                    && $this->isWorkingDay($contract, $day, $from, $to)) {
                    $expected++;
                }
            }

            $onDay = $entries->filter(fn (array $entry) => in_array($key, $entry['dates'], true));

            $days[$key] = [
                'date' => $key,
                'expected' => $expected,
                'away' => $onDay->filter(fn (array $e) => ! $e['partial'] && $e['status'] !== 'pending' && in_array($key, $e['counted'], true))->count(),
                'partial' => $onDay->filter(fn (array $e) => $e['partial'] && $e['status'] !== 'pending')->count(),
                'pending' => $onDay->filter(fn (array $e) => $e['status'] === 'pending')->count(),
                'is_holiday' => isset($viewerCalendar[1][$key]),
                'is_weekend' => $viewerCalendar[0] !== [] && in_array($day->dayOfWeek, $viewerCalendar[0], true),
                'members' => $onDay->values()->all(),
            ];
        }

        return $days;
    }

    /**
     * The requester's hint (§4.5 `team_overlap_hint`, Q4): how many colleagues
     * are already away on an accepted leave on at least one of the
     * requester's own working days in the window. A count only, never names.
     */
    public function colleaguesAwayCount(User $requester, CarbonImmutable $from, CarbonImmutable $to): int
    {
        [$from, $to] = $this->bounded($from, $to);
        $own = $this->ownWorkingDates($requester, $from, $to);

        if ($own === []) {
            return 0;
        }

        return $this->absences($requester, 'colleagues', $from, $to, ['status' => 'accepted'])
            ->reject(fn (array $entry) => $entry['is_self'])
            ->filter(fn (array $entry) => array_intersect($entry['counted'] ?: $entry['dates'], $own) !== [])
            ->pluck('person')
            ->unique()
            ->count();
    }

    /** §4.5 `manager_absent`: the requester's direct manager has an accepted leave in the window. */
    public function managerAway(User $requester, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        [$from, $to] = $this->bounded($from, $to);
        $managerPositionIds = $this->context($requester)['positions']->pluck('direct_manager_id')->filter()->unique()->all();

        if ($managerPositionIds === []) {
            return false;
        }

        $managerIds = Contract::query()->activeNow()
            ->whereIn('position_id', $managerPositionIds)
            ->whereHas('employee', fn (Builder $e) => $e->where('is_active', true))
            ->pluck('employee_id')
            ->all();

        return $managerIds !== [] && $this->overlapping(LeaveRequest::query(), $from, $to)
            ->where('status', LeaveRequestStatus::Accepted)
            ->whereHas('leaveBalance.contract', fn (Builder $c) => $c->whereIn('employee_id', $managerIds))
            ->exists();
    }

    /**
     * The deciding manager's warning (§4.5 `team_overlap`, Q3: any overlap):
     * other members of the requester's direct-manager team away, accepted or
     * pending, on the requester's working days of $leave. Empty when none.
     * Advisory only: it is never an error and never blocks the decision.
     *
     * Each member is shown only as far as the decider ($viewer, by default the
     * signed-in user) may see them on the calendar (§4.1, G-C-13 / F12): out
     * of reach, left out; at colleague level, accepted leave only. HR deciding
     * on behalf of a manager whose team spans cities therefore never learns
     * of a pending request in another city.
     *
     * @return array{dates: array<int, string>, members: array<int, array{name: string, status: string, dates: array<int, string>}>}
     */
    public function teamOverlap(LeaveRequest $leave, ?User $viewer = null): array
    {
        $none = ['dates' => [], 'members' => []];
        $viewer ??= auth()->user();
        $leave->loadMissing('leaveBalance.contract.position.center');
        $contract = $leave->leaveBalance?->contract;
        $managerPositionId = $contract?->position?->direct_manager_id;

        if (! $contract || ! $managerPositionId || ! $leave->start_at || ! $leave->end_at) {
            return $none;
        }

        $from = CarbonImmutable::parse($leave->start_at->toDateString());
        $to = CarbonImmutable::parse($leave->end_at->toDateString());

        if ($to->lt($from) || $from->diffInDays($to) + 1 > (int) config('team-coverage.max_days')) {
            return $none;
        }

        $own = $leave->type === 'hour' ? [$from->toDateString()] : $this->workingDates($contract, $from, $to);

        $team = $this->activeContracts()
            ->whereHas('position', fn (Builder $p) => $p->where('direct_manager_id', $managerPositionId))
            ->where('employee_id', '!=', $contract->employee_id)
            ->get()
            ->keyBy('employee_id');

        if ($team->isEmpty() || $own === []) {
            return $none;
        }

        $members = [];
        $dates = [];

        foreach ($this->leavesOf($team->keys()->all(), $from, $to) as $other) {
            if ($other->is($leave)) {
                continue;
            }

            $member = $team[$other->leaveBalance->contract->employee_id];
            $pending = (int) $other->status === LeaveRequestStatus::Pending;

            if ($viewer !== null) {
                $level = $this->levelFor($viewer, $this->context($viewer), $member);

                if ($level === null || ($pending && $level < self::LEVEL_MANAGER)) {
                    continue;
                }
            }

            $occupied = $this->occupiedDates($other, $from, $to);
            $counted = $other->type === 'hour' ? $occupied : array_values(array_intersect($occupied, $this->workingDates($member, $from, $to)));
            $overlap = array_values(array_intersect($counted, $own));

            if ($overlap === []) {
                continue;
            }

            $dates = [...$dates, ...$overlap];
            $members[] = [
                'name' => (string) $member->employee?->name,
                'status' => $pending ? 'pending' : 'accepted',
                'dates' => $overlap,
            ];
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        return ['dates' => $dates, 'members' => $members];
    }

    // ── Who is visible ───────────────────────────────────────────────────

    /**
     * Active colleagues in $scope that $viewer may see, keyed by employee id,
     * each with the level that applies and the active contract that sets
     * their working days.
     *
     * @return array<int, array{level: int, contract: Contract, is_self: bool}>
     */
    private function members(User $viewer, string $scope, ?int $cityId = null): array
    {
        $key = $viewer->getKey().':'.$viewer->myRole().':'.$scope.':'.$cityId;

        return $this->memberSets[$key] ??= $this->resolveMembers($viewer, $scope, $cityId);
    }

    /** @return array<int, array{level: int, contract: Contract, is_self: bool}> */
    private function resolveMembers(User $viewer, string $scope, ?int $cityId): array
    {
        if (! in_array($scope, $this->scopesFor($viewer), true)) {
            return [];
        }

        $context = $this->context($viewer);
        $query = $this->activeContracts();

        $applied = match ($scope) {
            'colleagues' => $this->constrainToColleagues($query, $context),
            'department' => $query->whereHas('position', fn (Builder $p) => $p->whereIn('department_id', $context['departmentIds'] ?: [0])),
            'center' => $query->whereHas('position', fn (Builder $p) => $p->whereIn('center_id', $context['centerIds'] ?: [0])),
            'directManager' => $query->whereHas('position', fn (Builder $p) => $p
                ->whereIn('direct_manager_id', $context['managerPositionIds'] ?: [0])
                ->orWhereIn($p->qualifyColumn('id'), $context['managerPositionIds'] ?: [0])),
            'programManagers' => $query->whereHas('position', fn (Builder $p) => $this->projectManagers($p, $context)),
            'serviceDepartments' => $query->whereHas('position', fn (Builder $p) => $this->serviceDepartments($p, $context)),
            'reports' => $query->whereHas('position', fn (Builder $p) => $p->whereIn('direct_manager_id', $context['positionIds'] ?: [0])),
            'city' => $query->whereHas('employee', fn (Builder $e) => $e->whereIn('city_id', $context['hrCityIds'] ?: [0])),
            'org' => $cityId ? $query->whereHas('employee', fn (Builder $e) => $e->where('city_id', $cityId)) : $query,
        };

        $members = [];

        foreach ($applied->get() as $contract) {
            $level = $this->levelFor($viewer, $context, $contract);

            if ($level === null) {
                continue;
            }

            $current = $members[$contract->employee_id] ?? null;

            if ($current === null || $level > $current['level']) {
                $members[$contract->employee_id] = [
                    'level' => $level,
                    'contract' => $contract,
                    'is_self' => $viewer->employee_id !== null && (int) $contract->employee_id === (int) $viewer->employee_id,
                ];
            }
        }

        return $members;
    }

    /** The level $viewer has over the holder of $contract, or null when none (§4.1). */
    private function levelFor(User $viewer, array $context, Contract $contract): ?int
    {
        $employee = $contract->employee;
        $position = $contract->position;

        if (! $employee || ! $position) {
            return null;
        }

        if ($viewer->employee_id !== null && (int) $employee->id === (int) $viewer->employee_id) {
            return self::LEVEL_MANAGER;
        }

        $levels = [];

        if ($context['orgLevel'] !== null) {
            $levels[] = $context['orgLevel'];
        }

        if ($context['hrCityIds'] !== null && self::has($employee->city_id, $context['hrCityIds'])) {
            $levels[] = self::LEVEL_HR;
        }

        if (self::has($position->direct_manager_id, $context['positionIds'])) {
            $levels[] = self::LEVEL_MANAGER;
        }

        if ($this->isColleague($context, $position)) {
            $levels[] = self::LEVEL_COLLEAGUE;
        }

        return $levels === [] ? null : max($levels);
    }

    /** §3.1: same department (any governorate), same center, same direct manager or that manager, PM peers, service departments for a PM. */
    private function isColleague(array $context, Position $position): bool
    {
        if ($context['positions']->isEmpty()) {
            return false;
        }

        $grade = (int) config('team-coverage.project_manager_grade');

        return self::has($position->department_id, $context['departmentIds'])
            || self::has($position->center_id, $context['centerIds'])
            || self::has($position->direct_manager_id, $context['managerPositionIds'])
            || self::has($position->id, $context['managerPositionIds'])
            || ((int) $position->jobLevel?->grade === $grade && self::has($position->program_id, $context['pmProgramIds']))
            || (in_array($position->department?->code, config('team-coverage.service_department_codes'), true)
                && self::has($this->governorate($position), $context['pmCityIds']));
    }

    private function constrainToColleagues(Builder $query, array $context): Builder
    {
        if ($context['positions']->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('position', fn (Builder $p) => $p->where(function (Builder $any) use ($context): void {
            $any->whereIn('department_id', $context['departmentIds'] ?: [0])
                ->orWhereIn('center_id', $context['centerIds'] ?: [0])
                ->orWhereIn('direct_manager_id', $context['managerPositionIds'] ?: [0])
                ->orWhereIn($any->qualifyColumn('id'), $context['managerPositionIds'] ?: [0]);

            if ($context['pmPositions']->isNotEmpty()) {
                $any->orWhere(fn (Builder $pm) => $this->projectManagers($pm, $context))
                    ->orWhere(fn (Builder $service) => $this->serviceDepartments($service, $context));
            }
        }));
    }

    private function projectManagers(Builder $positions, array $context): Builder
    {
        return $positions->whereIn('program_id', $context['pmProgramIds'] ?: [0])
            ->whereHas('jobLevel', fn (Builder $l) => $l->where('grade', (int) config('team-coverage.project_manager_grade')));
    }

    private function serviceDepartments(Builder $positions, array $context): Builder
    {
        return $positions
            ->whereHas('department', fn (Builder $d) => $d->whereIn('code', config('team-coverage.service_department_codes')))
            ->inCity($context['pmCityIds'] ?: [0]);
    }

    /**
     * The viewer's own footing, read once per request from the active role and
     * the positions they hold on active contracts.
     *
     * @return array<string, mixed>
     */
    private function context(User $viewer): array
    {
        $key = $viewer->getKey().':'.$viewer->myRole();

        if (isset($this->contexts[$key])) {
            return $this->contexts[$key];
        }

        $positions = $viewer->employee_id
            ? Contract::query()->activeNow()
                ->where('employee_id', $viewer->employee_id)
                ->with(['position.center', 'position.jobLevel'])
                ->get()
                ->pluck('position')
                ->filter()
                ->unique('id')
                ->values()
            : collect();

        $grade = (int) config('team-coverage.project_manager_grade');
        $pmPositions = $positions->filter(fn (Position $p) => (int) $p->jobLevel?->grade === $grade)->values();
        $role = (string) $viewer->myRole();

        $orgLevel = match (true) {
            in_array($role, ['HR-SYR', 'super-admin'], true) => self::LEVEL_HR,
            $role === 'general-manager' => self::LEVEL_MANAGER,
            default => null,
        };

        return $this->contexts[$key] = [
            'positions' => $positions,
            'positionIds' => self::ids($positions->pluck('id')->all()),
            'departmentIds' => self::ids($positions->pluck('department_id')->all()),
            'centerIds' => self::ids($positions->pluck('center_id')->all()),
            'managerPositionIds' => self::ids($positions->pluck('direct_manager_id')->all()),
            'pmPositions' => $pmPositions,
            'pmProgramIds' => self::ids($pmPositions->pluck('program_id')->all()),
            'pmCityIds' => self::ids($pmPositions->map(fn (Position $p) => $this->governorate($p))->all()),
            'hrCityIds' => $orgLevel === null && str_starts_with($role, 'HR-') ? self::ids($viewer->activeCityIds() ?? []) : null,
            'orgLevel' => $orgLevel,
        ];
    }

    private function hasReports(User $viewer): bool
    {
        $positionIds = $this->context($viewer)['positionIds'];

        return $positionIds !== [] && $this->activeContracts()
            ->whereHas('position', fn (Builder $p) => $p->whereIn('direct_manager_id', $positionIds))
            ->exists();
    }

    /** Running contracts of active employees, with what the projection and the working days need. */
    private function activeContracts(): Builder
    {
        return Contract::query()->activeNow()
            ->whereHas('employee', fn (Builder $e) => $e->where('is_active', true))
            ->with([
                'employee' => fn ($e) => $e->select(DirectoryAccess::COLUMNS)->with('media'),
                'position.center',
                'position.jobLevel',
                'position.department',
            ]);
    }

    // ── Leaves and dates ─────────────────────────────────────────────────

    /** @return Collection<int, LeaveRequest> */
    private function leavesOf(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->overlapping(LeaveRequest::query(), $from, $to)
            ->whereIn('status', [LeaveRequestStatus::Accepted, LeaveRequestStatus::Pending])
            ->whereHas('leaveBalance.contract', fn (Builder $c) => $c->whereIn('employee_id', $employeeIds))
            ->with([
                'leaveBalance:id,contract_id,type_id',
                'leaveBalance.leaveType:id,name_en,name_ar',
                'leaveBalance.contract:id,employee_id',
                'alternativeEmployee' => fn ($e) => $e->select(DirectoryAccess::COLUMNS),
            ])
            ->orderBy('start_at')
            ->get();
    }

    /** §4.2: start_at < (to + 1 day) 00:00 and end_at >= from 00:00, sargable. */
    private function overlapping(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query
            ->where('start_at', '<', $to->addDay()->startOfDay()->toDateTimeString())
            ->where('end_at', '>=', $from->startOfDay()->toDateTimeString());
    }

    /** @return array<string, mixed>|null */
    private function project(User $viewer, LeaveRequest $leave, array $member, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $level = $member['level'];
        $pending = (int) $leave->status === LeaveRequestStatus::Pending;

        // Colleagues see accepted leave only (§3.1 Q2).
        if ($pending && $level < self::LEVEL_MANAGER) {
            return null;
        }

        $contract = $member['contract'];
        $employee = $contract->employee;
        $partial = $leave->type === 'hour';
        $dates = $this->occupiedDates($leave, $from, $to);
        $counted = $partial ? $dates : array_values(array_intersect($dates, $this->workingDates($contract, $from, $to)));
        $type = $leave->leaveBalance?->leaveType;
        $neutral = $level < self::LEVEL_MANAGER
            && in_array((int) $type?->getKey(), config('team-coverage.neutral_type_ids'), true);
        $manager = $level >= self::LEVEL_MANAGER;

        return [
            // Opaque at colleague level, so no request id reaches that browser.
            'key' => $manager ? 'leave-'.$leave->getKey() : 'a-'.substr(hash('sha256', $leave->getKey().'|'.session()->getId().'|'.$viewer->getKey()), 0, 16),
            'id' => $manager ? $leave->getKey() : null,
            'person' => 'p-'.substr(hash('sha256', $employee->getKey().'|'.session()->getId()), 0, 12),
            'name' => (string) $employee->name,
            'avatar' => $employee->avatar_url,
            'position' => (string) $contract->position?->title,
            'from' => $dates[0] ?? $from->toDateString(),
            'to' => $dates[count($dates) - 1] ?? $to->toDateString(),
            'dates' => $dates,
            'counted' => $counted,
            'partial' => $partial,
            'hours' => $manager && $partial ? $leave->start_at->format('H:i').'–'.$leave->end_at->format('H:i') : null,
            'type' => $neutral ? __('team_calendar.on_leave') : (string) ($type?->name ?? __('team_calendar.on_leave')),
            'status' => $manager ? ($pending ? 'pending' : 'accepted') : null,
            'substitute' => $manager ? $leave->alternativeEmployee?->name : null,
            'is_self' => $member['is_self'],
            'level' => $level,
        ];
    }

    /** @return array<int, string> the leave's dates inside [$from, $to] */
    private function occupiedDates(LeaveRequest $leave, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = CarbonImmutable::parse($leave->start_at->toDateString());
        $end = CarbonImmutable::parse($leave->end_at->toDateString());
        $start = $start->lt($from) ? $from : $start;
        $end = $end->gt($to) ? $to : $end;
        $dates = [];

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /** @return array<int, string> the working days of $contract's holder in the range (no weekend, no holiday) */
    private function workingDates(Contract $contract, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            if ($this->isWorkingDay($contract, $day, $from, $to)) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }

    private function isWorkingDay(Contract $contract, CarbonImmutable $day, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        [$weekend, $holidays] = $this->calendarOf($contract, $from, $to);

        return ! in_array($day->dayOfWeek, $weekend, true) && ! isset($holidays[$day->toDateString()]);
    }

    /**
     * Weekend and holidays from LeaveRequestPricing, read once per range for
     * everything they depend on (center, program, governorate, the holder's
     * city), so a team in one center costs the same as one person.
     */
    private function calendarOf(Contract $contract, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $position = $contract->position;
        $key = implode(':', [
            $position?->center_id, $position?->program_id, $position?->city_id, $contract->employee?->city_id,
            $from->toDateString(), $to->toDateString(),
        ]);

        return $this->calendars[$key] ??= [
            $this->pricing->weekendFor($contract)[0],
            $this->pricing->holidayDates($contract, $from, $to),
        ];
    }

    /** @return array{0: array<int, int>, 1: array<string, true>} the viewer's own weekend and holidays, for the grid */
    private function viewerCalendar(User $viewer, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $contract = $viewer->profile?->latestActiveContract();

        return $contract ? $this->calendarOf($contract, $from, $to) : [[], []];
    }

    /** @return array<int, string> */
    private function ownWorkingDates(User $viewer, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $contract = $viewer->profile?->latestActiveContract();

        return $contract ? $this->workingDates($contract, $from, $to) : [];
    }

    private function governorate(Position $position): ?int
    {
        $cityId = $position->center?->city_id ?? $position->city_id;

        return $cityId === null ? null : (int) $cityId;
    }

    /**
     * Ids as distinct positive ints, so comparisons do not depend on whether
     * the driver returned strings or ints.
     *
     * @return array<int, int>
     */
    private static function ids(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values))));
    }

    private static function has(mixed $id, array $ids): bool
    {
        return $id !== null && in_array((int) $id, $ids, true);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function bounded(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        if ($to->lt($from) || $from->diffInDays($to) + 1 > (int) config('team-coverage.max_days')) {
            throw new InvalidArgumentException('Team coverage reads at most '.config('team-coverage.max_days').' days.');
        }

        return [$from, $to];
    }
}
