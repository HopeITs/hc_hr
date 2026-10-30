<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * "Contact the team" tile: the CEO and deputies, the viewer's direct manager,
 * then everyone else. Loaded on its own (it was a section of Dashboard\Index),
 * so a failure shows inside this tile only.
 */
class TeamCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public function render()
    {
        return view('livewire.dashboard.team-card', [
            'members' => $this->guardedLoad(fn () => $this->loadTeamMembers(), collect()),
        ]);
    }

    protected function loadTeamMembers(): Collection
    {
        [$viewerEmployee, $viewerContract] = $this->tileViewer();

        if (! $viewerContract?->position) {
            return collect();
        }

        $locale = app()->getLocale();
        $titleCol = $locale === 'ar' ? 'title_ar' : 'title_en';

        $position = $viewerContract->position;

        $members = collect();

        // The CEO and the Deputy CEO always head the list. Everyone else below
        // must be active and hold a contract running today.
        $ceo = Employee::query()
            ->where('id', 1)
            ->with(['media', 'contracts.position.jobLevel'])
            ->first();

        // The CEO and the deputies are pinned by id and by position rather
        // than by the is_active filter below, so they go through the Directory
        // rule instead: a former holder is not shown to staff and managers
        // (access-policy.md A2D-Q3).
        $viewer = auth()->user();
        $visible = fn (?Employee $employee) => $employee && $viewer
            && Gate::forUser($viewer)->allows('viewDirectory', $employee);

        if ($ceo && ! $visible($ceo)) {
            $ceo = null;
        }

        if ($ceo) {
            $ceo->positionTitle = __('dashboard.ceo-title');
            $members->push($ceo);
        }

        // The deputies (Deputy CEO, Second Deputy CEO) are found by position —
        // executive grade + "deputy" title, as the org chart does — from any
        // contract, so they stay even without a current one.
        $deputies = Contract::query()
            ->whereHas('position', fn ($query) => $query
                ->whereHas('jobLevel', fn ($level) => $level->where('grade', 1))
                ->where(fn ($title) => $title
                    ->where('title_en', 'like', '%deputy%')
                    ->orWhere('title_ar', 'like', '%نائب%')))
            ->when($ceo, fn ($query) => $query->where('employee_id', '!=', $ceo->id))
            ->with(['position', 'employee.media', 'employee.contracts.position'])
            ->latest('start_date')
            ->get()
            ->unique('employee_id')
            ->sortBy('position_id')
            ->filter(fn (Contract $contract) => $visible($contract->employee))
            ->map(function (Contract $contract) use ($titleCol) {
                $contract->employee->positionTitle = $contract->position?->$titleCol ?? '—';

                return $contract->employee;
            })
            ->values();

        $members->push(...$deputies);

        $directManager = null;
        if ($position?->direct_manager_id) {
            $managerPosition = Position::with(['jobLevel', 'activeContracts.employee.media'])->find($position->direct_manager_id);

            if ($managerPosition) {
                $activeContract = $managerPosition
                    ->activeContracts()
                    ->with(['employee.contracts.position.jobLevel'])
                    ->latest('start_date')
                    ->first();

                $directManager = $activeContract?->employee;

                if ($directManager && ! $directManager->is_active) {
                    $directManager = null;
                }

                if ($directManager) {
                    $directManager->positionTitle = $activeContract?->position?->$titleCol ?? '—';
                }
            }
        }

        if ($directManager && ! $members->contains('id', $directManager->id)) {
            $members->push($directManager);
        }

        $nameCol = "name_{$locale}";

        $excludeIds = collect([$viewerEmployee?->id, $ceo?->id, $directManager?->id])
            ->merge($deputies->pluck('id'))
            ->filter()
            ->unique()
            ->all();

        // Every employee in the organization: head office (Syria-level
        // positions) first, then the viewer's own city, then the other cities
        // one at a time in city-code order (Aleppo, Homs, Damascus, ...). Each
        // group is ordered by job grade.
        $ownCityId = $viewerEmployee?->city_id;
        $headOfficeCityId = City::query()->where('slug', City::NATIONAL_SLUG)->value('id');

        $allEmployees = Employee::query()
            ->where('is_active', true)
            ->whereHas('contracts')
            ->whereNotIn('id', $excludeIds)
            ->whereNotNull('username')
            ->with(['media', 'account', 'city', 'contracts.position.jobLevel'])
            ->get()
            ->map(function ($emp) use ($nameCol, $titleCol, $ownCityId, $headOfficeCityId) {
                $contract = $emp->contracts->filter(fn ($c) => $c->is_active || (! $c->terminated_at && $c->start_date <= now() && $c->end_date >= now()))->sortByDesc('start_date')->first() ?? $emp->latestActiveContract();

                $emp->displayName = $emp->$nameCol ?? $emp->name_en;
                $emp->positionTitle = $contract?->position?->$titleCol ?? ($contract?->position?->title_en ?? '—');
                $emp->jobLevelGrade = $contract?->position?->jobLevel?->grade ?? 999;
                $emp->groupRank = match (true) {
                    $headOfficeCityId && $contract?->position?->city_id === $headOfficeCityId => 0,
                    $emp->city_id === $ownCityId => 1,
                    default => 2,
                };

                return $emp;
            })
            ->sortBy([
                fn ($a, $b) => $a->groupRank <=> $b->groupRank,
                fn ($a, $b) => $a->groupRank === 2 ? (string) $a->city?->code <=> (string) $b->city?->code : 0,
                fn ($a, $b) => $a->jobLevelGrade <=> $b->jobLevelGrade,
            ])
            ->values();

        // The card links each member's work email (account login) — load it in one query.
        $team = (new EloquentCollection($members->merge($allEmployees)->unique('id')->values()))
            ->loadMissing('account');

        // Decide once, here, who may be shown a photo (the same ability the
        // avatar endpoint enforces). The card then renders an <img> only for
        // those people: a forbidden photo is never requested, so it neither
        // costs a 403 round trip nor puts its URL in the HTML.
        $team->each(function (Employee $employee) use ($viewer): void {
            $employee->canViewAvatar = $viewer !== null
                && Gate::forUser($viewer)->allows('viewAvatar', $employee);
        });

        return $team;
    }
}
