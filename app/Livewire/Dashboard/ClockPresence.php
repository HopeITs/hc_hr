<?php

namespace App\Livewire\Dashboard;

use App\Support\Dashboard\Presence;
use Livewire\Component;
use Throwable;

/**
 * "Online now" on a big clock tile (inside ClockCard): the people of the
 * viewer's own scope who used the app in the last few minutes (Presence).
 * Read only, no actions: it re-renders on its poll, once a minute and only
 * while it is on screen (a small clock hides it, so it never polls).
 */
class ClockPresence extends Component
{
    /** Faces drawn; the rest are the "+N". */
    public const SHOWN = 12;

    public function render()
    {
        $user = auth()->user();

        try {
            $online = $user ? app(Presence::class)->onlineFor($user) : ['people' => collect(), 'total' => 0];
        } catch (Throwable $e) {
            // Presence is a nicety: a failure leaves the clock itself standing.
            report($e);
            $online = ['people' => collect(), 'total' => 0];
        }

        return view('livewire.dashboard.clock-presence', [
            'shown' => $online['people']->take(self::SHOWN),
            'count' => $online['people']->count(),
            'total' => $online['total'],
        ]);
    }
}
