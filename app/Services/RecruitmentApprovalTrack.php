<?php

namespace App\Services;

use App\Enums\ApprovalDecision;
use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus as Status;
use App\Enums\WorkflowInstanceStatus;
use App\Enums\WorkflowSkipReason;
use App\Enums\WorkflowStepKey;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentRequestVersion;

/**
 * A request's history, told one version at a time.
 *
 * A request sent back for changes does not carry on where it left off — it
 * becomes a new version and takes the road again from the start (D58). Its
 * history is therefore not one long line but several short ones, and telling
 * it as a single stream leaves the reader working out for themselves which
 * approval belonged to which draft.
 *
 * So each version gets its own track: the stops it passed, who decided each
 * one, what the requester changed in it, and how it ended. A version that
 * ended in a request for changes says who asked, why, and which fields they
 * opened — which is exactly the reason the next version exists.
 *
 * The stops come from the workflow that actually ran, so a requester with
 * nobody above them has no manager stop and a direct opening has one. The
 * shortening is in the data rather than in a style that greys something out.
 */
class RecruitmentApprovalTrack
{
    public const DONE = 'done';

    public const CURRENT = 'current';

    public const SKIPPED = 'skipped';

    public const PENDING = 'pending';

    /**
     * Every version of this request, newest first, each with its own road.
     *
     * Newest first because that is the one being worked on. The older ones
     * answer "what did they ask for last time", which is a question asked
     * after the current state rather than before it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function for(RecruitmentRequest $request): array
    {
        $request->loadMissing([
            'versions', 'requester.profile',
            'workflowInstances.steps.workflowStep', 'workflowInstances.steps.actedBy.profile',
        ]);

        $versions = $request->versions->sortByDesc('version_no')->values();

        // A draft has no version yet, but it still has a road ahead of it and
        // a reader who wants to see what they are about to submit into.
        if ($versions->isEmpty()) {
            return [$this->round($request, null)];
        }

        return $versions
            ->map(fn (RecruitmentRequestVersion $version): array => $this->round($request, $version))
            ->all();
    }

    /**
     * One version, and everything that happened to it.
     *
     * @return array<string, mixed>
     */
    private function round(RecruitmentRequest $request, ?RecruitmentRequestVersion $version): array
    {
        $instance = $version === null
            ? null
            : $request->workflowInstances->firstWhere('request_version_id', $version->getKey());

        return [
            'version' => $version?->version_no,
            'created' => ($version?->submitted_at ?? $version?->created_at)?->format('Y-m-d H:i'),
            'author' => $request->requester?->profile?->name ?? $request->requester?->name,
            'stops' => $this->stops($request, $instance),
            'edits' => $version === null ? [] : $this->edits($request, $version),
            'outcome' => $this->outcome($request, $version, $instance),
        ];
    }

    /**
     * The approval stops this version passed, in order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function stops(RecruitmentRequest $request, $instance): array
    {
        if ($instance === null) {
            return $this->expectedStops($request);
        }

        $current = (int) $instance->current_step_order;
        $running = (int) $instance->status === WorkflowInstanceStatus::Running;

        // Which version this run belonged to. Steps are rows of a shared
        // workflow definition, so the same step id appears in every version's
        // run; without the version, every round would show the newest round's
        // decision.
        $versionId = $instance->request_version_id === null ? null : (int) $instance->request_version_id;

        return $instance->steps
            ->sortBy(fn ($step) => (int) $step->workflowStep?->step_order)
            ->map(function ($step) use ($current, $running, $request, $versionId): array {
                $decision = $step->decision === null ? null : (int) $step->decision;
                $state = $this->stepState($step, $decision, (int) $step->workflowStep?->step_order, $current, $running);

                $event = $this->decisionEvent($request, $step, $decision, $versionId);

                return [
                    'label' => WorkflowStepKey::getLocalizedName((int) $step->workflowStep?->step_key)['value'],
                    'state' => $state,
                    'decision' => $decision,
                    'at' => $step->acted_at?->format('Y-m-d H:i'),
                    'by' => $step->actedBy?->profile?->name ?? $step->actedBy?->name,
                    // The role as it was recorded, never looked up from the
                    // person today: reading an old decision through this
                    // week's roles would misstate who made it.
                    'role' => $event?->actor_role,
                    'comment' => $state === self::SKIPPED ? $this->skipNote($step) : $step->decision_reason,
                    'fields' => $this->fieldsFrom($event, $decision),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Where a stop stands.
     *
     * A step with no decision is only "waiting" while its workflow is still
     * running. Once the run was cancelled or rejected, the steps it never
     * reached are simply steps it never reached.
     */
    private function stepState($step, ?int $decision, int $order, int $current, bool $running): string
    {
        if ($decision === null && str_starts_with((string) $step->decision_reason, 'skip:')) {
            return self::SKIPPED;
        }

        if ($decision !== null) {
            return self::DONE;
        }

        return $running && $order === $current ? self::CURRENT : self::PENDING;
    }

