<?php

namespace App\Livewire\Team;

use App\Models\City;
use App\Models\Holiday;
use App\Services\HolidayProjects;
use App\Services\LeaveRequestPricing;
use App\Services\TeamCoverage;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * The team calendar (C5; team-coverage-contract.md). Every absence and count
 * comes from TeamCoverage, the reader the leave form's hint and the deciding
 * manager's warning also use (AC-C5-01).
 *
 * Only scalars are public: the absences are computed in render() and never
 * kept in the component's state, so no request id or model reaches a
 * colleague-level browser through the payload (§4.4). The URL parameters are
 * client input: render() checks each one against what the active role may
 * use, and an unknown or forbidden value falls back to the default.
 */
class Calendar extends Component
{
    public const VIEWS = ['month', 'week', 'day', 'agenda'];

    public const STATUSES = ['all', 'accepted', 'pending'];

    #[Url(except: 'month')]
    public string $view = 'month';

    #[Url(except: '')]
    public string $date = '';

    #[Url(except: '')]
    public string $scope = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(as: 'city', except: 0)]
    public int $cityId = 0;

    /** The embedded holiday dialog saved: redraw with the new day names. */
    protected $listeners = ['holidaysChanged' => '$refresh'];

    /** Previous (-1) or next (1) month, week or day, by the current view. */
    public function move(int $step): void
    {
        $step = $step < 0 ? -1 : 1;
        $anchor = $this->anchor();

        $next = match ($this->normalisedView()) {
            'week' => $anchor->addWeeks($step),
            'day' => $anchor->addDays($step),
            default => $anchor->startOfMonth()->addMonthsNoOverflow($step),
        };

        $this->date = $next->toDateString();
    }

    /** From a full month cell to that day's list. */
    public function openDay(string $date): void
    {
        $this->date = $date;
        $this->view = 'day';
    }

    public function goToday(): void
    {
        $this->date = '';
    }

    public function render()
    {
        $user = auth()->user();
        $coverage = app(TeamCoverage::class);

        $scopes = $coverage->scopesFor($user);
        $scope = in_array($this->scope, $scopes, true) ? $this->scope : $coverage->defaultScope($user);
        $managerView = $coverage->maxLevel($user) >= TeamCoverage::LEVEL_MANAGER;
        $status = $managerView && in_array($this->status, self::STATUSES, true) ? $this->status : 'all';
        $cities = $scope === 'org' ? City::query()->orderBy(transColumn('name'))->get(['id', 'name_en', 'name_ar']) : collect();
        $cityId = $cities->contains('id', $this->cityId) ? $this->cityId : null;
        $view = $this->normalisedView();
        [$from, $to] = $this->range($view);

        $failed = false;

        try {
            $days = $coverage->coverage($user, $scope, $from, $to, ['status' => $status, 'city_id' => $cityId]);
        } catch (Throwable $e) {
            report($e);
            $days = [];
            $failed = true;
        }

        $holidayProjects = app(HolidayProjects::class);
        $canManageHolidays = $holidayProjects->canManage($user);

        return view('livewire.team.calendar', [
            'holidays' => $this->holidayLabels($user, $from, $to, $holidayProjects, $canManageHolidays),
            'canManageHolidays' => $canManageHolidays,
            'days' => $days,
            'failed' => $failed,
            'from' => $from,
            'to' => $to,
            'anchor' => $this->anchor(),
            'viewName' => $view,
            'scopes' => $scopes,
            'currentScope' => $scope,
            'managerView' => $managerView,
            'currentStatus' => $status,
            'cities' => $cities,
            'currentCity' => $cityId,
            // Expected head-counts mean something only for a whole team.
            'showCounts' => in_array($scope, ['reports', 'city', 'org'], true),
            'hasAbsences' => collect($days)->contains(fn (array $day) => $day['members'] !== []),
        ]);
    }

    /**
     * The Sunday–Saturday week holding $day: what the week view shows, and
     * what the home page's "this week" reads up to (G-C-10).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function weekOf(CarbonImmutable $day): array
    {
        $start = $day->startOfDay()->subDays($day->dayOfWeek);

        return [$start, $start->addDays(6)];
    }

    /**
     * Holiday names per day: the viewer's own (national + their project), and,
     * for someone who sets holidays, the ones they look after — so a holiday
     * added from this page shows up on it.
     *
     * @return array<string, list<array{id: int, name: string, editable: bool, national: bool}>>
     */
    private function holidayLabels($user, CarbonImmutable $from, CarbonImmutable $to, HolidayProjects $projects, bool $canManage): array
    {
        $pricing = app(LeaveRequestPricing::class);
        $position = $user?->profile?->latestActiveContract()?->position;
        $cityId = $position?->center?->city_id ?? $position?->city_id;

        $byDate = $pricing->holidaysOn($position?->program_id ? (int) $position->program_id : null, $cityId !== null ? (int) $cityId : null, $from, $to);

        if ($canManage) {
            $managed = $projects->holidaysOf($user)->occurringBetween($from, $to)->get();

            foreach ($pricing->spread($managed, $from, $to) as $date => $rows) {
                $byDate[$date] = [...($byDate[$date] ?? []), ...$rows];
            }
        }

        $labels = [];

        foreach ($byDate as $date => $rows) {
            $labels[$date] = collect($rows)
                ->unique('id')
                ->map(fn (Holiday $h) => [
                    'id' => (int) $h->id,
                    'name' => (string) $h->name,
                    'national' => $h->isNational(),
                    'editable' => $canManage && $projects->canChange($user, $h),
                ])
                ->values()
                ->all();
        }

        return $labels;
    }

    private function normalisedView(): string
    {
        return in_array($this->view, self::VIEWS, true) ? $this->view : 'month';
    }

    private function anchor(): CarbonImmutable
    {
        try {
            return $this->date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1
                ? CarbonImmutable::createFromFormat('!Y-m-d', $this->date)
                : CarbonImmutable::today();
        } catch (Throwable) {
            return CarbonImmutable::today();
        }
    }

    /**
     * The dates a view reads (§4.2 bounds): the month's six-week grid (42
     * days), a week, a day, or the month as a list (≤ 31 days). Weeks start
     * on Sunday, so the Friday–Saturday weekend closes each row.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(string $view): array
    {
        $anchor = $this->anchor();
        $weekStart = fn (CarbonImmutable $day) => self::weekOf($day)[0];

        return match ($view) {
            'week' => self::weekOf($anchor),
            'day' => [$anchor, $anchor],
            'agenda' => [$anchor->startOfMonth(), $anchor->endOfMonth()->startOfDay()],
            default => [$weekStart($anchor->startOfMonth()), $weekStart($anchor->startOfMonth())->addDays(41)],
        };
    }
}
