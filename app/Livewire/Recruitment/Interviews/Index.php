<?php

namespace App\Livewire\Recruitment\Interviews;

use App\Enums\RecruitmentInterviewStatus;
use App\Models\RecruitmentInterview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Phase 2 Task 7: the interviews page — a briefing (the user's next
 * interview, the evaluations waiting on them, and the week at a glance)
 * above a board or a month calendar of every interview the user may see
 * (their own as a participant, or those in their recruitment scope).
 * Nothing is changed from here; each interview opens on its own page,
 * where the actions are.
 */
class Index extends Component
{
    #[Url]
    public string $view = 'board';

    public function mount(): void
    {
        Gate::authorize('viewAny', RecruitmentInterview::class);

        if (! in_array($this->view, ['board', 'calendar'], true)) {
            // Includes the retired "list" view of old bookmarks.
            $this->view = 'board';
        }
    }

    public function render()
    {
        Gate::authorize('viewAny', RecruitmentInterview::class);
        $user = auth()->user();
        $timezone = config('app.timezone');

        // The user's own next interview first; HR with nothing of their own
        // sees the next one in their scope instead, and the heading says so.
        $next = RecruitmentInterview::query()->visibleTo($user)->upcoming()
            ->whereHas('currentParticipants', fn ($participants) => $participants->where('user_id', $user->getKey()))
            ->orderBy('scheduled_at')->first();
        $nextIsMine = $next !== null;
        $next ??= RecruitmentInterview::query()->visibleTo($user)->upcoming()->orderBy('scheduled_at')->first();
        $next?->load('application.applicant', 'application.vacancy.position', 'currentParticipants.user.profile');

        $waiting = RecruitmentInterview::query()->visibleTo($user)->awaitingEvaluationBy($user);

        // The working week starts on Saturday, as in the calendar, and like
        // the calendar it is laid out in the application timezone.
        $weekStart = now()->startOfWeek(Carbon::SATURDAY);
        $week = RecruitmentInterview::query()->visibleTo($user)
            ->whereBetween('scheduled_at', [$weekStart, $weekStart->copy()->addDays(7)->subSecond()])
            ->orderBy('scheduled_at')
            ->get(['id', 'status', 'scheduled_at', 'interview_type'])
            ->groupBy(fn (RecruitmentInterview $interview) => $interview->scheduled_at->toDateString());

        return view('livewire.recruitment.interviews.index', [
            'next' => $next,
            'nextIsMine' => $nextIsMine,
            // Rendered only when allowed; never stored on the component.
            'nextMeetingUrl' => $next && Gate::allows('viewMeetingLink', $next) ? $next->meeting_url : null,
            'waitingCount' => (clone $waiting)->count(),
            'waiting' => $waiting->with('application.applicant', 'application.vacancy.position')->orderBy('scheduled_at')->limit(3)->get(),
            'weekStart' => $weekStart,
            'week' => $week,
            'weekTotals' => $this->weekTotals($week->flatten()),
            'timezone' => $timezone,
        ]);
    }

    /** @return array<string, int> */
    private function weekTotals($interviews): array
    {
        return [
            'all' => $interviews->count(),
            'ahead' => $interviews->filter(fn ($i) => RecruitmentInterviewStatus::isOpen($i->status))->count(),
            'held' => $interviews->where('status', RecruitmentInterviewStatus::Completed)->count(),
            'off' => $interviews->whereIn('status', [RecruitmentInterviewStatus::Cancelled, RecruitmentInterviewStatus::NoShow])->count(),
        ];
    }
}
