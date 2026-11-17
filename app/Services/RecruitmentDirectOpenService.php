<?php

namespace App\Services;

use App\Enums\ExternalConfirmationDecision;
use App\Enums\ExternalConfirmationMethod;
use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus as Status;
use App\Enums\RecruitmentRequestType as Type;
use App\Enums\RecruitmentScopeType;
use App\Enums\WorkflowStepKey;
use App\Models\Position;
use App\Models\RecruitmentRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Opening a request that was already agreed outside the system.
 *
 * Sometimes the manager, the financial approver and HR have settled a hire in
 * a meeting or over the phone before anybody opened a screen. Making them
 * repeat those decisions as electronic approvals would be theatre; leaving the
 * action undocumented would be worse. This is the third way: the request is
 * recorded as already decided, and its applicable external decisions are
 * written down. HR's own action is recorded by the request itself.
 *
 * Two rules keep this from becoming a hole in the workflow.
 *
 *   The confirmations are recorded as external, never as approvals. The
 *   timeline must never show that somebody pressed a button they did not
 *   press. The applicable manager and finance steps say who confirmed them,
 *   by what means and when — or that they did not apply. The HR step is the
 *   direct-opening action and is never entered a second time.
 *
 *   Who opens it decides whether it still needs a decision. HR Syria's own
 *   direct opening is final, because there is nobody above them to ask. A
 *   governorate's goes to HR Syria through a one-step workflow, so it shows up
 *   in their pending list like everything else and leaves the same trail.
 */
class RecruitmentDirectOpenService
{
    /** The decisions made outside the system before HR records the opening. */
    private const CONFIRMABLE = [
        WorkflowStepKey::ManagerApproval,
        WorkflowStepKey::FinanceApproval,
    ];

    /** Requests that still reserve their position for a fill-vacancy decision. */
    private const BLOCKING_FILL_STATUSES = [
        Status::Submitted,
        Status::UnderReview,
        Status::ChangesRequested,
        Status::Resubmitted,
        Status::Approved,
        Status::FailedApplication,
    ];

    public function __construct(
        private readonly RecruitmentRequestDraftService $drafts,
        private readonly RecruitmentRequestSubmissionService $submissions,
        private readonly RecruitmentRequestRevalidator $revalidator,
        private readonly RecruitmentWorkflowInstanceService $workflows,
        private readonly RecruitmentRequestStateMachine $states,
        private readonly RecruitmentRequestApplicationService $applications,
        private readonly RecruitmentScopeResolver $scopes,
    ) {}

