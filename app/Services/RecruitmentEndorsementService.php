<?php

namespace App\Services;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationEndorsement;
use App\Models\User;

/**
 * Manages endorsements ("shortlist votes") on recruitment applications.
 *
 * Governorate HR and managers each cast at most one endorsement per application. An
 * application is "preferred" when both have endorsed it (2 votes), "alternative"
 * when exactly one has endorsed it (1 vote), and "unvoted" when neither has.
 *
 * The voter_role distinguishes who cast the vote so the UI can show which roles
 * have endorsed, not just the count. Casting again is idempotent; the vote
 * stays until explicitly retracted.
 */
class RecruitmentEndorsementService
{
    public const ROLE_HR = 'hr';

    public const ROLE_MANAGER = 'manager';

    /**
     * Returns the voter role for this user, or null when they may not endorse.
     *
     * Only governorate HR and managers may endorse. Organization-wide HR
     * (HR-SYR) supervises the process but does not cast the governorate HR
     * endorsement. Everyone else is refused before the DB is touched.
     */
    public function voterRole(User $user): ?string
    {
        $scope = app(RecruitmentScopeResolver::class);

        $type = $scope->scopeType($user);

        return match (true) {
            $type === \App\Enums\RecruitmentScopeType::City => self::ROLE_HR,
            $type === \App\Enums\RecruitmentScopeType::DirectReports => self::ROLE_MANAGER,
            default => null,
        };
    }

    /**
     * Cast or refresh an endorsement. Idempotent — calling it twice has no
     * extra effect. Returns the endorsement record (existing or new).
     */
    public function endorse(User $user, RecruitmentApplication $application): RecruitmentApplicationEndorsement
    {
        $role = $this->voterRole($user);
        abort_if($role === null, 403);

        return RecruitmentApplicationEndorsement::firstOrCreate(
            ['application_id' => $application->getKey(), 'user_id' => $user->getKey()],
            ['voter_role' => $role],
        );
    }

    /**
     * Retract the current user's endorsement, if any.
     */
    public function retract(User $user, RecruitmentApplication $application): void
    {
        RecruitmentApplicationEndorsement::query()
            ->where('application_id', $application->getKey())
            ->where('user_id', $user->getKey())
            ->delete();
    }

    /**
     * Whether the given user has endorsed this application.
     */
    public function hasEndorsed(User $user, RecruitmentApplication $application): bool
    {
        return RecruitmentApplicationEndorsement::query()
            ->where('application_id', $application->getKey())
            ->where('user_id', $user->getKey())
            ->exists();
    }

    /**
     * Endorsement count for a single application.
     */
    public function count(RecruitmentApplication $application): int
    {
        return $application->endorsements()->distinct('voter_role')->count('voter_role');
    }

    /**
     * Group label: 'preferred' (2+), 'alternative' (1), or 'unvoted' (0).
     */
    public function group(RecruitmentApplication $application): string
    {
        $count = $this->count($application);

        return match (true) {
            $count >= 2 => 'preferred',
            $count === 1 => 'alternative',
            default => 'unvoted',
        };
    }
}
