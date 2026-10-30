<?php

namespace App\Livewire\Concerns;

use App\Models\Contract;
use App\Models\Employee;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Contracts\View\View;

/**
 * A component drawn as a dashboard tile (DashboardLayout::COMPONENTS). The
 * dashboard mounts every tile lazily, so the page never waits for one: until
 * its data arrives the tile shows the card placeholder, titled with the tile's
 * name. Elsewhere (the statistics page) the same component renders straight
 * away and never asks for it.
 *
 * Lazy means the card arrives in a Livewire update, not with the page: an
 * inline <script> in its view does not run and @push never reaches the
 * layout. A card that needs JavaScript uses @script (it runs just before the
 * card replaces its placeholder, so wait a frame for the markup) or @assets.
 *
 * Nothing to show right now: put data-tile-empty on the line that says so
 * (x-center-empty-state's data-state="empty" and .hc-nt-empty count too).
 * The grid then folds the tile to the bottom as one closed line, with those
 * words; Customise shows it whole (home-screen.js, readEmpty).
 */
trait IsDashboardTile
{
    /** @var array{0: ?Employee, 1: ?Contract}|null */
    private ?array $tileViewer = null;

    public function placeholder(array $params = []): View
    {
        $key = DashboardLayout::keyOf(static::class);

        return view('livewire.dashboard.card-placeholder', [
            'title' => $key ? app(DashboardLayout::class)->label($key) : '',
        ]);
    }

    /**
     * The signed-in user's employee record and latest contract (by start
     * date, with its position), as the dashboard has always read them.
     *
     * @return array{0: ?Employee, 1: ?Contract}
     */
    protected function tileViewer(): array
    {
        if ($this->tileViewer !== null) {
            return $this->tileViewer;
        }

        $employee = auth()->user()?->profile()->first();

        $contract = $employee
            ? Contract::query()
                ->with(['position.department', 'position.center', 'position.program'])
                ->where('employee_id', $employee->id)
                ->orderByDesc('start_date')
                ->first()
            : null;

        return $this->tileViewer = [$employee, $contract];
    }
}
