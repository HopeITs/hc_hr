<?php

namespace App\Livewire\Dashboard;

use App\Support\Dashboard\WaitingOnYou;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The top of the dashboard (prompt 7): the greeting, large, and the job title.
 * It no longer says what waits: the "Needs you" tile and the rail's badge do.
 *
 * The late strip and the decisions used to sit here, fixed above the grid.
 * They are tiles now ("Past the deadline", LateCard; "Decisions for you",
 * DecisionsCard), to move, resize, pin or hide like the others (ported from
 * media-request b183c0c). The figures are the same: WaitingOnYou.
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
        return view('livewire.dashboard.header', [
            'greeting' => __('dashboard.greeting', ['name' => $this->name !== '' ? $this->name : (string) auth()->user()?->name]),
        ]);
    }
}
