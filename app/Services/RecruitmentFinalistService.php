<?php

namespace App\Services;

use App\Enums\RecruitmentApplicationStatus as Status;
use App\Models\HiringCampaign;
use App\Models\RecruitmentApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecruitmentFinalistService
{
    public function __construct(private readonly RecruitmentApplicationStatusService $statuses) {}

    /**
     * Finalize a campaign that has exactly one finalist. Every application in
     * the campaign is locked before counting so two reviewers cannot finalize
     * different snapshots of the list at the same time.
     */
    public function selectSingle(User $actor, HiringCampaign $campaign): RecruitmentApplication
    {
        Gate::forUser($actor)->authorize('view', $campaign);

        return DB::transaction(function () use ($actor, $campaign): RecruitmentApplication {
            $applications = RecruitmentApplication::query()
                ->where('campaign_id', $campaign->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $finalists = $applications->where('status', Status::Finalist)->values();

            if ($finalists->count() !== 1) {
                throw ValidationException::withMessages([
                    'finalists' => $finalists->isEmpty()
                        ? __('recruitment-applicant.finalists.none')
                        : __('recruitment-applicant.finalists.multiple', ['count' => $finalists->count()]),
                ]);
            }

            /** @var RecruitmentApplication $finalist */
            $finalist = $finalists->first();
            $this->statuses->change($actor, $finalist, Status::Finalist, Status::Selected, null);

            return $finalist->fresh();
        });
    }
}
