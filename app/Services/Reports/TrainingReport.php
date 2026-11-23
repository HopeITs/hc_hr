<?php

namespace App\Services\Reports;

use App\Models\Contract;
use App\Models\Course;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Courses recorded on employee files. A course "counts" when it is approved,
 * in progress or finished; pending and rejected ones are shown by status but
 * left out of the totals. Scoped to the viewer's city, like every HR page.
 * Coverage is measured over people with a contract in force today.
 */
class TrainingReport
{
    /** courses.status */
    public const PENDING = 1;

    public const APPROVED = 2;

    public const REJECTED = 3;

    public const IN_PROGRESS = 4;

    public const FINISHED = 5;

    public const COUNTED = [self::APPROVED, self::IN_PROGRESS, self::FINISHED];

    public const UNTRAINED_LIMIT = 20;

    public function __construct(public readonly User $viewer, public readonly ?int $year = null, public readonly ?int $categoryId = null) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $courses = $this->courses()->get();
        $counted = $courses->whereIn('status', self::COUNTED);
        $total = $counted->count();

        $inForce = $this->peopleInForce();
        $trainedIds = $counted->pluck('employee_id')->unique();
        $untrained = $inForce->reject(fn (Employee $e) => $trainedIds->contains($e->id))->values();

        return [
            'year' => $this->year,
            'courses' => $total,
            'trained' => $trainedIds->intersect($inForce->pluck('id'))->count(),
            'cost' => round((float) $counted->sum('cost'), 2),
            'certificates' => $counted->where('has_certificate', true)->count(),
            'people' => $inForce->count(),
            'coveragePercent' => $inForce->isEmpty() ? null : round($trainedIds->intersect($inForce->pluck('id'))->count() / $inForce->count() * 100, 1),
            'byCategory' => $this->share($counted->groupBy(fn (Course $c) => $c->category?->{transColumn('name')} ?: __('No category')), $total),
            'byStatus' => $this->share($courses->groupBy(fn (Course $c) => $this->statusLabel((int) $c->status)), $courses->count()),
            'byCity' => $this->share($counted->groupBy(fn (Course $c) => $c->employee?->city?->name ?: '-'), $total),
            'byMonth' => $counted->filter(fn (Course $c) => $c->start_date)
                ->groupBy(fn (Course $c) => $c->start_date->format('Y-m'))->map->count()->sortKeys(),
            'byOrganization' => $counted->filter(fn (Course $c) => filled($c->organization))
                ->groupBy(fn (Course $c) => trim($c->organization))->map->count()->sortDesc()->take(8),
            'untrainedCount' => $untrained->count(),
            'untrained' => $untrained->map(fn (Employee $e) => [
                'name' => $e->name,
                'username' => $e->username,
                'city' => $e->city?->name ?: '-',
            ])->values(),
        ];
    }

    private function courses(): Builder
    {
        $cityIds = $this->viewer->activeCityIds();

        return Course::query()
            ->when($cityIds !== null, fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $e->whereIn('city_id', $cityIds)))
            ->when($this->year, fn (Builder $q) => $q->whereYear('start_date', $this->year))
            ->when($this->categoryId, fn (Builder $q) => $q->where('category_id', $this->categoryId))
            ->with(['category', 'employee.city']);
    }

    /** @return Collection<int, Employee> people with a contract in force today, inside the viewer's scope */
    private function peopleInForce(): Collection
    {
        $ids = Contract::query()->activeNow()->visibleTo($this->viewer)->pluck('employee_id')->unique();

        return Employee::query()->where('is_active', true)->whereIn('id', $ids)->with('city')->orderBy('id')->get();
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            self::PENDING => __('Pending approval'),
            self::APPROVED => __('Approved'),
            self::REJECTED => __('Rejected'),
            self::IN_PROGRESS => __('In progress'),
            self::FINISHED => __('Finished'),
            default => '-',
        };
    }

    /** @return Collection<int, array{label: string, count: int, percent: float}> */
    private function share(Collection $groups, int $total): Collection
    {
        return $groups->map(fn (Collection $g, string $label) => [
            'label' => $label,
            'count' => $g->count(),
            'percent' => $total ? round($g->count() / $total * 100, 1) : 0.0,
        ])->sortByDesc('count')->values();
    }
}
