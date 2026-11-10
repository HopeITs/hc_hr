<?php

namespace App\Livewire\Recruitment\Requests;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentRequestType;
use App\Models\HiringCampaign;
use App\Models\Position;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentPositionActionService;
use App\Services\RecruitmentScopeResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The manager's own slice of the org chart, drawn as a chart rather than a list.
 *
 * It is the same tree the organisation chart draws, re-rooted on the signed-in
 * manager's position and read through a staffing lens: every card carries the
 * seats it holds, the seats standing open, and the one request that can be
 * started from it. The chart is where a manager already pictures their team, so
 * starting the request there costs two clicks instead of a form that asks which
 * position they meant.
 */
class TeamStaffing extends Component
{
    /** @var array<int, bool> */
    public array $collapsed = [];

    /**
     * Prune the chart to the branches that lead to an unfilled seat.
     *
     * A manager of forty positions opens this page to find the few that need
     * them, not to read the roster. The filter that replaced "needs action" is
     * narrower on purpose: a request already in flight is visible on its own
     * card and needs no filter of its own, while an empty seat is the thing
     * this page exists to find.
     */
    #[Url(as: 'vacant', except: false)]
    public bool $vacantOnly = false;

    /**
     * Which face of the chart is showing.
     *
     * The same tree either way — the shape of the team is the point of the page
     * — but a manager asks two different questions of it. "Who works for me"
     * wants a card per person; "where is the establishment short" wants a card
     * per post, with its seats, requests and the action that fixes it. One
     * chart answering both at once answered neither clearly.
     */
    #[Url(as: 'view', except: 'staff')]
    public string $view = 'staff';

    public string $density = 'comfortable';

    public function boot(): void
    {
        Gate::authorize('create', RecruitmentRequest::class);
    }

    public function toggle(int $positionId): void
    {
        $this->collapsed[$positionId] = ! ($this->collapsed[$positionId] ?? false);
    }

    public function expandAll(): void
    {
        foreach ($this->positionIds() as $positionId) {
            $this->collapsed[$positionId] = false;
        }
    }

    public function collapseAll(): void
    {
        foreach ($this->positionIds() as $positionId) {
            $this->collapsed[$positionId] = true;
        }
    }

    public function toggleVacantOnly(): void
    {
        $this->vacantOnly = ! $this->vacantOnly;
        $this->collapsed = [];
    }

    /** @param  'staff'|'positions'  $view */
    public function setView(string $view): void
    {
        if (! in_array($view, ['staff', 'positions'], true)) {
            return;
        }

        $this->view = $view;
    }

    public function toggleDensity(): void
    {
        $this->density = $this->density === 'compact' ? 'comfortable' : 'compact';
    }

    public function render(): View
    {
        Gate::authorize('create', RecruitmentRequest::class);

        return view('livewire.recruitment.requests.team-staffing', $this->teamData(auth()->user()));
    }

