<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Holiday;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may set which holidays (team-coverage-contract §6.1, H-1 … H-6).
 *
 * A project is a program in one governorate, headed by a project-manager
 * position (config team-coverage.project_manager_grade). National HR
 * (HR-SYR, super-admin) sets any holiday. A program manager sets project
 * holidays and one-off grants only for the projects whose project-manager
 * position reports to one of their own positions — responsibility follows
 * direct_manager_id, not program_id. The picker and the validation both read
 * this class, so they cannot drift.
 */
class HolidayProjects
{
    /** @var array<string, Collection<string, array{program_id: int, city_id: int, label: string}>> */
    private array $managed = [];

    private ?Collection $all = null;

    public function isNationalHr(?User $user): bool
    {
        return (bool) $user?->activeRoleIs(...config('access-control.administrator_roles', []));
    }

    public function canManage(?User $user): bool
    {
        return $this->isNationalHr($user) || $this->managesAnyProject($user);
    }

    /** One cheap query, for the menu: does a project-manager position report to one of $user's positions? */
    public function managesAnyProject(?User $user): bool
    {
        if (! $user?->employee_id) {
            return false;
        }

        $own = Contract::query()->activeNow()->where('employee_id', $user->employee_id)->select('position_id');

        return $this->projectManagerPositions()->whereIn('direct_manager_id', $own)->exists();
    }

    /**
     * Every project, keyed "programId:cityId".
     *
     * @return Collection<string, array{program_id: int, city_id: int, label: string}>
     */
    public function all(): Collection
    {
        return $this->all ??= $this->toProjects($this->projectManagerPositions()->get());
    }

    /**
     * The projects $user may set holidays for: all of them for national HR.
     *
     * @return Collection<string, array{program_id: int, city_id: int, label: string}>
     */
    public function managedBy(?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        if ($this->isNationalHr($user)) {
            return $this->all();
        }

        $key = $user->getKey().':'.$user->myRole();

        if (isset($this->managed[$key])) {
            return $this->managed[$key];
        }

        $ownPositionIds = $user->employee_id
            ? Contract::query()->activeNow()->where('employee_id', $user->employee_id)->pluck('position_id')->filter()->unique()->all()
            : [];

        return $this->managed[$key] = $ownPositionIds === []
            ? collect()
            : $this->toProjects($this->projectManagerPositions()->whereIn('direct_manager_id', $ownPositionIds)->get());
    }

    /** May $user change this existing holiday? Every one of its projects must be theirs. */
    public function canChange(?User $user, Holiday $holiday): bool
    {
        if ($this->isNationalHr($user)) {
            return true;
        }

        if ($holiday->isNational() || $holiday->kind === Holiday::KIND_BIRTHDAY) {
            return false;
        }

        $links = $holiday->relationLoaded('projects') ? $holiday->projects : $holiday->projects()->get(['program_id', 'city_id']);
        $mine = $this->managedBy($user);

        return $links->isNotEmpty()
            && $links->every(fn ($l) => $mine->has($l->program_id.':'.$l->city_id));
    }

    /**
     * The non-birthday holidays $user looks after: every one for national
     * HR, those on any of their projects for a program manager.
     *
     * @return Builder<Holiday>
     */
    public function holidaysOf(?User $user): Builder
    {
        $query = Holiday::query()->with('projects')->where('kind', '!=', Holiday::KIND_BIRTHDAY);

        if ($this->isNationalHr($user)) {
            return $query;
        }

        $mine = $this->managedBy($user);

        if ($mine->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('projects', fn (Builder $link) => $link->where(function (Builder $any) use ($mine) {
            foreach ($mine as $p) {
                $any->orWhere(fn (Builder $pair) => $pair->where('program_id', $p['program_id'])->where('city_id', $p['city_id']));
            }
        }));
    }

    /** @return Builder<Position> */
    private function projectManagerPositions(): Builder
    {
        $grade = (int) config('team-coverage.project_manager_grade');

        return Position::query()
            ->where('positions.is_active', true)
            ->whereNotNull('program_id')
            ->whereHas('jobLevel', fn (Builder $level) => $level->where('grade', $grade))
            ->with(['program:id,name', 'center.city', 'city']);
    }

    /**
     * @param  Collection<int, Position>  $positions
     * @return Collection<string, array{program_id: int, city_id: int, label: string}>
     */
    private function toProjects(Collection $positions): Collection
    {
        return $positions
            ->map(function (Position $p) {
                $city = $p->center?->city ?? $p->city;

                return $city ? [
                    'program_id' => (int) $p->program_id,
                    'city_id' => (int) $city->id,
                    'label' => $p->program?->name.' — '.$city->name,
                ] : null;
            })
            ->filter()
            ->keyBy(fn ($p) => $p['program_id'].':'.$p['city_id'])
            ->sortBy('label')
            ->map(fn ($p) => $p);
    }
}
