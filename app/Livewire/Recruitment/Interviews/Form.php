<?php

namespace App\Livewire\Recruitment\Interviews;

use App\Enums\RecruitmentApplicationStatus;
use App\Enums\RecruitmentInterviewLocationType;
use App\Enums\RecruitmentInterviewType;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewParticipant;
use App\Models\User;
use App\Services\RecruitmentInterviewSchedulingService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Phase 2 Task 7: schedule an interview for an application, or edit one.
 *
 * On edit, a change of time or place is a reschedule and needs a reason; the
 * service decides that from what actually changed, not from which button was
 * pressed. The status and start time the user opened the form with are
 * locked here and handed to the service, which refuses if someone else
 * changed the interview in between.
 *
 * Both the component and the service authorize: this guards the screen, the
 * service the action. The meeting link is loaded into the form only for a
 * user allowed to see it — and the form is HR-only anyway.
 */
class Form extends Component
{
    #[Locked]
    public ?int $applicationId = null;

    #[Locked]
    public ?int $interviewId = null;

    #[Locked]
    public ?string $expectedStatus = null;

    #[Locked]
    public ?string $expectedStartsAt = null;

    public string $type = RecruitmentInterviewType::Hr;

    /** Local wall-clock time, Y-m-d\TH:i, in the application timezone. */
    public string $startsAt = '';

    public int|string $duration = 60;

    public string $locationType = RecruitmentInterviewLocationType::InPerson;

    public string $locationValue = '';

    public string $meetingUrl = '';

    /** @var array<int|string, string> [user_id => role] */
    public array $participants = [];

    public ?string $newParticipantId = null;

    public string $newParticipantRole = RecruitmentInterviewParticipant::ROLE_INTERVIEWER;

    /** Edit only: why the time or place is changing. Internal, never emailed. */
    public string $reason = '';

    public bool $notifyCandidate = true;

    public function mount(?int $applicationId = null, ?int $interviewId = null): void
    {
        if ($interviewId !== null) {
            $interview = $this->resolveInterview($interviewId);
            Gate::authorize('manage', $interview);
            abort_unless($interview->isOpen(), 404);

            $timezone = config('app.timezone');
            $this->interviewId = $interview->getKey();
            $this->applicationId = $interview->application_id;
            $this->expectedStatus = $interview->status;
            $this->expectedStartsAt = $interview->scheduled_at->toIso8601String();
            $this->type = $interview->interview_type;
            $this->startsAt = $interview->scheduled_at->copy()->setTimezone($timezone)->format('Y-m-d\TH:i');
            $this->duration = $interview->ends_at ? (int) $interview->scheduled_at->diffInMinutes($interview->ends_at) : 60;
            $this->locationType = $interview->location_type;
            $this->locationValue = (string) $interview->location_value;
            $this->meetingUrl = Gate::allows('viewMeetingLink', $interview) ? (string) $interview->meeting_url : '';
            $this->participants = $interview->currentParticipants()->pluck('role', 'user_id')->all();

            return;
        }

        abort_if($applicationId === null, 404);
        $application = $this->resolveApplication($applicationId);
        Gate::authorize('schedule', [RecruitmentInterview::class, $application]);

        $this->applicationId = $application->getKey();
        $this->duration = (int) config('recruitment.interviews.default_duration_minutes', 60);
    }

    /** Form state only; the list is validated again, user by user, on save. */
    public function addParticipant(): void
    {
        $userId = filter_var($this->newParticipantId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($userId === false || ! in_array($this->newParticipantRole, RecruitmentInterviewParticipant::roles(), true)) {
            return;
        }

        $this->participants[$userId] = $this->newParticipantRole;
        $this->newParticipantId = null;
        $this->resetErrorBag('participants');
    }

    public function removeParticipant(int $userId): void
    {
        unset($this->participants[$userId]);
    }

    public function save(RecruitmentInterviewSchedulingService $scheduling): void
    {
        // Errors from a previous attempt must not outlive the fix.
        $this->resetErrorBag();

        $input = [
            'type' => $this->type,
            'startsAt' => $this->startsAt,
            'duration' => $this->duration,
            'locationType' => $this->locationType,
            'locationValue' => $this->locationValue,
            'meetingUrl' => $this->meetingUrl,
            'participants' => $this->participants,
        ];

        try {
            if ($this->interviewId !== null) {
                $interview = $this->resolveInterview($this->interviewId);
                Gate::authorize('manage', $interview);

                $interview = $scheduling->update(
                    auth()->user(),
                    $interview,
                    (string) $this->expectedStatus,
                    (string) $this->expectedStartsAt,
                    $input,
                    $this->reason,
                    $this->notifyCandidate,
                );
                session()->flash('recruitment_toast', __('recruitment-applicant.interviews.updated'));
            } else {
                $application = $this->resolveApplication((int) $this->applicationId);
                Gate::authorize('schedule', [RecruitmentInterview::class, $application]);

                $interview = $scheduling->schedule(auth()->user(), $application, $input, $this->notifyCandidate);
                session()->flash('recruitment_toast', __('recruitment-applicant.interviews.scheduled'));
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->redirectRoute('recruitment.interviews.show', ['interviewId' => $interview->getKey()], navigate: true);
    }

    public function render(RecruitmentInterviewSchedulingService $scheduling)
    {
        $application = $this->resolveApplication((int) $this->applicationId);
        $interview = $this->interviewId ? $this->resolveInterview($this->interviewId) : null;
        $chosen = User::query()->whereKey(array_keys($this->participants))->with('profile')->get()->keyBy('id');

        return view('livewire.recruitment.interviews.form', [
            'application' => $application->loadMissing('applicant', 'campaign.vacancy.position'),
            'interview' => $interview,
            'chosen' => $chosen,
            // Only active staff can be added; someone already on the interview
            // who became inactive stays listed (and flagged) until removed.
            'candidates' => User::query()
                ->whereHas('profile', fn ($query) => $query->where('is_active', true))
                ->whereKeyNot(array_keys($this->participants))
                ->with('profile')
                ->get()
                ->sortBy(fn (User $user) => $user->profile?->name)
                ->values(),
            'timezone' => $scheduling->timezone(),
            'isReopenable' => $application->status === RecruitmentApplicationStatus::Rejected,
        ]);
    }

    private function resolveApplication(int $applicationId): RecruitmentApplication
    {
        $application = RecruitmentApplication::query()->visibleTo(auth()->user())->findOrFail($applicationId);
        Gate::authorize('view', $application);

        return $application;
    }

    private function resolveInterview(int $interviewId): RecruitmentInterview
    {
        return RecruitmentInterview::query()->visibleTo(auth()->user())->findOrFail($interviewId);
    }
}