    /**
     * @return array{root: ?array<string, mixed>, summary: array<string, int>, hasTeam: bool, hidden: int}
     */
    private function teamData(User $user): array
    {
        $scopes = app(RecruitmentScopeResolver::class);
        $managerPosition = $scopes->position($user);

        if ($managerPosition === null) {
            return $this->emptyState();
        }

        $root = Position::query()
            ->whereKey($managerPosition->getKey())
            ->with([...Position::cardRelations('direct'), 'directTeamRecursive'])
            ->first();

        if ($root === null) {
            return $this->emptyState();
        }

        $positions = collect();
        $flatten = function (Position $position) use (&$flatten, &$positions): void {
            if (! $position->is_active) {
                return;
            }

            $positions->put($position->getKey(), $position);

            foreach ($position->getRelation('directTeamRecursive')->where('is_active', true) as $child) {
                $flatten($child);
            }
        };
        $flatten($root);

        $positionIds = $positions->keys()->map(fn ($id): int => (int) $id)->values();
        $activeRequests = RecruitmentRequest::query()
            ->whereIn('target_position_id', $positionIds)
            ->whereIn('status', RecruitmentPositionActionService::ACTIVE_REQUEST_STATUSES)
            ->get()
            ->groupBy('target_position_id');
        $visibleRequests = RecruitmentRequest::query()
            ->visibleTo($user)
            ->whereIn('target_position_id', $positionIds)
            ->whereIn('status', RecruitmentPositionActionService::DISPLAY_REQUEST_STATUSES)
            ->get()
            ->groupBy('target_position_id');
        $openVacancies = Vacancy::query()
            ->whereIn('position_id', $positionIds)
            ->available()
            ->get()
            ->groupBy('position_id');
        $vacancyIds = $openVacancies->flatten()->pluck('id');
        $activeCampaignVacancyIds = $vacancyIds->isEmpty()
            ? collect()
            : HiringCampaign::query()
                ->whereIn('vacancy_id', $vacancyIds)
                ->whereIn('status', [HiringCampaignStatus::Published, HiringCampaignStatus::Open])
                ->pluck('vacancy_id')
                ->map(fn ($id): int => (int) $id);
        $proposedManagerIds = $scopes->scopeProposedManagers(Position::query(), $user)
            ->whereIn('id', $positionIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $actions = app(RecruitmentPositionActionService::class);

        $build = function (Position $position, int $depth) use (&$build, $activeRequests, $visibleRequests, $openVacancies, $activeCampaignVacancyIds, $proposedManagerIds, $actions, $root): array {
            $positionId = (int) $position->getKey();
            $isRoot = $positionId === (int) $root->getKey();
            $children = $position->getRelation('directTeamRecursive')
                ->filter(fn (Position $child): bool => $child->is_active)
                ->values()
                ->map(fn (Position $child): array => $build($child, $depth + 1))
                ->all();

            $availability = $actions->actionsFor(
                $position,
                $activeRequests->get($positionId, collect()),
                $openVacancies->get($positionId, collect()),
                $activeCampaignVacancyIds,
                $isRoot,
                in_array($positionId, $proposedManagerIds, true),
            );

            // Everything the chart already knows travels to the form, so the
            // form never asks again what the card was clicked on. A vacancy
            // only rides along when there is exactly one to mean: with two
            // open, which one is a real question and the form asks it.
            $vacancies = $openVacancies->get($positionId, collect());
            $onlyVacancyId = $vacancies->count() === 1 ? (int) $vacancies->first()->getKey() : null;

            $actionItems = [];
            foreach ($availability as $type => $item) {
                $isCreate = $type === 'create_position';
                $vacancyScoped = in_array((int) $type, [
                    RecruitmentRequestType::FillVacancy,
                    RecruitmentRequestType::CloseVacancy,
                ], true);

                $parameters = $isCreate
                    ? ['type' => RecruitmentRequestType::CreatePosition, 'direct_manager_id' => $positionId]
                    : ['type' => $type, 'position_id' => $positionId];

                if ($vacancyScoped && $onlyVacancyId !== null) {
                    $parameters['vacancy_id'] = $onlyVacancyId;
                }

                $actionItems[$type] = [
                    ...$item,
                    'label' => $isCreate
                        ? __('recruitment.team_staffing.create_position')
                        : RecruitmentRequestType::getLocalizedName((int) $type)['value'],
                    'url' => route('recruitment.requests.create', $parameters),
                ];
            }

            $requests = $visibleRequests->get($positionId, collect());
            $openSeats = max(0, (int) $position->max_emp - (int) $position->exist_emp);

            return [
                'position' => $position,
                'id' => $positionId,
                'depth' => $depth,
                'isRoot' => $isRoot,
                'children' => $children,
                'requests' => $requests,
                'openVacancies' => $openVacancies->get($positionId, collect()),
                'actions' => $actionItems,
                'openSeats' => $openSeats,
                // Filled in by the prune pass below, which can see the subtree.
                'subtreeOpenSeats' => 0,
                'passthrough' => false,
            ];
        };

        $tree = $this->summarise($build($root, 0));
        $visible = $this->vacantOnly ? $this->prune($tree) : $tree;

        $summary = [
            'positions' => $positions->count(),
            'filled' => (int) $positions->sum('exist_emp'),
            'capacity' => (int) $positions->sum('max_emp'),
            'vacancies' => (int) $positions->sum(fn (Position $position): int => max(0, (int) $position->max_emp - (int) $position->exist_emp)),
        ];

        return [
            'root' => $visible,
            'summary' => $summary,
            'hasTeam' => $positions->count() > 1,
            'hidden' => $positions->count() - $this->countNodes($visible),
        ];
    }

    /**
     * Roll the open seats of every descendant up into each node, so a collapsed
     * branch can still say how much is waiting inside it.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function summarise(array $node): array
    {
        $children = array_map(fn (array $child): array => $this->summarise($child), $node['children']);
        $node['children'] = $children;
        $node['subtreeOpenSeats'] = $node['openSeats']
            + array_sum(array_column($children, 'subtreeOpenSeats'));

        return $node;
    }

    /**
     * Drop every branch with no unfilled seat anywhere inside it. A node that is
     * fully staffed itself but carries a short descendant is kept as a
     * passthrough, so the reporting line stays readable instead of the chart
     * re-parenting itself onto a grandchild.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function prune(array $node): ?array
    {
        if (($node['subtreeOpenSeats'] ?? 0) < 1 && ! $node['isRoot']) {
            return null;
        }

        $node['children'] = collect($node['children'])
            ->map(fn (array $child): ?array => $this->prune($child))
            ->filter()
            ->values()
            ->all();
        $node['passthrough'] = $node['openSeats'] < 1 && ! $node['isRoot'];

        return $node;
    }

    /** @param  array<string, mixed>|null  $node */
    private function countNodes(?array $node): int
    {
        if ($node === null) {
            return 0;
        }

        return 1 + array_sum(array_map(fn (array $child): int => $this->countNodes($child), $node['children']));
    }

    /** @return list<int> */
    private function positionIds(): array
    {
        $root = app(RecruitmentScopeResolver::class)->position(auth()->user());

        if ($root === null) {
            return [];
        }

        return app(RecruitmentScopeResolver::class)
            ->manageablePositionIds(auth()->user(), true);
    }

    /** @return array{root: null, summary: array<string, int>, hasTeam: false, hidden: int} */
    private function emptyState(): array
    {
        return [
            'root' => null,
            'summary' => ['positions' => 0, 'filled' => 0, 'capacity' => 0, 'vacancies' => 0],
            'hasTeam' => false,
            'hidden' => 0,
        ];
    }
}
