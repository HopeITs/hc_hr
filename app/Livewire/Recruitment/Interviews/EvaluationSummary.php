<?php

namespace App\Livewire\Recruitment\Interviews;

use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewEvaluation;
use App\Services\RecruitmentInterviewEvaluationService;
use App\Traits\WithAlerts;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Phase 2 Task 8: the aggregated view of an interview's evaluations, for HR
 * with view-evaluations in scope — how many reviewers recommended what, and
 * each reviewer's evaluation with its history.
 *
 * Advisory: the view says so, and nothing on it moves the application. The
 * hiring decision stays in the applicant's decision panel.
 *
 * HR with correct-evaluations can correct a mistake in an evaluation or
 * withdraw it, each with a reason kept in the evaluation's history, in a
 * popup (the Leaves decision popup, <x-modal.dialog>); HR managing the
 * interview can close evaluation once it is completed.
 */
class EvaluationSummary extends Component
{
    use WithAlerts;

    #[Locked]
    public int $interviewId;

    /** 'correct' | 'withdraw' | null */
    #[Locked]
    public ?string $panel = null;

    /**
     * Whether the popup is showing. Not locked: the popup closes itself on
     * Escape or a click outside, and writes that back. Which action and
     * evaluation it holds stay locked.
     */
    public bool $panelOpen = false;

    #[Locked]
    public ?int $evaluationId = null;

    #[Locked]
    public ?int $expectedVersion = null;

    public string $recommendation = '';

    public string $pros = '';

    public string $cons = '';

    public string $notes = '';

    /** Why HR corrects or withdraws. Kept in the evaluation's history. */
    public string $reason = '';

    public function mount(int $interviewId): void
    {
        $this->interviewId = $interviewId;
        Gate::authorize('viewEvaluations', $this->resolveInterview());
    }

    public function beginCorrect(int $evaluationId): void
    {
        $evaluation = $this->resolveEvaluation($evaluationId);
        Gate::authorize('correct', $evaluation);

        $this->openPanel('correct', $evaluation);
        $this->recommendation = (string) $evaluation->recommendation;
        $this->pros = (string) $evaluation->pros;
        $this->cons = (string) $evaluation->cons;
        $this->notes = (string) $evaluation->notes;
    }

    public function saveCorrection(RecruitmentInterviewEvaluationService $evaluations): void
    {
        abort_unless($this->panel === 'correct' && $this->evaluationId !== null, 404);
        $evaluation = $this->resolveEvaluation($this->evaluationId);
        Gate::authorize('correct', $evaluation);

        $this->attempt(fn () => $evaluations->correct(auth()->user(), $evaluation, (int) $this->expectedVersion, [
            'recommendation' => $this->recommendation,
            'pros' => $this->pros,
            'cons' => $this->cons,
            'notes' => $this->notes,
        ], $this->reason), __('recruitment-applicant.evaluations.corrected'));
    }

    public function beginWithdraw(int $evaluationId): void
    {
        $evaluation = $this->resolveEvaluation($evaluationId);
        Gate::authorize('withdraw', $evaluation);

        $this->openPanel('withdraw', $evaluation);
    }

    public function withdraw(RecruitmentInterviewEvaluationService $evaluations): void
    {
        abort_unless($this->panel === 'withdraw' && $this->evaluationId !== null, 404);
        $evaluation = $this->resolveEvaluation($this->evaluationId);
        Gate::authorize('withdraw', $evaluation);

        $this->attempt(
            fn () => $evaluations->withdraw(auth()->user(), $evaluation, (int) $this->expectedVersion, $this->reason),
            __('recruitment-applicant.evaluations.withdrawn_done'),
        );
    }

    public function closeEvaluations(RecruitmentInterviewEvaluationService $evaluations): void
    {
        $interview = $this->resolveInterview();
        Gate::authorize('closeEvaluations', $interview);

        $this->attempt(
            fn () => $evaluations->close(auth()->user(), $interview, (string) $interview->status),
            __('recruitment-applicant.evaluations.closed_done'),
        );
    }

    /** The user backs out: the panel closes and focus returns to the button that opened it. */
    public function dismissPanel(): void
    {
        $opener = $this->panel !== null && $this->evaluationId !== null
            ? 'evaluation-'.$this->evaluationId.'-'.$this->panel
            : 'evaluations-title';

        $this->cancelPanel();
        $this->dispatch('focus-element', id: $opener);
    }

    /** A reviewer saved on the same page: re-render through the same scoped query. */
    #[On('recruitment-evaluations-changed')]
    public function refreshEvaluations(): void {}

    public function render(RecruitmentInterviewEvaluationService $evaluations)
    {
        $interview = $this->resolveInterview();

        return view('livewire.recruitment.interviews.evaluation-summary', [
            'interview' => $interview,
            'summary' => $evaluations->summary($interview),
            'evaluations' => $interview->evaluations()
                ->with([
                    'reviewer.profile',
                    'withdrawnBy.profile',
                    'revisions' => fn ($revisions) => $revisions->latest('version'),
                    'revisions.changedBy.profile',
                ])
                ->orderByRaw('withdrawn_at is not null')
                ->orderBy('submitted_at')
                ->get(),
            'roles' => $interview->participants()->pluck('role', 'user_id'),
            'canClose' => Gate::allows('closeEvaluations', $interview)
                && $interview->status === \App\Enums\RecruitmentInterviewStatus::Completed
                && $interview->evaluations_closed_at === null,
            'closedReason' => $evaluations->closedReason($interview),
            'timezone' => $interview->timezone ?: config('app.timezone'),
        ]);
    }

    private function openPanel(string $panel, RecruitmentInterviewEvaluation $evaluation): void
    {
        $this->cancelPanel();
        $this->panel = $panel;
        $this->panelOpen = true;
        $this->evaluationId = $evaluation->getKey();
        $this->expectedVersion = $evaluation->version;

        $this->dispatch('focus-element', id: 'evaluation-panel-title');
    }

    private function cancelPanel(): void
    {
        $this->reset('panel', 'panelOpen', 'evaluationId', 'expectedVersion', 'recommendation', 'pros', 'cons', 'notes', 'reason');
        $this->resetErrorBag();
    }

    private function attempt(callable $action, string $message): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->cancelPanel();
        $this->successToast($message);

        // The panel and its buttons are gone: the evaluations, as they now stand, are next.
        $this->dispatch('focus-element', id: 'evaluations-title');
    }

    /** Out of reach is a 404, like everywhere else on these pages. */
    private function resolveInterview(): RecruitmentInterview
    {
        $interview = RecruitmentInterview::query()->visibleTo(auth()->user())->findOrFail($this->interviewId);
        Gate::authorize('viewEvaluations', $interview);

        return $interview;
    }

    private function resolveEvaluation(int $evaluationId): RecruitmentInterviewEvaluation
    {
        $interview = $this->resolveInterview();

        return RecruitmentInterviewEvaluation::query()
            ->where('interview_id', $interview->getKey())
            ->findOrFail($evaluationId);
    }
}
