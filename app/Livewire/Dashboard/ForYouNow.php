<?php

namespace App\Livewire\Dashboard;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Concerns\IsDashboardTile;
use App\Livewire\Team\Calendar;
use App\Models\Checklist;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveDecisionAuthority;
use App\Services\LeaveRequestNextStep;
use App\Services\LeaveRequestPricing;
use App\Services\TeamCoverage;
use App\Services\WorkInbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Throwable;

/**
 * "For you now / لك الآن" (C4; interaction-contract.md §5, C0-Q5): one ordered
 * block at the top of the home page with the active role's next actions,
 * above the existing cards.
 *
 * Every figure is the same scoped query as the page it opens: the work inbox
 * (WorkInbox, C3), who is off (TeamCoverage, C5), contracts ending soon
 * (Contract::visibleTo + inView, the contracts table), awaiting/incomplete
 * employees (the B5 views of the employees table). Nothing is counted that
 * the page would not list. No pay, ever.
 *
 * Nothing is kept in public state: render() rebuilds the parts from the
 * active role on every request, so switching role never shows the previous
 * role's figures (AC-C4-02). Each part loads on its own: one that fails is
 * shown as failed, and the others still appear. A part with nothing to show
 * is left out.
 */
class ForYouNow extends Component
{
    use IsDashboardTile;

    public const PREVIEW_ROWS = 3;

    /** One WorkInbox snapshot per render, shared by the parts that read it. */
    protected ?array $inbox = null;

    public function render()
    {
        $user = auth()->user();
        $parts = [];
        $failed = [];

        foreach ($this->plan($user) as $key) {
            try {
                $part = $this->{'part'.str_replace('-', '', ucwords($key, '-'))}($user);
            } catch (Throwable $e) {
                report($e);
                $failed[] = $key;

                continue;
            }

            if ($part !== null) {
                $parts[$key] = $part;
            }
        }

        return view('livewire.dashboard.for-you-now', [
            'parts' => $parts,
            'failed' => $failed,
        ]);
    }

    /**
     * 'soon' or 'started' for a leave still waiting for a decision (G-C-5),
     * null for any other row. The inbox page labels its rows the same way.
     */
    public static function startFlag(array $row): ?string
    {
        return $row['source'] === 'leave' && $row['action'] === 'decide'
            ? LeaveRequest::startFlag($row['start_at'] ?? null)
            : null;
    }

    /**
     * The parts for the active role, in the order C0 §5 fixes.
     *
     * @return array<int, string>
     */
    private function plan(User $user): array
    {
        $role = (string) $user->myRole();

        return match (true) {
            str_starts_with($role, 'HR-') => ['inbox', 'contracts-ending', 'employees-waiting'],
            $role === 'super-admin' => ['inbox', 'admin-shortcuts'],
            // G-C-11 F9: items to hand over / take back, which the menu badge counts.
            $role === 'general-manager' => ['decisions', 'inbox-items', 'key-figures', 'contracts-ending'],
            $role === 'manager' => ['decisions', 'inbox-items', 'team-off', 'balance'],
            // A direct manager decides whatever their role (DEC-05 revision 2,
            // G-C-17), so their decisions lead the home as they lead the badge.
            default => app(LeaveDecisionAuthority::class)->holdsManagerPosition($user)
                ? ['decisions', 'inbox-items', 'balance', 'my-requests', 'my-items']
                : ['inbox-items', 'balance', 'my-requests', 'my-items'],
        };
    }

    // ── Parts ───────────────────────────────────────────────────────────