    /**
     * The road a draft is about to take, before any workflow exists.
     *
     * @return array<int, array<string, mixed>>
     */
    private function expectedStops(RecruitmentRequest $request): array
    {
        $keys = (int) $request->mode === RecruitmentRequestMode::DirectHr
            ? [WorkflowStepKey::HrSyriaDirectOpenApproval]
            : [WorkflowStepKey::ManagerApproval, WorkflowStepKey::FinanceApproval, WorkflowStepKey::GovernorateHrApproval];

        $scopes = app(RecruitmentScopeResolver::class);
        $requester = $request->requester;

        $skips = $requester === null ? [] : array_values(array_filter([
            $scopes->mayRequestWithoutManagerApproval($requester) ? WorkflowStepKey::ManagerApproval : null,
            $scopes->isFinanceApprover($requester) ? WorkflowStepKey::FinanceApproval : null,
        ], fn (?int $key): bool => $key !== null));

        return collect($keys)
            ->reject(fn (int $key): bool => in_array($key, $skips, true))
            ->map(fn (int $key): array => [
                'label' => WorkflowStepKey::getLocalizedName($key)['value'],
                'state' => self::PENDING,
                'decision' => null,
                'at' => null,
                'by' => null,
                'role' => null,
                'comment' => null,
                'fields' => [],
            ])
            ->values()
            ->all();
    }

    /**
     * What the requester changed to arrive at this version.
     *
     * Edit events carry no version of their own — an edit happens while the
     * next version is still being written — so they are placed by time: an
     * edit belongs to the first version frozen after it, and anything edited
     * since the last version belongs to that one, because that is the draft
     * being worked on.
     *
     * RV-1b: "the request was edited" without naming the fields tells an
     * approver nothing about whether their own approval still stands.
     *
     * @return array<int, array<string, mixed>>
     */
    private function edits(RecruitmentRequest $request, RecruitmentRequestVersion $version): array
    {
        $previous = $request->versions
            ->where('version_no', '<', $version->version_no)
            ->max('created_at');

        $isLatest = (int) $version->version_no === (int) $request->versions->max('version_no');

        return $request->events()
            ->where('event_type', RecruitmentEventType::RequestEdited)
            ->when($previous !== null, fn ($query) => $query->where('created_at', '>', $previous))
            // The newest version keeps collecting: a request sent back is
            // being edited now, and those edits have no later version yet.
            ->when(! $isLatest, fn ($query) => $query->where('created_at', '<=', $version->created_at))
            ->orderBy('id')
            ->get()
            ->flatMap(function ($event): array {
                $changes = $event->meta['changes'] ?? [];

                return collect(is_array($changes) ? $changes : [])
                    ->map(fn (array $change, string $field): array => [
                        'field' => $field,
                        'from' => $change['from'] ?? null,
                        'to' => $change['to'] ?? null,
                    ])
                    ->values()
                    ->all();
            })
            ->all();
    }

