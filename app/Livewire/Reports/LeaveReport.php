<?php

namespace App\Livewire\Reports;

use App\Exports\ReportWorkbook;
use App\Models\Department;
use App\Models\User;
use App\Services\Reports\LeaveReport as Report;
use App\Support\Reports\ReportCatalog;
use Livewire\Attributes\Url;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

/** Leave balances owed and leave taken. */
class LeaveReport extends Component
{
    #[Url(as: 'period')]
    public int $days = 365;

    #[Url(as: 'department')]
    public ?string $departmentId = null;

    public static function mayView(?User $user): bool
    {
        return ReportCatalog::allows('leave', $user);
    }

    public function mount(): void
    {
        abort_unless(self::mayView(auth()->user()), 403);
    }

    public function export()
    {
        abort_unless(self::mayView(auth()->user()), 403);
        $r = $this->report();

        return Excel::download(new ReportWorkbook([
            ['title' => 'Summary', 'headings' => ['Metric', 'Value'], 'rows' => [
                ['People with annual leave', $r['people']],
                ['Annual leave owed (days)', $r['owedDays']],
                ['Average owed per person (days)', $r['averageOwedDays'] ?? '-'],
                ['Not taking leave', $r['idleCount']],
                ['Leave taken in the period (days)', $r['takenDays']],
                ['Days to answer a request', $r['daysToAnswer'] ?? '-'],
                ['Pending requests', $r['pending']],
                ['Pending more than 3 days', $r['pendingOverThree']],
            ]],
            ['title' => 'By department', 'headings' => ['Department', 'People', 'Owed (days)', 'Average (days)'],
                'rows' => $r['byDepartment']->map(fn ($d) => [$d['label'], $d['people'], $d['owed'], $d['average']])->all()],
            ['title' => 'Most owed', 'headings' => ['Name', 'Department', 'City', 'Owed (days)', 'Used (days)'],
                'rows' => $r['mostOwed']->map(fn ($p) => [$p['name'], $p['department'], $p['city'], $p['remaining'], $p['used']])->all()],
            ['title' => 'Not taking leave', 'headings' => ['Name', 'Department', 'City', 'Owed (days)'],
                'rows' => $r['idle']->map(fn ($p) => [$p['name'], $p['department'], $p['city'], $p['remaining']])->all()],
            ['title' => 'By type', 'headings' => ['Leave type', 'Days', 'Share'],
                'rows' => $r['byType']->map(fn ($t) => [$t['label'], $t['count'], $t['percent'].'%'])->all()],
            ['title' => 'By month', 'headings' => ['Month', 'Days'],
                'rows' => $r['byMonth']->map(fn ($d, $m) => [$m, $d])->values()->all()],
        ]), 'leave_report_'.now()->format('Y-m-d').'.xlsx');
    }

    public function render()
    {
        abort_unless(self::mayView(auth()->user()), 403);

        return view('livewire.reports.leave', [
            'r' => $this->report(),
            'periods' => Report::PERIODS,
            'departments' => Department::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    private function report(): array
    {
        return (new Report(auth()->user(), $this->days, $this->departmentId ? (int) $this->departmentId : null))->build();
    }
}
