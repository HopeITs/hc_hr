<?php

namespace App\Livewire\Dashboard;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * "Needs your attention": what is late or about to run out on the viewer's
 * own record, never anyone else's (the late tile is that, for the people one
 * decides for). Two things today: the viewer's current contract ending within
 * ENDING_DAYS with no later contract on record, and the viewer's own leave
 * requests still waiting although their first day has passed. Nothing: one
 * quiet line, which the grid folds to the bottom (data-tile-empty).
 */
class AttentionCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public const ENDING_DAYS = 30;

    public function render()
    {
        return view('livewire.dashboard.attention-card', [
            'notes' => $this->guardedLoad(fn () => $this->notes(), fallback: []),
        ]);
    }

    /** @return list<array{kind: string, date: CarbonImmutable, last?: CarbonImmutable, days?: int, url: string}> */
    protected function notes(): array
    {
        $employee = auth()->user()?->profile;

        if (! $employee instanceof Employee) {
            return [];
        }

        $today = CarbonImmutable::today();
        $notes = [];
        $contract = $employee->latestActiveContract();

        if ($contract?->end_date && $contract->end_date->lte($today->addDays(self::ENDING_DAYS))
            // Any later contract counts as the renewal (contracts() holds only today's, so not through it).
            && ! Contract::query()->where('employee_id', $employee->id)->whereDate('start_date', '>', $contract->end_date->toDateString())->exists()) {
            $end = CarbonImmutable::parse($contract->end_date);
            $notes[] = ['kind' => 'contract', 'date' => $end, 'days' => (int) $today->diffInDays($end), 'url' => route('my-profile')];
        }

        $waiting = LeaveRequest::query()
            ->whereHas('leaveBalance.contract', fn ($q) => $q->where('employee_id', $employee->id))
            ->where('status', LeaveRequestStatus::Pending)
            ->where('start_at', '<', $today)
            ->orderBy('start_at')
            ->limit(3)
            ->get(['id', 'start_at', 'end_at']);

        foreach ($waiting as $leave) {
            $notes[] = [
                'kind' => 'leave',
                'date' => CarbonImmutable::parse($leave->start_at),
                'last' => CarbonImmutable::parse($leave->end_at),
                'url' => route('leaves.index', ['leave' => $leave->id]),
            ];
        }

        return $notes;
    }
}
