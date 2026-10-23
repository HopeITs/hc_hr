<?php

namespace App\Livewire\Position;

use App\Models\Center;
use App\Models\City;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Support\DirectoryAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OrgChart extends Component
{
    /** Filter keys that, when changed, require the tree to be rebuilt. */
    private const TREE_FILTERS = [
        'city_id', 'program_id', 'center_id', 'department_id',
        'job_level_id', 'focus_manager_id', 'manager_type',
    ];

    /** The main "parent" city (Syria) that scopes the whole org. */
    private const MAIN_CITY_ID = 1;

    /**
     * Job-level grade shared by the chief executive and the deputies. The top of
     * the chart is grouped by *grade* rather than by the stored reporting line, so
     * an executive parented into a branch still reads at their real rank.
     */
    private const EXECUTIVE_GRADE = 1;

    /**
     * Title fragments that split the deputy tier (level 2) off from the chief
     * executive. Matched against the raw title columns so the tier is stable
     * whichever locale is active.
     */
    private const DEPUTY_TITLES = ['deputy', 'نائب'];

    /** @var Collection<int, City> */
    public $cities;

    /** @var Collection<int, Program> */
    public $programs;

    /** @var Collection<int, Center> */
    public $centers;

    /** @var Collection<int, Department> */
    public $departments;

    /** @var Collection<int, JobLevel> */
    public $jobLevels;

    /** Flat manager list for the "focus on a manager" filter. */
    public Collection $managers;

    public array $collapsed = [];

    public array $stats = [
        'positions' => 0,
        'employees' => 0,
        'vacancies' => 0,
        'managers' => 0,
    ];

    // ---- Filters --------------------------------------------------------
    public ?int $city_id = null;

    public ?int $program_id = null;

    public ?int $center_id = null;

    public ?int $department_id = null;

    public ?int $job_level_id = null;

    /** Re-root the chart on a specific manager's subtree. */
    public ?int $focus_manager_id = null;

    /** Free-text search across employee name and position title. */
    public string $search = '';

    /**
     * Active structural reporting line. Locked to "direct": the technical-manager
     * line is retired from the UI, but the tree code still supports "tech".
     */
    #[Locked]
    public string $manager_type = 'direct';

    /**
     * Whether unfilled positions are drawn. Turning this off hides vacant *leaves*
     * only — a vacancy that manages a team stays, because dropping it would take
     * its whole branch off the chart with it.
     */
    public bool $showVacancies = true;

    // ---- View options ---------------------------------------------------
    /** Active page view: "chart" (tree) or "directory" (employee list). */
    public string $view = 'chart';

    /** "comfortable" or "compact" — compact maximises cards on screen. */
    public string $density = 'comfortable';

    public float $scale = 1.0;

    public int $managersCount = 0;

    /**
     * The built chart tree. Protected (not a Livewire public property) so the
     * Eloquent models — with their *filtered* eager-loaded team relations — are
     * never serialized/rehydrated between requests. Rehydration re-fetches models
     * fresh and drops the constrained eager loads, which would make collapsing /
     * zooming lazy-load every report unfiltered. Instead it is rebuilt once per
     * request in render().
     */
    protected array $tree = [];

    /** Whether the tree has already been built during the current request. */
    protected bool $treeBuilt = false;

    public function mount(): void
    {
        $this->managers = collect();
        $this->loadLookups();
        $this->refreshTree();
    }

    // -------------------------------------------------------------------
    // Interaction
    // -------------------------------------------------------------------

    public function toggleCollapse(int $id): void
    {
        $this->collapsed[$id] = ! ($this->collapsed[$id] ?? true);
    }

    public function expandAll(): void
    {
        foreach (array_keys($this->collapsed) as $id) {
            $this->collapsed[$id] = false;
        }

        $this->dispatch('expand-all');
    }

    public function collapseAll(): void
    {
        foreach (array_keys($this->collapsed) as $id) {
            $this->collapsed[$id] = true;
        }

        $this->dispatch('collapse-all');
    }

    public function toggleDensity(): void
    {
        $this->density = $this->density === 'compact' ? 'comfortable' : 'compact';
    }

    public function toggleVacancies(): void
    {
        $this->showVacancies = ! $this->showVacancies;
        $this->refreshTree();
    }

    public function zoomIn(): void
    {
        $this->scale = min(2, round($this->scale + 0.1, 1));
    }

    public function zoomOut(): void
    {
        $this->scale = max(0.3, round($this->scale - 0.1, 1));
    }

    public function fitToScreen(): void
    {
        $this->scale = max(0.3, min(1.0, 1.22 - ($this->managersCount * 0.05)));
        $this->dispatch('reset-pan');
    }

    public function resetZoom(): void
    {
        $this->scale = 1.0;
        $this->dispatch('reset-pan');
    }

    public function resetFilters(): void
    {
        $this->reset([
            'city_id', 'program_id', 'center_id',
            'department_id', 'job_level_id', 'focus_manager_id', 'search',
        ]);
        $this->manager_type = 'direct';
        $this->loadLookups();
        $this->refreshTree();
        $this->resetZoom();
    }

    public function updated(string $property): void
    {
        if ($property === 'city_id') {
            $this->updateCityOptions();
        }

        if ($property === 'program_id') {
            $this->updateProgramOptions();
        }

        if (in_array($property, ['city_id', 'program_id', 'manager_type'], true)) {
            // The focus list depends on city/program/line; reset a focus that no
            // longer appears so we never re-root on a hidden manager.
            $this->refreshManagerOptions();

            if ($this->focus_manager_id && ! $this->managers->contains('id', $this->focus_manager_id)) {
                $this->focus_manager_id = null;
            }
        }

        if (in_array($property, self::TREE_FILTERS, true)) {
            $this->refreshTree();
            $this->fitToScreen();
        }
    }

    /** Whether a free-text search is currently active. */
    public function getIsSearchingProperty(): bool
    {
        return trim($this->search) !== '';
    }

    /**
     * Flat list of positions matching the search term (by position title or
     * employee name, in either locale), honouring the active lookup filters.
     * A flat grid is clearer than trying to prune the hierarchy.
     *
     * @return Collection<int, Position>
     */
    public function getSearchResultsProperty(): Collection
    {
        if (! $this->isSearching) {
            return collect();
        }

        $term = '%'.trim($this->search).'%';

        return Position::query()
            ->visible()
            ->where(function (Builder $query) use ($term) {
                $query->where('title_en', 'like', $term)
                    ->orWhere('title_ar', 'like', $term)
                    // A name only matches someone the viewer may see, or a
                    // search would confirm a former employee's existence.
                    ->orWhereHas('activeContracts.employee', function (Builder $employee) use ($term) {
                        DirectoryAccess::constrain($employee->where(fn (Builder $name) => $name
                            ->where('first_name_en', 'like', $term)
                            ->orWhere('last_name_en', 'like', $term)
                            ->orWhere('first_name_ar', 'like', $term)
                            ->orWhere('last_name_ar', 'like', $term)), auth()->user());
                    });
            })
            ->when($this->cityFilterIds(), fn (Builder $query, array $ids) => $query->whereIn('city_id', $ids))
            ->when($this->program_id, fn (Builder $query) => $query->where('program_id', $this->program_id))
            ->when($this->center_id, fn (Builder $query) => $query->where('center_id', $this->center_id))
            ->when($this->department_id, fn (Builder $query) => $query->where('department_id', $this->department_id))
            ->when($this->job_level_id, fn (Builder $query) => $query->where('job_level_id', $this->job_level_id))
            ->with($this->cardRelations())
            ->orderByGrade()
            ->limit(60)
            ->get();
    }

    /** Count of filters currently narrowing the chart (drives the UI badge). */
    public function getActiveFilterCountProperty(): int
    {
        return collect([
            $this->city_id, $this->program_id, $this->center_id,
            $this->department_id, $this->job_level_id, $this->focus_manager_id,
        ])->filter()->count();
    }

    public function render()
    {
        // Actions that don't change a tree filter (collapse, zoom, density…) skip
        // refreshTree(); rebuild here so the view always renders a freshly filtered
        // tree rather than a rehydrated, unfiltered one.
        if (! $this->treeBuilt) {
            $this->refreshTree();
        }

        // The focus list comes back from the snapshot without its holders;
        // reload them under the same Directory rule rather than letting the
        // view lazy-load every holder unfiltered.
        if ($this->managers instanceof EloquentCollection) {
            $this->managers->loadMissing(['activeContracts' => DirectoryAccess::holders(auth()->user()), 'jobLevel']);
        }

        return view('livewire.position.org-chart', ['tree' => $this->tree]);
    }

    // -------------------------------------------------------------------
    // Lookups
    // -------------------------------------------------------------------

    private function loadLookups(): void
    {
        $this->cities = City::where('is_active', true)->orderBy(transColumn('name'))->get();
        $this->programs = Program::where('is_active', true)->orderBy('name')->get();
        $this->centers = Center::where('is_active', true)->orderBy(transColumn('name'))->get();
        $this->departments = Department::orderBy('name')->get();
        $this->jobLevels = JobLevel::orderBy('grade')->get();
        $this->refreshManagerOptions();
    }

    /** Managers available to focus on, scoped by the active reporting line. */
    private function refreshManagerOptions(): void
    {
        $this->managers = Position::query()
            ->visible()
            ->managersOnly($this->manager_type)
            ->whereHas('activeContracts')
            ->when($this->cityFilterIds(), fn (Builder $q, array $ids) => $q->whereIn('city_id', $ids))
            ->when($this->program_id, fn (Builder $q) => $q->where('program_id', $this->program_id))
            ->with(['activeContracts' => DirectoryAccess::holders(auth()->user()), 'jobLevel'])
            ->orderByGrade()
            ->get();
    }

    /**
     * Position::cardRelations(), with each card's holders limited to the
     * people the viewer may see at the Directory tier (access-policy.md
     * A2D-Q3): a former employee still sitting on a running contract is not
     * drawn for staff, and the seat reads as vacant. No city scoping: every
     * role sees every active colleague here (DEC-01, A2D-Q4).
     *
     * @return array<int|string, mixed>
     */
    private function cardRelations(): array
    {
        return [
            ...Position::cardRelations($this->manager_type),
            'activeContracts' => DirectoryAccess::holders(auth()->user()),
        ];
    }

    private function updateCityOptions(): void
    {
        if ($this->city_id === 1 || $this->city_id === null) {
            $this->centers = Center::where('is_active', true)->orderBy(transColumn('name'))->get();
            $this->programs = Program::where('is_active', true)->orderBy('name')->get();
        } else {
            $this->centers = Center::where('is_active', true)
                ->where('city_id', $this->city_id)
                ->orderBy(transColumn('name'))
                ->get();
            $this->programs = Program::where('is_active', true)
                ->whereHas('positions', fn (Builder $query) => $query->where('city_id', $this->city_id))
                ->orderBy('name')
                ->get();
        }

        $this->center_id = null;
        $this->program_id = null;
    }

    private function updateProgramOptions(): void
    {
        if ($this->program_id === 1 || $this->program_id === null) {
            $this->centers = Center::where('is_active', true)->orderBy(transColumn('name'))->get();
        } else {
            $this->centers = Center::where('is_active', true)
                ->whereHas('positions', fn (Builder $query) => $query->where('program_id', $this->program_id))
                ->orderBy(transColumn('name'))
                ->get();
        }

        $this->center_id = null;
    }

    // -------------------------------------------------------------------
    // Tree building
    // -------------------------------------------------------------------

    private function refreshTree(): void
    {
        $this->tree = $this->buildTree();
        $this->treeBuilt = true;
        $this->collapsed = $this->syncCollapsedState();
        $this->updateStats();
    }

    /** Keep collapse state for every branch that has direct reports. */
    private function syncCollapsedState(): array
    {
        $teamRelation = $this->manager_type.'Team';
        $ids = collect();

        $walk = function ($items) use (&$walk, &$ids, $teamRelation): void {
            foreach ($items as $position) {
                $team = $position->getRelation($teamRelation);

                if ($team->isNotEmpty()) {
                    $ids->push($position->id);
                }

                $walk($team);
            }
        };

        $walk($this->tree['managers']);

        return $ids->unique()
            ->mapWithKeys(fn (int $id) => [$id => $this->collapsed[$id] ?? true])
            ->all();
    }

    private function buildTree(): array
    {
        // The whole tree follows the active reporting line: switching to "tech"
        // re-roots on the tech-manager chain rather than grafting tech teams onto
        // the direct-manager skeleton.
        $managerColumn = $this->manager_type.'_manager_id';
        $teamRelation = $this->manager_type.'Team';
        $relations = $this->cardRelations();

        $rootPositions = $this->resolveRoots($managerColumn, $relations);

        if ($rootPositions->isEmpty()) {
            $this->managersCount = 0;

            return ['root' => collect(), 'deputies' => collect(), 'managers' => collect()];
        }

        $rootIds = $rootPositions->pluck('id');

        // Tier 2: the deputy executives, selected by grade + title and lifted out of
        // whatever branch they happen to be parented to, so the rank reads as one row
        // instead of scattering down the chart. They render as plain cards — their own
        // reports are not nested beneath them but promoted onto tier 3, where their
        // grade puts them. Focusing on a manager collapses this tier: the focused
        // manager IS the root and their team becomes the first level.
        $deputies = $this->focus_manager_id
            ? collect()
            : Position::query()
                ->visible()
                ->whereNotIn('id', $rootIds)
                ->whereHas('jobLevel', fn (Builder $query) => $query->where('grade', self::EXECUTIVE_GRADE))
                ->with($relations)
                ->orderBy('id')
                ->get()
                ->filter($this->isDeputy(...))
                ->values();

        // Tiers 1-2 combined. Excluded from the recursion below so a lifted deputy
        // never renders twice — once on their own row and once inside a branch.
        $topIds = $rootIds->concat($deputies->pluck('id'))->all();

        $teamLoader = function (Relation $query) use (&$teamLoader, $teamRelation, $relations, $topIds): void {
            $this->applyFilters($query)
                ->whereNotIn('id', $topIds)
                ->orderByGrade()
                ->with([...$relations, $teamRelation => $teamLoader]);
        };

        // Tier 3: the managerial row, sourced from the children of tiers 1 *and* 2.
        // Reading from the root alone is what used to crop a position parented to a
        // deputy — the Senior Financial Manager hangs off the Deputy CEO — silently
        // taking its whole sub-tree off the chart with it. Grade drives the split
        // from here: the view keeps managerial grades on this row and pools the
        // operational grades into tier 4, and every sub-tree expands as before.
        //
        // They flow through the same applyFilters() as the recursion so every tier
        // obeys one rule; the root always stays visible and the filters only prune
        // the branches below it.
        $managers = Position::query()
            ->whereIn($managerColumn, $topIds)
            ->whereNotIn('id', $topIds)
            ->tap(fn (Builder $query) => $this->applyFilters($query))
            ->orderByGrade()
            ->with([...$relations, $teamRelation => $teamLoader])
            ->get();

        $this->managersCount = $managers->count();

        return [
            'root' => $rootPositions,
            'deputies' => $deputies,
            'managers' => $managers,
        ];
    }

    /**
     * Does this executive belong on the deputy row rather than beside the chief
     * executive? Matched on the raw title columns rather than the locale accessor
     * so the tier does not shift between EN and AR.
     */
    private function isDeputy(Position $position): bool
    {
        $title = mb_strtolower($position->title_en.' '.$position->title_ar);

        foreach (self::DEPUTY_TITLES as $needle) {
            if (str_contains($title, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function resolveRoots(string $managerColumn, array $relations): Collection
    {
        // A focused manager becomes the single source of truth for the root.
        if ($this->focus_manager_id) {
            return Position::query()
                ->visible()
                ->whereKey($this->focus_manager_id)
                ->with($relations)
                ->get();
        }

        // Deputies are rejected here so they fall through to tier 2 rather than
        // sharing the level 1 row — the Deputy CEO carries no manager of their own
        // and would otherwise qualify as a root. Same reason on the tech line, where
        // most executives have no tech manager: rejecting only the deputies keeps
        // that row populated without dropping the other roots, and their branches,
        // off the chart entirely.
        $roots = Position::query()
            ->visible()
            ->whereNull($managerColumn)
            ->whereHas('jobLevel', fn (Builder $query) => $query->where('grade', self::EXECUTIVE_GRADE))
            ->with($relations)
            ->orderByGrade()
            ->orderBy('id')
            ->get()
            ->reject($this->isDeputy(...))
            ->values();

        if ($roots->isNotEmpty()) {
            return $roots;
        }

        return Position::query()
            ->visible()
            ->whereNull($managerColumn)
            ->with($relations)
            ->orderByGrade()
            ->limit(1)
            ->get();
    }

    /**
     * City ids the chart should keep when a city filter is active. Filtering on
     * a branch city (e.g. Latakia) still keeps the main city (Syria) so the
     * HQ/parent positions stay visible and the tree remains connected.
     *
     * @return list<int>|null Null when no city filter is active.
     */
    private function cityFilterIds(): ?array
    {
        if (! $this->city_id) {
            return null;
        }

        return array_values(array_unique([self::MAIN_CITY_ID, $this->city_id]));
    }

    private function applyFilters(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->visible()
            ->whereNotNull($this->manager_type.'_manager_id')
            // Hiding vacancies keeps the ones that lead a team: removing a vacant
            // manager would orphan its reports and silently drop the whole branch.
            // The child check is deliberately unqualified — inside a self-referencing
            // whereHas Laravel aliases the table, so visible() would resolve its
            // qualified columns against the outer query instead.
            ->when(! $this->showVacancies, fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->whereHas('activeContracts')
                    ->orWhereHas($this->manager_type.'Team', fn (Builder $team) => $team->where('is_active', true)),
            ))
            ->when($this->cityFilterIds(), fn (Builder $query, array $ids) => $query->whereIn('city_id', $ids))
            ->when($this->program_id, fn (Builder $query) => $query->where('program_id', $this->program_id))
            ->when($this->center_id, fn (Builder $query) => $query->where('center_id', $this->center_id))
            ->when($this->department_id, fn (Builder $query) => $query->where('department_id', $this->department_id))
            ->when($this->job_level_id, fn (Builder $query) => $query->where('job_level_id', $this->job_level_id));
    }

    private function updateStats(): void
    {
        $positions = collect($this->tree['root'])
            ->concat($this->tree['deputies'])
            ->concat($this->tree['managers']);
        $teamRelation = $this->manager_type.'Team';

        $walk = function ($items) use (&$walk, &$positions, $teamRelation): void {
            foreach ($items as $position) {
                $children = $position->getRelation($teamRelation);
                $positions->push(...$children);
                $walk($children);
            }
        };

        // Only tier 3 carries loaded team relations; tiers 1 and 2 are flat rows.
        $walk($this->tree['managers']);

        $positions = $positions->unique('id')->values();
        $employees = $positions->sum(fn (Position $position) => $position->activeContracts->count());
        $vacancies = $positions->sum(
            fn (Position $position) => max(0, $position->max_emp - $position->activeContracts->count()),
        );

        $this->stats = [
            'positions' => $employees + $vacancies,
            'employees' => $employees,
            'vacancies' => $vacancies,
            'managers' => $this->managersCount,
        ];
    }
}
