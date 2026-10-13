<?php

namespace App\Services;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestStatus as Status;
use App\Enums\RecruitmentRequestType as Type;
use App\Enums\RecruitmentScopeType;
use App\Models\Position;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentRequestVersion;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Turns a draft into a submitted request, and freezes what was submitted.
 *
 * A draft is allowed to be half-finished — that is the point of a draft. Every
 * rule the request type carries is therefore enforced here, at the moment the
 * request stops being the requester's private working copy and becomes
 * something other people are asked to decide on.
 */
class RecruitmentRequestSubmissionService
{
    /** Statuses that are still being worked on, so they hold the slot (T3). */
    private const ACTIVE = [Status::Submitted, Status::UnderReview, Status::ChangesRequested, Status::Resubmitted];

    /** The requester may still edit in these. */
    private const EDITABLE = [Status::Draft, Status::ChangesRequested];

    public function __construct(
        private readonly RecruitmentScopeResolver $scopes,
        private readonly RecruitmentRequestStateMachine $states,
        private readonly RecruitmentRequestRevalidator $revalidator,
        private readonly RecruitmentWorkflowInstanceService $workflows,
    ) {}

    /**
     * Submit (T3) or resubmit (T11).
     *
     * Idempotent by design and without a stored key: the row is locked, then
     * the status is read again inside the lock. A second click, a retry after a
     * timeout, or two concurrent requests all find the status already moved on
     * and get the same request back rather than a second version. The unique
     * index on (request_id, version_no) is the last net under that.
     */
    public function submit(User $actor, RecruitmentRequest $request): RecruitmentRequest
    {
        $submitted = $this->submitWithin($actor, $request);

        // Only now, with the request and its workflow committed, is there
        // anything true to tell anybody. Sending from inside the transaction
        // would mean a rollback leaving somebody holding a notice about a
        // request that does not exist.
        app(RecruitmentNotifier::class)->workflowStarted(
            $submitted,
            $submitted->workflowInstances()->latest('id')->first(),
        );

        return $submitted;
    }

