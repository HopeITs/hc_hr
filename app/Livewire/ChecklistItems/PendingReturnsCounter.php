<?php

namespace App\Livewire\ChecklistItems;

use App\Models\Checklist;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Throwable;

class PendingReturnsCounter extends Component
{
    protected $listeners = ['pendingReturnsCount' => '$refresh'];

    public function getPendingReturnsCountProperty(): int
    {
        $employee = Auth::user()->profile ?? null;
        if (! $employee) {
            return 0;
        }

        return Checklist::where('receiver_id', $employee->id)
            ->where('returned_status', 'pending')
            ->count();
    }

    public function render()
    {
        return view('livewire.checklist-items.pending-returns-counter', [
            // A badge must never take the sidebar down: on a failed count show none and report it.
            'pendingReturnsCount' => $this->safeCount(),
        ]);
    }

    private function safeCount(): int
    {
        try {
            return $this->getPendingReturnsCountProperty();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
