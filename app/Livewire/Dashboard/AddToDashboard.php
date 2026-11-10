<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\WithFeedback;
use App\Models\SavedDashboardLayout;
use App\Support\Dashboard\DashboardLayout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Add to dashboard" on a page or a section of one: puts that page's live
 * card (a widget, or a record card "rec.<type>.<id>") on the viewer's own
 * dashboard for the active role, first after the pinned cards, at its usual
 * size; pressed again it takes it off. Self-scoped like saveLayout: only the
 * caller's own row, and DashboardLayout::allowed() decides, so a card the
 * person may not have is never offered and never stored.
 */
class AddToDashboard extends Component
{
    use WithFeedback;

    #[Locked]
    public string $tile = '';

    /** A small icon button (for a section heading) instead of a labelled one. */
    #[Locked]
    public bool $compact = false;

    public function toggle(): void
    {
        $user = auth()->user();
        $tiles = app(DashboardLayout::class);

        abort_unless($user && $tiles->allowed($user, $this->tile), 403);

        $layout = $this->layout($tiles);
        $index = array_search($this->tile, array_column($layout, 'key'), true);
        $on = $index !== false && ! $layout[$index]['hidden'];

        if ($on) {
            // Off the dashboard: hidden, and still offered in Add (a card added from a page simply leaves).
            $layout[$index]['hidden'] = true;
            unset($layout[$index]['min']);
            if ($tiles->isAddedCard($this->tile)) {
                array_splice($layout, $index, 1);
            }
        } else {
            if ($index !== false) {
                array_splice($layout, $index, 1);
            }
            $item = ['key' => $this->tile, 'size' => $tiles->defaultSize($this->tile), 'pinned' => false, 'hidden' => false];
            $pinned = count(array_filter($layout, fn (array $i): bool => $i['pinned']));
            array_splice($layout, $pinned, 0, [$item]);
        }

        SavedDashboardLayout::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'role' => DashboardLayout::roleKey($user)],
            ['layout' => $tiles->clean($user, $layout)],
        );

        // Undo is the same toggle again.
        $this->toast(__($on ? 'dashboard.add.removed' : 'dashboard.add.added', ['tile' => $tiles->label($this->tile, $user)]), undo: 'toggle');
    }

    public function render()
    {
        $user = auth()->user();
        $tiles = app(DashboardLayout::class);
        $allowed = $user && $this->tile !== '' && $tiles->allowed($user, $this->tile);
        $on = false;

        if ($allowed) {
            $item = collect($this->layout($tiles))->firstWhere('key', $this->tile);
            $on = $item !== null && ! $item['hidden'];
        }

        return view('livewire.dashboard.add-to-dashboard', ['allowed' => $allowed, 'on' => $on]);
    }

    /**
     * The caller's cleaned layout for the active role (as the dashboard reads
     * it). Private on purpose: this button exposes toggle() and nothing else.
     *
     * @return array<int, array<string, mixed>>
     */
    private function layout(DashboardLayout $tiles): array
    {
        $user = auth()->user();
        $stored = SavedDashboardLayout::query()
            ->where('user_id', $user->getKey())
            ->where('role', DashboardLayout::roleKey($user))
            ->value('layout');

        return $tiles->clean($user, is_string($stored) ? json_decode($stored, true) : $stored);
    }
}