    /**
     * Record an already-agreed hire and carry it to approved.
     *
     * The payload is the ordinary request form; `$documentation` is what makes
     * this a direct opening rather than a submission — who really asked for it,
     * why it bypassed the workflow, and the external confirmations.
     *
     * @param  array<string, mixed>  $payload  the request form
     * @param  array<string, mixed>  $documentation  actual requester, reason, confirmations
     */
    public function open(User $actor, array $payload, array $documentation): RecruitmentRequest
    {
        Gate::forUser($actor)->authorize('directOpen', RecruitmentRequest::class);

        $data = $this->drafts->validate($payload);
        $documentation = $this->normaliseDocumentation($actor, $data, $documentation);
        $requester = isset($documentation['actual_requester_user_id'])
            ? User::query()->find($documentation['actual_requester_user_id'])
            : null;
        $confirmationSteps = $this->confirmableStepsForRequester(
            $requester,
            (int) $data['request_type'],
        );
        $this->assertDocumented($documentation, $confirmationSteps);

        $request = DB::transaction(function () use ($actor, $data, $documentation, $confirmationSteps): RecruitmentRequest {
            $position = $this->targetWithinScope($actor, $data);

            $numbers = app(RecruitmentRequestNumber::class);
            $cityId = $this->cityOf($position, $data);

            $request = RecruitmentRequest::query()->create([
                'request_number' => $numbers->next(
                    $cityId,
                    $numbers->programIdFor($data, $position->getKey()),
                ),
                'requester_user_id' => $actor->getKey(),
                'request_type' => $data['request_type'],
                'mode' => RecruitmentRequestMode::DirectHr,
                'status' => Status::Submitted,
                'scope_city_id' => $cityId,
                'target_position_id' => $data['request_type'] === Type::CreatePosition ? null : $position->getKey(),
                'target_vacancy_id' => $data['vacancy_id'] ?? null,
                'draft_payload_json' => $data,
                'actual_requester_name' => $documentation['actual_requester_name'],
                'actual_requester_user_id' => $documentation['actual_requester_user_id'] ?? null,
                'actual_requester_position_id' => $documentation['actual_requester_position_id'] ?? null,
                'direct_open_reason' => $documentation['direct_open_reason'],
                'direct_opened_by_user_id' => $actor->getKey(),
                'direct_opened_at' => now(),
                'submitted_at' => now(),
            ]);

            $request->events()->create([
                'event_type' => RecruitmentEventType::RequestCreated,
                'to_status' => Status::Submitted,
                'actor_user_id' => $actor->getKey(),
                'actor_role' => $actor->myRole() ?? 'system',
                'scope_type' => $this->scopes->scopeType($actor),
            ]);

            // The rules of the request type still hold: a direct opening may
            // shorten the approvals, never the facts.
            $this->revalidator->assert($request, $data);

            // Freeze the same structured version used by normal submissions.
            // Job-post and application-form defaults read positionDetail, so a
            // direct opening must not create a snapshot-only version.
            $version = $this->submissions->freezeVersion($request, $actor, $data);

            $this->recordConfirmations(
                $actor,
                $request,
                $documentation['confirmations'] ?? [],
                $confirmationSteps,
            );

            // HR Syria's own opening completes here; a governorate's gets a
            // one-step workflow instance and waits on HR Syria (T19 · T20).
            $this->workflows->start($request->fresh(), $version);

            return $request->fresh();
        });

        // A governorate's opening waits on HR Syria, and until now it waited
        // silently: the normal submission path announced its workflow and this
        // one never did, so the approver had to happen to look. Announced here
        // rather than inside the transaction, for the same reason as there — a
        // rollback must not leave somebody holding a notice about a request
        // that does not exist.
        //
        // Safe for HR Syria's own opening too: that instance is already
        // complete, so there is no step waiting on anybody and nothing is sent.
        app(RecruitmentNotifier::class)->workflowStarted(
            $request,
            $request->workflowInstances()->latest('id')->first(),
        );

        // HR Syria has no electronic step, so its opening reaches Approved in
        // the transaction above. Apply the business effect afterwards: a
        // failed vacancy/position write must not erase the approval trail.
        if ((int) $request->status === Status::Approved) {
            return $this->applications->apply($request, $actor);
        }

        return $request;
    }

    /**
     * The generated lines an opener is asked to fill in.
     *
     * Manager and, for requests that spend money, finance, reflecting the
     * decisions made before HR records the opening. Closing a position or an
     * empty approved seat has no finance line because finance is not part of
     * either flow.
     *
     * @return array<int, int> step keys
     */
    public function confirmableSteps(?int $requestType = null): array
    {
        if (in_array($requestType, [Type::ClosePosition, Type::CloseVacancy], true)) {
            return [WorkflowStepKey::ManagerApproval];
        }

        return self::CONFIRMABLE;
    }

    /**
     * The external decisions that apply to this requester.
     *
     * A manager decision cannot exist when the requester's position has no
     * direct manager. Finance remains applicable for request types that use it.
     *
     * @return array<int, int> step keys
     */
    public function confirmableStepsForRequester(?User $requester, ?int $requestType = null): array
    {
        $steps = $this->confirmableSteps($requestType);

        if ($requester === null || $this->scopes->position($requester)?->direct_manager_id !== null) {
            return $steps;
        }

        return array_values(array_diff($steps, [WorkflowStepKey::ManagerApproval]));
    }

