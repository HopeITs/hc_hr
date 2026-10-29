<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Models\Contract;
use App\Support\Dashboard\DashboardLayout;
use App\Support\Dashboard\WaitingOnYou;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A shortcut made big (about 2×2 and up) becomes a small card with its data:
 * its name (opens the page), one number and a few short rows, simplified from
 * its page (ported from media-request 591021b). The same card is what a
 * shortcut shows in the side panel (32c7d37).
 *
 * Mounted lazily inside the shortcut's tile, in a box the stylesheet hides
 * while the tile is an icon: a hidden box never comes into view, so its data
 * is only read once the shortcut is big or its panel is open. The key is
 * checked against DashboardLayout::allowed() every time, so a page the role
 * cannot open never shows here; each figure keeps its page's own scope
 * (WorkInbox, Contract::visibleTo()).
 */
class AppCard extends Component
{
    use HandlesLoadFailure;

    /** Rows a card lists; the page has the rest. */
    public const ROWS = 4;

    #[Locked]
    public string $tile = '';

    public function placeholder(array $params = [])
    {
        return <<<'HTML'
            <div class="hc-appcard-data" aria-busy="true"></div>
            HTML;
    }

    public function render()
    {
        $user = auth()->user();
        $layout = app(DashboardLayout::class);
        $app = $user && $layout->isApp($this->tile) && $layout->allowed($user, $this->tile)
            ? $layout->apps($user)[$this->tile]
            : null;

        return view('livewire.dashboard.app-card', [
            'data' => $app ? $this->guardedLoad(fn () => $this->data($app['id']), ['rows' => []]) : ['rows' => []],
            'href' => $app ? route($app['route'], $app['params']) : null,
            'counter' => $app['counter'] ?? null,
        ]);
    }

    /**
     * @return array{num?: int, unit?: string, rows: array<int, array{t: string, s?: string, href?: string}>, empty?: string}
     */
    private function data(string $id): array
    {
        $user = auth()->user();

        return match ($id) {
            'nav.inbox' => (function () use ($user) {
                ['rows' => $rows, 'counts' => $counts] = app(WaitingOnYou::class)->inbox($user);

                return [
                    'num' => $counts['all'],
                    'unit' => trans_choice('dashboard.app-card.in-inbox', $counts['all']),
                    'rows' => $rows->take(self::ROWS)->map(fn (array $row) => [
                        't' => (string) ($row['subject']?->name ?? ''),
                        's' => __('inbox.action.'.$row['action']),
                        'href' => route('inbox', ['open' => $row['key']]),
                    ])->values()->all(),
                    'empty' => __('dashboard.app-card.inbox-empty'),
                ];
            })(),
            'nav.hr.contracts', 'nav.my-team.contracts' => (function () use ($user) {
                $ending = Contract::query()->visibleTo($user)->inView('ending-soon', 30)->count();
                $late = Contract::query()->visibleTo($user)->inView('not-renewed', 30)->count();

                return [
                    'num' => $ending,
                    'unit' => trans_choice('dashboard.app-card.ending-soon', $ending),
                    'rows' => [
                        ['t' => __('dashboard.app-card.not-renewed'), 's' => (string) $late, 'href' => route('contracts.index', ['view' => 'not-renewed'])],
                    ],
                ];
            })(),
            default => ['rows' => []],
        };
    }
}
