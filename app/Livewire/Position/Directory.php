<?php

namespace App\Livewire\Position;

use App\Models\Center;
use App\Models\City;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Program;
use App\Support\DirectoryAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Directory extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    public ?int $department_id = null;

    public ?int $center_id = null;

    public ?int $city_id = null;

    public ?int $program_id = null;

    public ?int $job_level_id = null;

    /** "" | "active" | "board" */
    public string $status = '';

    public int $perPage = 24;

    public Collection $departments;

    public Collection $centers;

    public Collection $cities;

    public Collection $programs;

    public Collection $jobLevels;

    public function mount(): void
    {
        $this->departments = Department::orderBy('name')->get();
        $this->centers = Center::where('is_active', true)->orderBy(transColumn('name'))->get();
        $this->cities = City::where('is_active', true)->orderBy(transColumn('name'))->get();
        $this->programs = Program::where('is_active', true)->orderBy('name')->get();
        $this->jobLevels = JobLevel::orderBy('grade')->get();
    }

    public function updated(string $name): void
    {
        // A filter/search change returns to the first page (but not page navigation).
        if (in_array($name, [
            'search', 'department_id', 'center_id',
            'city_id', 'program_id', 'job_level_id', 'status',
        ], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset([
            'search', 'department_id', 'center_id',
            'city_id', 'program_id', 'job_level_id', 'status',
        ]);
        $this->resetPage();
    }

    public function getActiveFilterCountProperty(): int
    {
        return collect([
            $this->department_id, $this->center_id, $this->city_id,
            $this->program_id, $this->job_level_id, $this->status,
        ])->filter()->count();
    }

    /**
     * Stream the filtered directory as a CSV download: Directory-tier columns
     * only, and exactly the rows the list shows this viewer (access-policy.md
     * §4.3 actions), because both read baseQuery().
     */
    public function exportCsv(): StreamedResponse
    {
        $filename = 'directory-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($handle, [
                __('general.name'), __('general.position'), trans_choice('general.program', 1),
                trans_choice('general.department', 1), trans_choice('general.center', 1),
                trans_choice('general.city', 1), __('general.work-phone'), __('general.email'),
            ]);

            $this->baseQuery()
                ->with(['city', DirectoryAccess::ACCOUNT_COLUMNS, 'contracts' => fn ($q) => $this->withVisiblePosition($q)])
                ->chunk(200, function ($employees) use ($handle) {
                    foreach ($employees as $employee) {
                        $position = $employee->contracts->first()?->position;
                        fputcsv($handle, [
                            $employee->name,
                            $position?->title,
                            $position?->program?->name,
                            $position?->department?->name,
                            $position?->center?->name,
                            $position?->city?->name ?? $employee->city?->name,
                            $employee->work_phone,
                            $employee->work_email,
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        $employees = $this->baseQuery()
            ->with([
                'media',
                'city',
                'contracts' => fn ($q) => $this->withVisiblePosition($q),
            ])
            ->paginate($this->perPage);

        return view('livewire.position.directory', compact('employees'));
    }

    /**
     * Base query shared by the paginated list and the CSV export.
     *
     * The row set is the viewer's Directory cell (access-policy.md A2D-Q3):
     * staff and managers never get former employees, whatever the status
     * filter says; regional HR gets them for its own cities only; the general
     * manager, HR-SYR and super-admin get everyone. Only Directory columns are
     * selected.
     */
    private function baseQuery(): Builder
    {
        $term = '%'.trim($this->search).'%';

        return DirectoryAccess::constrain(Employee::query()->select(DirectoryAccess::COLUMNS), auth()->user())
            ->whereHas('contracts.position', fn (Builder $p) => $p->visible())
            ->when($this->status === 'board', fn (Builder $q) => $q->where('is_board_member', true))
            ->when($this->status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(function (Builder $sub) use ($term) {
                $sub->where('first_name_en', 'like', $term)
                    ->orWhere('last_name_en', 'like', $term)
                    ->orWhere('first_name_ar', 'like', $term)
                    ->orWhere('last_name_ar', 'like', $term)
                    ->orWhereHas('contracts.position', fn (Builder $p) => $p->visible()->where(fn (Builder $t) => $t->where('title_en', 'like', $term)->orWhere('title_ar', 'like', $term)));
            }))
            ->when($this->program_id || $this->department_id || $this->center_id || $this->job_level_id, function (Builder $q) {
                $q->whereHas('contracts.position', function (Builder $p) {
                    $p->visible()
                        ->when($this->program_id, fn (Builder $b) => $b->where('program_id', $this->program_id))
                        ->when($this->department_id, fn (Builder $b) => $b->where('department_id', $this->department_id))
                        ->when($this->center_id, fn (Builder $b) => $b->where('center_id', $this->center_id))
                        ->when($this->job_level_id, fn (Builder $b) => $b->where('job_level_id', $this->job_level_id));
                });
            })
            ->when($this->city_id, fn (Builder $q) => $q->where('city_id', $this->city_id))
            ->orderBy('first_name_'.app()->getLocale());
    }

    private function withVisiblePosition($query)
    {
        return $query
            // The position is all a Directory row needs from the contract.
            ->select(['id', 'employee_id', 'position_id', 'start_date', 'end_date', 'is_active', 'created_at'])
            ->whereHas('position', fn (Builder $position) => $position->visible())
            ->with([
                'position.department',
                'position.center',
                'position.program',
                'position.jobLevel',
                'position.city',
            ]);
    }
}
