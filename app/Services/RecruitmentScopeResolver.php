<?php

namespace App\Services;

use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentScopeType;
use App\Models\City;
use App\Models\Contract;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentWorkflowInstanceStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Answers "where does this user reach, and who approves what" for recruitment.
 *
 * Authorization in this module is three separate questions, and all three are
 * asked every time:
 *
 *   permission  — from the user's active role: may they approve at all?
 *   eligibility — from their job level: may they raise a request at all?
 *   scope       — from their position: which requests are theirs to see?
 *
 * Nothing here reads a role name to decide *where* someone reaches, except for
 * the HR-{CITY} roles, which are themselves a statement of governorate. The
 * rest comes off the org chart, so a promotion or a transfer moves someone's
 * reach the same day without an administrator touching anything.
 *
 * The resolver deliberately fails closed: a user it cannot place gets
 * RecruitmentScopeType::None, and a query it cannot place returns nothing.
 */
class RecruitmentScopeResolver
{
    // --------------------------------------------
    // Breadth of access
    // --------------------------------------------

    /**
     * The breadth of this user's access.
     *
     * Order matters: organization-wide roles win outright, then a governorate
     * HR role, then the org chart. Anyone left over sees nothing.
     */
    public function scopeType(User $user): int
    {
        if ($this->isOrganizationWide($user)) {
            return RecruitmentScopeType::Organization;
        }

        if ($this->governorateCityIds($user) !== []) {
            return RecruitmentScopeType::City;
        }

        if ($this->position($user) !== null) {
            return RecruitmentScopeType::DirectReports;
        }

        return RecruitmentScopeType::None;
    }

    /**
     * HR Syria, the general management and super-admin watch the whole
     * organization without being a step in any workflow.
     */
    public function isOrganizationWide(User $user): bool
    {
        return $user->activeRoleIs(...config('recruitment.organization_wide_roles', []))
            || $this->isFinanceApprover($user);
    }

