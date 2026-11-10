<?php

namespace App\Livewire\Recruitment\Interviews;

use App\Livewire\Concerns\WithFeedback;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewEvaluation;
use App\Services\RecruitmentInterviewEvaluationService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Phase 2 Task 8: a participant's own evaluation of an interview, on the
 * interview page. Renders nothing for anyone who may not evaluate it.
 *
 * Only ever loads the current user's own evaluation — never another
 * reviewer's. The version it was opened on is locked here and checked by the
 * service, so a save from a stale tab (or over an HR correction) is refused
 * rather than overwriting what is stored.
 */
class EvaluationForm extends Component
{
    use WithFeedback;

    #[Locked]
    public int $interviewId;

    /** The version this form was loaded from; null until the first save. */
    #[Locked]
    public ?int $expectedVersion = null;

    public string $recommendation = '';

    public string $pros = '';

    public string $cons = '';

    public string $notes = '';

    public function mount(int $interviewId): void
    {
        $this->interviewId = $interviewId;
        $this->loadOwn();
    }

    public function save(RecruitmentInterviewEvaluationService $evaluations): void
    {
        $this->resetErrorBag();
        $interview = $this->resolveInterview();
        Gate::authorize('evaluate', $interview);

        try {
            $evaluation = $evaluations->submit(auth()->user(), $interview, [
                'recommendation' => $this->recommendation,
                'pros' => $this->pros,
                'cons' => $this->cons,
                'notes' => $this->notes,
            ], $this->expectedVersion);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->expectedVersion = $evaluation->version;
        $this->dispatch('recruitment-evaluations-changed');
        $this->toast(__('recruitment-applicant.evaluations.saved'));
    }

    /** Discards unsaved edits and reloads what is stored — the way out of a stale save. */
    public function reload(): void
    {
        $this->resetErrorBag();
        $this->loadOwn();
        $this->dispatch('recruitment-evaluation-reloaded');
    }

    public function render(RecruitmentInterviewEvaluationService $evaluations)
    {
        $interview = $this->resolveInterview();
        $canEvaluate = Gate::allows('evaluate', $interview);

        return view('livewire.recruitment.interviews.evaluation-form', [
            'canEvaluate' => $canEvaluate,
            'closedReason' => $canEvaluate ? $evaluations->closedReason($interview) : null,
            'timezone' => $interview->timezone ?: config('app.timezone'),
            'maxLength' => (int) config('recruitment.evaluations.max_text_length', 5000),
            'own' => $canEvaluate ? $this->own()?->load(['revisions' => fn ($r) => $r->latest('version'), 'revisions.changedBy.profile']) : null,
        ]);
    }

    private function loadOwn(): void
    {
        $interview = $this->resolveInterview();
        $own = Gate::allows('evaluate', $interview) ? $this->own() : null;

        $this->expectedVersion = $own?->version;
        $this->recommendation = (string) $own?->recommendation;
        $this->pros = (string) $own?->pros;
        $this->cons = (string) $own?->cons;
        $this->notes = (string) $own?->notes;
    }

    private function own(): ?RecruitmentInterviewEvaluation
    {
        return RecruitmentInterviewEvaluation::query()
            ->where('interview_id', $this->interviewId)
            ->where('reviewer_user_id', auth()->id())
            ->first();
    }

    private function resolveInterview(): RecruitmentInterview
    {
        $interview = RecruitmentInterview::query()->visibleTo(auth()->user())->findOrFail($this->interviewId);
        Gate::authorize('view', $interview);

        return $interview;
    }
}
