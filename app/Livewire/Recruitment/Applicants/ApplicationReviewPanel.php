<?php

namespace App\Livewire\Recruitment\Applicants;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationParticipant;
use App\Models\RecruitmentApplicationReview;
use App\Models\User;
use App\Services\RecruitmentApplicationParticipantService;
use App\Services\RecruitmentApplicationReviewService;
use App\Traits\WithAlerts;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ApplicationReviewPanel extends Component
{
    use WithAlerts;

    #[Locked]
    public int $applicationId;

    public ?string $newParticipantId = null;

    public string $newParticipantRole = RecruitmentApplicationParticipant::ROLE_REVIEWER;

    public bool $showAccess = true;

    #[Locked]
    public ?int $expectedVersion = null;

    public string $recommendation = '';

    public string $pros = '';

    public string $cons = '';

    public string $notes = '';

    public function mount(int $applicationId, bool $showAccess = true): void
    {
        $this->applicationId = $applicationId;
        $this->showAccess = $showAccess;
        $this->resolveApplication();
        $this->loadOwnReview();
    }

    public function addParticipant(RecruitmentApplicationParticipantService $participants): void
    {
        $application = $this->resolveApplication();
        Gate::authorize('manageReviewParticipants', $application);
        $userId = filter_var($this->newParticipantId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($userId === false) {
            $this->addError('newParticipantId', __('recruitment-applicant.profile_reviews.errors.user_required'));

            return;
        }

        try {
            $participants->assign(auth()->user(), $application, $userId, $this->newParticipantRole);
        } catch (ValidationException $exception) {
            $this->addErrors($exception);

            return;
        }

        $this->reset('newParticipantId');
        $this->resetErrorBag(['newParticipantId', 'newParticipantRole']);
        $this->successToast(__('recruitment-applicant.profile_reviews.assigned'));
    }

    public function removeParticipant(int $participantId, RecruitmentApplicationParticipantService $participants): void
    {
        $application = $this->resolveApplication();
        Gate::authorize('manageReviewParticipants', $application);
        $participants->remove(auth()->user(), $application, $participantId);
        $this->successToast(__('recruitment-applicant.profile_reviews.removed'));
    }

    public function saveReview(RecruitmentApplicationReviewService $reviews): void
    {
        $this->resetErrorBag();
        $application = $this->resolveApplication();
        Gate::authorize('submit', [RecruitmentApplicationReview::class, $application]);

        try {
            $review = $reviews->submit(auth()->user(), $application, [
                'recommendation' => $this->recommendation,
                'pros' => $this->pros,
                'cons' => $this->cons,
                'notes' => $this->notes,
            ], $this->expectedVersion);
        } catch (ValidationException $exception) {
            $this->addErrors($exception);

            return;
        }

        $this->expectedVersion = $review->version;
        $this->successToast(__('recruitment-applicant.profile_reviews.saved'));
    }

    public function reloadReview(): void
    {
        $this->resetErrorBag();
        $this->loadOwnReview();
    }

    public function render()
    {
        $application = $this->resolveApplication();
        $user = auth()->user();
        $canManage = Gate::allows('manageReviewParticipants', $application);
        $ownParticipant = $application->applicant?->currentReviewParticipants()
            ->where('user_id', $user->getKey())
            ->first();

        $participants = $application->applicant?->currentReviewParticipants()
            ->with('user.profile')
            ->oldest('id')
            ->get();
        $applicantEmployeeId = $application->applicant()->value('employee_id');

        return view('livewire.recruitment.applicants.application-review-panel', [
            'application' => $application,
            'canManage' => $canManage,
            'canReview' => $ownParticipant?->role === RecruitmentApplicationParticipant::ROLE_REVIEWER,
            'ownParticipant' => $ownParticipant,
            'participants' => $participants ?? collect(),
            'reviews' => $canManage
                ? $application->profileReviews()->with('reviewer.profile')->latest('updated_at')->get()
                : $application->profileReviews()->where('reviewer_user_id', $user->getKey())->with('reviewer.profile')->get(),
            'candidates' => $canManage ? User::query()
                ->whereHas('profile', fn ($profile) => $profile->where('is_active', true))
                ->when($applicantEmployeeId !== null, fn ($candidates) => $candidates->where('employee_id', '!=', $applicantEmployeeId))
                ->whereKeyNot(($participants ?? collect())->pluck('user_id'))
                ->with('profile')
                ->get()
                ->sortBy(fn (User $candidate) => $candidate->profile?->name)
                ->values() : collect(),
            'maxLength' => (int) config('recruitment.evaluations.max_text_length', 5000),
        ]);
    }

    private function loadOwnReview(): void
    {
        $review = RecruitmentApplicationReview::query()
            ->where('application_id', $this->applicationId)
            ->where('reviewer_user_id', auth()->id())
            ->first();

        $this->expectedVersion = $review?->version;
        $this->recommendation = (string) $review?->recommendation;
        $this->pros = (string) $review?->pros;
        $this->cons = (string) $review?->cons;
        $this->notes = (string) $review?->notes;
    }

    private function resolveApplication(): RecruitmentApplication
    {
        $application = RecruitmentApplication::query()
            ->profileVisibleTo(auth()->user())
            ->findOrFail($this->applicationId);

        Gate::authorize('view', $application);

        return $application;
    }

    private function addErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            $this->addError($field, $messages[0]);
        }
    }
}
