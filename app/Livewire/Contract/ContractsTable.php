<?php

namespace App\Livewire\Contract;

use App\Enums\SalaryPer;
use App\Exports\ContractExport;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\BooleanColumn;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContractsTable extends LivewireTable
{
    protected string $model = Contract::class;

    protected $listeners = [
        'delete',
        'toggleAutoRenewConfirmed',
        'refreshContractsTable' => '$refresh',
        'exportExcel' => 'exportExcel',
    ];

    public function confirmDelete(int $id): void
    {
        LivewireAlert::title(__('general.delete-item'))
            ->text(__('general.confirm-delete-item'))
            ->question()
            ->withConfirmButton(__('general.delete'))
            ->onConfirm('delete', ['id' => $id])
            ->withCancelButton(__('general.cancel'))
            ->show();
    }

    public function delete(array $payload): void
    {
        $contract = Contract::findOrFail($payload['id']);
        Gate::authorize('delete', $contract);

        DB::transaction(fn () => $contract->delete());

        LivewireAlert::title(__('general.success-message'))
            ->success()->toast()->position('top-start')->show();

        $this->dispatch('refreshContractsTable');
    }

    public function confirmToggleAutoRenew(int $id): void
    {
        $contract = Contract::findOrFail($id);
        Gate::authorize('manageAutoRenew', $contract);

        LivewireAlert::title(__('general.toggle-auto-renew'))
            ->text(__('general.confirm-auto-renew-change'))
            ->question()
            ->withConfirmButton(__('general.save'))
            ->onConfirm('toggleAutoRenewConfirmed', ['id' => $id])
            ->withCancelButton(__('general.cancel'))
            ->show();
    }

    public function toggleAutoRenewConfirmed(array $payload): void
    {
        $contract = Contract::findOrFail($payload['id']);
        Gate::authorize('manageAutoRenew', $contract);
        // The toggle is not rendered for a terminated contract, and
        // ContractObserver::saving() would force the flag straight back off.
        abort_if($contract->isTerminated(), 403);

        $contract->update(['auto_renew' => ! $contract->auto_renew]);

        LivewireAlert::title(__('general.item-updated'))
            ->success()->toast()->position('top-start')->show();

        $this->dispatch('refreshContractsTable');
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

        $filename = 'contracts_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new ContractExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    protected function link(Model $model): ?string
    {
        return auth()->user()->hasActivePermission('contracts.update')
            ? route('contracts.edit', $model->getKey())
            : null;
    }

    protected function query(): Builder
    {
        return $this->model()::query()
            ->with([
                'employee',
                'position.department',
                'position.program',
                'position.jobLevel',
                'position.city',
                'position.center.city',
                'contractType',
                'leaveBalances',
                'terminationReason',
            ])
            ->when(
                auth()->user()->activeCityIds(),
                fn (Builder $q, array $cityIds) => $q->whereHas('employee', fn (Builder $e) => $e->whereIn('city_id', $cityIds)),
            );
    }

    protected function globalSearchColumns(): array
    {
        $loc = app()->getLocale();

        return [
            'id',
            'employee.'.($loc === 'ar' ? 'first_name_ar' : 'first_name_en'),
            'position.'.($loc === 'ar' ? 'title_ar' : 'title_en'),
            'contractType.'.($loc === 'ar' ? 'name_ar' : 'name_en'),
            'position.department.name',
        ];
    }

    protected function columns(): array
    {
        $loc = app()->getLocale();
        $name = $loc === 'ar' ? 'name_ar' : 'name_en';
        $title = $loc === 'ar' ? 'title_ar' : 'title_en';
        $canUpdate = auth()->user()?->hasActivePermission('contracts.update') ?? false;
        $canDelete = auth()->user()?->hasActivePermission('contracts.delete') ?? false;
        $canManageAutoRenew = auth()->user()?->activeRoleIs('HR') && $canUpdate;
        // Resolved once, like the flags above. Calling Gate::allows('renew')
        // per row would re-run ContractPolicy::renew's city-scope query for
        // every contract on the page; query() has already restricted the whole
        // result set to the active city, so only the row's own state is left
        // to check — which is done inline below, without touching the database.
        $canCreate = auth()->user()?->hasActivePermission('contracts.create') ?? false;
        // Mirrors ContractPolicy::export, which also refuses a role that may
        // not see pay figures - the PDF carries the stipend clause. Hoisted
        // here so the button is absent rather than present and 403-ing.
        $canExport = (auth()->user()?->hasActivePermission('contracts.export') ?? false)
            && (auth()->user()?->canViewSalary() ?? false);

        return [
            Column::make(trans_choice('general.employee', 1), 'employee')
                ->displayUsing(fn ($v, Model $m) => $m->employee?->name ?? '—')
                ->searchable(function (Builder $query, $value) {
                    $parts = array_filter(explode(' ', trim($value)));

                    $query->whereHas('employee', function (Builder $q) use ($parts) {
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
                     SelectColumn::make(trans_choice('general.program', 1), 'position.program.slug')
                ->options(Program::orderBy('slug')->pluck('slug', 'slug')->toArray())
                ->sortable()->searchable(),

            SelectColumn::make(trans_choice('general.positions', 1), 'position.'.$title)
                ->options(Position::orderBy($title)->pluck($title, $title)->toArray())
                ->sortable()->searchable(),

            SelectColumn::make(trans_choice('general.contract-type', 1), 'contractType.'.$name)
                ->options(ContractType::orderBy($name)->pluck($name, $name)->toArray())
                ->sortable()->searchable(),

            SelectColumn::make(trans_choice('general.city', 1), 'city')
                ->options(City::orderBy($name)->pluck($name, 'id')->toArray())
                ->displayUsing(fn ($v, Model $m) => $m->position?->city?->name ?? $m->position?->center?->city?->name ?? '—')
                ->searchable(function (Builder $query, $value) {
                    $query->whereHas('position', function (Builder $q) use ($value) {
                        $q->where('city_id', $value)
                            ->orWhereHas('center', fn (Builder $c) => $c->where('city_id', $value));
                    });
                }),

            SelectColumn::make(trans_choice('general.department', 1), 'position.department.name')
                ->options(Department::orderBy('name')->pluck('name', 'name')->toArray())
                ->sortable()->searchable(),


            SelectColumn::make(trans_choice('general.job-level', 1), 'position.jobLevel.'.$name)
                ->options(JobLevel::orderBy('grade')->pluck($name, $name)->toArray())
                ->sortable()->searchable(),

            Column::make(__('general.duration'), 'duration')
                ->displayUsing(fn ($v, Model $m) => $m->duration.' '.__('general.months'))
                ->sortable(),

            Column::make(__('general.period'), 'period')
                ->displayUsing(fn ($v, Model $m) => Blade::render('<x-period :start="$start" :end="$end" />', [
                    'start' => $m->start_date,
                    'end' => $m->end_date,
                ]))->asHtml(),
            // The reason rides along as a tooltip rather than its own column:
            // it only means something next to the date it explains.
            Column::make(__('general.terminated-at'), 'terminated_at')
                ->displayUsing(fn ($v, Model $m) => $m->isTerminated()
                    ? Blade::render(
                        '<span title="{{ $reason }}" class="px-2 py-1 font-medium text-red-800 bg-red-100 whitespace-nowrap rounded-inset text-small">{{ $date }}</span>',
                        [
                            'date' => $m->terminated_at?->format('Y-m-d') ?? __('general.terminated-at'),
                            'reason' => $m->terminationReason?->title ?? $m->reason_of_termination ?? '',
                        ],
                    )
                    : '—')
                ->sortable()
                ->asHtml(),

            Column::make(__('general.yearly-leave-days'), 'leaveBalances')
                ->displayUsing(fn ($m) => $m[0]->deserved_formatted ?? 0)
                ->sortable()->asHtml(),

            SelectColumn::make(__('general.salary-per'), 'salary_per')
                ->options(
                    collect(SalaryPer::getValues())->mapWithKeys(fn ($value) => [
                        $value => SalaryPer::getLocalizedName((int) $value)['value'],
                    ])->toArray(),
                )
                ->displayUsing(function ($value) {
                    $value = $value instanceof SalaryPer ? $value->value : (int) $value;

                    $per = SalaryPer::getLocalizedName($value);

                    return Blade::render('<x-badge :color="$color">{{$text}}</x-badge>', [
                        'color' => $per['color'] ?? 'secondary-light',
                        'text' => $per['value'] ?? $value,
                    ]);
                })
                ->sortable()
                ->searchable()
                ->asHtml(),

            Column::make(__('general.weekly-hours'), 'weekly_hours')
                ->displayUsing(fn ($v) => $v ? "{$v} ".trans_choice('general.hour', 2) : '—')
                ->sortable(),

            BooleanColumn::make(__('general.status'), 'is_active')->searchable(),

            BooleanColumn::make(__('general.auto-renew'), 'auto_renew')->searchable(),

            Column::make(__('general.actions'), function (Model $m) use ($canUpdate, $canDelete, $canCreate, $canExport, $canManageAutoRenew): string {
                $view = Blade::render('<x-table.show-btn :href="route(\'contracts.show\', $id)" />', ['id' => $m->getKey()]);
                $edit = $canUpdate
                    ? Blade::render('<x-table.edit-btn :href="route(\'contracts.edit\', $id)" />', ['id' => $m->getKey()])
                    : '';
                $autoRenew = $canManageAutoRenew && ! $m->isTerminated()
                    ? Blade::render(
                        '<x-forms.toggle-input class="p-2 text-xl" active="'.($m->auto_renew ? 1 : 0).'" :model-id="'.$m->getKey().'" wire-event="confirmToggleAutoRenew" title="'.e(__('general.auto-renew')).'" />',
                    )
                    : '';
                // Mirrors ContractPolicy::renew: permission (hoisted), city
                // scope (guaranteed by query()), and the contract's own state.
                $isRenewable = $canCreate && $m->is_active && $m->terminated_at === null;
                $renew = $isRenewable
                    ? Blade::render('<x-table.renew-btn :modelId="$id" />', ['id' => $m->getKey()])
                    : '';
                $export = $canExport
                    ? Blade::render('<x-table.export-btn :href="route(\'contracts.export\', $id)" />', ['id' => $m->getKey()])
                    : '';
                $delete = $canDelete
                    ? Blade::render('<x-table.delete-btn :modelId="$id" />', ['id' => $m->getKey()])
                    : '';

                return '<div class="flex items-center justify-center gap-2">'.$autoRenew.$view.$edit.$renew.$export.$delete.'</div>';
            })->asHtml()->clickable(false),
        ];
    }
}
