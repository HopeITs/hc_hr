<?php

namespace App\Livewire\Layout;

use App\Models\Checklist;
use App\Services\RecruitmentApproverEligibility;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class Sidebar extends Component
{
    public bool $open = false;

    protected $listeners = ['sidebar.toggle' => 'toggle'];

    public function toggle()
    {
        $this->open = ! $this->open;
    }

    public function close()
    {
        $this->open = false;
    }

    #[On('pendingDeliversCount')]
    #[On('pendingReturnsCount')]
    public function refreshCounts(): void
    {
        // Trigger re-render when either event fires
    }

    public function render()
    {
        $employee = Auth::user()->profile ?? null;
        $employeeId = $employee->id ?? null;
        $departmentName = $employee?->contracts()->with('position.department')->first()?->position?->department?->code;

        $isDeliverResponsible = in_array($departmentName, ['IT', 'CT', 'HR']);

        $pendingDeliversCount = $employeeId
            ? Checklist::where('delivered_id', $employeeId)
                ->where('delivered_status', '!=', 'delivered')
                ->count()
            : 0;

        $pendingReturnsCount = $employeeId
            ? Checklist::where('receiver_id', $employeeId)
                ->where('returned_status', 'pending')
                ->count()
            : 0;

        $user = Auth::user();

        return view('livewire.layout.sidebar', [
            'canSeeRecruitmentApprovals' => $user !== null
                && app(RecruitmentApproverEligibility::class)->mayReceiveApprovals($user),
            'user' => $user,
            'employee' => $employee,
            'employeeId' => $employeeId,
            'departmentName' => $departmentName,
            'isDeliverResponsible' => $isDeliverResponsible,
            'pendingDeliversCount' => $pendingDeliversCount,
            'pendingReturnsCount' => $pendingReturnsCount,
        ]);
    }
}
