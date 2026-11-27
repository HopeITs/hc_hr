<?php

namespace App\Services;

use App\Models\Checklist;
use App\Models\DefaultDeliverable;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\ChecklistAssigned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Facades\Activity;

/**
 * The only place checklist delivery/return state may change.
 *
 * The components previously resolved the record with a bare
 * Checklist::findOrFail($id) and then wrote to it. The list *above* the buttons
 * was correctly scoped to the signed-in employee, which made the flaw easy to
 * miss: the read was safe, the write was global. Anyone able to reach the page
 * could post any checklist id and mark another employee's equipment delivered,
 * returned, or deleted.
 *
 * Every transition here shares one shape:
 *
 *   - the row is located *through* the relationship that authorizes it
 *     (assignee for delivery, receiver for return, edited employee for the HR
 *     workflow), so a foreign id simply does not match;
 *   - the expected source status is part of that same query, so a transition
 *     cannot skip a step or run twice;
 *   - the lookup happens inside a transaction with lockForUpdate, so two
 *     concurrent submissions serialize and the loser matches nothing;
 *   - the actor, the record, and both states are written to the audit log.
 */
class ChecklistWorkflow
{
    /**
     * The work inbox's batch (C3, approval-source-contract.md §6.2): each item
     * is still confirmed here one by one, and its audit entry carries the
     * batch id so the batch can be traced. Null outside a batch.
     */
    private ?string $batchId = null;

    /**
     * Put an item on a deliverer's list: a pending row for this volunteer and
     * item. A row still pending moves to the new deliverer; one already
     * delivered is never touched. Returns the row only when something changed,
     * so the caller notifies once.
     */
    public function assignForDelivery(Employee $volunteer, int $itemId, Employee $deliverer): ?Checklist
    {
        $row = DB::transaction(function () use ($volunteer, $itemId, $deliverer): ?Checklist {
            $checklist = Checklist::query()
                ->where('employee_id', $volunteer->id)
                ->where('item_id', $itemId)
                ->lockForUpdate()
                ->first();

            if ($checklist === null) {
                $checklist = Checklist::query()->create([
                    'employee_id' => $volunteer->id,
                    'item_id' => $itemId,
                    'delivered_id' => $deliverer->id,
                    'delivered_status' => 'pending',
                ]);

                $this->record($checklist, 'checklist_assigned', 'delivered_id', null, (string) $deliverer->id);

                return $checklist;
            }

            if ($checklist->delivered_status !== 'pending' || (int) $checklist->delivered_id === $deliverer->id) {
                return null;
            }

            $from = $checklist->delivered_id;
            $checklist->update(['delivered_id' => $deliverer->id]);
            $this->record($checklist, 'checklist_reassigned', 'delivered_id', $from === null ? null : (string) $from, (string) $deliverer->id);

            return $checklist;
        });

        if ($row !== null) {
            User::query()->where('employee_id', $deliverer->id)->first()?->notify(new ChecklistAssigned($row->load(['employee', 'item']), 'deliver'));
        }

        return $row;
    }

    /**
     * The assignee confirms they handed the item over.
     */
    public function markDelivered(int $checklistId, int $actorEmployeeId, ?string $notes, ?string $batchId = null): Checklist
    {
        $this->batchId = $batchId;

        $checklist = $this->transition(
            fn (Builder $query) => $query
                ->where('delivered_id', $actorEmployeeId)
                ->where('delivered_status', 'pending'),
            $checklistId,
            [
                'delivered_status' => 'delivered',
                'delivered_at' => now(),
                'notes' => $notes,
            ],
            'delivered_status',
            'checklist_delivered',
        );

        // The onboarding journey reads deliveries from here: it recomputes its own
        // completion (it owns that write), once the delivery is committed.
        if ($checklist->employee) {
            app(OnboardingJourneys::class)->settle($checklist->employee);
        }

        return $checklist;
    }

    /**
     * The nominated receiver confirms the item came back.
     */
    public function markReceived(int $checklistId, int $actorEmployeeId, ?string $notes, ?string $batchId = null): Checklist
    {
        $this->batchId = $batchId;

        return $this->transition(
            fn (Builder $query) => $query
                ->where('receiver_id', $actorEmployeeId)
                ->where('returned_status', 'pending'),
            $checklistId,
            [
                'returned_status' => 'received',
                'returned_at' => now(),
                'notes' => $notes,
            ],
            'returned_status',
            'checklist_received',
        );
    }

    /**
     * HR starts a return cycle from the employee's edit screen.
     *
     * Scoped to the employee actually being edited, so the checklist id in the
     * payload cannot be swapped for one belonging to a different employee — or
     * a different city, since reaching that edit screen is already gated by
     * EmployeePolicy::update.
     */
    public function initiateReturn(int $checklistId, Employee $employee, int $receiverId): Checklist
    {
        return $this->transition(
            fn (Builder $query) => $query
                ->where('employee_id', $employee->id)
                ->where('delivered_status', 'delivered')
                // Not "whereNot('returned_status', 'received')" — that compiles
                // to "NOT (returned_status = 'received')", which SQL evaluates
                // to NULL (excluded) for a row that has never been through a
                // return cycle yet, i.e. every freshly delivered item.
                ->where(fn (Builder $q) => $q
                    ->whereNull('returned_status')
                    ->orWhere('returned_status', '!=', 'received')),
            $checklistId,
            [
                'receiver_id' => $receiverId,
                'returned_status' => 'pending',
                'returned_at' => now(),
            ],
            'returned_status',
            'checklist_return_initiated',
        );
    }