    /**
     * Manager / general manager: what waits for my decision (C3 inbox, decide
     * rows only). The figures only: the decisions themselves are the
     * dashboard header's decision card (prompt 7), so they are not listed twice.
     */
    private function partDecisions(User $user): ?array
    {
        ['rows' => $rows, 'counts' => $counts] = $this->snapshot($user);
        $rows = $rows->where('action', 'decide')->values();

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            // The exact count, as on the badge (G-C-7).
            'count' => $counts['decide'],
            'soon' => $this->countStarting($rows, 'soon'),
            'started' => $this->countStarting($rows, 'started'),
            'rows' => [],
            'url' => route('inbox', ['show' => 'decide']),
        ];
    }

    /** HR and super-admin: the whole work inbox by kind (C3); the preview leaves out the header's decisions. */
    private function partInbox(User $user): ?array
    {
        ['rows' => $rows, 'counts' => $counts] = $this->snapshot($user);

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            // The exact counts, as on the badge (G-C-7).
            'count' => $counts['all'],
            'kinds' => array_filter([
                'decide' => $counts['decide'],
                'review' => $counts['review'],
                'checklist' => $counts['checklist'],
            ]),
            'soon' => $this->countStarting($rows, 'soon'),
            'started' => $this->countStarting($rows, 'started'),
            'rows' => $rows->whereNotIn('action', Header::DECISIONS)->take(self::PREVIEW_ROWS)->map(fn (array $row) => $this->inboxRow($row))->values()->all(),
            'url' => route('inbox'),
        ];
    }

    /** Roles without the whole inbox part: the checklist rows of the inbox (G-C-11 F9). */
    private function partInboxItems(User $user): ?array
    {
        ['rows' => $rows, 'counts' => $counts] = $this->snapshot($user);
        $rows = $rows->where('source', 'checklist')->values();

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'count' => $counts['checklist'],
            'soon' => 0,
            'started' => 0,
            'rows' => $rows->take(self::PREVIEW_ROWS)->map(fn (array $row) => $this->inboxRow($row))->all(),
            'url' => route('inbox', ['show' => 'checklist']),
        ];
    }

    /**
     * Manager: who in my team is off today and this week (C5, accepted +
     * pending, manager level). "This week" is the rest of the calendar's own
     * Sunday–Saturday week, so every name here is on the week view the link
     * opens (G-C-10 / F7).
     */
    private function partTeamOff(User $user): ?array
    {
        $coverage = app(TeamCoverage::class);

        if (! in_array('reports', $coverage->scopesFor($user), true)) {
            return null;
        }

        $today = CarbonImmutable::today();
        $week = $coverage->absences($user, 'reports', $today, Calendar::weekOf($today)[1]);

        if ($week->isEmpty()) {
            return null;
        }

        $isToday = fn (array $entry) => in_array($today->toDateString(), $entry['dates'], true);

        return [
            'today' => $week->filter($isToday)->values()->all(),
            'later' => $week->reject($isToday)->values()->all(),
            'url' => route('team.calendar', ['scope' => 'reports', 'view' => 'week']),
        ];
    }

    /** Employee and manager: the main balance, the next holiday, and Request leave. */
    private function partBalance(User $user): ?array
    {
        $employee = $user->profile;

        if (! $employee || ! $user->hasActivePermission('leaves.view-own')) {
            return null;
        }

        $leave = $employee->availableLeaves()?->first();
        $contract = $employee->latestActiveContract();

        if (! $leave && ! $contract) {
            return null;
        }

        return [
            'type' => $leave?->leaveType?->name,
            // LeaveBalance::remaining and formatDuration() return markup for the old card; shown here as plain text.
            'remaining' => $leave ? trim(strip_tags($leave->remaining)) : null,
            'pending' => $leave && ($leave->pending_total_hours ?? 0) > 0 ? trim(strip_tags(formatDuration($leave->pending_days, $leave->pending_hours))) : null,
            'holiday' => $contract ? $this->nextHoliday($contract) : null,
            'can_request' => $user->hasActivePermission('leaves.create'),
            'request_url' => route('leaves.index', ['new' => 1]),
            'url' => route('leaves.index'),
        ];
    }

    /** Employee: my open requests and their next step (the same wording as My leaves). */
    private function partMyRequests(User $user): ?array
    {
        if (! $user->employee_id || ! $user->hasActivePermission('leaves.view-own')) {
            return null;
        }

        $open = LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Pending)
            ->whereHas('leaveBalance.contract', fn (Builder $c) => $c->where('employee_id', $user->employee_id))
            ->with(['leaveBalance.leaveType', 'leaveBalance.contract.position'])
            ->orderBy('start_at')
            ->get();

        if ($open->isEmpty()) {
            return null;
        }

        $next = app(LeaveRequestNextStep::class);

        return [
            'count' => $open->count(),
            'rows' => $open->take(self::PREVIEW_ROWS)->map(fn (LeaveRequest $leave) => [
                'type' => $leave->leaveBalance?->leaveType?->name,
                'from' => $leave->start_at->toDateString(),
                'to' => $leave->end_at->toDateString(),
                'next' => $next->describe($leave),
            ])->all(),
            'url' => route('leaves.index'),
        ];
    }

    /** Employee: items I am to receive or hand back (the My items page's own rows). */
    private function partMyItems(User $user): ?array
    {
        if (! $user->employee_id || ! $user->hasActivePermission('checklists.view-own')) {
            return null;
        }

        $mine = Checklist::query()->where('employee_id', $user->employee_id);
        $receive = (clone $mine)->where('delivered_status', 'pending')->count();
        $return = (clone $mine)->where('returned_status', 'pending')->count();

        if ($receive + $return === 0) {
            return null;
        }

        return ['receive' => $receive, 'return' => $return, 'url' => route('checklist-items.index')];
    }

    /** HR and general manager: running contracts ending within 30 days, as the contracts table's view counts them. */
    private function partContractsEnding(User $user): ?array
    {
        if (! $user->hasActivePermission('contracts.view')) {
            return null;
        }

        $count = Contract::query()->visibleTo($user)->inView('ending-soon', 30)->count();

        return $count > 0 ? ['count' => $count, 'url' => route('contracts.index', ['view' => 'ending-soon'])] : null;
    }

    /** HR: volunteers awaiting their first contract, and incomplete records (B5 views). */
    private function partEmployeesWaiting(User $user): ?array
    {
        if (! $user->hasActivePermission('employees.view')) {
            return null;
        }

        $scoped = fn () => Employee::query()->inActiveCities($user);

        $awaiting = $scoped()->awaitingContract()->count();
        $incomplete = $scoped()->drafts()->count();

        if ($awaiting + $incomplete === 0) {
            return null;
        }

        return [
            'awaiting' => $awaiting,
            'incomplete' => $incomplete,
            'awaiting_url' => route('employees.index', ['view' => 'awaiting']),
            'incomplete_url' => route('employees.index', ['view' => 'incomplete']),
        ];
    }

    /** General manager: a few organisation figures, then the statistics page. No pay. */
    private function partKeyFigures(User $user): ?array
    {
        if (! $user->hasActivePermission('statistics.view')) {
            return null;
        }

        return [
            'employees' => Employee::query()->where('is_active', true)->count(),
            'contracts' => Contract::query()->visibleTo($user)->inView('active')->count(),
            'url' => route('statistics.general'),
        ];
    }

    /** super-admin: the settings it works in. */
    private function partAdminShortcuts(User $user): ?array
    {
        $links = array_filter([
            $user->hasActivePermission('access-management.view') ? ['label' => __('access.title'), 'url' => route('access-management.index')] : null,
            $user->hasActivePermission('activity-log.view') ? ['label' => __('general.activity-log'), 'url' => route('activity-log.index')] : null,
        ]);

        return $links === [] ? null : ['links' => array_values($links)];
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @return array{rows: \Illuminate\Support\Collection<int, array<string, mixed>>, counts: array<string, int>} */
    private function snapshot(User $user): array
    {
        return $this->inbox ??= app(WorkInbox::class)->snapshot($user);
    }

    private function countStarting($rows, string $flag): int
    {
        return $rows->filter(fn (array $row) => self::startFlag($row) === $flag)->count();
    }

    /** An inbox row reduced to what the preview shows: no reason, no pay. */
    private function inboxRow(array $row): array
    {
        return [
            'name' => (string) ($row['subject']?->name ?? ''),
            'action' => $row['action'],
            'what' => $row['source'] === 'leave' ? $row['leave_type'] : $row['item'],
            'from' => isset($row['start_at']) ? $row['start_at']->toDateString() : null,
            'to' => isset($row['end_at']) ? $row['end_at']->toDateString() : null,
            'start_flag' => self::startFlag($row),
            'url' => route('inbox', ['open' => $row['key']]),
        ];
    }

    /** The next holiday on the holder's own calendar within 60 days (A3 holidays: national, city, program). */
    private function nextHoliday(Contract $contract): ?string
    {
        $today = CarbonImmutable::today();
        $dates = array_keys(app(LeaveRequestPricing::class)->holidayDates($contract, $today, $today->addDays(60)));
        sort($dates);

        return $dates[0] ?? null;
    }
}
