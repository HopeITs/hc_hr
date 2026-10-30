<?php

namespace App\Livewire\Layout;

use App\Support\BottomBar;
use App\Support\NavigationMemory;
use Livewire\Component;

/**
 * Choosing the phone bar's four shortcuts (prompt 10), shown in the
 * dashboard's customise mode on a phone: <livewire:layout.bottom-bar-picker />.
 *
 * One select per place, so the bar is always complete: picking a page that
 * already sits in another place swaps the two. Each change is kept at once,
 * on the caller's own navigation_preferences row, and only shortcuts the
 * active role may use are accepted (BottomBar::options). The first
 * BottomBar::BEFORE_NEW places sit before the raised + and the rest after it;
 * + (the New sheet) and More (the whole menu) cannot be chosen away.
 */
class BottomBarPicker extends Component
{
    /** What the last change did, for the status line: saved | reset. */
    public ?string $status = null;

    /** Put $key in place $slot (0-based). Self-scoped; an unknown place or shortcut changes nothing. */
    public function setSlot(int $slot, string $key): void
    {
        $user = auth()->user();
        $options = BottomBar::options($user);
        $keys = array_column(BottomBar::slots($user, $options), 'key');

        if (! isset($keys[$slot], $options[$key])) {
            return;
        }

        // Already in another place: the two swap, so no shortcut is there twice.
        $from = array_search($key, $keys, true);

        if ($from !== false) {
            $keys[$from] = $keys[$slot];
        }

        $keys[$slot] = $key;

        if (NavigationMemory::setBottomBar($user, $keys, $options, count($keys))) {
            $this->status = 'saved';
            $this->dispatch('bottom-bar-changed');
        }
    }

    /** Back to the default bar. Self-scoped. */
    public function resetBar(): void
    {
        NavigationMemory::resetBottomBar(auth()->user());

        $this->status = 'reset';
        $this->dispatch('bottom-bar-changed');
    }

    public function render()
    {
        $user = auth()->user();
        $options = BottomBar::options($user);

        return view('livewire.layout.bottom-bar-picker', [
            'places' => BottomBar::slots($user, $options),
            'pages' => array_filter($options, fn (string $key) => ! BottomBar::isAction($key), ARRAY_FILTER_USE_KEY),
            'actions' => array_filter($options, fn (string $key) => BottomBar::isAction($key), ARRAY_FILTER_USE_KEY),
            'custom' => NavigationMemory::bottomBar($user, fn () => $options) !== null,
        ]);
    }
}
