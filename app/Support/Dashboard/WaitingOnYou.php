<?php

namespace App\Support\Dashboard;

use App\Models\Contract;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\WorkInbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What waits on the viewer right now (prompt 7), read by the dashboard's
 * headline (Dashboard\Header) and by the "Past the deadline" and "Work
 * inbox" tiles (LateCard, DecisionsCard). Ported from media-request, where
 * the late strip and the decisions became tiles to move, resize, pin or hide.
 *
 *  - late: leave that has started with no decision (the inbox's own decide
 *    rows), and contracts that ended without a renewal (the contracts table's
 *    `not-renewed` view, for the roles that may open it);
 *  - decisions: the inbox rows that need the viewer's decision, oldest first,
 *    then the rest of the inbox (items to confirm), which the work inbox tile
 *    lists under them (it took over "For you now").
 *
 * Every figure is the page's own: one WorkInbox snapshot (the badge's count,
 * shared within the request) and the contracts view through
 * Contract::visibleTo(). No pay, no leave reason.
 */
class WaitingOnYou
{
    /** Inbox actions that are a decision (the rest are confirmations). */
    public const DECISIONS = ['decide', 'review', 'approve'];

    /** Most decisions in the carousel; the rest are in the inbox it links to. */
    public const CARDS = 10;

    /** Other inbox rows the work inbox tile lists under the decisions. */
    public const ITEMS = 3;

    /** Names the late strip lists before "…". */
    public const LATE_NAMES = 3;

    public function __construct(private WorkInbox $inbox) {}

    /** May the viewer have any decision at all (the decisions tile)? */
    public function decides(User $user): bool
    {
        return $this->inbox->hasSources($user);
    }

    /** May anything be late on the viewer (the late tile)? */
    public function watchesLate(User $user): bool
    {
        return $this->inbox->hasSources($user) || $this->seesEndedContracts($user);
    }

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, counts: array{all: int, decide: int, review: int, checklist: int, profile: int, journey: int}}
     */
    public function inbox(User $user): array
    {
        return $this->inbox->hasSources($user)
            ? $this->inbox->shared($user)
            : ['rows' => collect(), 'counts' => ['all' => 0, 'decide' => 0, 'review' => 0, 'checklist' => 0, 'profile' => 0, 'journey' => 0]];
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
     * @return array{cards: array<int, array<string, mixed>>, total: int, items: array<int, array<string, mixed>>, others: int, all: int} oldest first
     */
    public function decisions(User $user): array
    {
        ['rows' => $rows, 'counts' => $counts] = $this->inbox($user);
        $total = $counts['decide'] + $counts['review'] + $counts['profile'];
        $others = $rows->whereNotIn('action', self::DECISIONS)->values();

        return [
            // Oldest first: the snapshot is already ordered by waiting_since.
            'cards' => $rows->whereIn('action', self::DECISIONS)->values()
                ->take(self::CARDS)->map(fn (array $row) => $this->card($row))->all(),
            'total' => $total,
            // The rest of the inbox (items to hand over or take back), as a short list.
            'items' => $others->take(self::ITEMS)->map(fn (array $row) => $this->card($row))->all(),
            'others' => max(0, $counts['all'] - $total),
            // The exact count, as on the badge (G-C-7).
            'all' => $counts['all'],
        ];
    }

    /** What is late in the viewer's scope, oldest first; null when nothing is. */
    public function late(User $user): ?array
    {
        $items = collect();

        // Leave that has started and still waits for this viewer's decision.
        $leaves = $this->inbox($user)['rows']->filter(fn (array $row) => self::startFlag($row) === 'started');
        foreach ($leaves as $row) {
            $items->push([
                'name' => (string) ($row['subject']?->name ?? ''),
                'since' => CarbonImmutable::instance($row['start_at'])->startOfDay(),
                'url' => route('inbox', ['open' => $row['key']]),
            ]);
        }

        // The contracts table's own view, for the roles that may open it (as the contracts table counts them).
        $contracts = 0;
        if ($this->seesEndedContracts($user)) {
            $url = route('contracts.index', ['view' => 'not-renewed']);
            $ended = Contract::query()->visibleTo($user)->inView('not-renewed', 30)
                ->with('employee')->orderBy('contracts.end_date')->get();
            $contracts = $ended->count();

            foreach ($ended as $contract) {
                $items->push([
                    'name' => (string) ($contract->employee?->name ?? ''),
                    'since' => CarbonImmutable::instance($contract->end_date)->startOfDay()->addDay(),
                    'url' => $url,
                ]);
            }
        }

        if ($items->isEmpty()) {
            return null;
        }

        $items = $items->sortBy(fn (array $item) => $item['since']->timestamp)->values();

        return [
            'count' => $items->count(),
            'parts' => array_filter([
                'leave' => $leaves->count(),
                'contract' => $contracts,
            ]),
            'names' => $items->take(self::LATE_NAMES)->pluck('name')->filter()->all(),
            'more' => $items->count() > self::LATE_NAMES,
            'oldest' => $items->first(),
        ];
    }

    private function seesEndedContracts(User $user): bool
    {
        return $user->activeRoleIs('HR', 'general-manager') && $user->hasActivePermission('contracts.view');
    }

    /** One decision, reduced to what the card shows: no reason, no pay. */
    private function card(array $row): array
    {
        $since = $row['waiting_since'] ? CarbonImmutable::instance($row['waiting_since']) : null;

        return [
            'key' => $row['key'],
            'action' => $row['action'],
            // What the row is, in words: a journey row names itself (journey_inbox.*).
            'label' => $row['label'] ?? __('inbox.action.'.$row['action']),
            'name' => (string) ($row['subject']?->name ?? ''),
            'what' => $row['source'] === 'leave' ? $row['leave_type'] : $row['item'],
            'city' => $row['city'] ?? null,
            'from' => isset($row['start_at']) ? $row['start_at']->toDateString() : null,
            'to' => isset($row['end_at']) ? $row['end_at']->toDateString() : null,
            // "2 days", not "2 days ago": the line reads "Waiting 2 days".
            'since' => $since?->locale(app()->getLocale())->diffForHumans(null, true),
            'old' => $since !== null && $since->lt(now()->subDay()),
            'start_flag' => self::startFlag($row),
            'url' => route('inbox', ['open' => $row['key']]),
        ];
    }
}