    /**
     * How this version ended, which is where the next one began.
     *
     * The interesting case is a version sent back for changes: it names who
     * asked, why, and what they opened — and the version after it exists
     * because of that answer.
     *
     * @return array<string, mixed>
     */
    private function outcome(RecruitmentRequest $request, ?RecruitmentRequestVersion $version, $instance): array
    {
        $latest = (int) $request->versions->max('version_no');
        $isLatest = $version === null || (int) $version->version_no === $latest;

        // Only the current version can still be in flight; an older one ended
        // when it was superseded, usually by being sent back.
        if (! $isLatest) {
            return $this->changeRequest($request, $version) ?? $this->plainOutcome(
                __('recruitment.track.superseded'), self::DONE,
            );
        }

        $status = (int) $request->status;

        if ($status === Status::ChangesRequested) {
            $sentBack = $this->changeRequest($request, $version);

            if ($sentBack !== null) {
                return $sentBack;
            }
        }

        [$label, $state] = match ($status) {
            Status::Applied => [__('recruitment.track.applied'), self::DONE],
            Status::Approved, Status::FailedApplication => [__('recruitment.track.approved'), self::CURRENT],
            Status::Rejected => [__('recruitment.track.rejected'), self::DONE],
            Status::Cancelled => [__('recruitment.track.cancelled'), self::DONE],
            Status::ChangesRequested => [__('recruitment.track.changes_requested'), self::CURRENT],
            Status::Draft => [__('recruitment.track.draft'), self::PENDING],
            default => [__('recruitment.track.applied'), self::PENDING],
        };

        return $this->plainOutcome($label, $state, $request->applied_at?->format('Y-m-d H:i'));
    }

    /** @return array<string, mixed> */
    private function plainOutcome(string $label, string $state, ?string $at = null): array
    {
        return [
            'label' => $label,
            'state' => $state,
            'at' => $at,
            'by' => null,
            'role' => null,
            'comment' => null,
            'fields' => [],
        ];
    }

    /**
     * The moment this version was sent back, if it was.
     *
     * @return array<string, mixed>|null
     */
    private function changeRequest(RecruitmentRequest $request, ?RecruitmentRequestVersion $version): ?array
    {
        if ($version === null) {
            return null;
        }

        $event = $request->events()
            ->where('event_type', RecruitmentEventType::ChangesRequested)
            ->where('request_version_id', $version->getKey())
            ->with('actor.profile')
            ->latest('id')
            ->first();

        if ($event === null) {
            return null;
        }

        $fields = $event->meta['requested_fields'] ?? [];

        return [
            'label' => __('recruitment.track.changes_requested'),
            'state' => self::DONE,
            'at' => $event->created_at?->format('Y-m-d H:i'),
            'by' => $event->actor?->profile?->name ?? $event->actor?->name,
            'role' => $event->actor_role,
            'comment' => $event->comment,
            'fields' => is_array($fields) ? array_values($fields) : [],
        ];
    }

    /**
     * Why a step was skipped, in words rather than a code.
     *
     * D57 keeps the reason in `decision_reason` as `skip:<n>` because there is
     * no column for it. A reader of the page should not have to know that.
     */
    private function skipNote($step): string
    {
        $reason = (int) str_replace('skip:', '', (string) $step->decision_reason);

        return match ($reason) {
            WorkflowSkipReason::TopLevelRequester => __('recruitment-workflow.top_level_manager_skipped'),
            WorkflowSkipReason::RequesterIsApprover => __('recruitment-workflow.requester_is_approver_skipped'),
            WorkflowSkipReason::SelfApprovalEscalated => __('recruitment-workflow.self_approval_escalated'),
            default => __('recruitment.track.states.skipped'),
        };
    }

    /**
     * The event that recorded this step's decision.
     *
     * The step says who acted and when; the event says what role they held at
     * the time and which fields they opened. Both halves of a decision live in
     * different tables, so the one that carries the audit detail is read here.
     */
    private function decisionEvent(RecruitmentRequest $request, $step, ?int $decision, ?int $versionId)
    {
        if ($decision === null) {
            return null;
        }

        return $request->events()
            ->whereIn('event_type', [
                RecruitmentEventType::StepApproved,
                RecruitmentEventType::StepRejected,
                RecruitmentEventType::ChangesRequested,
            ])
            ->where('workflow_step_id', $step->workflow_step_id)
            // Scoped to this version's run: the step id is the definition's,
            // shared by every version, so without this each round would read
            // the newest round's decision.
            ->when($versionId !== null, fn ($query) => $query->where('request_version_id', $versionId))
            ->with('actor.profile')
            ->latest('id')
            ->first();
    }

    /**
     * The fields an approver opened when they sent a request back.
     *
     * Read from the event rather than the step: that is where the ask was
     * recorded, and the two must not be able to disagree (D69).
     *
     * @return array<int, string>
     */
    private function fieldsFrom($event, ?int $decision): array
    {
        if ($decision !== ApprovalDecision::RequestChanges || $event === null) {
            return [];
        }

        $fields = $event->meta['requested_fields'] ?? [];

        return is_array($fields) ? array_values($fields) : [];
    }
}
