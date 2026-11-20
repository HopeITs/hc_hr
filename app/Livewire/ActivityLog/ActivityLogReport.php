<?php

namespace App\Livewire\ActivityLog;

use App\Exports\ActivityReportExport;
use App\Services\ActivityReport;
use Livewire\Attributes\Url;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Summary report shown above the activity log table: who uses the system most,
 * when and where. Figures come from App\Services\ActivityReport.
 */
class ActivityLogReport extends Component
{
    #[Url(as: 'period')]
    public int $days = 30;

    public function export()
    {
        abort_unless(auth()->user()?->hasActivePermission('activity-log.view'), 403);

        return Excel::download(
            new ActivityReportExport((new ActivityReport($this->days))->build()),
            'activity_report_'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function render()
    {
        return view('livewire.activity-log.report', [
            'r' => (new ActivityReport($this->days))->build(),
            'periods' => ActivityReport::PERIODS,
        ]);
    }
}
