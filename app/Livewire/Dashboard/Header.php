<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Support\Dashboard\WaitingOnYou;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The top of the dashboard (prompt 7): it answers "what is waiting on me
 * right now?" in one headline: decisions waiting, else the work inbox, else
 * what is late, else "nothing is waiting".
 *
 * The late strip and the decisions used to sit here, fixed above the grid.
 * They are tiles now ("Past the deadline", LateCard; "Decisions for you",
 * DecisionsCard), to move, resize, pin or hide like the others (ported from
 * media-request b183c0c). The figures are the same: WaitingOnYou.
 *
 * It renders with the page, not lazily, so the headline is there at once; a
 * child component, so the grid's own renders (Add, Reset) never recompute it.
 */
class Header extends Component
{
    /** Inbox actions that are a decision (the rest are confirmations). */
    public const DECISIONS = WaitingOnYou::DECISIONS;

    #[Locked]
    public string $name = '';

    #[Locked]
    public string $jobTitle = '';

    public function render()
    {
        $user = auth()->user();

        try {
            $headline = $user ? $this->headline($user) : null;
        } catch (Throwable $e) {
            // The header must not take the dashboard down: the greeting stays, no claim is made.
            report($e);
            $headline = null;
        }

        return view('livewire.dashboard.header', [
            'headline' => $headline,
            'greeting' => __('dashboard.greeting', ['name' => $this->name !== '' ? $this->name : (string) $user?->name]),
        ]);
    }

    private function headline(User $user): string
    {
        $waiting = app(WaitingOnYou::class);
        $counts = $waiting->inbox($user)['counts'];
        $decisionTotal = $counts['decide'] + $counts['review'] + $counts['profile'];

        if ($decisionTotal > 0) {
            return $counts['decide'] === $decisionTotal
                ? trans_choice('dashboard.header.headline.leave', $decisionTotal, ['count' => $decisionTotal])
                : trans_choice('dashboard.header.headline.decisions', $decisionTotal, ['count' => $decisionTotal]);
        }

        if ($counts['all'] > 0) {
            return trans_choice('dashboard.header.headline.inbox', $counts['all'], ['count' => $counts['all']]);
        }

        $late = $waiting->late($user);

        return $late !== null
            ? trans_choice('dashboard.header.headline.late', $late['count'], ['count' => $late['count']])
            : __('dashboard.header.headline.clear');
    }
}
