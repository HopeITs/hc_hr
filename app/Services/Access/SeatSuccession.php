<?php

namespace App\Services\Access;

use App\Enums\ContractType;
use App\Models\Checklist;
use App\Models\Contract;
use App\Models\DefaultDeliverable;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserRoleSuspension;
use App\Services\ChecklistWorkflow;
use App\Services\NotificationDeliveryLedger;
use Illuminate\Support\Str;
use Spatie\Activitylog\Facades\Activity;

/**
 * What belongs to a seat (a position) moves with it when its holder leaves
 * and someone else takes it, with nobody doing it by hand (decided 2026-11-25):
 *
 *  - the raised roles the last holder had (HR of a city, general manager) are
 *    given to whoever holds the position next, once per contract
 *    (inheritRoles, from OrgRoleSync at sign-in and nightly). An
 *    administrator role never travels: it is granted on purpose;
 *  - work left open by someone who left (items still to hand over or to
 *    take back) goes to whoever now holds their position, and so does their
 *    place in the city's delivery list (handOverOpenWork, nightly);
 *  - until that runs, readers ask current() for the person to show.
 *
 * "Holds" is a running, non-draft contract on the position. "Left" is an
 * inactive employee. When a position has several holders the successor is
 * ambiguous and nothing moves: HR decides.
 */
class SeatSuccession
{
    /** Roles that travel with the seat. Never super-admin. */
    public const SEAT_ROLES = ['general-manager', 'HR-*'];

    public const LEDGER_KIND = 'seat-roles';

    /**
     * The person to use in place of $named: themself while active, else the
     * one person who now holds the position they last held. Null when nobody
     * (or more than one person) does.
     */
    public function current(?Employee $named): ?Employee
    {
        if ($named === null) {
            return null;
        }

        return $named->is_active ? $named : $this->successorOf($named);
    }

    public function successorOf(Employee $employee): ?Employee
    {
        $positionId = Contract::query()
            ->where('employee_id', $employee->id)
            ->where('type', '!=', ContractType::Draft)
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->value('position_id');

        if ($positionId === null) {
            return null;
        }

        $holders = Employee::query()
            ->where('is_active', true)
            ->whereKeyNot($employee->id)
            ->whereHas('contracts', fn ($contract) => $contract
                ->activeNow()
                ->where('type', '!=', ContractType::Draft)
                ->where('position_id', $positionId))
            ->with('account')
            ->limit(2)
            ->get();

        return $holders->count() === 1 ? $holders->first() : null;
    }

    /**
     * Give $user the seat roles the previous holder of each position they now
     * hold had. Once per contract: a role HR later takes away by hand is not
     * given back.
     *
     * @return list<string> the roles given
     */
    public function inheritRoles(User $user): array
    {
        if ($user->employee_id === null) {
            return [];
        }

        $given = [];
        $ledger = app(NotificationDeliveryLedger::class);

        $contracts = Contract::query()
            ->activeNow()
            ->where('employee_id', $user->employee_id)
            ->where('type', '!=', ContractType::Draft)
            ->get(['id', 'position_id', 'start_date']);

        foreach ($contracts as $contract) {
            $predecessor = $this->predecessorOn((int) $contract->position_id, (int) $user->employee_id);
            $roles = $predecessor ? $this->seatRolesOf($predecessor) : [];
            $missing = array_values(array_filter($roles, fn (string $role) => ! $user->hasRole($role)));

            if ($missing === []) {
                continue;
            }

            if ($ledger->claim(self::LEDGER_KIND, (int) $user->id, [(int) $contract->id], $contract->start_date) === []) {
                continue;
            }

            foreach ($missing as $role) {
                $user->assignRole($role);
                $given[] = $role;
            }

            Activity::useLog('security')
                ->performedOn($user)
                ->withProperties(['roles' => $missing, 'position_id' => (int) $contract->position_id, 'contract_id' => (int) $contract->id, 'from_employee_id' => $predecessor->id])
                ->log('roles_inherited_with_position');
        }

        return array_values(array_unique($given));
    }

