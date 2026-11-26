<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\BoardMeeting;
use App\Models\BoardMeetingAttendee;
use App\Models\BoardMeetingFollowUp;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * "Next board meeting", for the board only (DashboardLayout::allowed): when, in how many days, whether the viewer
 * is expected or excused, and the follow-up rows still waiting on them. A link to the meeting does the rest.
 */
class BoardMeetingCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public function render()
    {
        return view('livewire.dashboard.board-meeting-card', $this->guardedLoad(fn () => $this->load()) ?? [
            'meeting' => null, 'days' => null, 'mine' => null, 'followUps' => collect(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function load(): array
    {
        $employeeId = auth()->user()?->employee_id;
        $meeting = BoardMeeting::query()->upcoming()->orderBy('starts_at')->first();

        return [
            'meeting' => $meeting,
            'days' => $meeting ? (int) CarbonImmutable::today()->diffInDays($meeting->starts_at->copy()->startOfDay()) : null,
            'mine' => $meeting && $employeeId
                ? BoardMeetingAttendee::query()->where('board_meeting_id', $meeting->id)->where('employee_id', $employeeId)->first()
                : null,
            'followUps' => $employeeId
                ? BoardMeetingFollowUp::query()->where('employee_id', $employeeId)->where('status', BoardMeetingFollowUp::OPEN)
                    ->whereHas('meeting', fn ($q) => $q->whereNotNull('published_at'))->with('meeting')->limit(3)->get()
                : collect(),
        ];
    }
}
