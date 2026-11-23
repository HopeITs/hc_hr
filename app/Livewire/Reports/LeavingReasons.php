<?php

namespace App\Livewire\Reports;

use App\Exports\LeavingReasonsExport;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\User;
use App\Services\LeavingReasonsReport;
use Livewire\Attributes\Url;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Why people leave. HR (their city) and the head-office roles only: a manager
 * reaches their team's contracts elsewhere, but not this report.
 */
class LeavingReasons extends Component
{
    #[Url(as: 'period')]
    public int $days = 365;

    /** @var array<string, int|string|null> */
    #[Url(as: 'f')]
    public array $filters = [];

    public static function mayView(?User $user): bool
    {
        return $user !== null
            && $user->hasActivePermission('contracts.view')
            && $user->activeRoleIs('HR', ...config('access-control.administrator_roles', []));
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

        return Excel::download(
            new LeavingReasonsExport($this->report()->build()),
            'leaving_reasons_'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function render()
    {
        abort_unless(self::mayView(auth()->user()), 403);

        return view('livewire.reports.leaving-reasons', [
            'r' => $this->report()->build(),
            'periods' => LeavingReasonsReport::PERIODS,
            'options' => $this->options(),
            'canEditContracts' => auth()->user()->hasActivePermission('contracts.update'),
        ]);
    }

    private function report(): LeavingReasonsReport
    {
        return new LeavingReasonsReport(auth()->user(), $this->days, $this->filters);
    }

    /** @return array<string, array<int, string>> id => label, per filter */
    private function options(): array
    {
        return [
            'department_id' => Department::query()->orderBy('name')->pluck('name', 'id')->all(),
            'program_id' => Program::query()->orderBy('name')->pluck('name', 'id')->all(),
            'job_level_id' => JobLevel::query()->orderBy('grade')->pluck(transColumn('name'), 'id')->all(),
            'position_id' => Position::query()->orderBy(transColumn('title'))->pluck(transColumn('title'), 'id')->all(),
            'contract_type_id' => ContractType::query()->orderBy('id')->pluck(transColumn('name'), 'id')->all(),
        ];
    }
}
