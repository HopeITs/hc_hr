<?php

namespace App\Livewire\Recruitment\Approvals;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Services\RecruitmentApproverEligibility;
use App\Services\RecruitmentPendingApprovalQuery;
use Carbon\CarbonImmutable;
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

    public function render()
    {
        $pending = app(RecruitmentPendingApprovalQuery::class);
        $queue = $pending->forUser(auth()->user())->setEagerLoads([])->reorder();
        $oldest = (clone $queue)->select([])->addSelect(['waiting_since' => $pending->waitingSince()])
            ->pluck('waiting_since')->filter()->min();

        return view('livewire.recruitment.approvals.pending', [
            'waiting' => (clone $queue)->count(),
            'longest' => $oldest ? (int) CarbonImmutable::parse($oldest)->diffInDays(now()) : null,
        ]);
    }
}
