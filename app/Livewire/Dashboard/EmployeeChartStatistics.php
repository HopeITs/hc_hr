<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\IsDashboardTile;
use Livewire\Component;

/**
 * "Personnel traffic" (stats-personnel): new, ended and terminated contracts
 * over time, the statistics page's personnel card as a tile. The figures come
 * from the city-scoped /employee-statistics/{mode} endpoint (behind
 * statistics.view, like the tile itself), fetched by the card's own script.
 */
class EmployeeChartStatistics extends Component
{
    use IsDashboardTile;

    public function render()
    {
        return view('livewire.dashboard.employee-chart-statistics');
    }
}
