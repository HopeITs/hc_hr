<?php

namespace App\Livewire\Reports;

use App\Support\Reports\ReportCatalog;
use Livewire\Component;

/** The reports hub: one card per report the viewer may open. */
class Index extends Component
{
    public function mount(): void
    {
        abort_if(ReportCatalog::visibleTo(auth()->user()) === [], 403);
    }

    public function render()
    {
        return view('livewire.reports.index', ['reports' => ReportCatalog::visibleTo(auth()->user())]);
    }
}
