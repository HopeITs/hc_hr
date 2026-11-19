<?php

namespace App\Services\CriticalPositions;

use App\Contracts\CandidateSource;
use App\Enums\RecruitmentApplicationStatus as Status;
use App\Models\Position;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\User;
use App\Services\RecruitmentScopeResolver;
use Illuminate\Support\Facades\Gate;

/**
 * Old CVs: people who reached the last stages of an earlier campaign and were
 * not hired. Everything the recruitment module already enforces still holds:
 * the viewer needs recruitment-applicants.view, sees only applications in their
 * recruitment scope, and applicants who were anonymized, whose retention date
 * passed, or who are volunteers already (the internal source covers them) are
 * never listed. Only the name, the stage reached and the campaign are shown;
 * the full file stays behind the recruitment applicant page and its own checks.
 */
final class PastApplicantsSource implements CandidateSource
{
    /** Stages that count as "reached the final stages". Hired is excluded on purpose. */
    public const FINAL_STAGES = [
        Status::Finalist,
        Status::Selected,
        Status::OfferSent,
        Status::OfferAccepted,
        Status::OfferRejected,
    ];

    public function __construct(private readonly RecruitmentScopeResolver $scopes) {}

    public function suggest(Position $position, User $viewer, int $limit): array
    {
        if (Gate::forUser($viewer)->denies('viewAny', RecruitmentApplicant::class)) {
            return [];
        }

        $applications = $this->scopes->scopeApplications(RecruitmentApplication::query(), $viewer)
            ->whereIn('status', self::FINAL_STAGES)
            ->whereHas('applicant', fn ($q) => $q
                ->whereNull('anonymized_at')
                ->whereNull('employee_id')
                ->where(fn ($r) => $r->whereNull('retention_until')->orWhere('retention_until', '>', now()))
                ->whereDoesntHave('applications', fn ($a) => $a->where('status', Status::Hired)))
            ->whereHas('vacancy.position', fn ($q) => $q->whereKey($position->getKey())
                ->orWhere(fn ($same) => $same
                    ->when($position->department_id, fn ($x) => $x->where('department_id', $position->department_id))
                    ->when($position->job_level_id, fn ($x) => $x->where('job_level_id', $position->job_level_id))
                    ->when(! $position->department_id && ! $position->job_level_id, fn ($x) => $x->whereRaw('0 = 1'))))
            ->with(['applicant', 'vacancy.position'])
            ->latest('id')
            ->limit($limit * 4)
            ->get();

        return $applications
            ->unique('applicant_id')
            ->sortByDesc(fn (RecruitmentApplication $a) => (int) ($a->vacancy?->position_id === $position->getKey()))
            ->take($limit)
            ->map(function (RecruitmentApplication $application) use ($position): CandidateSuggestion {
                $samePosition = $application->vacancy?->position_id === $position->getKey();

                return new CandidateSuggestion(
                    source: CandidateSuggestion::CV,
                    id: $application->applicant_id,
                    name: $application->applicant->full_name,
                    score: null,
                    reasons: [
                        ['key' => 'critical-positions.cv.reached', 'params' => ['stage' => __('recruitment-applicant.statuses.'.$application->status)]],
                        ['key' => $samePosition ? 'critical-positions.cv.same-position' : 'critical-positions.cv.similar-position', 'params' => ['position' => $application->vacancy?->position?->title]],
                    ],
                    url: route('recruitment.applicants.show', $application->applicant_id),
                );
            })
            ->values()
            ->all();
    }
}