    /**
     * Managers HR may name as the person who actually requested the opening.
     *
     * The list is deliberately based on active management positions rather
     * than role labels: the application currently uses one shared manager role
     * for programme, project and department managers.
     *
     * @return Collection<int, User>
     */
    public function requesterCandidates(User $actor): Collection
    {
        $cityIds = $this->scopes->governorateCityIds($actor);

        return User::query()
            ->with([
                'profile.contracts.position',
                'profile.contracts.position.city',
                'profile.contracts.position.center.city',
            ])
            ->whereHas('profile', fn (Builder $profile): Builder => $profile->where('is_active', true))
            ->whereHas('profile.contracts', function (Builder $contracts) use ($cityIds): Builder {
                return $contracts->activeNow()->whereHas('position', function (Builder $positions) use ($cityIds): Builder {
                    return $positions
                        ->where('is_active', true)
                        ->whereHas('jobLevel', fn (Builder $levels): Builder => $levels
                            ->whereIn('grade', config('recruitment.requester_job_level_grades')))
                        ->when($cityIds !== [], function (Builder $positions) use ($cityIds): Builder {
                            return $positions->where(function (Builder $scoped) use ($cityIds): void {
                                foreach ($cityIds as $cityId) {
                                    $scoped->orWhere(fn (Builder $city): Builder => $city->inCity((int) $cityId));
                                }
                            });
                        });
                });
            })
            ->orderBy('email')
            ->get()
            ->filter(fn (User $user): bool => $this->scopes->position($user) !== null)
            ->sortBy(fn (User $user): string => mb_strtolower((string) ($user->profile?->name ?? $user->email)))
            ->values();
    }

    /**
     * Positions the selected requester could target, intersected with the HR
     * opener's governorate boundary.
     *
     * @return Builder<Position>
     */
    public function positionsForRequester(User $actor, ?User $requester, int $requestType): Builder
    {
        $positions = Position::query();

        if ($requester === null) {
            return $positions->whereRaw('0 = 1');
        }

        $positions = $requestType === Type::CreatePosition
            ? $this->scopes->scopeProposedManagers($positions, $requester)
            : $this->scopes->scopeRequestablePositions($positions, $requester);

        if ($requestType === Type::FillVacancy) {
            $positions
                ->where('is_active', true)
                ->whereColumn('exist_emp', '<', 'max_emp')
                ->whereDoesntHave('recruitmentRequests', function (Builder $requests): void {
                    $this->constrainBlockingFillRequests($requests);
                });
        }

        if ($requestType === Type::ClosePosition) {
            $positions
                ->where('exist_emp', 0)
                ->where('max_emp', 1)
                ->whereDoesntHave('activeContracts');
        }

        if ($requestType === Type::CloseVacancy) {
            $positions
                ->where('is_active', true)
                ->where('max_emp', '>', 1)
                ->whereColumn('exist_emp', '<', 'max_emp');
        }

        $cityIds = $this->scopes->governorateCityIds($actor);

        if ($cityIds === []) {
            return $positions;
        }

        return $positions->where(function (Builder $scoped) use ($cityIds): void {
            foreach ($cityIds as $cityId) {
                $scoped->orWhere(fn (Builder $city): Builder => $city->inCity((int) $cityId));
            }
        });
    }

    /**
     * Candidates shown in each “Who confirmed it” picker.
     *
     * @return Collection<int, User>
     */
    public function confirmationCandidates(User $requester, int $stepKey, ?int $cityId): Collection
    {
        if ($cityId === null && $stepKey === WorkflowStepKey::GovernorateHrApproval) {
            $requesterPosition = $this->scopes->position($requester);

            $cityId = $requesterPosition?->city_id
                ?? $requesterPosition?->center?->city_id;
        }

        return app(RecruitmentApproverResolver::class)
            ->confirmationCandidates($requester, $stepKey, $cityId);
    }

