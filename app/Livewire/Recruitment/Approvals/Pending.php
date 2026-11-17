<?php

namespace App\Livewire\Recruitment\Approvals;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Services\RecruitmentApproverEligibility;
use App\Services\RecruitmentPendingApprovalQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Pending extends Component
{
    use AuthorizesActivePermissions;

    public function mount(): void
    {
        $this->authorizeActivePermission('recruitment-requests.approve');

        // Hidden from the menu for anyone no approval can reach; the address
        // is closed to them too, so hiding the link is not the only guard.
        abort_unless(app(RecruitmentApproverEligibility::class)->mayReceiveApprovals(auth()->user()), 403);
    }

    public function render(): View
    {
        $pending = app(RecruitmentPendingApprovalQuery::class);
        $queue = $pending->forUser(auth()->user())->setEagerLoads([])->reorder();

        $now = CarbonImmutable::now();
        $arrivals = (clone $queue)->select([])->addSelect(['waiting_since' => $pending->waitingSince()])
            ->pluck('waiting_since')->filter()
            ->map(fn ($since): CarbonImmutable => CarbonImmutable::parse($since));
        $days = $arrivals->map(fn (CarbonImmutable $since): int => (int) $since->diffInDays($now));

        return view('livewire.recruitment.approvals.pending', [
            'waiting' => (clone $queue)->count(),
            'longest' => $days->max(),
            'overdue' => $days->filter(fn (int $d): bool => $d >= PendingApprovalTable::LONG_WAIT_DAYS)->count(),
            'arrivedToday' => $arrivals->filter(fn (CarbonImmutable $since): bool => $since->isSameDay($now))->count(),
            'longWaitDays' => PendingApprovalTable::LONG_WAIT_DAYS,
        ]);
    }
}
