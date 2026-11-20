<?php

namespace App\Services;

use App\Models\City;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * One place that turns the audit log into the figures of the Activity report,
 * so the page and the Excel export can never disagree.
 */
class ActivityReport
{
    public const PERIODS = [7, 30, 90, 365];

    public function __construct(public readonly int $days = 30) {}

    public static function normaliseDays(int $days): int
    {
        return in_array($days, self::PERIODS, true) ? $days : 30;
    }

    /** @return array<string, mixed> */
    public function build(int $inactiveLimit = 15): array
    {
        $topUsers = $this->base()
            ->whereNotNull('causer_id')
            ->select('causer_id', DB::raw('count(*) as actions'), DB::raw('max(created_at) as last_action'))
            ->groupBy('causer_id')
            ->orderByDesc('actions')
            ->limit(5)
            ->get();

        $users = User::with('profile')->whereIn('id', $topUsers->pluck('causer_id'))->get()->keyBy('id');

        return [
            'days' => self::normaliseDays($this->days),
            'total' => $this->base()->count(),
            'activeUsers' => $this->base()->whereNotNull('causer_id')->distinct()->count('causer_id'),
            'topUsers' => $topUsers->map(fn ($row) => [
                'name' => $users[$row->causer_id]->profile?->name ?? $users[$row->causer_id]->email ?? '-',
                'actions' => (int) $row->actions,
                'last' => $row->last_action,
            ]),
            'topModules' => $this->grouped('subject_type', fn ($v) => class_basename($v)),
            'topEvents' => $this->grouped('event', fn ($v) => ucfirst($v)),
            'daily' => $this->daily(),
            'hours' => $this->hours(),
            'cities' => $this->cities(),
            'inactive' => $this->inactive(),
            'inactiveLimit' => $inactiveLimit,
        ];
    }

    private function from(): Carbon
    {
        return now()->subDays(self::normaliseDays($this->days) - 1)->startOfDay();
    }

    private function base()
    {
        return Activity::query()->where('activity_log.created_at', '>=', $this->from());
    }

    private function grouped(string $column, Closure $label): Collection
    {
        return $this->base()
            ->whereNotNull($column)
            ->select($column, DB::raw('count(*) as actions'))
            ->groupBy($column)
            ->orderByDesc('actions')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['name' => $label($r->{$column}), 'actions' => (int) $r->actions, 'last' => null]);
    }

    /** Every day of the period, zero-filled, oldest first. */
    private function daily(): Collection
    {
        $counts = $this->base()
            ->select(DB::raw('date(created_at) as d'), DB::raw('count(*) as actions'))
            ->groupBy('d')
            ->pluck('actions', 'd');

        $days = collect();
        for ($day = $this->from()->copy(); $day->lte(now()); $day->addDay()) {
            $key = $day->toDateString();
            $days->push(['date' => $key, 'actions' => (int) ($counts[$key] ?? 0)]);
        }

        return $days;
    }

    /** Actions per hour of the day, 0–23, zero-filled. */
    private function hours(): Collection
    {
        $expr = DB::connection()->getDriverName() === 'sqlite'
            ? "cast(strftime('%H', created_at) as integer)"
            : 'hour(created_at)';

        $counts = $this->base()
            ->select(DB::raw("$expr as h"), DB::raw('count(*) as actions'))
            ->groupBy('h')
            ->pluck('actions', 'h');

        return collect(range(0, 23))->map(fn ($h) => ['hour' => $h, 'actions' => (int) ($counts[$h] ?? 0)]);
    }

    /** Actions and distinct users per city of the acting employee. */
    private function cities(): Collection
    {
        $rows = $this->base()
            ->where('activity_log.causer_type', (new User)->getMorphClass())
            ->join('users', 'users.id', '=', 'activity_log.causer_id')
            ->join('employees', 'employees.id', '=', 'users.employee_id')
            ->select('employees.city_id', DB::raw('count(*) as actions'), DB::raw('count(distinct '.DB::getQueryGrammar()->wrap('users.id').') as users'))
            ->groupBy('employees.city_id')
            ->orderByDesc('actions')
            ->get();

        // Names go through the model, like every other city label, not a raw column.
        $cities = City::whereIn('id', $rows->pluck('city_id')->filter())->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'name' => ($cities[$r->city_id] ?? null)?->name ?: '-',
            'actions' => (int) $r->actions,
            'users' => (int) $r->users,
        ]);
    }

    /** Users linked to an employee who did nothing in the period. */
    private function inactive(): Collection
    {
        $activeIds = $this->base()->whereNotNull('causer_id')->select('causer_id');

        return User::with('profile')
            ->whereNotNull('employee_id')
            ->whereNotIn('id', $activeIds)
            ->orderBy('last_seen_at')
            ->get()
            ->map(fn (User $u) => [
                'name' => $u->profile?->name ?? $u->email,
                'last_seen' => $u->last_seen_at,
            ]);
    }
}
