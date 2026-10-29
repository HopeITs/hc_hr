<?php

namespace App\Support\Dashboard;

use App\Livewire\Dashboard\ForYouNow;
use App\Models\Contract;
use App\Models\User;
use App\Services\WorkInbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What waits on the viewer right now (prompt 7), read by the dashboard's
 * headline (Dashboard\Header) and by the "Past the deadline" and "Decisions
 * for you" tiles (LateCard, DecisionsCard). Ported from media-request, where
 * the late strip and the decisions became tiles to move, resize, pin or hide.
 *
 *  - late: leave that has started with no decision (the inbox's own decide
 *    rows), and contracts that ended without a renewal (the contracts table's
 *    `not-renewed` view, for the roles that may open it);
 *  - decisions: the inbox rows that need the viewer's decision, oldest first.
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
     * @return array{rows: Collection<int, array<string, mixed>>, counts: array{all: int, decide: int, review: int, checklist: int, profile: int}}
     */
    public function inbox(User $user): array
    {
        return $this->inbox->hasSources($user)
            ? $this->inbox->shared($user)
            : ['rows' => collect(), 'counts' => ['all' => 0, 'decide' => 0, 'review' => 0, 'checklist' => 0, 'profile' => 0]];
    }

    /** @return array{cards: array<int, array<string, mixed>>, total: int} oldest first */
    public function decisions(User $user): array
    {
        ['rows' => $rows, 'counts' => $counts] = $this->inbox($user);

        return [
            // Oldest first: the snapshot is already ordered by waiting_since.
            'cards' => $rows->whereIn('action', self::DECISIONS)->values()
                ->take(self::CARDS)->map(fn (array $row) => $this->card($row))->all(),
            'total' => $counts['decide'] + $counts['review'] + $counts['profile'],
        ];
    }

    /** What is late in the viewer's scope, oldest first; null when nothing is. */
    public function late(User $user): ?array
    {
        $items = collect();

        // Leave that has started and still waits for this viewer's decision.
        $leaves = $this->inbox($user)['rows']->filter(fn (array $row) => ForYouNow::startFlag($row) === 'started');
        foreach ($leaves as $row) {
            $items->push([
                'name' => (string) ($row['subject']?->name ?? ''),
                'since' => CarbonImmutable::instance($row['start_at'])->startOfDay(),
                'url' => route('inbox', ['open' => $row['key']]),
            ]);
        }

        // The contracts table's own view, for the roles that may open it (as For you's contracts-ending).
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
            'name' => (string) ($row['subject']?->name ?? ''),
            'what' => $row['source'] === 'leave' ? $row['leave_type'] : $row['item'],
            'city' => $row['city'] ?? null,
            'from' => isset($row['start_at']) ? $row['start_at']->toDateString() : null,
            'to' => isset($row['end_at']) ? $row['end_at']->toDateString() : null,
            // "2 days", not "2 days ago": the line reads "Waiting 2 days".
            'since' => $since?->locale(app()->getLocale())->diffForHumans(null, true),
            'old' => $since !== null && $since->lt(now()->subDay()),
            'start_flag' => ForYouNow::startFlag($row),
            'url' => route('inbox', ['open' => $row['key']]),
        ];
    }
}
