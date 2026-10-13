<?php

namespace App\Livewire\Employee;

use App\Exports\EmployeeExport;
use App\Models\City;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\BooleanColumn;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\DateColumn;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Enums\SearchScope;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeTable extends LivewireTable
{
    protected string $model = Employee::class;

    protected $listeners = ['delete', 'toggleItemStatusConfirmed', 'exportExcel' => 'exportExcel'];

    public function confirmDelete($id): void
    {
        LivewireAlert::title(__('general.delete-item'))
            ->text(__('general.confirm-delete-item'))
            ->question()
            ->withConfirmButton(__('general.delete'))
            ->onConfirm('delete', ['id' => $id])
            ->withCancelButton(__('general.cancel'))
            ->show();
    }

    public function delete($payload): void
    {
        $employee = Employee::findOrFail($payload['id']);
        Gate::authorize('delete', $employee);
        $employee->delete();

        LivewireAlert::title(__('general.success-message'))
            ->success()->toast()->position('top-start')->show();
    }

    public function confirmToggleItemStatus($id): void
    {
        LivewireAlert::title(__('general.toggle-status'))
            ->text(__('general.confirm-status-change'))
            ->question()
            ->withConfirmButton(__('general.save'))
            ->onConfirm('toggleItemStatusConfirmed', ['id' => $id])
            ->withCancelButton(__('general.cancel'))
            ->show();
    }

    public function toggleItemStatusConfirmed($payload): void
    {
        $item = Employee::findOrFail($payload['id']);
        Gate::authorize('update', $item);
        $item->is_active = ! $item->is_active;
        $item->save();

        LivewireAlert::title(__('general.item-updated'))
            ->success()->toast()->position('top-start')->show();
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
            ->filter(fn ($column) => $column->label() !== __('general.actions') && $column->code() !== 'photo')
            ->map(fn ($column) => $column->code())
            ->toArray();

        $filename = 'employees_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new EmployeeExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    protected function link(Model $model): ?string
    {
        return auth()->user()->hasActivePermission('employees.update')
            ? route('employees.edit', $model->getKey())
            : null;
    }

    protected function query(): Builder
    {
        return $this->model()::query()
            ->with([
                'contracts.position.directManager.activeContracts',
                'account',
                'media',
            ])
            ->when(
                auth()->user()->activeCityIds(),
                fn (Builder $q, array $cityIds) => $q->whereIn('city_id', $cityIds),
            )
            ->orderByDesc('is_active');
    }

    protected function globalSearchColumns(): array
    {
        return ['name', 'actual_dob'];
    }

    protected function managerOptions(): array
    {
        return Employee::query()
            ->select(['id', 'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar'])
            ->when(
                auth()->user()->activeCityIds(),
                fn (Builder $q, array $cityIds) => $q->whereIn('city_id', $cityIds),
            )
            ->where('is_active', true)
            ->orderBy(transColumn('first_name'), 'asc')
            ->get()
            ->mapWithKeys(fn ($e) => [$e->id => $e->name])
            ->all();
    }

    protected function columns(): array
    {
        $user = auth()->user();
        $canUpdate = $user->hasActivePermission('employees.update');
        $canDelete = $user->hasActivePermission('employees.delete');

        return [
            Column::make('ID', 'id')->sortable(),

            Column::make(trans('general.photo'), 'photo')
                ->displayUsing(function ($v, Model $m): string {
                    $src = $m->avatar_url;

                    return $src
                        ? '<img src="'.e($src).'" alt="'.e($m->name).'" class="size-[38px] max-w-none shrink-0 rounded-full border border-primary bg-white object-cover" />'
                        : '<div class="bg-neutral-200 rounded-full size-[38px]"></div>';
                })->asHtml(),

            // Column::make(trans('general.name'), transColumn('name'))
            //     ->displayUsing(fn($v, Model $m) => $m->name) // uses accessor
            //     ->sortable()->searchable(),
            Column::make(trans('general.name'), 'employee')
                ->displayUsing(fn ($v, Model $m) => $m->name)
                ->sortable()
                ->searchable(function (Builder $query, $value) {
                    $parts = array_filter(explode(' ', trim($value)));

                    $query->where(function (Builder $q) use ($parts) {
                        foreach ($parts as $part) {
                            $q->where(function (Builder $inner) use ($part) {
                                $inner->where('first_name_en', 'LIKE', "%{$part}%")
                                    ->orWhere('first_name_ar', 'LIKE', "%{$part}%")
                                    ->orWhere('last_name_en', 'LIKE', "%{$part}%")
                                    ->orWhere('last_name_ar', 'LIKE', "%{$part}%");
                            });
                        }
                    });
                }),
            Column::make(trans_choice('general.role', 1), 'account')
                ->displayUsing(fn ($v, Model $m) => $m->account?->myRole()),

            Column::make(trans('general.years-of-service'), 'years_of_service_formatted')->computed()->sortable(),

            DateColumn::make(trans('general.join-at'), 'joined_at')->format('d-M-Y')->sortable(),
            SelectColumn::make(trans_choice('general.city', 1), 'city.'.transColumn('name'))
                ->options(City::query()->whereNot('code', '000')->where('is_active', true)->get()->pluck(transColumn('name'), transColumn('name'))->toArray())
                ->sortable()->searchable(),

            SelectColumn::make(trans('general.direct_manager'), 'direct_manager')
                ->displayUsing(function ($v, Model $model) {
                    return $model->latestActiveContract()?->position->directManager?->employee()?->name ?? '—';
                })
                ->options($this->managerOptions())
                ->sortable()
                ->searchable(function (Builder $builder, mixed $search, SearchScope $scope): void {
                    $builder->whereHas('positions', function (Builder $builder) use ($search) {
                        $builder->whereHas('directManager', function (Builder $builder) use ($search) {
                            $builder->whereHas('contracts', function (Builder $builder) use ($search) {
                                $builder->whereHas('employee', function (Builder $builder) use ($search) {
                                    $builder->where('id', $search);
                                });
                            });
                        });
                    });
                }),

            SelectColumn::make(trans('general.position'), 'contracts.position.'.transColumn('title'))
                ->options(Position::query()->orderby(transColumn('title'), 'asc')->get()->pluck(transColumn('title'), transColumn('title'))->toArray())
                ->sortable()->searchable(),

            Column::make(trans('general.work_email'), 'work_email')
                ->displayUsing(fn ($v, Model $m) => $m->account?->email ?? '—'),

            BooleanColumn::make(trans('general.is-active'), 'is_active')->searchable(),

            Column::make(__('general.actions'), function (Model $model) use ($canUpdate, $canDelete): string {
                $isActive = $model->is_active ? 1 : 0;

                $toggleBtn = $canUpdate
                    ? Blade::render(
                        '<x-forms.toggle-input class="p-2 m-2 text-xl" active="'.$isActive.
                            '" :model-id="'.$model->getKey().
                            '" wire-event="confirmToggleItemStatus" />',
                    )
                    : '';

                // Profile route is keyed by username; imported employees may
                // not have one yet, so only link when it exists.
                $viewBtn = $model->username
                    ? Blade::render(
                        '<a  href="'.route('employees.show', ['employee' => $model->username]).'" class="p-2 " title="View Profile">
                                <x-icons.eye class="transition text-secondary-light hover:text-primary" />
                            </a>',
                    )
                    : '';

                $editBtn = $canUpdate
                    ? Blade::render('<x-table.edit-btn href="'.route('employees.edit', $model->getKey()).'" />')
                    : '';

                $deleteBtn = $canDelete
                    ? Blade::render('<x-table.delete-btn :modelId="'.$model->getKey().'" />')
                    : '';

                return '<div class="flex items-center gap-1">'.
                    $toggleBtn.$viewBtn.$editBtn.$deleteBtn.
                    '</div>';
            })->asHtml()->clickable(false),

        ];
    }
}
