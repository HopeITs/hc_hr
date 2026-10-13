<?php

namespace App\Services;

use App\Models\RecruitmentRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class RecruitmentRequestListQuery
{
    public const ALL = 'all';

    public const MINE = 'mine';

    public const TEAM = 'team';

    public const NEEDS_REVIEW = 'needs_review';

    public function __construct(
        private readonly RecruitmentPendingApprovalQuery $pendingApprovals,
    ) {}

    /** @return array<int, string> */
    public function filters(): array
    {
        return [self::ALL, self::MINE, self::TEAM, self::NEEDS_REVIEW];
    }

    /**
     * The requests this user may see, narrowed by their relationship to them.
     *
     * Every branch starts from visibleTo(), so a presentation filter can
     * never widen the authorization boundary.
     *
     * @return Builder<RecruitmentRequest>
     */
    public function forUser(User $user, string $filter = self::ALL): Builder
    {
        $requests = RecruitmentRequest::query()->visibleTo($user);

        return match ($filter) {
            self::MINE => $requests->whereBelongsTo($user, 'requester'),
            self::TEAM => $requests->where('requester_user_id', '!=', $user->getKey()),
            self::NEEDS_REVIEW => $requests->whereIn(
                $requests->qualifyColumn('id'),
                $this->pendingApprovals->requestIdsForUser($user),
            ),
            default => $requests,
        };
    }

    /** @return array<int, int> */
    public function pendingRequestIds(User $user): array
    {
        return $this->pendingApprovals->requestIdsForUser($user)
            ->pluck('pending_requests.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
