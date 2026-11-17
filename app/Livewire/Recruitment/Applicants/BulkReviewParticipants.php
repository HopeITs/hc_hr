<?php

namespace App\Livewire\Recruitment\Applicants;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationParticipant;
use App\Models\User;
use App\Services\RecruitmentApplicationParticipantService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

class BulkReviewParticipants extends Component
{
    public array $applicationIds = [];

    public bool $open = false;

    public string $userId = '';

    public string $role = RecruitmentApplicationParticipant::ROLE_REVIEWER;

    public ?string $result = null;

    #[On('recruitment-bulk-review-access')]
    public function open(array $applicationIds = []): void
    {
        Gate::authorize('bulkManageReviewParticipants', RecruitmentApplication::class);
        $this->resetErrorBag();
        $this->result = null;
        $this->applicationIds = collect($applicationIds)->filter(fn ($id): bool => is_scalar($id) && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $this->open = true;
        $this->dispatch('focus-element', id: 'bulk-review-access-title');
    }

    public function apply(RecruitmentApplicationParticipantService $participants): void
    {
        Gate::authorize('bulkManageReviewParticipants', RecruitmentApplication::class);

        try {
            $outcome = $participants->bulkAssign(auth()->user(), $this->applicationIds, $this->userId, $this->role);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->result = __('recruitment-applicant.profile_reviews.bulk_done', $outcome);
        $this->reset('applicationIds', 'open', 'userId', 'role');
        $this->dispatch('recruitment-review-access-changed');
        $this->dispatch('focus-element', id: 'bulk-review-access-result');
    }

    public function cancel(): void
    {
        $this->reset('applicationIds', 'open', 'userId', 'role', 'result');
        $this->resetErrorBag();
        $this->dispatch('focus-element', id: 'applicants-title');
    }

    public function render()
    {
        return view('livewire.recruitment.applicants.bulk-review-participants', [
            'candidates' => $this->open ? User::query()->whereHas('profile', fn ($profile) => $profile->where('is_active', true))->with('profile')->get()->sortBy(fn (User $user) => $user->profile?->name)->values() : collect(),
        ]);
    }
}
