<?php

namespace App\Livewire\Layout;

use App\Services\WorkInbox;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * Phone bottom navigation (D2). At most five self-service destinations, each
 * shown only when the active role holds the permission its page already
 * checks, so the bar never offers a page that would refuse the user. It is a
 * shortcut, never the permission: every page keeps its own server check.
 *
 *   Home · My leaves (leaves.view-own) · Work inbox (an inbox source, with the
 *   same count as the menu) · Team calendar (leaves.view-own) · My profile
 *
 * Shown below md only (the sidebar becomes a drawer there). It steps aside
 * while a field has focus and on pages that have their own sticky save bar,
 * so it never covers a focused control, an error or a submit row (app.css,
 * "D2: bottom navigation").
 */
class BottomNav extends Component
{
    /** The page the bar was rendered on; kept for highlighting after Livewire updates. */
    public string $currentRoute = '';

    public function mount(): void
    {
        $this->currentRoute = (string) Route::currentRouteName();
    }

    #[On('inbox-changed')]
    public function refreshInbox(): void
    {
        // Re-render only: the count is read again below.
    }

    public function render()
    {
        $user = auth()->user();
        $inbox = app(WorkInbox::class);
        $count = null;

        if ($inbox->hasSources($user)) {
            try {
                $count = $inbox->count($user);
            } catch (Throwable $e) {
                report($e);
                $count = 0;
            }
        }

        $items = array_values(array_filter([
            ['key' => 'home', 'route' => 'dashboard', 'active' => ['dashboard', 'employee.dashboard'], 'icon' => 'home', 'label' => __('general.dashboard')],
            $user->hasActivePermission('leaves.view-own')
                ? ['key' => 'leaves', 'route' => 'leaves.index', 'active' => ['leaves.index'], 'icon' => 'my-leaves', 'label' => __('general.my-leaves')]
                : null,
            $count !== null
                ? ['key' => 'inbox', 'route' => 'inbox', 'active' => ['inbox'], 'icon' => 'check', 'label' => __('general.nav.work-inbox'), 'count' => $count]
                : null,
            $user->hasActivePermission('leaves.view-own')
                ? ['key' => 'calendar', 'route' => 'team.calendar', 'active' => ['team.calendar'], 'icon' => 'calendar', 'label' => __('team_calendar.nav')]
                : null,
            $user->profile
                ? ['key' => 'profile', 'route' => 'my-profile', 'active' => ['my-profile'], 'icon' => 'user', 'label' => __('general.my-profile')]
                : null,
        ]));

        return view('livewire.layout.bottom-nav', ['items' => $items]);
    }
}
