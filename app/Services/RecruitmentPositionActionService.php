<?php

namespace App\Services;

use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Models\Position;
use Illuminate\Support\Collection;

class RecruitmentPositionActionService
{
    /** @var list<int> */
    public const ACTIVE_REQUEST_STATUSES = [
        RecruitmentRequestStatus::Submitted,
        RecruitmentRequestStatus::UnderReview,
        RecruitmentRequestStatus::ChangesRequested,
        RecruitmentRequestStatus::Resubmitted,
    ];

    /** @var list<int> */
    public const DISPLAY_REQUEST_STATUSES = [
        ...self::ACTIVE_REQUEST_STATUSES,
        RecruitmentRequestStatus::Approved,
    ];

    /**
     * Return the actions that can be started for one position.
     *
     * The draft service remains the final authority when the user saves. An
     * action can become unavailable between rendering and clicking.
     *
     * @param  Collection<int, \App\Models\RecruitmentRequest>  $activeRequests
     * @param  Collection<int, \App\Models\Vacancy>  $openVacancies
     * @param  Collection<int, int>  $activeCampaignVacancyIds
     * @return array<string|int, array{available: bool, reason: ?string}>
     */
    public function actionsFor(
        Position $position,
        Collection $activeRequests,
        Collection $openVacancies,
        Collection $activeCampaignVacancyIds,
        bool $isRoot,
        bool $canCreatePosition,
    ): array {
        $hasPositionRequest = fn (int $type): bool => $activeRequests
            ->contains(fn ($request): bool => (int) $request->request_type === $type);

        $vacancyRequestExists = fn (int $vacancyId): bool => $activeRequests
            ->contains(fn ($request): bool => in_array((int) $request->request_type, [
                RecruitmentRequestType::FillVacancy,
                RecruitmentRequestType::CloseVacancy,
            ], true) && ($request->target_vacancy_id === null
                || (int) $request->target_vacancy_id === $vacancyId));

        $fillConflictWithoutNamedVacancy = $activeRequests->contains(
            fn ($request): bool => in_array((int) $request->request_type, [
                RecruitmentRequestType::FillVacancy,
                RecruitmentRequestType::CloseVacancy,
            ], true) && $request->target_vacancy_id === null,
        );

        $fillCanTargetSeat = ! $fillConflictWithoutNamedVacancy
            && ($openVacancies->isEmpty() || $openVacancies->contains(
                fn ($vacancy): bool => ! $vacancyRequestExists((int) $vacancy->getKey()),
            ));

        $closeVacancyCanTargetSeat = $openVacancies->contains(function ($vacancy) use ($activeCampaignVacancyIds, $vacancyRequestExists): bool {
            return ! $activeCampaignVacancyIds->contains((int) $vacancy->getKey())
                && ! $vacancyRequestExists((int) $vacancy->getKey());
        });

        $rootReason = __('recruitment.team_staffing.root_not_targetable');
        $pendingReason = __('recruitment.team_staffing.request_pending');

        return [
            RecruitmentRequestType::FillVacancy => [
                'available' => ! $isRoot && $position->is_active && $position->canAssign()
                    && $fillCanTargetSeat,
                'reason' => $isRoot ? $rootReason
                    : (! $position->is_active ? __('recruitment.position_inactive')
                        : (! $position->canAssign() ? __('recruitment.no_headcount_room')
                            : ($fillCanTargetSeat ? null : $pendingReason))),
            ],
            RecruitmentRequestType::IncreaseHeadcount => [
                'available' => ! $isRoot && $position->is_active
                    && ! $hasPositionRequest(RecruitmentRequestType::IncreaseHeadcount),
                'reason' => $isRoot ? $rootReason
                    : (! $position->is_active ? __('recruitment.position_inactive')
                        : ($hasPositionRequest(RecruitmentRequestType::IncreaseHeadcount) ? $pendingReason : null)),
            ],
            RecruitmentRequestType::ClosePosition => [
                'available' => ! $isRoot && $position->is_active
                    && (int) $position->max_emp === 1
                    && (int) $position->exist_emp === 0
                    && ! $hasPositionRequest(RecruitmentRequestType::ClosePosition),
                'reason' => $isRoot ? $rootReason
                    : (! $position->is_active ? __('recruitment.position_inactive')
                        : ((int) $position->max_emp !== 1
                            ? __('recruitment.close_position_single_seat_only')
                            : ((int) $position->exist_emp > 0
                                ? __('recruitment.position_has_contracts')
                                : ($hasPositionRequest(RecruitmentRequestType::ClosePosition) ? $pendingReason : null)))),
            ],
            RecruitmentRequestType::CloseVacancy => [
                'available' => ! $isRoot && $position->is_active
                    && (int) $position->max_emp > 1 && $closeVacancyCanTargetSeat,
                'reason' => $isRoot ? $rootReason
                    : (! $position->is_active ? __('recruitment.position_inactive')
                        : ((int) $position->max_emp <= 1
                            ? __('recruitment.close_vacancy_many_seats_only')
                            : ($openVacancies->isEmpty() ? __('recruitment.team_staffing.no_open_vacancy')
                                : ($closeVacancyCanTargetSeat ? null : __('recruitment.team_staffing.vacancy_action_blocked'))))),
            ],
            'create_position' => [
                'available' => $canCreatePosition,
                'reason' => $canCreatePosition ? null : __('recruitment.team_staffing.parent_not_eligible'),
            ],
        ];
    }
}