    /** Cancel a request the requester still owns (T12 · T14 · T15). */
    public function cancel(User $actor, RecruitmentRequest $request, ?string $reason = null): RecruitmentRequest
    {
        return DB::transaction(function () use ($actor, $request, $reason) {
            $fresh = RecruitmentRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $fresh->requester_user_id !== (int) $actor->getKey()) {
                throw ValidationException::withMessages(['request' => __('recruitment.not_your_request')]);
            }

            // Cancelling twice is the same retry shape as submitting twice.
            if ((int) $fresh->status === Status::Cancelled) {
                return $fresh;
            }

            Gate::forUser($actor)->authorize('cancel', $fresh);

            $from = (int) $fresh->status;
            // T12 · T14 · T15 — the table decides which of the three applies.
            $this->states->apply($fresh, 'cancel');

            $this->record($fresh, $actor, [
                'event_type' => RecruitmentEventType::RequestCancelled,
                'from_status' => $from,
                'to_status' => Status::Cancelled,
                'comment' => $reason,
            ]);

            return $fresh;
        });
    }

    /**
     * Freeze the payload as version N+1 with its position details.
     *
     * Approvals attach to a version, so the version is what must not move. The
     * details row carries the column names RV-1 compares field by field, which
     * is why the draft's own field names are mapped here rather than stored
     * loosely: a field that never lands in a column is a change that silently
     * invalidates nothing.
     */
    public function freezeVersion(RecruitmentRequest $request, User $actor, array $payload): RecruitmentRequestVersion
    {
        $next = (int) $request->versions()->max('version_no') + 1;

        $version = $request->versions()->create([
            'version_no' => $next,
            'snapshot_json' => $payload,
            'created_by_user_id' => $actor->getKey(),
            'submitted_at' => now(),
        ]);

        $version->positionDetail()->create($this->positionDetails($request, $payload));

        return $version;
    }

    private function submitWithin(User $actor, RecruitmentRequest $request): RecruitmentRequest
    {
        return DB::transaction(function () use ($actor, $request) {
            $fresh = RecruitmentRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $fresh->requester_user_id !== (int) $actor->getKey()) {
                throw ValidationException::withMessages(['request' => __('recruitment.not_your_request')]);
            }

            // The retry path, checked before the policy runs: once the status has
            // moved on, the submit policy denies — correctly, since the request is
            // no longer editable — and a retry would surface that denial as a
            // failure when the work had in fact succeeded. The requester gets his
            // own request back instead.
            if (! in_array((int) $fresh->status, self::EDITABLE, true)) {
                return $fresh;
            }

            Gate::forUser($actor)->authorize('submit', $fresh);

            $wasDraft = (int) $fresh->status === Status::Draft;
            $payload = (array) ($fresh->draft_payload_json ?? []);

            $this->assertComplete($fresh, $payload);
            if ((int) $fresh->mode === \App\Enums\RecruitmentRequestMode::Normal) {
                app(RecruitmentRequestDraftService::class)->assertCurrentScope($actor, $fresh);
            }
            $this->revalidator->assert($fresh, $payload);
            $this->assertNoActiveDuplicate($fresh);

            $version = $this->freezeVersion($fresh, $actor, $payload);

            // T3 and T11 go through the table like every other move.
            $this->states->apply($fresh, $wasDraft ? 'submit' : 'resubmit');
            $fresh->forceFill(['submitted_at' => now()])->save();

            $this->record($fresh, $actor, [
                'event_type' => $wasDraft ? RecruitmentEventType::RequestSubmitted : RecruitmentEventType::VersionCreated,
                'request_version_id' => $version->getKey(),
                'from_status' => $wasDraft ? Status::Draft : Status::ChangesRequested,
                'to_status' => (int) $fresh->status,
            ]);

            // Freeze and start the approval workflow from this exact submitted
            // version before the transaction commits. This keeps submission,
            // versioning, status, and approvals atomic and retry-safe.
            $this->workflows->start($fresh->fresh(), $version);

            return $fresh;
        });
    }

    /**
     * Every field the request type calls mandatory must be present.
     *
     * The lists come straight from the request type matrix. A draft may be
     * missing any of them; a submission may not.
     */
    private function assertComplete(RecruitmentRequest $request, array $payload): void
    {
        $missing = [];

        foreach ($this->requiredFields((int) $request->request_type) as $field) {
            $value = $payload[$field] ?? null;

            if ($value === null || $value === '') {
                $missing['data.'.$field] = __('recruitment.required_on_submit', ['field' => __('recruitment.'.$field)]);
            }
        }

        // Picking "other" without saying what it is answers nothing.
        if (($payload['recruitment_reason'] ?? null) === 'other' && blank($payload['other_reason'] ?? null)) {
            $missing['data.other_reason'] = __('recruitment.required_on_submit', ['field' => __('recruitment.other_reason')]);
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /** @return array<int, string> */
    private function requiredFields(int $type): array
    {
        $money = ['salary_amount', 'currency', 'salary_per', 'contract_type_id'];

        // Everything the paper form marks with a star for a recruiting request.
        $recruiting = array_merge([
            'recruitment_reason', 'priority', 'volunteer_type',
            'weekly_hours', 'needed_by_date',
        ], $money);

        return match ($type) {
            Type::FillVacancy => array_merge(['volunteers_required'], $recruiting),
            Type::IncreaseHeadcount => array_merge(['additional_count'], $recruiting),
            Type::DecreaseHeadcount => ['reduce_by', 'reason', 'effective_date'],
            Type::CreatePosition => array_merge([
                'program_id', 'city_id', 'department_id', 'job_level_id',
                'title_en', 'title_ar', 'qualifications', 'responsibilities',
                'max_emp', 'direct_manager_id',
            ], $recruiting),
            Type::ClosePosition => ['reason', 'effective_date'],
            Type::CloseVacancy => ['reason', 'effective_date'],
            default => [],
        };
    }

    /**
     * One active request per subject per type.
     *
     * A fill request tied to a specific vacancy uses that vacancy as its
     * subject. Every other request uses the position. Filling an unassigned
     * seat and removing an approved empty seat from the same position are
     * contradictory intentions and are refused against each other.
     *
     * Rejected and cancelled requests are final and release the subject at once,
     * which is why they are not in self::ACTIVE.
     */
    private function assertNoActiveDuplicate(RecruitmentRequest $request): void
    {
        $type = (int) $request->request_type;
        $vacancyScoped = $type === Type::FillVacancy;

        $base = RecruitmentRequest::query()
            ->whereKeyNot($request->getKey())
            ->whereIn('status', self::ACTIVE);

        if ($vacancyScoped && $request->target_vacancy_id !== null) {
            // Same intention twice, or the opposite intention, on one vacancy.
            $clash = (clone $base)
                ->where('target_vacancy_id', $request->target_vacancy_id)
                ->whereIn('request_type', [Type::FillVacancy, Type::CloseVacancy])
                ->first(['request_number']);

            if ($clash !== null) {
                throw ValidationException::withMessages([
                    'data.vacancy_id' => __('recruitment.duplicate_vacancy_request', [
                        'number' => $clash->request_number,
                    ]),
                ]);
            }

            return;
        }

        if ($request->target_position_id === null) {
            return;
        }

        $positionTypes = in_array($type, [Type::FillVacancy, Type::CloseVacancy], true)
            ? [Type::FillVacancy, Type::CloseVacancy]
            : [$type];

        $clash = $base
            ->where('target_position_id', $request->target_position_id)
            ->whereIn('request_type', $positionTypes)
            ->first(['request_number']);

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'data.position_id' => __('recruitment.duplicate_position_request', [
                    'number' => $clash->request_number,
                ]),
            ]);
        }
    }

    /**
     * Map the draft's field names onto the detail columns.
     *
     * effective_date folds into expected_start_date, the two never being asked
     * for by the same request type. tech_manager_id is dropped: D16 took it out
     * of the approval path, so carrying it would be an unclassified field under
     * RV-1.
     *
     * @return array<string, mixed>
     */
    private function positionDetails(RecruitmentRequest $request, array $payload): array
    {
        $current = $request->target_position_id === null ? null
            : (int) Position::query()->whereKey($request->target_position_id)->value('max_emp');

        $requested = match ((int) $request->request_type) {
            Type::IncreaseHeadcount => $current + (int) ($payload['additional_count'] ?? 0),
            Type::DecreaseHeadcount => $current - (int) ($payload['reduce_by'] ?? 0),
            Type::CloseVacancy => $current - 1,
            Type::CreatePosition => (int) ($payload['max_emp'] ?? 1),
            default => $current,
        };

        return [
            'position_id' => $request->target_position_id,
            'program_id' => $payload['program_id'] ?? null,
            'center_id' => $payload['center_id'] ?? null,
            'department_id' => $payload['department_id'] ?? null,
            'job_level_id' => $payload['job_level_id'] ?? null,
            'contract_type_id' => $payload['contract_type_id'] ?? null,
            'direct_supervisor_position_id' => $payload['direct_manager_id'] ?? null,
            'current_headcount' => $current,
            'requested_headcount' => $requested,
            'recruitment_reason' => $payload['recruitment_reason'] ?? null,
            'other_reason' => $payload['other_reason'] ?? null,
            'priority' => $payload['priority'] ?? null,
            'volunteer_type' => $payload['volunteer_type'] ?? null,
            'weekly_hours' => $payload['weekly_hours'] ?? null,
            'volunteer_period_start_date' => $payload['volunteer_period_start_date'] ?? null,
            'volunteer_period_end_date' => $payload['volunteer_period_end_date'] ?? null,
            'role_title_en' => $payload['title_en'] ?? null,
            'role_title_ar' => $payload['title_ar'] ?? null,
            'expected_start_date' => $payload['needed_by_date'] ?? $payload['effective_date'] ?? null,
            'salary_amount' => $payload['salary_amount'] ?? null,
            'currency' => $payload['currency'] ?? null,
            'salary_per' => $payload['salary_per'] ?? null,
            'main_duties' => $payload['responsibilities'] ?? null,
            'required_qualifications' => $payload['qualifications'] ?? null,
            'detailed_explanation' => $payload['detailed_explanation'] ?? null,
            'reason' => $payload['reason'] ?? null,
        ];
    }

    /** D8: the timeline must not be able to roll back the thing it describes. */
    private function record(RecruitmentRequest $request, User $actor, array $attributes): void
    {
        try {
            DB::transaction(fn () => $request->events()->create(array_merge([
                'actor_user_id' => $actor->getKey(),
                'actor_role' => $actor->myRole(),
                'scope_type' => $this->scopes->scopeType($actor),
                'scope_id' => $this->scopes->scopeType($actor) === RecruitmentScopeType::DirectReports
                    ? $this->scopes->position($actor)?->getKey() : null,
            ], $attributes)));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
