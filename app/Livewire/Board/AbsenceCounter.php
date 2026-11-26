<?php

namespace App\Livewire\Board;

use App\Models\BoardMeetingAttendee;
use App\Services\Board\BoardAccess;
use Livewire\Component;
use Throwable;

/** The chair's badge beside "Board meetings" in the menu: absence requests that wait for his answer. */
class AbsenceCounter extends Component
{
    public function render()
    {
        return view('livewire.board.absence-counter', ['count' => $this->safeCount()]);
    }

    private function safeCount(): int
    {
        try {
            if (! app(BoardAccess::class)->canManage(auth()->user())) {
                return 0;
            }

            return BoardMeetingAttendee::query()
                ->where('absence_request', BoardMeetingAttendee::REQUEST_PENDING)
                ->whereHas('meeting', fn ($q) => $q->upcoming()->whereNull('published_at'))
                ->count();
        } catch (Throwable $e) {
            // A badge must never take the sidebar down.
            report($e);

            return 0;
        }
    }
}