    /**
     * Move what people who left still hold to whoever holds their position now:
     * items they were to hand over or take back, and their place in the
     * city's delivery list.
     *
     * @return array{deliveries: int, returns: int, defaults: int}
     */
    public function handOverOpenWork(): array
    {
        $moved = ['deliveries' => 0, 'returns' => 0, 'defaults' => 0];
        $workflow = app(ChecklistWorkflow::class);
        $successors = [];
        $successor = function (int $employeeId) use (&$successors): ?Employee {
            if (! array_key_exists($employeeId, $successors)) {
                $employee = Employee::query()->find($employeeId);
                $successors[$employeeId] = $employee && ! $employee->is_active ? $this->successorOf($employee) : null;
            }

            return $successors[$employeeId];
        };

        Checklist::query()
            ->where('delivered_status', 'pending')
            ->whereHas('delivered', fn ($e) => $e->where('is_active', false))
            ->with('employee')
            ->each(function (Checklist $row) use ($workflow, $successor, &$moved): void {
                $to = $successor((int) $row->delivered_id);

                if ($to && $row->employee && $workflow->assignForDelivery($row->employee, (int) $row->item_id, $to)) {
                    $moved['deliveries']++;
                }
            });

        Checklist::query()
            ->where('returned_status', 'pending')
            ->whereHas('receiver', fn ($e) => $e->where('is_active', false))
            ->each(function (Checklist $row) use ($workflow, $successor, &$moved): void {
                $to = $successor((int) $row->receiver_id);

                if ($to && $workflow->reassignReturn($row->id, $to)) {
                    $moved['returns']++;
                }
            });

        DefaultDeliverable::query()
            ->whereHas('employee', fn ($e) => $e->where('is_active', false))
            ->each(function (DefaultDeliverable $row) use ($successor, &$moved): void {
                $to = $successor((int) $row->employee_id);

                if (! $to) {
                    return;
                }

                $from = (int) $row->employee_id;
                $row->update(['employee_id' => $to->id]);
                Activity::useLog('checklist')
                    ->performedOn($row)
                    ->withProperties(['from' => $from, 'to' => $to->id, 'item_id' => (int) $row->item_id, 'city_id' => (int) $row->city_id])
                    ->log('default_deliverer_succeeded');
                $moved['defaults']++;
            });

        return $moved;
    }

    /**
     * The last person to hold $positionId before $newcomerId, once they no
     * longer hold it (now or later).
     */
    private function predecessorOn(int $positionId, int $newcomerId): ?Employee
    {
        $last = Contract::query()
            ->where('position_id', $positionId)
            ->where('employee_id', '!=', $newcomerId)
            ->where('type', '!=', ContractType::Draft)
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->first(['id', 'employee_id']);

        if ($last === null) {
            return null;
        }

        $stillHolds = Contract::query()
            ->where('position_id', $positionId)
            ->where('employee_id', $last->employee_id)
            ->where('type', '!=', ContractType::Draft)
            ->where('is_active', true)
            ->whereDate('end_date', '>=', now())
            ->exists();

        return $stillHolds ? null : Employee::query()->with('account')->find($last->employee_id);
    }

    /** @return list<string> the seat roles the person holds or had suspended */
    private function seatRolesOf(Employee $employee): array
    {
        $user = $employee->account;

        if ($user === null) {
            return [];
        }

        $names = $user->roles()->pluck('roles.name')
            ->merge(UserRoleSuspension::query()->where('user_id', $user->id)->with('role')->get()->pluck('role.name'))
            ->filter()
            ->unique();

        return $names->filter(fn (string $name) => $this->travels($name))->values()->all();
    }

    private function travels(string $role): bool
    {
        foreach (self::SEAT_ROLES as $entry) {
            if ($entry === $role || ($entry === 'HR-*' && Str::startsWith($role, 'HR-'))) {
                return true;
            }
        }

        return false;
    }
}
