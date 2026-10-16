<?php

namespace App\Livewire\Position;

use App\Exports\PositionExport;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Center;
use App\Models\City;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\BooleanColumn;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PositionTable extends LivewireTable
{
    use WithFeedback;

    public ?int $centerId = null;

    public ?int $cityId = null;

    public $itemID;

    protected string $model = Position::class;

    protected $listeners = [
        'exportExcel' => 'exportExcel',
    ];

    public function mount(?int $centerId = null, ?int $cityId = null): void
    {
        $this->centerId = $centerId ?? request()->get('center');
        $this->cityId = $cityId ?? request()->get('city');
    }

    public function confirmDelete($id)
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete($payload)
    {
        $position = Position::findOrFail($payload['id']);
        Gate::authorize('delete', $position);
        $position->delete();

        $this->toast();
    }

    public function confirmToggleItemStatus($id)
    {
        $this->itemID = $id;

        $this->confirm('toggleItemStatusConfirmed', ['id' => $id], title: __('general.toggle-status'), message: __('general.confirm-status-change'), confirmLabel: __('general.save'), danger: false);
    }

    public function toggleItemStatusConfirmed($payload)
    {
        $item = Position::findOrFail($payload['id']);
        Gate::authorize('update', $item);
        $item->is_active = ! $item->is_active;
        $item->save();

        $this->toast(__('general.item-updated'));
    }

    public function exportExcel(): BinaryFileResponse
    {
        $query = $this->appliedQuery();
        $data = $query->get();

        $userSelectedColumns = $this->columns;

        $visibleColumnCodes = collect($this->resolveColumns())
            ->filter(fn ($column) => $column->isVisible())
            ->filter(function ($column) use ($userSelectedColumns) {
                if (empty($userSelectedColumns)) {
                    return true;
                }

                return in_array($column->code(), $userSelectedColumns);
            })
            ->filter(fn ($column) => $column->label() !== __('general.actions'))
            ->map(fn ($column) => $column->code())
            ->toArray();

        $filename = 'positions_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new PositionExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    protected function link(Model $model): ?string
    {
        return auth()->user()?->hasActivePermission('positions.update')
            ? route('positions.edit', $model->getKey())
            : null;
    }

    protected function query(): Builder
    {
        $query = $this->model()::query()->with(['city', 'program', 'center', 'jobLevel', 'department', 'directManager']);

        $query->when(
            auth()->user()?->activeCityIds(),
            fn (Builder $query, array $cityIds) => $query->inCity($cityIds),
        );

        if ($this->centerId) {
            $query->where('positions.center_id', $this->centerId);
        }
        if ($this->cityId) {
            $query->where('positions.city_id', $this->cityId);
        }

        return $query->orderBy('direct_manager_id')->orderBy('tech_manager_id');
    }

    protected function columns(): array
    {
        $canUpdate = auth()->user()?->hasActivePermission('positions.update') ?? false;
        $canDelete = auth()->user()?->hasActivePermission('positions.delete') ?? false;

        return [
            Column::make(__('general.title').' (EN)', 'title_en')->sortable()->searchable(),
            Column::make(__('general.title').' (AR)', 'title_ar')->sortable()->searchable(),
            SelectColumn::make(trans_choice('general.city', 1), 'city.'.transColumn('name'))
                ->options(City::query()->get()->pluck(transColumn('name'), transColumn('name'))->toArray())
                ->sortable()->searchable(),
            SelectColumn::make(trans_choice('general.program', 1), 'program.name')
                ->options(Program::query()->orderby('name')->get()->pluck('name', 'name')->toArray())
                ->sortable()->searchable(),
            SelectColumn::make(trans_choice('general.center', 1), 'center.'.transColumn('name'))
                ->options(Center::query()->orderBy(transColumn('name'))->pluck(transColumn('name'), transColumn('name'))->toArray())
                ->sortable()->searchable(),
            SelectColumn::make(trans_choice('general.department', 1), 'department.name')
                ->options(Department::query()->orderby('name')->get()->pluck('name', 'name')->toArray())
                ->sortable()->searchable(),
            SelectColumn::make(trans_choice('general.job-level', 1), 'jobLevel.'.transColumn('name'))
                ->options(JobLevel::query()->orderBy('grade')->get()->pluck(transColumn('name'), transColumn('name'))->toArray())
                ->sortable()->searchable(),
            SelectColumn::make(__('general.direct-manager'), 'directManager.'.transColumn('title'))
                ->options(Position::query()->orderby(transColumn('title'))->get()->pluck(transColumn('title'), transColumn('title'))->toArray())
                ->sortable()->searchable(),

            BooleanColumn::make(__('general.is-active'), 'is_active'),

            Column::make(__('general.actions'), function (Model $m) use ($canUpdate, $canDelete): string {
                $isActive = $m->is_active ? 1 : 0;

                $toggleBtn = $canUpdate
                    ? Blade::render(
                        '<x-forms.toggle-input class="!me-2 text-xl p-2 mb-3"
                            active="'.$isActive.'"
                            :model-id="'.$m->id.'"
                            wire-event="confirmToggleItemStatus" />',
                    )
                    : '';
                $editBtn = $canUpdate
                    ? Blade::render('<x-table.edit-btn href="'.route('positions.edit', $m->id).'" />')
                    : '';
                $deleteBtn = $canDelete
                    ? Blade::render('<x-table.delete-btn :modelId="'.$m->id.'" />')
                    : '';

                return $toggleBtn.$editBtn.$deleteBtn;
            })->asHtml()->visible($canUpdate || $canDelete)->clickable(false),
        ];
    }

    protected function actions(): array
    {
        return [];
    }
}
