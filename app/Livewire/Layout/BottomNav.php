<?php

namespace App\Livewire\Layout;

use App\Services\WorkInbox;
use App\Support\BottomBar;
use App\Support\Navigation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * Phone bottom navigation (D2), app style: the person's four shortcuts, two
 * on each side of a raised + in the middle, and More at the end.
 *
 *   [ place 1 ][ place 2 ]( + )[ place 3 ][ place 4 ][ More ]
 *
 * - The four places are App\Support\BottomBar's (the person's choice, made
 *   with <livewire:layout.bottom-bar-picker>, or the default bar).
 * - + opens the New sheet: the header's New menu (Navigation::newActions),
 *   so only what the active role may start; there is no + when it may start
 *   nothing.
 * - More opens the More sheet: the role's whole menu (Navigation::for), by
 *   area, with My profile on top.
 *
 * Both sheets are dialogs (aria-modal, focus trapped and given back, Escape,
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
        // The menu without its count: the count is read once, below, for the bar and the sheet alike.
        $nav = Navigation::for($user, Navigation::isDeliverResponsible($user), countInbox: false);
        $count = $nav['inbox'] ? $this->inboxCount() : 0;

        foreach ($items as $i => $item) {
            if ($item['route'] === 'inbox') {
                $items[$i]['count'] = $count;
            }
        }

        return view('livewire.layout.bottom-nav', [
            'items' => $items,
            'before' => array_slice($items, 0, BottomBar::BEFORE_NEW),
            'after' => array_slice($items, BottomBar::BEFORE_NEW),
            'actions' => Navigation::newActions($user),
            'sections' => $this->sections($nav, $count, (bool) $user->profile),
        ]);
    }

    /**
     * The More sheet: My profile and the work inbox on top, then each area of
     * the role's menu in its order; a group (Project basics, Statistics) is a
     * heading followed by its pages.
     *
     * @return list<array{id: string, label: ?string, rows: list<array<string, mixed>>}>
     */
    private function sections(array $nav, int $count, bool $hasProfile): array
    {
        $link = fn (string $id, string $route, string $label, ?string $icon, array $active, int $count = 0): array => [
            'id' => $id,
            'href' => route($route),
            'label' => $label,
            'icon' => $icon && View::exists('components.icons.'.$icon) ? $icon : 'document-text',
            'current' => $this->currentRoute !== '' && Str::is($active, $this->currentRoute),
            'count' => $count,
        ];

        $top = array_values(array_filter([
            $hasProfile ? $link('my-profile', 'my-profile', __('general.my-profile'), 'user', ['my-profile']) : null,
            $nav['inbox'] ? $link('nav.inbox', $nav['inbox']['route'], __('general.nav.work-inbox'), 'notification', [$nav['inbox']['route']], $count) : null,
        ]));

        $sections = $top === [] ? [] : [['id' => 'me', 'label' => null, 'rows' => $top]];

        foreach ($nav['areas'] as $area) {
            $rows = [];

            foreach ($area['items'] as $item) {
                if (isset($item['children'])) {
                    $rows[] = ['id' => $item['id'], 'heading' => $item['label']];

                    foreach ($item['children'] as $child) {
                        $rows[] = $link($child['id'], $child['route'], $child['label'], $child['icon'] ?? $item['icon'], $child['active']) + ['group' => $item['label']];
                    }

                    continue;
                }

                $rows[] = $link($item['id'], $item['route'], $item['label'], $item['icon'], $item['active']);
            }

            $sections[] = ['id' => $area['id'], 'label' => $area['label'], 'rows' => $rows];
        }

        return $sections;
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