    /**
     * Every governorate an HR-{CITY} role reviews — its own and any assigned.
     *
     * A role's own city is not the whole answer. Governorates without an HR of
     * their own are assigned to another role, and the reviewer has to be able
     * to open and decide those requests, not merely be named on the step. HR
     * Latakia reviews Tartous, so Tartous is inside its scope.
     *
     * HR-SYR is excluded on purpose: it is organization-wide, and
     * User::activeCityId() already returns null for it.
     *
     * @return array<int, int>
     */
    public function governorateCityIds(User $user): array
    {
        $role = strtoupper(trim($user->myRole() ?? ''));

        if (! Str::startsWith($role, 'HR-') || $role === 'HR-SYR') {
            return [];
        }

        $ids = [];

        if (($own = $user->activeCityId()) !== null) {
            $ids[] = $own;
        }

        // Cities this role reviews on another governorate's behalf: those the
        // fallback sends to it and that have no HR of their own. Asked of the
        // same resolver that routes the request, not of the map alone, so a
        // governorate that gains an HR of its own leaves this role's scope.
        $fallbacks = array_keys(array_filter(
            config('recruitment.governorate_hr.map', []),
            fn (string $reviewer): bool => strtoupper($reviewer) === $role,
        ));

        if ($fallbacks !== []) {
            $ids = array_merge($ids, $user->remember('governorate-city-ids:'.$role.':'.implode(',', $fallbacks), fn (): array => City::query()
                ->whereIn('slug', $fallbacks)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $cityId): bool => strtoupper($this->governorateHrRole($cityId)) === $role)
                ->values()
                ->all()));
        }

        return array_values(array_unique($ids));
    }

    /**
     * The position this user currently occupies, through their active contract.
     *
     * Everything about where a manager reaches hangs off this. An employee
     * between contracts has no position and therefore no scope.
     */
    public function position(User $user): ?Position
    {
        return $user->profile?->latestActiveContract()?->position;
    }

    /**
     * Positions reporting directly to this user's position — one level only.
     *
     * A manager sees exactly what he is answerable for: the requests he is the
     * approver of. Going deeper would show him requests he never decides.
     *
     * @return array<int, int>
     */
    public function directReportPositionIds(User $user): array
    {
        $position = $this->position($user);

        if ($position === null) {
            return [];
        }

        return Position::query()
            ->where('direct_manager_id', $position->getKey())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    // --------------------------------------------
    // Visibility
    // --------------------------------------------

    /**
     * The visibility boundary for requests — the single source of truth for
     * lists, exports, selectors and record lookup alike.
     *
     * Every caller goes through this. Applying scope to the list and forgetting
     * it on the detail screen is the classic way this kind of module leaks, so
     * canAccessRequest() below is this same query with a key on it rather than
     * a second set of rules that can drift.
     */
    public function scopeRequests(Builder $query, User $user): Builder
    {
        // The state-machine contract makes drafts private to their requester.
        $query->where(fn (Builder $drafts) => $drafts
            ->where($query->qualifyColumn('status'), '!=', RecruitmentRequestStatus::Draft)
            ->orWhere($query->qualifyColumn('requester_user_id'), $user->getKey()));

        return match ($this->scopeType($user)) {
            RecruitmentScopeType::Organization => $query,

            // A governorate HR sees every request raised for the cities it
            // reviews, whoever raised them.
            RecruitmentScopeType::City => $query->whereIn(
                'scope_city_id',
                $this->governorateCityIds($user),
            ),

            // A manager sees:
            //  a) his own requests and those of his direct reports, AND
            //  b) any request where he is or was an assigned workflow approver.
            // (b) ensures managers see applicants from campaigns raised by
            // positions outside their reporting line when the workflow routed
            // an approval step to them.
            RecruitmentScopeType::DirectReports => $query->where(function (Builder $manager) use ($user): void {
                $manager->whereIn('requester_user_id', $this->visibleRequesterUserIds($user))
                    ->orWhereHas('workflowInstances.steps', fn (Builder $steps): Builder => $steps
                        ->where('assigned_user_id', $user->getKey()));
            }),

            // Fail closed. A user the resolver cannot place sees nothing, and
            // whereRaw('0 = 1') keeps that an empty result rather than an
            // unscoped one.
            default => $query->whereRaw('0 = 1'),
        };
    }

    public function canAccessRequest(User $user, RecruitmentRequest $request): bool
    {
        return $this->scopeRequests(RecruitmentRequest::query(), $user)
            ->whereKey($request->getKey())
            ->exists();
    }

    /**
     * Requests are the root of visibility; everything downstream inherits it.
     *
     * A vacancy exists because a request was approved, and a campaign exists
     * because of a vacancy. Re-deriving scope at each level would let the two
     * answers drift apart, so each one reaches back to the request instead.
     */
    public function scopeVacancies(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'createdFromRequest',
            fn (Builder $requests): Builder => $this->scopeRequests($requests, $user),
        );
    }

    public function scopeCampaigns(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'vacancy.createdFromRequest',
            fn (Builder $requests): Builder => $this->scopeRequests($requests, $user),
        );
    }

    public function scopeJobPosts(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'campaign.vacancy.createdFromRequest',
            fn (Builder $requests): Builder => $this->scopeRequests($requests, $user),
        );
    }

    public function scopeApplicationForms(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'campaign.vacancy.createdFromRequest',
            fn (Builder $requests): Builder => $this->scopeRequests($requests, $user),
        );
    }

    public function scopePublicLinks(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'campaign.vacancy.createdFromRequest',
            fn (Builder $requests): Builder => $this->scopeRequests($requests, $user),
        );
    }

    public function scopeRequestEvents(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'request',
            fn (Builder $requests): Builder => $this->scopeRequests($requests, $user),
        );
    }

    /** An application belongs to its campaign's request, like everything else. */
    public function scopeApplications(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'campaign.vacancy.createdFromRequest',
            fn (Builder $requests): Builder => $this->scopeRequests($requests, $user),
        );
    }

    /**
     * An applicant is visible through an application in scope, or — only when
     * they have no application at all — through their own city.
     *
     * The city branch exists for talent-pool records with nothing to inherit
     * scope from. It deliberately stops once any application exists: a Homs
     * applicant who applied to a Damascus campaign is Damascus's to see.
     */
    public function scopeApplicants(Builder $query, User $user): Builder
    {
        $type = $this->scopeType($user);

        if ($type === RecruitmentScopeType::Organization) {
            return $query;
        }

        if ($type === RecruitmentScopeType::None) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function (Builder $applicants) use ($user, $type): void {
            $applicants->whereHas(
                'applications',
                fn (Builder $applications): Builder => $this->scopeApplications($applications, $user),
            );

            if ($type === RecruitmentScopeType::City) {
                $applicants->orWhere(fn (Builder $pool) => $pool
                    ->whereIn($pool->qualifyColumn('city_id'), $this->governorateCityIds($user))
                    ->whereDoesntHave('applications'));
            }
        });
    }

    /**
     * Notes are scoped by what they are attached to, never by the applicant.
     *
     * An applicant can be visible to two governorates through two campaigns;
     * each sees only the notes on its own application. A note on the applicant
     * alone belongs to the applicant's own record (see ownsApplicantRecord()).
     */
    public function scopeApplicantNotes(Builder $query, User $user): Builder
    {
        $type = $this->scopeType($user);

        if ($type === RecruitmentScopeType::Organization) {
            return $query;
        }

        if ($type === RecruitmentScopeType::None) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function (Builder $notes) use ($user, $type): void {
            $notes->where(fn (Builder $attached) => $attached
                ->whereNotNull($attached->qualifyColumn('application_id'))
                ->whereHas('application', fn (Builder $applications): Builder => $this->scopeApplications($applications, $user)));

            if ($type === RecruitmentScopeType::City) {
                $notes->orWhere(fn (Builder $own) => $own
                    ->whereNull($own->qualifyColumn('application_id'))
                    ->whereHas('applicant', fn (Builder $applicants) => $applicants
                        ->whereIn('city_id', $this->governorateCityIds($user))));
            }
        });
    }

    public function canAccessApplicant(User $user, RecruitmentApplicant $applicant): bool
    {
        return $this->scopeApplicants(RecruitmentApplicant::query(), $user)
            ->whereKey($applicant->getKey())
            ->exists();
    }

    /**
     * Is every part of this applicant inside the user's scope?
     *
     * canAccessApplicant() answers "may they see this person", which one
     * application in scope is enough for. Acting on the whole person — erasing
     * them — needs more: every application they made must be visible too, or a
     * governorate would be deleting another governorate's records.
     */
    public function coversWholeApplicant(User $user, RecruitmentApplicant $applicant): bool
    {
        if (! $this->canAccessApplicant($user, $applicant)) {
            return false;
        }

        if ($this->scopeType($user) === RecruitmentScopeType::Organization) {
            return true;
        }

        $total = $applicant->applications()->count();

        if ($total === 0) {
            return $this->ownsApplicantRecord($user, $applicant);
        }

        return $applicant->applications()->visibleTo($user)->count() === $total;
    }

    public function canAccessApplication(User $user, RecruitmentApplication $application): bool
    {
        return $this->scopeApplications(RecruitmentApplication::query(), $user)
            ->whereKey($application->getKey())
            ->exists();
    }

    /**
     * Records hanging off the applicant with no application to inherit scope
     * from — an orphaned CV, a note on a talent-pool record. Organization-wide
     * users reach them all; a governorate HR only its own cities'; a manager
     * never, because nothing makes such a record his.
     */
    public function ownsApplicantRecord(User $user, RecruitmentApplicant $applicant): bool
    {
        return match ($this->scopeType($user)) {
            RecruitmentScopeType::Organization => true,
            RecruitmentScopeType::City => $applicant->city_id !== null
                && in_array((int) $applicant->city_id, $this->governorateCityIds($user), true),
            default => false,
        };
    }

    /**
     * This user plus whoever currently occupies a position reporting to them.
     *
     * Occupancy is read live rather than stored on the request, so someone who
     * transfers away takes their requests out of their old manager's list on
     * the day they move — the same rule that governs every other scope here.
     *
     * @return array<int, int>
     */
    public function visibleRequesterUserIds(User $user): array
    {
        $ids = [(int) $user->getKey()];

        $positionIds = $this->directReportPositionIds($user);

        if ($positionIds !== []) {
            $ids = array_merge($ids, User::query()
                ->whereIn('employee_id', Contract::query()
                    ->activeNow()
                    ->whereIn('position_id', $positionIds)
                    ->select('employee_id'))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all());
        }

        return array_values(array_unique($ids));
    }

    // --------------------------------------------
    // Raising a request
    // --------------------------------------------

    /** Grade eligibility alone; normal-request role permission is separate (D50). */
    public function isEligibleRequester(User $user): bool
    {
        $position = $this->position($user);

        if ($position === null || ! $position->is_active) {
            return false;
        }

        $grades = config('recruitment.requester_job_level_grades', []);

        return in_array((int) $position->jobLevel?->grade, $grades, true);
    }

    /** HR uses Direct Opening only, including HR with an eligible grade. */
    public function canRaiseNormalRequest(User $user): bool
    {
        return ! $user->activeRoleIs('HR')
            && $user->activeRoleIs('manager', 'general-manager')
            && $this->isEligibleRequester($user);
    }

    /**
     * Creation scope (D49), distinct from D30's one-level request visibility.
     * Traverse direct_manager_id only; city/program/technical manager grant no
     * access. A visited set terminates malformed cycles and excludes the root
     * from existing-position targets even if it is encountered again.
     *
     * @return array<int, int>
     */
    public function manageablePositionIds(User $user, bool $includeSelf = false): array
    {
        if (! $this->canRaiseNormalRequest($user)) {
            return [];
        }

        if ($this->hasOrganizationWideCreationScope($user)) {
            return Position::query()->visible()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        $rootId = (int) $this->position($user)->getKey();
        $seen = [$rootId => true];
        $frontier = [$rootId];
        $ids = $includeSelf ? [$rootId] : [];

        while ($frontier !== []) {
            $children = Position::query()->whereIn('direct_manager_id', $frontier)->pluck('id');
            $frontier = [];
            foreach ($children as $childId) {
                $id = (int) $childId;
                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $frontier[] = $id;
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function scopeManageablePositions(Builder $query, User $user, bool $includeSelf = false): Builder
    {
        return $query->whereIn($query->qualifyColumn('id'), $this->manageablePositionIds($user, $includeSelf));
    }

    /**
     * Positions this requester may actually target from the normal request
     * form.
     *
     * Project managers are limited to their reporting tree and governorate.
     * Program managers use their programme as the boundary for existing
     * positions, because a programme can span governorates. General management
     * remains the explicit organisation-wide exception.
     */
    public function scopeRequestablePositions(Builder $query, User $user, bool $includeSelf = false): Builder
    {
        $position = $this->position($user);

        if ($position === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($this->hasProgramTargetScope($user)) {
            $query->visible()->where('program_id', $position->program_id ?? 0);
        } else {
            $query = $this->scopeManageablePositions($query, $user, $includeSelf)->visible();
        }

        if (! $includeSelf) {
            $query->where($query->qualifyColumn('id'), '!=', $position->getKey());
        }

        if ($this->hasGovernorateCreationScope($user)) {
            $cityId = $this->positionCityId($user);

            if ($cityId === null || City::isNational($cityId)) {
                return $query;
            }

            // The governorate, including any city administered as part of it.
            // A manager in Homs with posts under him in Homs Rural was losing
            // exactly the half of his team he was asking about, because Homs
            // Rural is a separate row in `cities` but not a separate
            // governorate.
            $query->inCities($this->clusterCityIds($cityId));
        }

        return $query;
    }

    /**
     * Every city administered as part of one governorate, including itself.
     *
     * Read from config rather than from the data: nothing in `cities` records
     * that Homs Rural belongs to Homs, and inventing a column for it would put
     * the same fact in two places (the HR map already states it).
     *
     * @return array<int, int>
     */
    public function clusterCityIds(?int $cityId): array
    {
        if ($cityId === null) {
            return [];
        }

        $clusters = array_change_key_case(
            array_map('strtoupper', config('recruitment.governorate_clusters', [])),
            CASE_UPPER,
        );

        $slug = strtoupper((string) City::query()->whereKey($cityId)->value('slug'));

        if ($slug === '') {
            return [$cityId];
        }

        // The governorate this city answers to, which is itself when it is one.
        $parent = $clusters[$slug] ?? $slug;

        $slugs = array_values(array_unique(array_merge(
            [$parent],
            array_keys(array_filter($clusters, fn (string $to): bool => $to === $parent)),
        )));

        $ids = City::query()->whereIn('slug', $slugs)->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();

        return $ids === [] ? [$cityId] : $ids;
    }

    /**
     * Every active position in the governorate this user recruits for — the
     * whole organisation for HR Syria.
     *
     * This is the direct-opening boundary, a different question from the
     * management subtree a manager's own request is scoped by: HR does not
     * manage the posts they recruit for. A non-organisation user with no
     * governorate reaches nothing, which fails closed.
     *
     * @param  Builder<Position>  $query
     * @return Builder<Position>
     */
    public function scopeGovernoratePositions(Builder $query, User $user): Builder
    {
        $query->where($query->qualifyColumn('is_active'), true);

        if ($this->scopeType($user) === RecruitmentScopeType::Organization) {
            return $query;
        }

        $cityIds = $this->governorateCityIds($user);

        if ($cityIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $inner) use ($cityIds): void {
            foreach ($cityIds as $cityId) {
                $inner->orWhere(fn (Builder $city) => $city->inCity((int) $cityId));
            }
        });
    }

    public function scopeProposedManagers(Builder $query, User $user): Builder
    {
        // A new position still needs an administrative parent in the
        // requester's tree. Programme breadth applies to existing targets;
        // it must not let a requester attach a new branch to an unrelated
        // tree.
        $query = $this->scopeManageablePositions($query, $user, true)->visible();
        $position = $this->position($user);

        if ($position === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($this->hasProgramCreationScope($user)) {
            $query->where('program_id', $position->program_id ?? 0);
        }

        if ($this->hasGovernorateCreationScope($user)) {
            $cityId = $this->positionCityId($user);

            if ($cityId === null || City::isNational($cityId)) {
                return $query;
            }

            $query->inCity($cityId);
        }

        $query = $query
            ->where(fn (Builder $positions) => $positions
                ->whereHas('jobLevel', fn (Builder $levels) => $levels
                    ->whereIn('grade', config('recruitment.requester_job_level_grades')))
                ->orWhereHas('directTeam', fn (Builder $team) => $team->where('is_active', true)));

        return $query;
    }

    public function hasProgramCreationScope(User $user): bool
    {
        return ! $this->isOrganizationWide($user)
            && in_array((int) $this->position($user)?->jobLevel?->grade,
                config('recruitment.program_scoped_creation_grades'), true);
    }

    public function hasGovernorateCreationScope(User $user): bool
    {
        return ! $this->isOrganizationWide($user)
            && in_array((int) $this->position($user)?->jobLevel?->grade,
                config('recruitment.governorate_scoped_creation_grades'), true);
    }

    public function hasProgramTargetScope(User $user): bool
    {
        return ! $this->isOrganizationWide($user)
            && in_array((int) $this->position($user)?->jobLevel?->grade,
                config('recruitment.program_wide_target_grades'), true);
    }

    public function scopeCreatablePrograms(Builder $query, User $user): Builder
    {
        return $query->where('is_active', true)
            ->when($this->hasProgramCreationScope($user), fn (Builder $programs) => $programs
                ->whereKey($this->position($user)?->program_id ?? 0));
    }

    public function scopeCreatableCities(Builder $query, User $user): Builder
    {
        $query->where('is_active', true);
        if ($this->hasGovernorateCreationScope($user)) {
            // The whole governorate, so a post may be established in the
            // countryside the requester already manages. The list and the
            // validation behind it both read this, so the two cannot disagree.
            $cityIds = $this->clusterCityIds($this->positionCityId($user));

            return $cityIds === [] ? $query->whereRaw('0 = 1') : $query->whereKey($cityIds);
        }

        // Program managers may establish a position in any active governorate
        // for their programme, including one where the programme has no seat
        // yet. The selected programme remains locked by scopeCreatablePrograms.
        return $query;
    }

    /**
     * Centres in the chosen governorate.
     *
     * The governorate is the only filter. Narrowing by programme as well was
     * tried and withdrawn: `centers` carries no program_id, so the only link
     * to a programme is a position sitting in both, and a centre the programme
     * had not staffed yet vanished from the list — which is exactly the centre
     * someone opening the first position there needs to pick.
     */
    public function scopeCreatableCenters(Builder $query, User $user, ?int $cityId): Builder
    {
        return $query->where('is_active', true)
            ->when(! City::isNational($cityId), fn (Builder $centers) => $centers
                ->where(fn (Builder $locations) => $locations->where('city_id', $cityId)->orWhereNull('city_id')));
    }

    /**
     * Job levels a requester may create a position at — strictly below his own.
     *
     * The direction is read from the data rather than assumed: across the 153
     * manager/report pairs on record, the report carries the larger grade
     * number in 89% of them, so a smaller number is the more senior rank and
     * "below me" means a grade greater than mine.
     *
     * A requester with no grade gets nothing, which fails closed.
     *
     * @return array<int, int>
     */
    public function creatableJobLevelIds(User $user): array
    {
        $grade = $this->position($user)?->jobLevel?->grade;

        if ($grade === null || ! $this->canRaiseNormalRequest($user)) {
            return [];
        }

        return JobLevel::query()
            ->where('grade', '>', (int) $grade)
            ->when($this->hasProgramCreationScope($user), fn (Builder $levels) => $levels
                ->where('grade', '!=', config('recruitment.department_manager_grade')))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The position that approves this user's own requests.
     *
     * Returns null both for the top of the org chart and for a position whose
     * manager was never recorded — the two are told apart by
     * mayRequestWithoutManagerApproval(), never by this method alone. Treating
     * a missing manager as "no approval needed" would let a data-entry gap
     * skip a mandatory step.
     */
    public function approvingManagerPosition(User $user): ?Position
    {
        return $this->position($user)?->directManager;
    }

    /**
     * Is this user legitimately at the top of the org chart?
     *
     * Granted per user, never through a role — see
     * config/recruitment.php:user_level_permissions for why.
     */
    public function mayRequestWithoutManagerApproval(User $user): bool
    {
        return $this->hasDirectPermission(
            $user,
            config('recruitment.user_level_permissions.top_level_requester'),
        );
    }

    // --------------------------------------------
    // Resolving and checking approvers
    // --------------------------------------------

    /**
     * Users who may decide the financial step.
     *
     * The financial approver is a person, not a role: today whoever holds the
     * Senior Financial Manager position. A direct user grant remains the
     * fallback for installations whose position data is not seeded yet; a role
     * carrying it never turns all its holders into approvers.
     *
     * @return array<int, int>
     */
    /**
     * Is this person the financial approver themselves?
     *
     * The organisation has one, and when they raise a request there is nobody
     * else to ask. Sending it to their own step would either stall until they
     * approved their own request or force a self-approval the module refuses
     * on principle, so the step is skipped and recorded as skipped.
     */
    public function isFinanceApprover(User $user): bool
    {
        return in_array((int) $user->getKey(), array_map('intval', $this->financeApproverUserIds()), true);
    }

    public function financeApproverUserIds(): array
    {
        $positionTitle = (string) config('recruitment.finance_approver_position_title', '');

        if ($positionTitle !== '') {
            $positionIds = Position::query()
                ->where('is_active', true)
                ->where('title_en', $positionTitle)
                ->pluck('id');

            $positionHolders = User::query()
                ->whereHas('profile', fn (Builder $profile): Builder => $profile->where('is_active', true))
                ->whereIn('employee_id', Contract::query()
                    ->activeNow()
                    ->whereIn('position_id', $positionIds)
                    ->select('employee_id'))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($positionHolders !== []) {
                return array_values(array_unique($positionHolders));
            }
        }

        return $this->userIdsWithDirectPermission(
            config('recruitment.user_level_permissions.finance_approver'),
        );
    }

    /**
     * The HR role that reviews requests for a governorate.
     *
     * The one rule everything else asks, so that who a request is routed to
     * and who may then decide it can never disagree:
     *
     *  1. a governorate with an HR of its own (an active holder of HR-{SLUG})
     *     is reviewed by it;
     *  2. otherwise the fallback the map names: Tartous to Latakia, Homs Rural
     *     to Homs;
     *  3. otherwise the default, HR Syria.
     *
     * Every city resolves to something, so a request is never stranded because
     * its governorate has no HR of its own.
     */
    public function governorateHrRole(?int $cityId): string
    {
        $map = config('recruitment.governorate_hr.map', []);
        $default = config('recruitment.governorate_hr.default', 'HR-SYR');

        if ($cityId === null) {
            return $default;
        }

        $slug = strtoupper((string) City::query()->whereKey($cityId)->value('slug'));

        if ($slug === '') {
            return $default;
        }

        // A governorate with its own HR reviews its own requests, whatever the
        // fallback says: the fallback is for the absence of one.
        $own = "HR-{$slug}";

        if ($this->roleHasHolders($own)) {
            return $own;
        }

        return $map[$slug] ?? $default;
    }

    /**
     * May this user decide this step, right now?
     *
     * Holding the permission is only the first of four questions, and it is
     * the only one an administrator role bypasses (D10). The other three are
     * asked of everyone, which is what stops HR Syria — who holds every
     * permission in the system — from deciding a step that is somebody else's,
     * or approving a request they raised themselves.
     *
     * The permission itself is checked by the policy, not here.
     */
    public function canActOnStep(User $user, RecruitmentWorkflowInstanceStep $step): bool
    {
        // 2. Is the step still open?
        if ($step->decision !== null || $step->assigned_user_id === null) {
            return false;
        }

        $step->loadMissing(['workflowInstance', 'workflowStep']);
        if ((int) $step->workflowInstance?->status !== \App\Enums\WorkflowInstanceStatus::Running
            || (int) $step->workflowStep?->step_order !== (int) $step->workflowInstance?->current_step_order) {
            return false;
        }

        // 3. Is this the assigned approver?
        if ((int) $step->assigned_user_id !== (int) $user->getKey()) {
            return false;
        }

        // 4. Do they still hold the scope they were assigned under? Someone who
        //    transferred out of a governorate stops being able to decide its
        //    requests, even though the step still carries their name.
        return $this->holdsScope(
            $user,
            $step->assigned_scope_type === null ? null : (int) $step->assigned_scope_type,
            $step->assigned_scope_id === null ? null : (int) $step->assigned_scope_id,
        );
    }

    /**
     * Does this user still sit inside the scope a step was assigned under?
     *
     * Unknown or missing scope is a no. The absence of a scope is not a
     * complete one.
     */
    public function holdsScope(User $user, ?int $scopeType, ?int $scopeId): bool
    {
        return match ($scopeType) {
            RecruitmentScopeType::Organization => $this->isOrganizationWide($user),

            // A city is held by the role governorateHrRole() resolves for it,
            // the very answer the step was assigned under, and nothing broader.
            // HR Syria is organisation-wide and so has no list of cities; it
            // holds only the ones that fall back to it.
            RecruitmentScopeType::City => $scopeId !== null
                && $this->isResolvedReviewer($user, $scopeId),

            // The scope of a manager step is the manager's own position.
            RecruitmentScopeType::DirectReports => $scopeId !== null
                && (int) ($this->position($user)?->getKey() ?? 0) === $scopeId,

            default => false,
        };
    }

    /** Is this user's active role the one the city's requests are routed to? */
    private function isResolvedReviewer(User $user, int $cityId): bool
    {
        $role = strtoupper(trim($user->myRole() ?? ''));

        return Str::startsWith($role, 'HR-')
            && strtoupper($this->governorateHrRole($cityId)) === $role;
    }

    private function hasOrganizationWideCreationScope(User $user): bool
    {
        return $user->activeRoleIs(...config('recruitment.organization_wide_creation_roles', []));
    }

    private function positionCityId(User $user): ?int
    {
        $position = $this->position($user);

        if ($position === null) {
            return null;
        }

        return $position->city_id !== null
            ? (int) $position->city_id
            : ($position->center?->city_id !== null ? (int) $position->center->city_id : null);
    }

    // --------------------------------------------
    // Internals
    // --------------------------------------------

    /**
     * Direct grants only — Spatie's hasPermissionTo() would also match
     * permissions inherited from a role, which is exactly what these two
     * person-level permissions must not do.
     */
    private function hasDirectPermission(User $user, ?string $permission): bool
    {
        if ($permission === null) {
            return false;
        }

        return $user->permissions()->where('name', $permission)->exists();
    }

    /**
     * @return array<int, int>
     */
    private function userIdsWithDirectPermission(?string $permission): array
    {
        if ($permission === null) {
            return [];
        }

        return DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('permissions.name', $permission)
            ->where('model_has_permissions.model_type', User::class)
            ->pluck('model_has_permissions.model_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Whether somebody active actually holds the role.
     *
     * The same test the approver is picked by: a role nobody active holds would
     * route requests to a step no one can decide.
     */
    private function roleHasHolders(string $roleName): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->join('employees', 'employees.id', '=', 'users.employee_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.is_active', true)
            ->where('roles.name', $roleName)
            ->where('employees.is_active', true)
            ->exists();
    }
}
