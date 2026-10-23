<?php

namespace App\Livewire\ChecklistItems;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Models\Checklist;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AllItems extends Component
{
    use HandlesLoadFailure;

    public function getItemsProperty()
    {
        $employee = Auth::user()->profile ?? null;
        if (! $employee) {
            return collect();
        }

        return Checklist::with(['employee', 'item'])
            ->where('employee_id', $employee->id)
            ->latest('updated_at')
            ->get()
            ->map(function ($item) {
                $item->display_status = match (true) {
                    $item->returned_status === 'received' => 'Returned',
                    $item->delivered_status === 'delivered' => 'Delivered',
                    default => 'On Hold',
                };

                return $item;
            });
    }

    public function render()
    {
        return view('livewire.checklist-items.all', [
            // A failed query shows a retry panel (retryLoad only re-renders; nothing is written).
            'items' => $this->guardedLoad(fn () => $this->items, fallback: collect()),
        ]);
    }
}
