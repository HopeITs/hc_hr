<?php

namespace App\Livewire\Recruitment\Applicants;

use App\Enums\RecruitmentApplicationStatus;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentInterview;
use App\Services\RecruitmentInterviewEvaluationService;
use App\Services\RecruitmentInterviewSchedulingService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Phase 2 Task 7: the interviews of one application, on the applicant
 * profile — every one of them, cancelled included, newest first — and the
 * way into scheduling another.
 *
 * Read-only: scheduling happens on its own page, which authorizes again.
 * The application is re-resolved through the recruitment scope on every
 * render, like everything else on the profile.
 */
class ApplicationInterviews extends Component
{
    #[Locked]
    public int $applicationId;

    public function mount(int $applicationId): void
    {
        $this->applicationId = $applicationId;
        $this->resolveApplication();
    }

    public function render()
    {
        $application = $this->resolveApplication();
        $interviews = RecruitmentInterview::query()
            ->where('application_id', $application->getKey())
            ->withCount(['currentParticipants as participants_count'])
            ->latest('scheduled_at')
            ->latest('id')
            ->get();

        return view('livewire.recruitment.applicants.application-interviews', [
            'application' => $application,
            'interviews' => $interviews,
            // Task 8: each interview's recommendation tally, for HR with
            // view-evaluations only. Advisory — beside, never inside, the
            // decision panel.
            'tallies' => $interviews
                ->filter(fn (RecruitmentInterview $interview) => Gate::allows('viewEvaluations', $interview))
                ->mapWithKeys(fn (RecruitmentInterview $interview) => [$interview->getKey() => app(RecruitmentInterviewEvaluationService::class)->summary($interview)])
                ->all(),
            'canSchedule' => Gate::allows('schedule', [RecruitmentInterview::class, $application])
                && in_array($application->status, RecruitmentInterviewSchedulingService::SCHEDULABLE, true),
            'mayScheduleAtAll' => Gate::allows('schedule', [RecruitmentInterview::class, $application]),
            'isRejected' => $application->status === RecruitmentApplicationStatus::Rejected,
        ]);
    }

    private function resolveApplication(): RecruitmentApplication
    {
        $application = RecruitmentApplication::query()->visibleTo(auth()->user())->findOrFail($this->applicationId);
        Gate::authorize('view', $application);

        return $application;
    }
}
