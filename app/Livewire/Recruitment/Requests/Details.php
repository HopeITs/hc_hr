<?php

namespace App\Livewire\Recruitment\Requests;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestStatus;
use App\Models\Center;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\HiringCampaign;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentWorkflowInstanceStep;
use App\Models\Vacancy;
use App\Services\RecruitmentApprovalService;
use App\Services\RecruitmentApprovalTrack;
use App\Services\RecruitmentEditableFields;
use App\Services\RecruitmentPendingApprovalQuery;
use App\Services\RecruitmentRequestApplicationService;
use App\Services\RecruitmentRequestSubmissionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Details extends Component
{
    #[Locked]
    public int $requestId;

    public ?int $versionId = null;

    /** The reason, or what needs changing — mandatory for both (D60). */
    public string $decisionComment = '';

    /**
     * The fields a request for changes unlocks.
     *
     * The request has been locked since it was submitted, so this is not a
     * hint: it is the whole of what the requester will be able to touch.
     *
     * @var array<int, string>
     */
    public array $requestedFields = [];

    /**
     * Whether the approver is part-way through asking for changes.
     *
     * Approving and rejecting are one click; asking for changes is a sentence
     * and a list of fields. Keeping those out of sight until the approver has
     * chosen that path stops the panel from asking for things three of its
     * buttons will never read.
     */
    public bool $askingForChanges = false;

    /**
     * Whether the approver is part-way through rejecting.
     *
     * A rejection is refused without its reason (D60), so the reason has to be
     * asked for before the decision is confirmed, not after it has failed.
     */
    public bool $rejecting = false;

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    /**
     * Submit (T3) or resubmit (T11).
     *
     * The service owns every rule; this only hands it the request and reports
     * what came back. Re-resolving through the visibility boundary first means
     * a stale page cannot act on a request the user has since lost.
     */
    public function submit(RecruitmentRequestSubmissionService $submissions): void
    {
        $request = $this->resolve();
        Gate::authorize('submit', $request);

        try {
            $submissions->submit(auth()->user(), $request);
        } catch (ValidationException $e) {
            // Every refusal the service raises is keyed to a form field —
            // data.position_id, data.salary_amount — and this screen is not the
            // form, so it has no outlet for any of them. The message went into
            // the error bag and nowhere else, and the button simply did nothing.
            // Saying it out loud is the only way the requester learns why.
            $this->refuse($e);

            return;
        }

        $this->toast(__('recruitment.submitted'));
    }

    /** Cancel while the request is still the requester's to withdraw. */
    public function cancelRequest(RecruitmentRequestSubmissionService $submissions): void
    {
        $submissions->cancel(auth()->user(), $this->resolve());

        $this->toast(__('recruitment.cancelled'));
    }

    public function render()
    {
        Gate::authorize('viewAny', RecruitmentRequest::class);
        // Resolve through the same visibility boundary on every Livewire update.
        $request = RecruitmentRequest::query()->visibleTo(auth()->user())
            ->with(['requester.profile', 'scopeCity', 'targetPosition', 'vacancy.position',
                'versions' => fn ($query) => $query->orderByDesc('version_no'),
                'events' => fn ($query) => $query->with(['actor.profile', 'requestVersion'])->orderBy('created_at')->orderBy('id'),
                'comments' => fn ($query) => $query->with('user.profile')->orderBy('created_at')->orderBy('id'),
                'externalConfirmations.recordedBy.profile'])
            ->findOrFail($this->requestId);
        Gate::authorize('view', $request);

        $version = $this->versionId === null
            ? $request->versions->first()
            : $request->versions()->findOrFail($this->versionId);
        $version?->load('positionDetail');

        $draftContext = [];
        if ($request->status === RecruitmentRequestStatus::Draft) {
            foreach ([
                'direct_manager_id' => ['parent_position', Position::class, 'title'],
                'program_id' => ['program', Program::class, 'name'],
                'center_id' => ['center', Center::class, 'name'],
                'department_id' => ['department', Department::class, 'name'],
                'job_level_id' => ['job_level', JobLevel::class, 'name'],
                'contract_type_id' => ['contract_type', ContractType::class, 'name'],
            ] as $field => [$label, $model, $attribute]) {
                $id = $request->draft_payload_json[$field] ?? null;
                if (is_numeric($id)) {
                    $draftContext[$label] = $model::find((int) $id)?->{$attribute} ?? '—';
                }
            }
        }

        $previousRejections = $this->previousRejections($request);
        // Present only when this user is the one the request is waiting on.
        $pendingStep = $this->pendingStep($request);

        // Approved is not the same as carried out. A reduction or a closure
        // dated ahead waits, and HR may bring it forward.
        $applications = app(RecruitmentRequestApplicationService::class);
        $awaitingExecution = $applications->isAwaitingEffectiveDate($request);
        $effectiveDate = $applications->effectiveDate($request);
        $mayExecuteNow = $awaitingExecution
            && auth()->user()?->hasActivePermission('recruitment-requests.manage');

        // What the approver may unlock, and what the requester currently may
        // touch. Both come from the same authority the write path uses.
        $editable = app(RecruitmentEditableFields::class);
        $selectableFields = $pendingStep
            ? $editable->selectable($request, (int) $pendingStep->workflowStep?->step_key)
            : [];
        $unlockedFields = $editable->requestedByApprover($request);
        $canCreateCampaign = $request->status === RecruitmentRequestStatus::Applied
            && $request->vacancy !== null
            && $request->vacancy->status === Vacancy::OPEN
            && $request->vacancy->closed_at === null
            && $request->vacancy->open_at?->isPast()
            && $request->vacancy->filled_count < $request->vacancy->needed_count
            && Gate::allows('create', [HiringCampaign::class, $request->vacancy]);

        // The road this request travels, shortened by whatever its own
        // workflow actually skips rather than by a style.
        $track = app(RecruitmentApprovalTrack::class)->for($request);

        return view('livewire.recruitment.requests.details', compact(
            'request', 'version', 'draftContext', 'previousRejections', 'pendingStep',
            'awaitingExecution', 'effectiveDate', 'mayExecuteNow',
            'selectableFields', 'unlockedFields', 'track', 'canCreateCampaign',
        ));
    }

    /**
     * Carry out an approved request before its effective date.
     *
     * HR's own decision, recorded as theirs: the event carries who brought it
     * forward and when, because a closure happening early is something a
     * person chose and not something the schedule did.
     */
    public function executeNow(RecruitmentRequestApplicationService $applications): void
    {
        $request = $this->resolve();
        Gate::authorize('execute', $request);

        $request = $applications->apply($request, auth()->user(), force: true);

        $applied = (int) $request->status === RecruitmentRequestStatus::Applied;

        $this->toast(
            __($applied ? 'recruitment.executed' : 'recruitment.execution_failed'),
            success: $applied,
        );
    }

    /** Open the second step: which fields, and why. */
    public function askForChanges(): void
    {
        $this->resetErrorBag();
        $this->askingForChanges = true;
    }

    /** Open the rejection's second step: the reason. */
    public function askToReject(): void
    {
        $this->resetErrorBag();
        $this->rejecting = true;
    }

    /** Step back to the three decisions, keeping nothing half-typed. */
    public function cancelChangeRequest(): void
    {
        $this->resetErrorBag();
        $this->askingForChanges = false;
        $this->rejecting = false;
        $this->requestedFields = [];
        $this->decisionComment = '';
    }

    /**
     * Decide the step this user is being waited on for.
     *
     * The step is resolved here rather than taken from the page, so a stale or
     * edited screen cannot aim a decision at a step that is no longer the one
     * in front of this user. The version number does come from the page, on
     * purpose: it is what check 6 compares against.
     */
    public function decide(string $action, RecruitmentApprovalService $approvals): void
    {
        $request = $this->resolve();
        $step = $this->pendingStep($request);

        if ($step === null) {
            $this->addError('step', __('recruitment.step_is_not_current'));

            return;
        }

        $versionNo = (int) $step->workflowInstance?->requestVersion?->version_no;
        $comment = trim($this->decisionComment);

        match ($action) {
            'approve' => $approvals->approve(auth()->user(), $step, $versionNo, $comment ?: null),
            'reject' => $approvals->reject(auth()->user(), $step, $versionNo, $comment),
            'request_changes' => $approvals->requestChanges(
                auth()->user(), $step, $versionNo, $comment, $this->requestedFields,
            ),
            default => throw new InvalidArgumentException('Unknown decision.'),
        };

        $this->decisionComment = '';
        $this->requestedFields = [];
        $this->askingForChanges = false;
        $this->rejecting = false;
        $this->toast(__('recruitment.decided'));
    }

    /**
     * Report a refusal the requester can act on.
     *
     * Every message, not the first: a submission can fail on several counts at
     * once — three unfilled fields, or a duplicate and a stale scope together —
     * and fixing one to be told about the next is a worse experience than being
     * told all of them now.
     */
    private function refuse(ValidationException $e): void
    {
        $messages = collect($e->errors())->flatten()->unique()->values();

        $this->toast($messages->implode(' '), false);
    }

    /**
     * Requests on this same position that were rejected before (D61).
     *
     * Rejection and cancellation release the position at once (D54), so the
     * same ask can come back a week later with nobody the wiser. The approver
     * is shown what happened last time, with its reason and its author — shown,
     * not blocked: he reads the history and decides.
     *
     * Read through the visibility boundary like everything else, so it cannot
     * become a window onto requests the viewer may not see.
     *
     * @return Collection<int, RecruitmentRequest>
     */
    private function previousRejections(RecruitmentRequest $request)
    {
        if ($request->target_position_id === null) {
            return collect();
        }

        return RecruitmentRequest::query()
            ->visibleTo(auth()->user())
            ->whereKeyNot($request->getKey())
            ->where('target_position_id', $request->target_position_id)
            ->where('status', RecruitmentRequestStatus::Rejected)
            ->with(['requester.profile', 'events' => fn ($events) => $events
                ->where('event_type', RecruitmentEventType::StepRejected)
                ->with('actor.profile')
                ->latest('created_at')
                ->limit(1)])
            ->latest('updated_at')
            ->limit(5)
            ->get();
    }

    /**
     * The step this user is the assigned approver of, if the request is
     * waiting on one. Null means there is nothing here for them to decide.
     */
    private function pendingStep(RecruitmentRequest $request): ?RecruitmentWorkflowInstanceStep
    {
        return app(RecruitmentPendingApprovalQuery::class)
            ->forUser(auth()->user())
            ->where('pending_requests.id', $request->getKey())
            ->first();
    }

    private function resolve(): RecruitmentRequest
    {
        $request = RecruitmentRequest::query()->visibleTo(auth()->user())->findOrFail($this->requestId);
        Gate::authorize('view', $request);

        return $request;
    }

    /**
     * Say what happened, the way the rest of the application says it.
     *
     * A toast rather than a coloured panel: the panel pushed the page down on
     * every save, and a red or green block across a form reads as part of the
     * form rather than as a remark about it.
     */
    private function toast(string $message, bool $success = true): void
    {
        $alert = LivewireAlert::title($message);

        ($success ? $alert->success() : $alert->error())
            ->toast()
            ->position('top-start')
            ->show();
    }
}
