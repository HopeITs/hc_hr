<?php

namespace App\Livewire\Reports;

use App\Exports\LeavingReasonsExport;
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

    public function export()
    {
        abort_unless(self::mayView(auth()->user()), 403);

        return Excel::download(
            new LeavingReasonsExport((new LeavingReasonsReport(auth()->user(), $this->days))->build()),
            'leaving_reasons_'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function render()
    {
        abort_unless(self::mayView(auth()->user()), 403);

        return view('livewire.reports.leaving-reasons', [
            'r' => (new LeavingReasonsReport(auth()->user(), $this->days))->build(),
            'periods' => LeavingReasonsReport::PERIODS,
        ]);
    }
}
