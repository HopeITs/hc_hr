<?php

namespace App\Livewire\Layout;

use App\Models\Checklist;
use App\Support\Navigation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class Sidebar extends Component
{
    public bool $open = false;

    /**
     * The page's route, kept from the page request: opening the drawer on a
     * phone re-renders through Livewire's update route, where routeIs() no
     * longer knows the page and the current item lost its highlight (C1).
     * Only used to highlight a link.
     */
    public ?string $currentRoute = null;

    protected $listeners = ['sidebar.toggle' => 'toggle'];

    public function mount(): void
    {
        $this->currentRoute = request()->route()?->getName();
    }

    public function toggle()
    {
        $this->open = ! $this->open;
    }

    public function close()
    {
        $this->open = false;
    }

    // C3: the work inbox changed (an item was handled), so its badge is recounted.
    #[On('inbox-changed')]
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

        // Badges only: a failed count must not take every page down with it.
        try {
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
        } catch (Throwable $e) {
            report($e);
            $pendingDeliversCount = $pendingReturnsCount = 0;
        }

        $user = Auth::user();

        return view('livewire.layout.sidebar', [
            'user' => $user,
            'employee' => $employee,
            'employeeId' => $employeeId,
            'departmentName' => $departmentName,
            'isDeliverResponsible' => $isDeliverResponsible,
            'pendingDeliversCount' => $pendingDeliversCount,
            'pendingReturnsCount' => $pendingReturnsCount,
            // C1: the areas of C0, built from the active role's permissions.
            'nav' => Navigation::for($user, $isDeliverResponsible),
        ]);
    }
}