    /**
     * A return still waiting goes to someone else (the one who was to take it
     * back left: Access\SeatSuccession). Returns the row only when it moved.
     */
    public function reassignReturn(int $checklistId, Employee $receiver): ?Checklist
    {
        $row = DB::transaction(function () use ($checklistId, $receiver): ?Checklist {
            $checklist = Checklist::query()
                ->whereKey($checklistId)
                ->where('returned_status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($checklist === null || (int) $checklist->receiver_id === $receiver->id) {
                return null;
            }

            $from = $checklist->receiver_id;
            $checklist->update(['receiver_id' => $receiver->id]);
            $this->record($checklist, 'checklist_return_reassigned', 'receiver_id', $from === null ? null : (string) $from, (string) $receiver->id);

            return $checklist;
        });

        if ($row !== null) {
            User::query()->where('employee_id', $receiver->id)->first()?->notify(new ChecklistAssigned($row->load(['employee', 'item']), 'receive'));
        }

        return $row;
    }

    /**
     * A volunteer's contract just stopped auto-renewing: start the return
     * cycle for whatever of their equipment is still out, and remind whoever
     * is meant to collect it.
     *
     * Only items already handed over (`delivered_status = 'delivered'`) that
     * have no return cycle running yet are touched — an item still pending
     * delivery, or already being returned, is left to whatever workflow
     * already owns it. The receiver comes from the city's DefaultDeliverable
     * for that item; an item with none configured is skipped rather than
     * guessed at, and stays visible for HR to assign by hand from the
     * employee's checklist tab.
     */
    public function requestBelongingsReturn(Employee $employee): void
    {
        $checklists = Checklist::query()
            ->where('employee_id', $employee->id)
            ->where('delivered_status', 'delivered')
            ->whereNull('returned_status')
            ->get();

        if ($checklists->isEmpty()) {
            return;
        }

        $defaultReceivers = DefaultDeliverable::query()
            ->where('city_id', $employee->city_id)
            ->pluck('employee_id', 'item_id');

        foreach ($checklists as $checklist) {
            $receiverId = $defaultReceivers->get($checklist->item_id);
            // Someone who left is replaced by whoever holds their position now.
            $receiverId = $receiverId ? app(Access\SeatSuccession::class)->current(Employee::query()->find($receiverId))?->id : null;

            if (! $receiverId) {
                continue;
            }

            $updated = $this->initiateReturn($checklist->id, $employee, (int) $receiverId);

            $receiver = User::whereHas('profile', fn ($q) => $q->where('id', $receiverId))->first();
            $receiver?->notify(new ChecklistAssigned($updated, 'receive'));
        }
    }

    /**
     * HR closes a completed cycle, removing the assignment record.
     */
    public function finalizeReturn(int $checklistId, Employee $employee): void
    {
        DB::transaction(function () use ($checklistId, $employee): void {
            $checklist = Checklist::query()
                ->whereKey($checklistId)
                ->where('employee_id', $employee->id)
                ->where('returned_status', 'received')
                ->lockForUpdate()
                ->first();

            abort_unless($checklist, Response::HTTP_NOT_FOUND);

            $this->record($checklist, 'checklist_finalized', 'returned_status', 'received', null);

            $checklist->delete();
        });
    }

    /**
     * @param  callable(Builder): Builder  $scope  the authorization + expected-state filter
     */
    private function transition(
        callable $scope,
        int $checklistId,
        array $attributes,
        string $stateColumn,
        string $event,
    ): Checklist {
        return DB::transaction(function () use ($scope, $checklistId, $attributes, $stateColumn, $event): Checklist {
            $checklist = $scope(Checklist::query()->whereKey($checklistId))
                ->lockForUpdate()
                ->first();

            // Deliberately indistinguishable from "no such checklist": a
            // caller probing ids learns nothing about records they cannot act
            // on, nor about which state a foreign record is in.
            abort_unless($checklist, Response::HTTP_NOT_FOUND);

            $from = $checklist->{$stateColumn};

            $checklist->update($attributes);

            $this->record($checklist, $event, $stateColumn, $from, $checklist->{$stateColumn});

            return $checklist;
        });
    }

    private function record(Checklist $checklist, string $event, string $column, ?string $from, ?string $to): void
    {
        Activity::useLog('checklist')
            ->performedOn($checklist)
            ->withProperties([
                'transition' => $column,
                'from' => $from,
                'to' => $to,
                'employee_id' => $checklist->employee_id,
                'item_id' => $checklist->item_id,
            ] + ($this->batchId !== null ? ['batch_id' => $this->batchId] : []))
            ->log($event);

        $this->batchId = null;
    }
}
