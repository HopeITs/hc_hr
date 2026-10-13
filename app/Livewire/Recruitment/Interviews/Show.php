<?php

namespace App\Livewire\Recruitment\Interviews;

use App\Enums\RecruitmentInterviewStatus;
use App\Models\RecruitmentInterview;
use App\Services\RecruitmentInterviewEvaluationService;
use App\Services\RecruitmentInterviewSchedulingService;
use App\Traits\WithAlerts;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Phase 2 Task 7: one interview.
 *
 * Seen by its participants and by HR in scope. The meeting link is rendered
 * only through the viewMeetingLink ability; it is never a public property,
 * so it never reaches the browser of anyone else.
 *
 * HR with schedule-interviews can cancel it (the candidate declined, someone
 * is unavailable, a mistake) or record how it went (held / no-show) once it
 * has started. Each action has an open / confirm pair, shown in a popup (the
 * Leaves decision popup, <x-modal.dialog>); the status and time the user saw
 * are locked when the popup opens and checked by the service.
 */
class Show extends Component
{
    use WithAlerts;

    #[Locked]
    public int $interviewId;

    /** 'cancel' | 'completed' | 'no_show' | null */
    #[Locked]
    public ?string $panel = null;

    /**
     * Whether the popup is showing. Not locked: the popup closes itself on
     * Escape or a click outside, and writes that back. Which action it holds
     * is $panel, which stays locked.
     */
    public bool $panelOpen = false;

    #[Locked]
    public ?string $expectedStatus = null;

    #[Locked]
    public ?string $expectedStartsAt = null;

    public string $cancelReason = '';

    /** Internal: kept on the interview, never sent to the candidate. */
    public string $cancelNote = '';

    public bool $notifyCandidate = true;

    /** Internal note on the outcome. */
    public string $outcomeNote = '';

    public function mount(int $interviewId): void
    {
        $this->interviewId = $interviewId;
        $this->resolveInterview();

        if ($message = session()->pull('recruitment_toast')) {
            $this->successToast($message);
        }
    }

    /** The viewer saved their evaluation on this page: re-render so the "evaluation due" note goes. */
    #[On('recruitment-evaluations-changed')]
    public function refreshEvaluationDue(): void {}

    public function beginCancel(): void
    {
        $this->openPanel('cancel');
    }

    public function beginOutcome(string $outcome): void
    {
        abort_unless(in_array($outcome, [RecruitmentInterviewStatus::Completed, RecruitmentInterviewStatus::NoShow], true), 403);

        $this->openPanel($outcome);
    }

    /** The user backs out: the panel closes and focus returns to the button that opened it. */
    public function dismissPanel(): void
    {
        $opener = $this->panel !== null ? 'interview-action-'.$this->panel : 'interview-title';

        $this->closePanel();
        $this->dispatch('focus-element', id: $opener);
    }

    public function cancelInterview(RecruitmentInterviewSchedulingService $scheduling): void
    {
        abort_unless($this->panel === 'cancel', 404);

        $interview = $this->resolveInterview();
        Gate::authorize('manage', $interview);

        $this->attempt(fn () => $scheduling->cancel(
            auth()->user(),
            $interview,
            (string) $this->expectedStatus,
            (string) $this->expectedStartsAt,
            $this->cancelReason,
            $this->cancelNote,
            $this->notifyCandidate,
        ), __('recruitment-applicant.interviews.cancelled'));
    }

    public function recordOutcome(RecruitmentInterviewSchedulingService $scheduling): void
    {
        abort_unless(in_array($this->panel, [RecruitmentInterviewStatus::Completed, RecruitmentInterviewStatus::NoShow], true), 404);

        $interview = $this->resolveInterview();
        Gate::authorize('manage', $interview);

        $this->attempt(fn () => $scheduling->recordOutcome(
            auth()->user(),
            $interview,
            (string) $this->expectedStatus,
            (string) $this->expectedStartsAt,
            (string) $this->panel,
            $this->outcomeNote,
        ), __('recruitment-applicant.interviews.outcome_recorded'));
    }

    public function render(RecruitmentInterviewSchedulingService $scheduling)
    {
        $interview = $this->resolveInterview()->load([
            'application.applicant',
            'application.campaign.vacancy.position',
            'participants' => fn ($participants) => $participants->orderByRaw('removed_at is not null')->orderBy('id'),
            'participants.user.profile',
            'creator.profile',
            'cancelledBy.profile',
            'outcomeRecordedBy.profile',
            'reschedules' => fn ($history) => $history->latest('rescheduled_at')->latest('id'),
            'reschedules.rescheduledBy.profile',
        ]);

        $canManage = Gate::allows('manage', $interview);

        return view('livewire.recruitment.interviews.show', [
            'interview' => $interview,
            'canManage' => $canManage,
            'evaluationDue' => $this->evaluationDue($interview),
            'canSeeApplicant' => Gate::allows('viewApplicant', $interview),
            // Rendered only when allowed; never stored on the component.
            'meetingUrl' => Gate::allows('viewMeetingLink', $interview) ? $interview->meeting_url : null,
            // Held, cancelled or a no-show: nothing left to join, the link stays for the record.
            'canJoin' => $interview->isOpen(),
            'notices' => $canManage ? $interview->notices()->latest('id')->get() : collect(),
            'cancellationReasons' => $scheduling->cancellationReasons(),
            'hasStarted' => ! $interview->scheduled_at->isFuture(),
        ]);
    }

    private function openPanel(string $panel): void
    {
        $interview = $this->resolveInterview();
        Gate::authorize('manage', $interview);
        abort_unless($interview->isOpen(), 403);

        $this->closePanel();
        $this->panel = $panel;
        $this->panelOpen = true;
        $this->expectedStatus = $interview->status;
        $this->expectedStartsAt = $interview->scheduled_at->toIso8601String();

        $this->dispatch('focus-element', id: 'interview-panel-title');
    }

    private function closePanel(): void
    {
        $this->reset('panel', 'panelOpen', 'expectedStatus', 'expectedStartsAt', 'cancelReason', 'cancelNote', 'notifyCandidate', 'outcomeNote');
        $this->resetErrorBag();
    }

    private function attempt(callable $action, string $message): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->closePanel();
        $this->successToast($message);

        // The buttons that opened and confirmed the panel are gone once the
        // interview is closed: the interview itself, with its new status, is next.
        $this->dispatch('focus-element', id: 'interview-title');
    }

    /**
     * The viewer may evaluate, evaluation is open, and they have not yet
     * submitted: the page points them to their form from the top.
     */
    private function evaluationDue(RecruitmentInterview $interview): bool
    {
        return Gate::allows('evaluate', $interview)
            && app(RecruitmentInterviewEvaluationService::class)->closedReason($interview) === null
            && ! $interview->evaluations()->where('reviewer_user_id', auth()->id())->exists();
    }

    /** Out of reach is a 404, not a 403: guessing ids reveals nothing. */
    private function resolveInterview(): RecruitmentInterview
    {
        $interview = RecruitmentInterview::query()->visibleTo(auth()->user())->findOrFail($this->interviewId);
        Gate::authorize('view', $interview);

        return $interview;
    }
}
