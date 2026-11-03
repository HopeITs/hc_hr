<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\HasDashboardLayout;
use Livewire\Component;

/**
 * The dashboard, a desk of panels like hc-tracking's (DashboardLayout,
 * resources/js/dashboard/home-screen.js): the hello on top (Header), then the
 * panels the person arranged on a twelve-column grid. This component draws the
 * grid and nothing else: each panel is its own lazy Livewire component
 * (DashboardLayout::COMPONENTS) that loads its data when it comes into view
 * and shows a failure inside itself. A hidden panel is not drawn at all, so
 * its data is never read.
 */
class Index extends Component
{
    use HandlesLoadFailure;
    use HasDashboardLayout;

    public $employee;

    /**
     * Sections whose load failed during THIS request (card key => true). Not a
     * public property: it is recomputed on every render, and a retry simply re-renders.
     *
     * @var array<string, bool>
     */
    protected array $failedSections = [];

    /** @var array<string, mixed> Per-request memo so a card that reads $this->x after render() does not query twice. */
    protected array $memo = [];

    /** ?customise=1 (the command palette's "Customise dashboard"): the page opens with the Customize sheet. */
    protected bool $customise = false;

    public function mount(): void
    {
        $this->customise = request()->boolean('customise');

        $user = auth()->user();
        if (! $user) {
            abort(403, 'Unauthorized');
        }

        $this->employee = $user->profile()->first();
    }

    public function render()
    {
        $tiles = $this->dashboardTiles();
        $layout = $this->dashboardLayout($tiles);
        $shown = array_column(array_filter($layout, fn (array $item): bool => ! $item['hidden']), 'key');

        // Kept in the layout but not drawn, nor offered in Customize: a widget that has
        // no component yet, and "my leaves" for someone without a leave balance
        // (its card reads one; today's dashboard left it out the same way).
        $absent = array_values(array_filter(
            array_column($layout, 'key'),
            fn (string $key): bool => $tiles->isWidget($key) && $tiles->component($key) === null,
        ));

        if (in_array('my-leaves', $shown, true)
            // Only whether a balance exists: availableLeaves() also prices each
            // balance's pending hours, which MyLeavesCard reads in its own request.
            && ! $this->section('leaves', fn () => $this->employee?->latestActiveContract()?->leaveBalances->isNotEmpty() ?? false, false)
            && ! isset($this->failedSections['leaves'])) {
            $absent[] = 'my-leaves';
        }

        $user = auth()->user();

        return view('livewire.dashboard.index', [
            'layout' => $layout,
            'tiles' => $tiles,
            'absent' => $absent,
            // The page each shown panel opens in full (only pages the role's menu has).
            'pages' => array_filter(array_combine($shown, array_map(fn (string $key): ?string => $tiles->page($user, $key), $shown))),
            'customise' => $this->customise,
            // "Customize your desk": the ready layouts this person may use, the suggested one first.
            'presets' => $tiles->presets($user),
            'failedSections' => $this->failedSections,
        ]);
    }

    /**
     * Run one card's loader once per request. A failure is reported, marked on
     * $failedSections (the view swaps that card for x-ui.load-error) and returns
     * the fallback, so a broken card never blanks or 500s the whole dashboard.
     */
    protected function section(string $key, callable $loader, mixed $fallback): mixed
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $result = $this->guardedLoad($loader, $fallback);

        if ($this->loadFailed) {
            $this->failedSections[$key] = true;
        }

        // guardedLoad() resets the flag on success; keep it true if any section failed.
        $this->loadFailed = $this->failedSections !== [];

        return $this->memo[$key] = $result;
    }
}
