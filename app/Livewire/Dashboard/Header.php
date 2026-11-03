<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Support\Dashboard\WaitingOnYou;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Throwable;

/**
 * The hello at the top of the dashboard (like hc-tracking's Desk): today's
 * date, the greeting, the day in two figures (what waits on the viewer, and
 * how much of it is late) and Customize. The figures lead down to the work inbox panel when it is on the
 * desk ($inboxPlaced).
 *
 * It renders with the page, not lazily, so the figures are there at once; a
 * child component, so the grid's own renders (Customize) never recompute it.
 */
class Header extends Component
{
    #[Locked]
    public string $name = '';

    /** Is the work inbox panel ("decide") on the desk? The figures link to it. */
    #[Reactive]
    public bool $inboxPlaced = false;

    public function render()
    {
        $user = auth()->user();

        try {
            $figures = $user ? $this->figures($user) : null;
        } catch (Throwable $e) {
            // The hello must not take the dashboard down: the greeting stays, no claim is made.
            report($e);
            $figures = null;
        }

        return view('livewire.dashboard.header', [
            'figures' => $figures,
            'greeting' => __('dashboard.greeting', ['name' => $this->name !== '' ? $this->name : (string) $user?->name]),
        ]);
    }

    /** @return array{waiting: int, late: int} */
    private function figures(User $user): array
    {
        $waiting = app(WaitingOnYou::class);

        return [
            'waiting' => $waiting->inbox($user)['counts']['all'],
            'late' => $waiting->late($user)['count'] ?? 0,
        ];
    }
}
