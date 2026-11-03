<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\HasDashboardLayout;
use App\Models\Contract;
use App\Models\User;
use App\Services\WorkInbox;
use App\Support\Dashboard\DashboardLayout;
use Livewire\Component;
use Throwable;

/**
 * The dashboard: a home screen of tiles the person arranges (DashboardLayout,
 * resources/js/dashboard/home-screen.js). This component draws the grid and
 * nothing else: each widget tile is its own lazy Livewire component
 * (DashboardLayout::COMPONENTS) that loads its data when it comes into view
 * and shows a failure inside itself. A hidden tile is not drawn at all, so
 * its data is never read.
 */
class Index extends Component
{
    use HandlesLoadFailure;
    use HasDashboardLayout;

    public $employee;

    public $positionTitle;

    /**
     * Sections whose load failed during THIS request (card key => true). Not a
     * public property: it is recomputed on every render, and a retry simply re-renders.
     *
     * @var array<string, bool>
     */
    protected array $failedSections = [];

    /** @var array<string, mixed> Per-request memo so a card that reads $this->x after render() does not query twice. */
    protected array $memo = [];

    /** ?customise=1 (the command palette's "Customise dashboard"): the page opens in edit mode. */
    protected bool $customise = false;

    public function mount(): void
    {
        $this->customise = request()->boolean('customise');

        $user = auth()->user();
        if (! $user) {
            abort(403, 'Unauthorized');
        }

        $this->employee = $user
            ->profile()
            ->with(['media', 'city'])
            ->first();

        // Only the greeting's job title is needed; the contract stays server-side.
        $contract = $this->employee
            ? Contract::with('position')
                ->where('employee_id', $this->employee->id)
                ->orderByDesc('start_date')
                ->first()
            : null;

        $this->positionTitle = $contract?->position?->{transColumn('title')} ?? '—';
    }

    public function render()
    {
        $user = auth()->user();
        $tiles = app(DashboardLayout::class);
        $layout = $this->dashboardLayout($tiles);
        $shown = array_column(array_filter($layout, fn (array $item): bool => ! $item['hidden']), 'key');

        // Kept in the layout but not drawn, nor offered in Add: a widget that has
        // no component yet, and "my leaves" for someone without a leave balance
        // (its card reads one; today's dashboard left it out the same way).
        $absent = array_values(array_filter(
            array_column($layout, 'key'),
            fn (string $key): bool => $tiles->isWidget($key) && $tiles->component($key) === null,
        ));

        if (in_array('my-leaves', $shown, true)
            && ! $this->section('leaves', fn () => $this->employee?->availableLeaves()?->count() > 0, false)
            && ! isset($this->failedSections['leaves'])) {
            $absent[] = 'my-leaves';
        }

        // The unused todayEvents list (36 queries for 100 employees: N+1 per
        // birthday) used to be computed here and never rendered; the
        // news.today-events-card component loads its own data.
        return view('livewire.dashboard.index', [
            'layout' => $layout,
            'tiles' => $tiles,
            'apps' => $tiles->apps($user),
            'absent' => $absent,
            // The page each shown widget opens on double-click (only pages the role's menu has).
            'pages' => array_filter(array_combine($shown, array_map(fn (string $key): ?string => $tiles->page($user, $key), $shown))),
            'inboxCount' => in_array(DashboardLayout::APP_PREFIX.'nav.inbox', $shown, true) ? $this->inboxCount($user) : 0,
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

    /** The inbox shortcut's badge: the same scoped count as the sidebar's (WorkInbox). */
    private function inboxCount(User $user): int
    {
        try {
            return app(WorkInbox::class)->shared($user)['counts']['all'];
        } catch (Throwable $e) {
            // A badge must not take the dashboard down with it.
            report($e);

            return 0;
        }
    }
}