    /**
     * The post being opened for, inside the opener's own governorate.
     *
     * Deliberately not the management tree that a normal request is scoped by:
     * HR does not manage the posts they recruit for, and the whole point of
     * this path is that somebody else already decided. What bounds it instead
     * is the governorate whose hiring this HR answers for — and for HR Syria,
     * nothing, because their scope is the organisation.
     *
     * @param  array<string, mixed>  $data
     */
    private function targetWithinScope(User $actor, array $data): Position
    {
        $isNewPosition = $data['request_type'] === Type::CreatePosition;
        $targetId = (int) ($isNewPosition ? $data['direct_manager_id'] : $data['position_id']);
        $field = 'data.'.($isNewPosition ? 'direct_manager_id' : 'position_id');

        $positions = Position::query()->whereKey($targetId)->lockForUpdate();

        if ($this->scopes->scopeType($actor) !== RecruitmentScopeType::Organization) {
            $cityIds = $this->scopes->governorateCityIds($actor);

            // scopeInCity is the single source of truth for city scoping, so
            // the governorate's several cities are ORed through it rather than
            // compared against city_id directly.
            $positions->where(function ($query) use ($cityIds): void {
                foreach ($cityIds as $cityId) {
                    $query->orWhere(fn ($inner) => $inner->inCity((int) $cityId));
                }
            });
        }

        $position = $positions->first();

        if ($position === null) {
            throw ValidationException::withMessages([
                $field => __('recruitment.direct_open.outside_scope'),
            ]);
        }

        if ((int) $data['request_type'] === Type::FillVacancy
            && $this->hasBlockingFillRequest($position)) {
            throw ValidationException::withMessages([
                'data.position_id' => __('recruitment.direct_open.position_already_open'),
            ]);
        }

        return $position;
    }

    private function hasBlockingFillRequest(Position $position): bool
    {
        $requests = $position->recruitmentRequests()->getQuery();
        $this->constrainBlockingFillRequests($requests);

        return $requests->exists();
    }

    private function constrainBlockingFillRequests(Builder $requests): void
    {
        $requests
            ->where('request_type', Type::FillVacancy)
            ->where(function (Builder $statuses): void {
                $statuses
                    ->whereIn('status', self::BLOCKING_FILL_STATUSES)
                    ->orWhere(function (Builder $applied): void {
                        $applied
                            ->where('status', Status::Applied)
                            ->whereHas('targetVacancy', fn (Builder $vacancies): Builder => $vacancies->available());
                    });
            });
    }

    /**
     * The governorate the request belongs to.
     *
     * Taken from the form for a new post, since that post has no city yet, and
     * from the existing post otherwise.
     *
     * @param  array<string, mixed>  $data
     */
    private function cityOf(Position $position, array $data): int
    {
        return $data['request_type'] === Type::CreatePosition
            ? (int) $data['city_id']
            : (int) $position->city_id;
    }

