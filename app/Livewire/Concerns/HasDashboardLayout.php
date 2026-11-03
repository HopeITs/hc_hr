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
     * One catalogue for the whole request, so an action that saves and then
     * renders (Add, a ready layout) reads the role's shortcuts once, not twice.
     * Not a public property: Livewire does not keep it, each request starts fresh.
     */
    protected ?DashboardLayout $dashboardTiles = null;

    /**
     * The whole layout as the browser now shows it.
     *
     * @param  array<int, array{key: string, size: string, hidden: bool, style?: string, fixed?: bool}>  $items
     */
    #[Renderless]
    public function saveLayout(array $items): void
    {
        $user = auth()->user();

        $valid = Validator::make(['items' => $items], [
            'items' => ['present', 'array', 'list', 'max:'.DashboardLayout::MAX_ITEMS],
            'items.*' => ['required', 'array:key,size,hidden,style,fixed'],
            'items.*.key' => ['required', 'string', 'max:100'],
            'items.*.size' => ['required', 'string', 'regex:/^\d{1,2}x\d{1,2}$/'],
            'items.*.hidden' => ['required', 'boolean:strict'],
            'items.*.style' => ['sometimes', 'string', 'max:20'],
            // Sized by hand: kept at exactly that size (DashboardLayout::clean).
            'items.*.fixed' => ['sometimes', 'boolean:strict'],
        ])->validate();

        SavedDashboardLayout::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'role' => DashboardLayout::roleKey($user)],
            ['layout' => $this->dashboardTiles()->clean($user, $valid['items'])],
        );
    }

    /**
     * Add, for a tile the page never drew: a hidden tile is not rendered at
     * all, so the browser cannot simply show it. Saves exactly as saveLayout
     * does and, unlike it, renders, so the new tile arrives (lazily, like the
     * others). Tiles already on the page are left as they are.
     *
     * @param  array<int, array{key: string, size: string, hidden: bool, style?: string, fixed?: bool}>  $items
     */
    public function addTile(array $items): void
    {
        $this->saveLayout($items);
    }

    /**
     * "Customize your desk" → a ready layout (DashboardLayout::PRESETS): its
     * cards shown at its sizes, the other cards hidden.
     * Re-renders, so the page shows it. A layout this person may not use is
     * refused without touching what they have.
     */
    public function applyPreset(string $preset): void
    {
        $user = auth()->user();
        $tiles = $this->dashboardTiles();
        $layout = $tiles->applyPreset($user, $preset, $this->dashboardLayout($tiles));

        if ($layout === null) {
            return;
        }

        SavedDashboardLayout::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'role' => DashboardLayout::roleKey($user)],
            ['layout' => $layout],
        );
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

    protected function dashboardTiles(): DashboardLayout
    {
        return $this->dashboardTiles ??= app(DashboardLayout::class);
    }

    /**
     * The caller's cleaned layout for the active role: what the page renders.
     * Pass the page's own DashboardLayout so the role's menu is read once.
     *
     * @return array<int, array{key: string, size: string, hidden: bool, style?: string, fixed?: bool}>
     */
    protected function dashboardLayout(?DashboardLayout $tiles = null): array
    {
        $user = auth()->user();

        $stored = SavedDashboardLayout::query()
            ->where('user_id', $user->getKey())
            ->where('role', DashboardLayout::roleKey($user))
            ->value('layout');

        return ($tiles ?? $this->dashboardTiles())->clean($user, is_string($stored) ? json_decode($stored, true) : $stored);
    }
}
