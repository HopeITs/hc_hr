<?php

namespace App\Livewire\ChecklistItems;

use App\Livewire\Concerns\WithFeedback;
use App\Models\Checklist;
use App\Services\ChecklistWorkflow;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class PendingItems extends Component
{
    use WithFeedback;

    public array $notes = [];

    public function getItemsProperty()
    {
        $employee = Auth::user()->profile ?? null;
        if (! $employee) {
            return collect();
        }

        return Checklist::with(['employee', 'item'])
            ->where('delivered_id', $employee->id)
            ->where('delivered_status', 'pending')
            ->latest()
            ->get();
    }

    public function markDelivered(int $id): void
    {
        $employee = Auth::user()?->profile;

        abort_unless($employee, 403);

        // The list above is scoped to this employee, but the id arrives from
        // the browser and must be re-scoped here — the read being safe never
        // made the write safe.
        app(ChecklistWorkflow::class)->markDelivered(
            checklistId: $id,
            actorEmployeeId: $employee->id,
            notes: $this->notes[$id] ?? null,
        );

        unset($this->notes[$id]);
        $this->dispatch('pendingDeliversCount');
        $this->toast(__('general.item_marked_delivered'));
    }

    public function render()
    {
        return view('livewire.checklist-items.pending', [
            'pendingItems' => $this->items,
        ]);
    }
}