    /**
     * Every generated line is answered, and the reasons are given.
     *
     * @param  array<string, mixed>  $documentation
     */
    private function assertDocumented(array $documentation, array $confirmationSteps): void
    {
        $errors = [];

        if (blank($documentation['actual_requester_user_id'] ?? null)
            && blank($documentation['actual_requester_name'] ?? null)) {
            $errors['actual_requester_user_id'] = __('recruitment.direct_open.requester_required');
            // Keep the old key for API callers that still send the original
            // free-text payload; the screen now points at the select control.
            $errors['actual_requester_name'] = __('recruitment.direct_open.requester_required');
        }

        if (blank($documentation['direct_open_reason'] ?? null)) {
            $errors['direct_open_reason'] = __('recruitment.direct_open.reason_required');
        }

        $confirmations = $documentation['confirmations'] ?? [];

        foreach ($confirmationSteps as $stepKey) {
            $line = $confirmations[$stepKey] ?? [];
            $decision = (int) ($line['decision'] ?? 0);

            if (! in_array($decision, [ExternalConfirmationDecision::Approved, ExternalConfirmationDecision::NotApplicable], true)) {
                $errors["confirmations.{$stepKey}.decision"] = __('recruitment.direct_open.decision_required');

                continue;
            }

            if ($decision === ExternalConfirmationDecision::NotApplicable) {
                // Saying a step did not apply is a claim, and a claim needs a
                // reason: this is the one line nobody confirmed.
                if (blank($line['not_applicable_reason'] ?? null)) {
                    $errors["confirmations.{$stepKey}.not_applicable_reason"] = __('recruitment.direct_open.not_applicable_reason_required');
                }

                continue;
            }

            if (blank($line['confirmer_user_id'] ?? null) && blank($line['confirmer_name'] ?? null)) {
                $errors["confirmations.{$stepKey}.confirmer_user_id"] = __('recruitment.direct_open.confirmer_required');
                // Backwards-compatible error key for older integrations.
                $errors["confirmations.{$stepKey}.confirmer_name"] = __('recruitment.direct_open.confirmer_required');
            }

            if (! in_array((int) ($line['method'] ?? 0), [
                ExternalConfirmationMethod::Phone, ExternalConfirmationMethod::Email,
                ExternalConfirmationMethod::Meeting, ExternalConfirmationMethod::Other,
            ], true)) {
                $errors["confirmations.{$stepKey}.method"] = __('recruitment.direct_open.method_required');
            }

            if (blank($line['confirmed_at'] ?? null)) {
                $errors["confirmations.{$stepKey}.confirmed_at"] = __('recruitment.direct_open.date_required');
            } elseif (Validator::make(
                ['confirmed_at' => $line['confirmed_at']],
                ['confirmed_at' => ['date_format:Y-m-d']],
            )->fails()) {
                $errors["confirmations.{$stepKey}.confirmed_at"] = __('validation.date_format', [
                    'attribute' => __('recruitment.direct_open.confirmed_at'),
                    'format' => 'YYYY-MM-DD',
                ]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Replace labels from the browser with snapshots of the selected people,
     * and enforce the same requester/position boundary used by normal drafts.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $documentation
     * @return array<string, mixed>
     */
    private function normaliseDocumentation(User $actor, array $data, array $documentation): array
    {
        $requesterId = filter_var(
            $documentation['actual_requester_user_id'] ?? null,
            FILTER_VALIDATE_INT,
        );

        if (! $requesterId) {
            return $documentation;
        }

        $requester = $this->requesterCandidates($actor)
            ->first(fn (User $candidate): bool => (int) $candidate->getKey() === (int) $requesterId);

        if ($requester === null) {
            throw ValidationException::withMessages([
                'actual_requester_user_id' => __('recruitment.direct_open.requester_outside_scope'),
            ]);
        }

        $requesterPosition = $this->scopes->position($requester);

        if ($requesterPosition === null) {
            throw ValidationException::withMessages([
                'actual_requester_user_id' => __('recruitment.direct_open.requester_without_position'),
            ]);
        }

        $targetField = $data['request_type'] === Type::CreatePosition ? 'direct_manager_id' : 'position_id';
        $targetId = (int) ($data[$targetField] ?? 0);
        $target = Position::query()->with('center')->find($targetId);

        if ((int) $data['request_type'] === Type::FillVacancy
            && $target !== null
            && $this->hasBlockingFillRequest($target)) {
            throw ValidationException::withMessages([
                'data.position_id' => __('recruitment.direct_open.position_already_open'),
            ]);
        }

        $allowedTarget = $this->positionsForRequester($actor, $requester, (int) $data['request_type'])
            ->whereKey($targetId)
            ->exists();

        if (! $allowedTarget) {
            throw ValidationException::withMessages([
                'data.'.$targetField => __('recruitment.direct_open.position_outside_requester_scope'),
            ]);
        }

        $documentation['actual_requester_user_id'] = (int) $requester->getKey();
        $documentation['actual_requester_name'] = $requester->profile?->name ?? $requester->email;
        $documentation['actual_requester_position_id'] = $requesterPosition->getKey();

        $cityId = $data['request_type'] === Type::CreatePosition
            ? (int) ($data['city_id'] ?? 0)
            : (int) ($target?->city_id ?? $target?->center?->city_id ?? 0);

        $errors = [];

        foreach ($this->confirmableStepsForRequester($requester, (int) $data['request_type']) as $stepKey) {
            $line = $documentation['confirmations'][$stepKey] ?? [];
            if ((int) ($line['decision'] ?? 0) !== ExternalConfirmationDecision::Approved
                || blank($line['confirmer_user_id'] ?? null)) {
                continue;
            }

            $confirmerId = filter_var($line['confirmer_user_id'], FILTER_VALIDATE_INT);
            $confirmer = $confirmerId
                ? $this->confirmationCandidates($requester, $stepKey, $cityId)
                    ->first(fn (User $candidate): bool => (int) $candidate->getKey() === (int) $confirmerId)
                : null;

            if ($confirmer === null) {
                $errors["confirmations.{$stepKey}.confirmer_user_id"] = __('recruitment.direct_open.confirmer_outside_flow');

                continue;
            }

            $documentation['confirmations'][$stepKey]['confirmer_user_id'] = (int) $confirmer->getKey();
            $documentation['confirmations'][$stepKey]['confirmer_name'] = $confirmer->profile?->name ?? $confirmer->email;
            $documentation['confirmations'][$stepKey]['confirmer_position_id'] = $this->scopes->position($confirmer)?->getKey();
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $documentation;
    }

    /**
     * Write the external lines, and say plainly what they are.
     *
     * `is_generated` marks them as the system's standard lines rather than
     * something HR added, and the event they raise is
     * ExternalConfirmationRecorded — never StepApproved. A reader of the
     * timeline must be able to tell a phone call from a click.
     *
     * @param  array<int, array<string, mixed>>  $confirmations
     */
    private function recordConfirmations(
        User $actor,
        RecruitmentRequest $request,
        array $confirmations,
        array $confirmationSteps,
    ): void {
        foreach ($confirmationSteps as $stepKey) {
            $line = $confirmations[$stepKey] ?? [];
            $decision = (int) ($line['decision'] ?? 0);
            $notApplicable = $decision === ExternalConfirmationDecision::NotApplicable;

            $request->externalConfirmations()->create([
                'step_key' => $stepKey,
                'is_generated' => true,
                'confirmer_name' => $notApplicable ? null : $line['confirmer_name'],
                'confirmer_user_id' => $notApplicable ? null : ($line['confirmer_user_id'] ?? null),
                'confirmer_position_id' => $line['confirmer_position_id'] ?? null,
                'decision' => $decision,
                'method' => $notApplicable ? null : (int) $line['method'],
                'confirmed_at' => $notApplicable ? null : $line['confirmed_at'],
                'not_applicable_reason' => $notApplicable ? $line['not_applicable_reason'] : null,
                'notes' => $line['notes'] ?? null,
                'recorded_by_user_id' => $actor->getKey(),
            ]);

            $request->events()->create([
                'event_type' => RecruitmentEventType::ExternalConfirmationRecorded,
                'actor_user_id' => $actor->getKey(),
                // The role as it was, never looked up when the event is read.
                'actor_role' => $actor->myRole() ?? 'system',
                'scope_type' => $this->scopes->scopeType($actor),
                'meta' => array_filter([
                    'step_key' => $stepKey,
                    'decision' => $decision,
                    'confirmer_name' => $notApplicable ? null : $line['confirmer_name'],
                    'confirmer_user_id' => $notApplicable ? null : ($line['confirmer_user_id'] ?? null),
                    'method' => $notApplicable ? null : (int) $line['method'],
                    'confirmed_at' => $notApplicable ? null : $line['confirmed_at'],
                    'not_applicable_reason' => $notApplicable ? $line['not_applicable_reason'] : null,
                ], fn ($value): bool => $value !== null),
            ]);
        }
    }
}
