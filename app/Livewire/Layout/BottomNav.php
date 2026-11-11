<?php

namespace App\Livewire\Layout;

use App\Services\WorkInbox;
use App\Support\BottomBar;
use App\Support\Navigation;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * Phone bottom navigation (D2), app style: the person's four shortcuts, two
 * on each side of a raised + in the middle.
 *
 *   [ place 1 ][ place 2 ]( + )[ place 3 ][ place 4 ]
 *
 * - The four places are App\Support\BottomBar's (the person's choice, made
 *   with <livewire:layout.bottom-bar-picker>, or the default bar).
 * - + opens the New sheet: the header's New menu (Navigation::newActions),
 *   so only what the active role may start; there is no + when it may start
 *   nothing.
 * - The role's whole menu is the header's menu button (the sidebar); the bar
 *   has no More of its own.
 *
 * The sheet is a dialog (aria-modal, focus trapped and given back, Escape,
 * backdrop, swipe down). A shortcut, never the permission: every page keeps
 * its own server check, and every screen New opens checks its permission again.
 *
 * Shown below md only. It steps aside while a field has focus and on pages
 * that have their own sticky save bar (app.css, "D2: bottom navigation").
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

    /** The person chose other shortcuts (the picker on the dashboard): show them. */
    #[On('bottom-bar-changed')]
    public function refreshBar(): void
    {
        // Re-render only: the places are read again below.
    }

    public function render()
    {
        $user = auth()->user();
        $items = BottomBar::slots($user);
        // The menu without its count: the count is read once, below, for the inbox place.
        $nav = Navigation::for($user, Navigation::isDeliverResponsible($user), countInbox: false);
        $count = $nav['inbox'] ? $this->inboxCount() : 0;

        foreach ($items as $i => $item) {
            if ($item['route'] === 'dashboard') {
                $items[$i]['count'] = $count;
            }
        }

        return view('livewire.layout.bottom-nav', [
            'items' => $items,
            'before' => array_slice($items, 0, BottomBar::BEFORE_NEW),
            'after' => array_slice($items, BottomBar::BEFORE_NEW),
            'actions' => Navigation::newActions($user),
        ]);
    }

    /** The menu's own count (WorkInbox, scoped to the caller); a badge never takes the page down. */
    private function inboxCount(): int
    {
        try {
            return app(WorkInbox::class)->shared(auth()->user())['counts']['all'];
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
