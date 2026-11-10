<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\LeaveRequest;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * "My leave requests": the live card of the leaves page (Add to dashboard on
 * My leaves). The viewer's own latest requests with their status, the annual
 * days left, and "New request" (the New menu's rule: leaves.create and
 * leaves.view-own). Only the viewer's own requests are read, through their own
 * contracts, exactly as the page's list does.
 */
class MyRequestsCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public const LIMIT = 5;

    public function render()
    {
        $user = auth()->user();

        return view('livewire.dashboard.my-requests-card', [
            'requests' => $this->guardedLoad(fn () => $this->requests(), fallback: new Collection),
            'annual' => $this->guardedLoad(fn () => $user?->profile?->latestActiveContract()?->leaveBalances()
                ->where('type_id', \App\Enums\LeaveTypeCases::Annual)->with('contract')->first()?->int_available),
            'newUrl' => $user && $user->hasActivePermission('leaves.create') && $user->hasActivePermission('leaves.view-own')
                ? route('leaves.index', ['new' => 1])
                : null,
        ]);
    }

    /** @return Collection<int, LeaveRequest> */
    protected function requests(): Collection
    {
        $employeeId = auth()->user()?->employee_id;

        if ($employeeId === null) {
            return new Collection;
        }

        return LeaveRequest::query()
            ->whereHas('leaveBalance.contract', fn ($q) => $q->where('employee_id', $employeeId))
            ->with('leaveBalance.leaveType')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get(['id', 'leave_balance_id', 'type', 'start_at', 'end_at', 'status']);
    }
}
