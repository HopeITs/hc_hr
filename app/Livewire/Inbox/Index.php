<?php

namespace App\Livewire\Inbox;

use App\Enums\LeaveRequestStatus;
use App\Exceptions\LeaveDecisionAlreadyMade;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\LeaveRequestForm;
use App\Models\Checklist;
use App\Models\LeaveRequest;
use App\Services\ChecklistWorkflow;
use App\Services\LeaveDecisionAuthority;
use App\Services\LeaveNotificationService;
use App\Services\LeaveRequestNextStep;
use App\Services\LeaveRequestReview;
use App\Services\ProfileChangeRequests;
use App\Services\TeamCoverage;
use App\Services\WorkInbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The work inbox page (C3; approval-source-contract.md). Lists what waits on
 * the signed-in user (WorkInbox) and launches each action through the one
 * method that owns it. Every action re-reads the record and reports one of
 * the five outcomes of §4.1: done, already_handled, changed, not_allowed,
 * invalid. Rows are recomputed on every render from the active role, so a
 * role switch or someone else's decision never leaves a stale row actionable.
 */
class Index extends Component
{
    use WithFeedback;

    public const BATCH_MAX = 50;

    public LeaveRequestForm $form;

    /** all | decide | review | checklist */
    #[Url(as: 'show', except: 'all')]
    public string $filter = 'all';

    /** A row to open on arrival, e.g. "leave:812:decide" from a notification. */
    #[Url(as: 'open', except: '')]
    public string $open = '';

    /** Per-row inputs, keyed by the row slug ("leave-812-decide"). */
    public array $reasons = [];

    public array $notes = [];

    /** Checklist rows ticked for a batch confirmation. */
    public array $selected = [];

