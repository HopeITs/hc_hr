<?php

namespace App\Livewire\Recruitment\Applicants;

use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationParticipant;
use App\Models\User;
use App\Services\RecruitmentApplicationParticipantService;
use App\Traits\WithAlerts;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ApplicantProfileAccess extends Component
{
    use WithAlerts;

    #[Locked]
    public int $applicantId;

    public ?string $newParticipantId = null;

    public string $newParticipantRole = RecruitmentApplicationParticipant::ROLE_REVIEWER;

    public function mount(int $applicantId): void
    {
        $this->applicantId = $applicantId;
    }

    public function addParticipant(RecruitmentApplicationParticipantService $participants): void
    {
        $application = $this->visibleApplication();
        $userId = filter_var($this->newParticipantId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($userId === false) {
            $this->addError('newParticipantId', __('recruitment-applicant.profile_reviews.errors.user_required'));

            return;
        }

        try {
            $participants->assign(auth()->user(), $application, $userId, $this->newParticipantRole);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->reset('newParticipantId');
        $this->successToast(__('recruitment-applicant.profile_reviews.assigned'));
    }

    public function removeParticipant(int $participantId, RecruitmentApplicationParticipantService $participants): void
    {
        $participants->remove(auth()->user(), $this->manageableApplication(), $participantId);
        $this->successToast(__('recruitment-applicant.profile_reviews.removed'));
    }

    public function render()
    {
        $applicant = RecruitmentApplicant::query()->visibleTo(auth()->user())->findOrFail($this->applicantId);
        $application = $this->visibleApplication();
        $participants = $applicant->currentReviewParticipants()->with('user.profile')->oldest('id')->get();
        $employeeId = $applicant->employee_id;
        $canManage = Gate::allows('manageReviewParticipants', $application);

        return view('livewire.recruitment.applicants.applicant-profile-access', [
            'applicant' => $applicant,
            'participants' => $participants,
            'canManage' => $canManage,
            'candidates' => $canManage
                ? User::query()
                    ->whereHas('profile', fn ($profile) => $profile->where('is_active', true))
                    ->when($employeeId !== null, fn ($query) => $query->where('employee_id', '!=', $employeeId))
                    ->whereKeyNot($participants->pluck('user_id'))
                    ->with('profile')
                    ->get()
                    ->sortBy(fn (User $candidate) => $candidate->profile?->name)
                    ->values()
                : collect(),
        ]);
    }

    private function visibleApplication(): RecruitmentApplication
    {
        return RecruitmentApplicant::query()
            ->visibleTo(auth()->user())
            ->findOrFail($this->applicantId)
            ->applications()
            ->profileVisibleTo(auth()->user())
            ->latest('id')
            ->firstOrFail();
    }

    private function manageableApplication(): RecruitmentApplication
    {
        $application = $this->visibleApplication();
        Gate::authorize('manageReviewParticipants', $application);

        return $application;
    }
}
