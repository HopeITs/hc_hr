<?php

namespace App\Services\Reports;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Models\Contract;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveRequestPricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Leave in two halves. Balances: the annual leave still owed on contracts in
 * force (what the organisation owes its people, and who is not taking it).
 * Usage: accepted leave in the period by type and month, and how long requests
 * wait for an answer. Scoped to the viewer's contracts (Contract::visibleTo).
 * Hours are shown as days at each contract's own working day.
 */
class LeaveReport
{
    public const PERIODS = [90, 365, 0];

    /** A volunteer with this many days of service and no leave taken is listed as not taking leave. */
    public const IDLE_AFTER_DAYS = 90;

    public function __construct(public readonly User $viewer, public readonly int $days = 365, public readonly ?int $departmentId = null) {}

    public static function normaliseDays(int $days): int
    {
        return in_array($days, self::PERIODS, true) ? $days : 365;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $days = self::normaliseDays($this->days);
        $from = $days > 0 ? now()->subDays($days)->startOfDay() : null;

        return ['days' => $days] + $this->balances() + $this->usage($from);
    }

    /** @return array<string, mixed> */
    private function balances(): array
    {
        $balances = LeaveBalance::query()
            ->where('type_id', LeaveTypeCases::Annual)
            ->whereHas('contract', fn (Builder $c) => $this->contracts($c)->activeNow())
            ->with(['contract.employee.city', 'contract.position.department'])
            ->get();

        $rows = $balances->map(function (LeaveBalance $b) {
            $total = (float) $b->deserved + $b->creditHours();
            $remaining = max(0, $total - (float) $b->used);
            $perDay = $this->hoursPerDay($b->contract);

            return [
                'name' => $b->contract->employee?->name ?? '-',
                'username' => $b->contract->employee?->username,
                'department' => $b->contract->position?->department?->name ?: '-',
                'city' => $b->contract->employee?->city?->name ?: '-',
                'remaining' => round($remaining / $perDay, 1),
                'used' => round((float) $b->used / $perDay, 1),
                'total' => round($total / $perDay, 1),
                'idle' => (float) $b->used <= 0 && Carbon::parse($b->contract->start_date)->lte(now()->subDays(self::IDLE_AFTER_DAYS)),
            ];
        });

        return [
            'people' => $rows->count(),
            'owedDays' => round($rows->sum('remaining'), 1),
            'averageOwedDays' => $rows->isEmpty() ? null : round($rows->avg('remaining'), 1),
            'idleCount' => $rows->where('idle', true)->count(),
            'byDepartment' => $rows->groupBy('department')->map(fn (Collection $g, string $label) => [
                'label' => $label,
                'people' => $g->count(),
                'owed' => round($g->sum('remaining'), 1),
                'average' => round($g->avg('remaining'), 1),
            ])->sortByDesc('owed')->values(),
            'mostOwed' => $rows->sortByDesc('remaining')->take(10)->values(),
            'idle' => $rows->where('idle', true)->sortByDesc('remaining')->take(10)->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function usage(?Carbon $from): array
    {
        $requests = LeaveRequest::query()
            ->whereHas('leaveBalance.contract', fn (Builder $c) => $this->contracts($c))
            ->when($from, fn (Builder $q) => $q->where('created_at', '>=', $from))
            ->with(['leaveBalance.contract', 'leaveBalance.leaveType'])
            ->get();

        $accepted = $requests->where('status', LeaveRequestStatus::Accepted);
        $inDays = fn (LeaveRequest $r) => $r->deductionHours() / $this->hoursPerDay($r->leaveBalance->contract);
        $total = max(0.0001, $accepted->sum($inDays));

        $decided = $requests->whereIn('status', [LeaveRequestStatus::Accepted, LeaveRequestStatus::Rejected])->whereNotNull('approved_at');
        $pending = $requests->where('status', LeaveRequestStatus::Pending);

        return [
            'requests' => $requests->count(),
            'takenDays' => round($accepted->sum($inDays), 1),
            'byType' => $accepted->groupBy(fn (LeaveRequest $r) => $r->leaveBalance->leaveType?->name ?: '-')
                ->map(fn (Collection $g, string $label) => [
                    'label' => $label,
                    'count' => round($g->sum($inDays), 1),
                    'percent' => round($g->sum($inDays) / $total * 100, 1),
                ])->sortByDesc('count')->values(),
            'byMonth' => $accepted->groupBy(fn (LeaveRequest $r) => $r->start_at->format('Y-m'))
                ->map(fn (Collection $g) => round($g->sum($inDays), 1))->sortKeys(),
            'daysToAnswer' => $decided->isEmpty() ? null : round($decided->avg(fn (LeaveRequest $r) => max(0, $r->created_at->floatDiffInDays($r->approved_at))), 1),
            'pending' => $pending->count(),
            'pendingOverThree' => $pending->filter(fn (LeaveRequest $r) => $r->created_at->lte(now()->subDays(3)))->count(),
        ];
    }

    /** Contracts the viewer may see, narrowed by the department filter. */
    private function contracts(Builder $query): Builder
    {
        return $query->visibleTo($this->viewer)
            ->when($this->departmentId, fn (Builder $q) => $q->whereHas('position', fn (Builder $p) => $p->where('department_id', $this->departmentId)));
    }

    /** Hours in one working day of the contract; 8 when the schedule is irregular or unknown. */
    private function hoursPerDay(?Contract $contract): float
    {
        $hours = $contract ? app(LeaveRequestPricing::class)->hoursPerDayFor($contract) : null;

        return $hours && $hours > 0 ? (float) $hours : 8.0;
    }
}
