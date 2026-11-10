<?php

namespace App\Livewire\Dashboard;

use App\Support\Dashboard\DashboardLayout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One dashboard card on a page of its own (2026-11-03): what a card shows when
 * it is taken out of the browser, as a window of its own or floating on top of
 * every program (the dashboard's pop-out, home-screen.js). The card is the same
 * component the grid mounts, with the same rule: DashboardLayout::allowed()
 * here, and the component's own checks inside it. A shortcut is a link, not a
 * card, so it has no window. Nothing is written: the window only reads.
 */
class Window extends Component
{
    #[Locked]
    public string $tile = '';

    public function mount(string $tile): void
    {
        $user = auth()->user();
        $tiles = app(DashboardLayout::class);

        // Unknown, not allowed or not a card: the same 404, so a key reveals nothing.
        abort_unless($user && ! $tiles->isApp($tile) && $tiles->allowed($user, $tile) && $tiles->component($tile) !== null, 404);

        $this->tile = $tile;
    }

    public function render()
    {
        $user = auth()->user();
        $tiles = app(DashboardLayout::class);
        $label = $tiles->label($this->tile, $user);

        return view('livewire.dashboard.window', [
            'component' => $tiles->component($this->tile),
            'params' => $tiles->componentParams($this->tile),
            'label' => $label,
            // A card with more than one look (the clock) shows its first one here.
            'look' => DashboardLayout::LOOKS[$this->tile][0] ?? '',
        ])->layout('components.layouts.window', ['title' => $label]);
    }
}
