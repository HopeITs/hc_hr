<?php

namespace App\Livewire\Reports;

use App\Exports\ReportWorkbook;
use App\Models\City;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use App\Services\Reports\PositionVacanciesReport;
use App\Support\Reports\ReportCatalog;
use Livewire\Attributes\Url;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

/** Position capacity against people in post. */
class PositionVacancies extends Component
{
    /** @var array<string, int|string|bool|null> */
    #[Url(as: 'f')]
    public array $filters = [];

    public static function mayView(?User $user): bool
    {
        return ReportCatalog::allows('vacancies', $user);
    }

    public function mount(): void
    {
        abort_unless(self::mayView(auth()->user()), 403);
    }

    public function clearFilters(): void
    {
        $this->filters = [];
    }

    public function export()
    {
        abort_unless(self::mayView(auth()->user()), 403);
        $r = $this->report();
        $groups = fn ($c) => $c->map(fn ($g) => [$g['label'], $g['positions'], $g['capacity'], $g['filled'], $g['vacant'], $g['percent'] === null ? '-' : $g['percent'].'%'])->all();
        $heads = fn (string $first) => [$first, 'Positions', 'Capacity', 'In post', 'Vacant', 'Filled'];

        return Excel::download(new ReportWorkbook([
            ['title' => 'Summary', 'headings' => ['Metric', 'Value'], 'rows' => [
                ['Positions', $r['positions']], ['Capacity', $r['capacity']], ['In post', $r['filled']],
                ['Vacant seats', $r['vacantSeats']], ['Filled %', $r['fillPercent'] ?? '-'],
                ['Positions with nobody', $r['emptyPositions']], ['Over capacity', $r['overPositions']],
                ['Critical positions with a vacancy', $r['criticalVacant']],
            ]],
            ['title' => 'By city', 'headings' => $heads('City'), 'rows' => $groups($r['byCity'])],
            ['title' => 'By department', 'headings' => $heads('Department'), 'rows' => $groups($r['byDepartment'])],
            ['title' => 'Positions', 'headings' => ['Position', 'City', 'Department', 'Program', 'Capacity', 'In post', 'Vacant', 'Over', 'Critical'],
                'rows' => $r['list']->map(fn ($p) => [$p['title'], $p['city'], $p['department'], $p['program'], $p['capacity'], $p['filled'], $p['vacant'], $p['over'], $p['critical'] ? 'Yes' : ''])->all()],
        ]), 'position_vacancies_'.now()->format('Y-m-d').'.xlsx');
    }

    public function render()
    {
        abort_unless(self::mayView(auth()->user()), 403);
        $cityIds = auth()->user()->activeCityIds();

        return view('livewire.reports.position-vacancies', [
            'r' => $this->report(),
            'options' => [
                'city_id' => City::query()->when($cityIds !== null, fn ($q) => $q->whereIn('id', $cityIds))->orderBy('id')->get()->pluck('name', 'id')->all(),
                'department_id' => Department::query()->orderBy('name')->pluck('name', 'id')->all(),
                'program_id' => Program::query()->orderBy('name')->pluck('name', 'id')->all(),
            ],
            'limit' => PositionVacanciesReport::LIST_LIMIT,
        ]);
    }

    private function report(): array
    {
        return (new PositionVacanciesReport(auth()->user(), $this->filters))->build();
    }
}
