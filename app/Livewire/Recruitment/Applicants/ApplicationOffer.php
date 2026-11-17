<?php

namespace App\Livewire\Recruitment\Applicants;

use App\Enums\RecruitmentApplicationStatus;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentJobOffer;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Phase 2 Task 9: an application's job offer on the applicant profile — its
 * status, and the way into creating one for a selected candidate. No terms,
 * no salary: those are on the offer page, for the users allowed to see them.
 * Renders nothing for users without view-offers.
 */
class ApplicationOffer extends Component
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
        $offer = RecruitmentJobOffer::query()->where('application_id', $application->getKey())->with(['currentVersion', 'hiringConversion'])->first();

        return view('livewire.recruitment.applicants.application-offer', [
            'visible' => Gate::allows('viewAny', RecruitmentJobOffer::class),
            'application' => $application,
            'offer' => $offer,
            'canCreate' => $offer === null
                && $application->status === RecruitmentApplicationStatus::Selected
                && Gate::allows('create', [RecruitmentJobOffer::class, $application]),
        ]);
    }

    private function resolveApplication(): RecruitmentApplication
    {
        $application = RecruitmentApplication::query()->visibleTo(auth()->user())->findOrFail($this->applicationId);
        Gate::authorize('view', $application);

        return $application;
    }
}