    /** The last outcome per row slug, shown on the row while it stays listed. */
    public array $outcomes = [];

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'decide', 'review', 'checklist', 'profile'], true) ? $filter : 'all';
    }

    // --- S1 leave decision ------------------------------------------------

    /** Accept (1) or reject (2) a pending leave request: LeaveRequestForm::submitDecision() decides. */
    public function decide(string $key, string $version, int $status): void
    {
        $this->resetErrorBag();
        $leave = $this->leaveFor($key, 'decide');

        if (! $leave) {
            return;
        }

        if ((int) $leave->status !== LeaveRequestStatus::Pending) {
            $this->report($key, 'already_handled', app(LeaveRequestNextStep::class)->alreadyDecided($leave, auth()->user()));

            return;
        }

        if (app(WorkInbox::class)->leaveVersion($leave) !== $version) {
            $this->report($key, 'changed', __('inbox.outcome.changed'));

            return;
        }

        $this->form->setDecisionLeave($leave);
        $this->form->status = $status;
        $this->form->reject_reason = trim((string) ($this->reasons[$this->slug($key)] ?? '')) ?: null;

        try {
            $saved = $this->form->submitDecision();
        } catch (LeaveDecisionAlreadyMade $e) {
            $this->report($key, 'already_handled', app(LeaveRequestNextStep::class)->alreadyDecided($e->leaveRequest, auth()->user()));

            return;
        } catch (AuthorizationException) {
            $this->report($key, 'not_allowed', __('inbox.outcome.not_allowed'));

            return;
        } catch (ValidationException $e) {
            throw $this->onRow($e, $key, ['form.reject_reason' => 'reasons', 'form.status' => 'reasons']);
        }

        app(LeaveNotificationService::class)->sendDecision($saved, auth()->user());
        $this->dispatch('notification-created');
        unset($this->reasons[$this->slug($key)]);
        $this->done($key, __((int) $saved->status === LeaveRequestStatus::Accepted ? 'inbox.outcome.accepted' : 'inbox.outcome.rejected'));
    }

    // --- S2 HR review and objection ---------------------------------------

    public function markSeen(string $key): void
    {
        $this->review($key, fn (LeaveRequest $leave, ?string $note) => app(LeaveRequestReview::class)->markSeen($leave->id, auth()->user(), $note), 'inbox.outcome.seen');
    }

    public function object(string $key): void
    {
        $this->review($key, fn (LeaveRequest $leave, ?string $note) => app(LeaveRequestReview::class)->object($leave->id, auth()->user(), $note), 'inbox.outcome.objected');
    }

    // --- S3/S4 checklist confirmation, S5 finalize --------------------------

    public function confirmItem(string $key): void
    {
        $outcome = $this->applyChecklist($key, trim((string) ($this->notes[$this->slug($key)] ?? '')) ?: null);
        $this->reportChecklist($key, $outcome);
    }

    /** D1: approve an employee's proposed profile change (HR of their city). */
    public function approveProfile(string $key): void
    {
        $this->resetErrorBag();
        [$id] = $this->parse($key, 'profile', ['approve']);

        try {
            $result = app(ProfileChangeRequests::class)->approve(auth()->user(), $id);
        } catch (AuthorizationException) {
            $this->report($key, 'not_allowed', __('inbox.outcome.not_allowed'));

            return;
        }

        $result === ProfileChangeRequests::ALREADY_HANDLED
            ? $this->report($key, 'already_handled', __('inbox.profile.already'))
            : $this->done($key, __('inbox.profile.approved'));
    }

    /** D1: reject it, with a reason the employee reads (required). */
    public function rejectProfile(string $key): void
    {
        $this->resetErrorBag();
        [$id] = $this->parse($key, 'profile', ['approve']);

        try {
            $result = app(ProfileChangeRequests::class)->reject(auth()->user(), $id, (string) ($this->notes[$this->slug($key)] ?? ''));
        } catch (AuthorizationException) {
            $this->report($key, 'not_allowed', __('inbox.outcome.not_allowed'));

            return;
        } catch (ValidationException $e) {
            throw $this->onRow($e, $key, ['reason' => 'notes']);
        }

        $result === ProfileChangeRequests::ALREADY_HANDLED
            ? $this->report($key, 'already_handled', __('inbox.profile.already'))
            : $this->done($key, __('inbox.profile.rejected'));
    }

    /** Close a returned item's cycle (HR). One by one (approval-source-contract.md §6.1). */
    public function finalizeItem(string $key): void
    {
        [$id] = $this->parse($key, 'checklist', ['finalize']);
        $item = Checklist::query()->with('employee')->find($id);

        if (! $item || ! $item->employee || ! Gate::allows('update', $item->employee)) {
            $this->report($key, 'not_allowed', __('inbox.outcome.not_allowed'));

            return;
        }

        try {
            app(ChecklistWorkflow::class)->finalizeReturn($item->id, $item->employee);
        } catch (HttpExceptionInterface) {
            $this->report($key, 'already_handled', __('inbox.outcome.already_confirmed'));

            return;
        }

        $this->done($key, __('inbox.outcome.finalized'));
    }

    /** Batch step 2: re-compute on the server what would happen, write nothing, ask to confirm. */
    public function previewBatch(): void
    {
        $eligible = $this->eligibleSelection();
        $gone = count($this->selected) - count($eligible);

        if ($eligible === []) {
            $this->selected = [];
            $this->toast(__('inbox.batch.nothing'), 'info');

            return;
        }

        $this->confirm(
            'applyBatch',
            $eligible,
            title: __('inbox.batch.title'),
            message: trans_choice('inbox.batch.preview', count($eligible), ['count' => count($eligible)])
                .($gone > 0 ? ' '.trans_choice('inbox.batch.gone', $gone, ['count' => $gone]) : ''),
            confirmLabel: __('inbox.batch.confirm'),
            danger: false,
        );
    }

    /**
     * Batch step 4: each item through its owner, one transaction each, no
     * all-or-nothing. Items that did not go through stay selected.
     *
     * @param  array<int, string>  $keys
     */
    public function applyBatch(array $keys): void
    {
        $keys = array_values(array_intersect($keys, $this->eligibleSelection($keys)));
        $batchId = (string) Str::uuid();
        $results = ['done' => [], 'already_handled' => [], 'not_allowed' => []];

        foreach (array_slice($keys, 0, self::BATCH_MAX) as $key) {
            $results[$this->applyChecklist($key, null, $batchId)][] = $key;
        }

        $this->selected = array_values(array_merge($results['already_handled'], $results['not_allowed']));
        $this->selected = array_values(array_intersect($this->selected, $this->eligibleSelection($this->selected)));

        $summary = collect([
            'done' => count($results['done']),
            'already_handled' => count($results['already_handled']),
            'not_allowed' => count($results['not_allowed']),
        ])->filter()->map(fn ($n, $outcome) => trans_choice("inbox.batch.result.{$outcome}", $n, ['count' => $n]))->implode(' · ');

        $this->dispatch('inbox-changed');
        $this->toast($summary, $results['done'] === [] ? 'info' : 'success');
    }

    public function render()
    {
        $failed = false;

        try {
            // Exact counts, the badge's (G-C-7); each source lists at most WorkInbox::PER_SOURCE.
            ['rows' => $rows, 'counts' => $counts] = app(WorkInbox::class)->snapshot(auth()->user());
        } catch (Throwable $e) {
            report($e);
            $rows = collect();
            $counts = ['all' => 0, 'decide' => 0, 'review' => 0, 'checklist' => 0, 'profile' => 0];
            $failed = true;
        }

        $visible = match ($this->filter) {
            'decide' => $rows->where('action', 'decide'),
            'review' => $rows->where('action', 'review'),
            'checklist' => $rows->where('source', 'checklist'),
            'profile' => $rows->where('source', 'profile'),
            default => $rows,
        };

        $shown = $visible->count();

        // Who the user decides for, on rows where HR stands in for the manager.
        $authority = app(LeaveDecisionAuthority::class);
        $nextStep = app(LeaveRequestNextStep::class);
        $visible = $visible->map(function (array $row) use ($authority, $nextStep) {
            if ($row['action'] === 'decide') {
                $leave = LeaveRequest::query()->with('leaveBalance.contract.position')->find($row['id']);
                $row['basis'] = $leave ? $authority->basisFor(auth()->user(), $leave) : null;
                $row['managers'] = $leave && $row['basis'] === LeaveDecisionAuthority::ON_BEHALF
                    ? $authority->directManagers($leave)->map->name->implode(__('leaves.next-step.list-separator'))
                    : null;
                // C5: others in the requester's team away on these days (advisory).
                $row['overlap'] = $leave ? $this->overlapFor($leave) : null;
            }
            if ($row['action'] === 'review') {
                // Who decided, from the stored basis, never assumed to be the manager (G-C-4).
                $leave = LeaveRequest::query()->find($row['id']);
                $row['decision'] = $leave ? $nextStep->decisionSummary($leave) : null;
            }
            $row['slug'] = $this->slug($row['key']);

            return $row;
        });

        return view('livewire.inbox.index', [
            'rows' => $visible->values(),
            'counts' => $counts,
            // Fewer listed than counted: some source reached its cap.
            'capped' => $counts[$this->filter] > $shown ? ['shown' => $shown, 'total' => $counts[$this->filter]] : null,
            'failed' => $failed,
            'showCity' => auth()->user()->activeCityIds() === null || count(auth()->user()->activeCityIds() ?? []) > 1,
        ]);
    }

    private function overlapFor(LeaveRequest $leave): ?array
    {
        try {
            return app(TeamCoverage::class)->teamOverlap($leave);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function review(string $key, callable $write, string $doneMessage): void
    {
        // A note fixed after a failed attempt must not keep the old error.
        $this->resetErrorBag();
        $leave = $this->leaveFor($key, 'review');

        if (! $leave) {
            return;
        }

        if ((int) $leave->status === LeaveRequestStatus::Pending || $leave->hr_approved) {
            $this->report($key, 'already_handled', __('inbox.outcome.already_reviewed'));

            return;
        }

        try {
            $write($leave, $this->notes[$this->slug($key)] ?? null);
        } catch (AuthorizationException) {
            // Locked and re-read: someone else got there first, or the role no longer may.
            $fresh = $leave->fresh();
            $handled = $fresh && ((int) $fresh->status === LeaveRequestStatus::Pending || $fresh->hr_approved) && Gate::allows('view', $fresh);
            $this->report($key, $handled ? 'already_handled' : 'not_allowed', __($handled ? 'inbox.outcome.already_reviewed' : 'inbox.outcome.not_allowed'));

            return;
        } catch (ValidationException $e) {
            throw $this->onRow($e, $key, ['note' => 'notes']);
        }

        $this->dispatch('notification-created');
        unset($this->notes[$this->slug($key)]);
        $this->done($key, __($doneMessage));
    }

    // --- helpers ------------------------------------------------------------

    /** done | already_handled | not_allowed */
    private function applyChecklist(string $key, ?string $note, ?string $batchId = null): string
    {
        [$id, $action] = $this->parse($key, 'checklist', ['deliver', 'receive']);
        $user = auth()->user();
        $permission = $action === 'deliver' ? 'checklists.deliver' : 'checklists.receive';

        if (! $user->employee_id || ! $user->hasActivePermission($permission)) {
            return 'not_allowed';
        }

        try {
            $action === 'deliver'
                ? app(ChecklistWorkflow::class)->markDelivered($id, $user->employee_id, $note, $batchId)
                : app(ChecklistWorkflow::class)->markReceived($id, $user->employee_id, $note, $batchId);
        } catch (HttpExceptionInterface) {
            // No match: already confirmed (still theirs) or not theirs at all.
            $item = Checklist::query()->find($id);
            $theirs = $item && (int) ($action === 'deliver' ? $item->delivered_id : $item->receiver_id) === (int) $user->employee_id;

            return $theirs ? 'already_handled' : 'not_allowed';
        }

        return 'done';
    }

    private function reportChecklist(string $key, string $outcome): void
    {
        match ($outcome) {
            'done' => $this->done($key, __('inbox.outcome.confirmed')),
            'already_handled' => $this->report($key, 'already_handled', __('inbox.outcome.already_confirmed')),
            default => $this->report($key, 'not_allowed', __('inbox.outcome.not_allowed')),
        };
    }

    /**
     * Selected keys that are still bulk-eligible rows of this user right now.
     *
     * @param  array<int, string>|null  $keys
     * @return array<int, string>
     */
    private function eligibleSelection(?array $keys = null): array
    {
        $bulkKeys = app(WorkInbox::class)->rows(auth()->user())->where('bulk', true)->pluck('key')->all();

        return array_values(array_slice(array_intersect(array_map('strval', $keys ?? $this->selected), $bulkKeys), 0, self::BATCH_MAX));
    }

    /** The leave request behind a key, or null after reporting not_allowed. */
    private function leaveFor(string $key, string $action): ?LeaveRequest
    {
        [$id] = $this->parse($key, 'leave', [$action]);
        $leave = LeaveRequest::query()->find($id);

        if (! $leave || ! Gate::allows('view', $leave)) {
            // Same answer whether it does not exist or may not be seen (A2-D).
            $this->report($key, 'not_allowed', __('inbox.outcome.not_allowed'));

            return null;
        }

        return $leave;
    }

    /** @return array{0: int, 1: string} */
    private function parse(string $key, string $source, array $actions): array
    {
        if (! preg_match('/^(leave|checklist|profile):(\d+):([a-z]+)$/', $key, $m) || $m[1] !== $source || ! in_array($m[3], $actions, true)) {
            abort(404);
        }

        return [(int) $m[2], $m[3]];
    }

    private function slug(string $key): string
    {
        return str_replace(':', '-', $key);
    }

    private function done(string $key, string $message): void
    {
        unset($this->outcomes[$this->slug($key)]);
        $this->selected = array_values(array_diff($this->selected, [$key]));
        $this->dispatch('inbox-changed');
        $this->toast($message);
    }

    private function report(string $key, string $outcome, string $message): void
    {
        $this->outcomes[$this->slug($key)] = ['outcome' => $outcome, 'message' => $message];
        // The row may have left the list (someone else handled it): the menu badge recounts (G-C-11 D-05).
        $this->dispatch('inbox-changed');
        $this->toast($message, $outcome === 'not_allowed' ? 'error' : 'info');
    }

    /** Re-key a domain validation error onto the row's own input (B2: field-linked, entries kept). */
    private function onRow(ValidationException $e, string $key, array $map): ValidationException
    {
        $messages = [];

        foreach ($e->errors() as $field => $errors) {
            $target = $map[$field] ?? null;
            $messages[$target ? $target.'.'.$this->slug($key) : $field] = $errors;
        }

        // The `invalid` outcome is shown on the field itself (B2), not repeated on the row.
        unset($this->outcomes[$this->slug($key)]);

        return ValidationException::withMessages($messages);
    }
}
