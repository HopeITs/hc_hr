<?php

namespace App\Livewire\ChecklistItems;

use App\Models\Checklist;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Throwable;

class PendingDeliversCounter extends Component
{
    protected $listeners = ['pendingDeliversCount' => '$refresh'];

    public function getPendingDeliversCountProperty(): int
    {
        $employee = Auth::user()->profile ?? null;
        if (! $employee) {
            return 0;
        }

        return Checklist::where('delivered_id', $employee->id)
            ->where('delivered_status', 'pending')
            ->count();
    }

    public function render()
    {
        return view('livewire.checklist-items.pending-delivers-counter', [
            // A badge must never take the sidebar down: on a failed count show none and report it.
            'pendingDeliversCount' => $this->safeCount(),
        ]);
    }

    private function safeCount(): int
    {
        try {
            return $this->getPendingDeliversCountProperty();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
