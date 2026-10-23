<?php

namespace App\Livewire\ChecklistItems;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Checklist;
use App\Services\ChecklistWorkflow;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class PendingReturns extends Component
{
    use HandlesLoadFailure;
    use WithFeedback;

    public array $notes = [];

    public function getItemsProperty()
    {
        $employee = Auth::user()->profile ?? null;
        if (! $employee) {
            return collect();
        }

        return Checklist::with(['employee', 'item'])
            ->where('receiver_id', $employee->id)
            ->where('returned_status', 'pending')
            ->latest()
            ->get();
    }

    public function markReceived(int $id): void
    {
        $employee = Auth::user()?->profile;

        abort_unless($employee, 403);

        // Re-scoped to the nominated receiver; the id in the payload is not
        // evidence that this user is entitled to close the return.
        app(ChecklistWorkflow::class)->markReceived(
            checklistId: $id,
            actorEmployeeId: $employee->id,
            notes: $this->notes[$id] ?? null,
        );

        unset($this->notes[$id]);
        $this->dispatch('pendingReturnsCount');
        $this->toast(__('general.item_marked_received'));
    }

    public function render()
    {
        return view('livewire.checklist-items.pending-returns', [
            // A failed query shows a retry panel (retryLoad only re-renders; nothing is written).
            'pendingReturns' => $this->guardedLoad(fn () => $this->items, fallback: collect()),
        ]);
    }
}
