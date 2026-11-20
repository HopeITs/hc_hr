<?php

namespace App\Livewire\ProgramManagement\Program;

use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The program page's "Employees" tab: everyone with a contract in force in one
 * of the program's positions. Paged by Livewire under its own name, so a page
 * link never reloads the program page (which would reset its tab and leave the
 * workspace shell). Head office first, then the cities in their code order,
 * each by job level; filters narrow by center, department and job level.
 */
class ProgramEmployees extends Component
{
    use WithPagination;

    public const PAGE_NAME = 'programEmployeesPage';

    public const FILTERS = ['centerId', 'departmentId', 'jobLevelId'];

    #[Locked]
    public int $programId;

    public string $search = '';

    #[Url(as: 'pe_center', except: '')]
    public ?string $centerId = '';

    #[Url(as: 'pe_dept', except: '')]
    public ?string $departmentId = '';

    #[Url(as: 'pe_level', except: '')]
    public ?string $jobLevelId = '';

    #[Locked]
    public int $perPage = 12;

    public function mount(int $programId): void
    {
        $this->programId = $programId;
        $this->authorizeProgram();
    }

    public function updated(string $property): void
    {
        if ($property === 'search' || in_array($property, self::FILTERS, true)) {
            $this->resetPage(self::PAGE_NAME);
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', ...self::FILTERS);
        $this->resetPage(self::PAGE_NAME);
    }

    public function render()
    {
        $program = $this->authorizeProgram();
        $employees = $this->employeeQuery()
            ->with([
                'media',
                'allContracts' => fn ($contracts) => $this->inProgram($contracts->activeNow())
                    ->with(['position.center.city', 'position.city', 'position.jobLevel'])
                    ->orderByDesc('start_date')
                    ->orderByDesc('id'),
            ])
            ->paginate($this->perPage, ['*'], self::PAGE_NAME);

        return view('livewire.program-management.program.program-employees', [
            'program' => $program,
            'employees' => $employees,
            'totalEmployees' => $program->employees()->count(),
            'filtering' => filled($this->search) || collect(self::FILTERS)->contains(fn ($f) => filled($this->{$f})),
            ...$this->filterOptions(),
        ]);
    }

    private function authorizeProgram(): Program
    {
        $program = Program::query()->findOrFail($this->programId);

        abort_unless(auth()->user()?->hasActivePermission('employees.view'), 403);

        return $program;
    }

    private function inProgram(Builder|HasMany $contracts): Builder|HasMany
    {
        return $contracts->whereHas('position', fn (Builder $position): Builder => $position->where('program_id', $this->programId));
    }

    /** A contract in force in this program whose position matches every filter set. */
    private function matchingContracts(Builder $contracts): Builder
    {
        return $contracts->activeNow()->whereHas('position', fn (Builder $position): Builder => $position
            ->where('program_id', $this->programId)
            ->when(filled($this->centerId), fn (Builder $q) => $q->where('center_id', (int) $this->centerId))
            ->when(filled($this->departmentId), fn (Builder $q) => $q->where('department_id', (int) $this->departmentId))
            ->when(filled($this->jobLevelId), fn (Builder $q) => $q->where('job_level_id', (int) $this->jobLevelId)));
    }

    private function employeeQuery(): Builder
    {
        $parts = preg_split('/\s+/u', trim($this->search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return Employee::query()
            ->whereHas('allContracts', fn (Builder $contracts) => $this->matchingContracts($contracts))
            ->when($parts !== [], function (Builder $employees) use ($parts): void {
                foreach ($parts as $part) {
                    $term = "%{$part}%";

                    $employees->where(function (Builder $match) use ($term): void {
                        $match->where('first_name_en', 'like', $term)
                            ->orWhere('first_name_ar', 'like', $term)
                            ->orWhere('last_name_en', 'like', $term)
                            ->orWhere('last_name_ar', 'like', $term)
                            ->orWhere('username', 'like', $term)
                            ->orWhereHas('allContracts', fn (Builder $contracts) => $this->inProgram($contracts->activeNow())
                                ->whereHas('position', fn (Builder $position): Builder => $position
                                    ->where('title_en', 'like', $term)
                                    ->orWhere('title_ar', 'like', $term)
                                    ->orWhereHas('center', fn (Builder $center): Builder => $center
                                        ->where('name_en', 'like', $term)
                                        ->orWhere('name_ar', 'like', $term))));
                    });
                }
            })
            ->orderBy($this->rank())
            ->orderBy(transColumn('first_name'))
            ->orderBy(transColumn('last_name'));
    }

    /**
     * Each person's place in the list, from their best-placed contract here:
     * head office (a national position) first, then the cities by their code
     * (Aleppo 001, Homs 002, ...), and within each the job level's grade
     * (Program Manager 1 ... Keepers 8). One number, so it sorts the same on
     * MySQL and SQLite.
     */
    private function rank(): Builder
    {
        $nationalId = (int) City::query()->where('slug', City::NATIONAL_SLUG)->value('id');
        // Through the grammar: production tables carry a prefix (hchr_) that raw SQL would miss.
        $col = fn (string $column): string => DB::connection()->getQueryGrammar()->wrap($column);

        return Contract::query()
            ->selectRaw(
                "MIN((CASE WHEN {$col('positions.city_id')} = ? THEN 0 ELSE 1 END) * 1000000"
                ." + COALESCE({$col('rank_cities.code')} + 0, 999) * 1000"
                ." + COALESCE({$col('job_levels.grade')}, 999))",
                [$nationalId],
            )
            ->join('positions', 'positions.id', '=', 'contracts.position_id')
            ->leftJoin('centers', 'centers.id', '=', 'positions.center_id')
            ->leftJoin('cities as rank_cities', 'rank_cities.id', '=', DB::raw("COALESCE({$col('positions.city_id')}, {$col('centers.city_id')})"))
            ->leftJoin('job_levels', 'job_levels.id', '=', 'positions.job_level_id')
            ->whereColumn('contracts.employee_id', 'employees.id')
            ->where('positions.program_id', $this->programId)
            ->activeNow();
    }

    /**
     * Only what this program's current contracts actually use, so no filter
     * offers a choice that can only come back empty.
     *
     * @return array<string, \Illuminate\Support\Collection>
     */
    private function filterOptions(): array
    {
        $positions = Position::query()
            ->where('program_id', $this->programId)
            ->whereHas('contracts', fn (Builder $contracts) => $contracts->activeNow())
            ->get(['id', 'city_id', 'center_id', 'department_id', 'job_level_id']);

        return [
            'centers' => Center::query()->whereIn('id', $positions->pluck('center_id')->filter()->unique())->with('city')->orderBy('city_id')->orderBy(transColumn('name'))->get(),
            'departments' => Department::query()->whereIn('id', $positions->pluck('department_id')->filter()->unique())->orderBy('name')->get(),
            'jobLevels' => JobLevel::query()->whereIn('id', $positions->pluck('job_level_id')->filter()->unique())->orderBy('grade')->get(),
        ];
    }
}
