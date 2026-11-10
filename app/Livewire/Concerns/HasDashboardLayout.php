<?php

namespace App\Livewire\Concerns;

use App\Models\SavedDashboardLayout;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Renderless;

/**
 * The customisable dashboard (DashboardLayout): the signed-in user's layout
 * for the role they are working under. The browser rearranges the tiles
 * itself and sends the whole layout back; it is validated, cleaned against
 * the catalogue and stored. Self-scoped: only ever the caller's own row, for
 * the caller's active role, and a tile the role may not have is dropped.
 *
 * The row is created on the first save, never on a read or a reset.
 */
trait HasDashboardLayout
{
    /**
     * The whole layout as the browser now shows it.
     *
     * @param  array<int, array{key: string, size: string, pinned: bool, hidden: bool, style?: string, fixed?: bool}>  $items
     */
    #[Renderless]
    public function saveLayout(array $items): void
    {
        $user = auth()->user();

        $valid = Validator::make(['items' => $items], [
            'items' => ['present', 'array', 'list', 'max:'.DashboardLayout::MAX_ITEMS],
            'items.*' => ['required', 'array:key,size,pinned,hidden,style,fixed'],
            'items.*.key' => ['required', 'string', 'max:100'],
            'items.*.size' => ['required', 'string', 'regex:/^\d{1,2}x\d{1,2}$/'],
            'items.*.pinned' => ['required', 'boolean:strict'],
            'items.*.hidden' => ['required', 'boolean:strict'],
            'items.*.style' => ['sometimes', 'string', 'max:20'],
            // Sized by hand: kept at exactly that size (DashboardLayout::clean).
            'items.*.fixed' => ['sometimes', 'boolean:strict'],
        ])->validate();

        SavedDashboardLayout::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'role' => DashboardLayout::roleKey($user)],
            ['layout' => app(DashboardLayout::class)->clean($user, $valid['items'])],
        );
    }

    /**
     * Add, for a tile the page never drew: a hidden tile is not rendered at
     * all, so the browser cannot simply show it. Saves exactly as saveLayout
     * does and, unlike it, renders, so the new tile arrives (lazily, like the
     * others). Tiles already on the page are left as they are.
     *
     * @param  array<int, array{key: string, size: string, pinned: bool, hidden: bool, style?: string, fixed?: bool}>  $items
     */
    public function addTile(array $items): void
    {
        $this->saveLayout($items);
    }

    /** Back to the role's default layout. Re-renders, so the page shows it. */
    public function resetLayout(): void
    {
        $user = auth()->user();

        SavedDashboardLayout::query()
            ->where('user_id', $user->getKey())
            ->where('role', DashboardLayout::roleKey($user))
            ->update(['layout' => null]);
    }

    /**
     * The caller's cleaned layout for the active role: what the page renders.
     * Pass the page's own DashboardLayout so the role's shortcuts are read once.
     *
     * @return array<int, array{key: string, size: string, pinned: bool, hidden: bool, style?: string, fixed?: bool}>
     */
    protected function dashboardLayout(?DashboardLayout $tiles = null): array
    {
        $user = auth()->user();

        $stored = SavedDashboardLayout::query()
            ->where('user_id', $user->getKey())
            ->where('role', DashboardLayout::roleKey($user))
            ->value('layout');

        return ($tiles ?? app(DashboardLayout::class))->clean($user, is_string($stored) ? json_decode($stored, true) : $stored);
    }
}
