<?php

namespace App\Livewire\Employee\Leave;

use App\Enums\LeaveTypeCases;
use App\Exports\EmployeeLeaveBalanceExport;
use App\Livewire\Concerns\HasScreenListStates;
use App\Livewire\Forms\LeaveBalanceForm;
use App\Models\Employee;
use App\Models\LeaveBalance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmpLeaveBalanceTable extends LivewireTable
{
    use HasScreenListStates;

    public LeaveBalanceForm $form;

    public $showModal = false;

    public $modalTitle = '';

    protected string $model = Employee::class;

    protected $listeners = ['exportExcel' => 'exportExcel'];

    public function edit($id)
    {
        $model = LeaveBalance::findOrFail($id);

        $employee = auth()->user()->profile;
        $modalTitle = __('general.edit');
        $form = $this->form;

        $this->showModal = true;
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

        $filename = 'employee_leave_balances_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new EmployeeLeaveBalanceExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    protected function emptyScreenState(): array
    {
        return [
            'title' => __('states.leave-balance-empty-title'),
            'description' => __('states.leave-balance-empty-help'),
        ];
    }

    protected function rowClickAction(Model $model): ?string
    {
        $balanceId = $this->annualBalance($model)?->id;

        return $balanceId ? "\$dispatch('openEdit', { id: {$balanceId} })" : null;
    }

    protected function query(): Builder
    {
        $user = auth()->user();
        $role = $user->myRole();

        $query = Employee::query()
            ->with(['contracts.position.center', 'contracts.leaveBalances'])
            ->whereHas('contracts.leaveBalances', function ($query) {
                return $query->where('type_id', LeaveTypeCases::Annual);
            });

        if ($role === 'HR-SYR' || $role === 'super-admin' || $role === 'general-manager') {
            return $query;
        } elseif ($role === 'manager') {
            $query->whereHas('positions', function ($qq) use ($user) {
                $managerPos = $user->profile->positions()->pluck('id')->toArray();
                $qq->whereIn('direct_manager_id', $managerPos)->orWhere('tech_manager_id', $managerPos);
            });
        } else {
            $query->whereIn('city_id', $user->activeCityIds() ?? []);
        }

        return $query;
    }

    protected function columns(): array
    {
        $user = auth()->user();

        return [
            // Column::make(trans('general.name'), transColumn('name'))
            //     ->displayUsing(fn($v, Model $m) => $m->name) // uses accessor
            //     ->sortable()->searchable(),
            // Column::make(trans('general.name'), transColumn('name'))

            //     ->displayUsing(fn($v, Model $m) => $m->name)

            //     ->searchable(function (Builder $query, string $search) {
            //         $query->where(transColumn('first_name'), 'like', "%{$search}%")
            //               ->orWhere(transColumn('last_name'), 'like', "%{$search}%");
            //     })

            //     ->sortable(function (Builder $query, string $direction) {
            //         $query->orderBy(transColumn('first_name'), $direction)
            //               ->orderBy(transColumn('last_name'), $direction);
            //     }),
            Column::make(trans('general.name'), 'employee')
                ->displayUsing(fn ($v, Model $m) => $m->name)
                ->searchable(function (Builder $query, $value) {
                    $parts = array_filter(explode(' ', trim($value)));
                    foreach ($parts as $part) {
                        $query->where(function (Builder $inner) use ($part) {
                            $inner->where('first_name_en', 'LIKE', "%{$part}%")
                                ->orWhere('first_name_ar', 'LIKE', "%{$part}%")
                                ->orWhere('last_name_en', 'LIKE', "%{$part}%")
                                ->orWhere('last_name_ar', 'LIKE', "%{$part}%");
                        });
                    }
                })
                ->sortable(function (Builder $query, string $direction) {
                    $query->orderBy(transColumn('first_name'), $direction)
                        ->orderBy(transColumn('last_name'), $direction);
                }),

            Column::make(trans('general.years-of-service'), 'years_of_service_formatted')->computed()->sortable(),

            Column::make(__('general.deserved'), 'deserved')
                ->displayUsing(function ($v, Model $model) {
                    return $this->annualBalance($model)?->deserved_formatted;
                })->asHtml(),

            Column::make(__('general.used'), 'used')
                ->displayUsing(function ($v, Model $model) {
                    return $this->annualBalance($model)?->used_formatted;
                })->asHtml(),

            Column::make(__('general.remaining'), 'remaining')
                ->displayUsing(function ($v, Model $model) {
                    return $this->annualBalance($model)?->remaining;
                })->asHtml(),
            Column::make(__('general.actions'), function (Model $model): string {
                $editBtn = '';
                $balanceId = $this->annualBalance($model)?->id;
                if ($balanceId) {
                    $editBtn = Blade::render('<x-table.edit-btn wire:click="$dispatch(\'openEdit\', { id: '.$balanceId.' })" />');
                }

                return $editBtn;
            })->asHtml()->clickable(false),

        ];
    }

    private function annualBalance(Model $employee): ?LeaveBalance
    {
        // latestActiveContract() memoizes and reuses the eager-loaded contracts
        // relation; reading the loaded leaveBalances collection avoids one
        // query per cell (this used to run 5 fresh queries per row).
        // The balance gets its contract back from that same collection, so
        // its day length (position.center) is read from the eager load too.
        $contract = $employee->latestActiveContract();

        return $contract?->leaveBalances
            ->firstWhere('type_id', LeaveTypeCases::Annual)
            ?->setRelation('contract', $contract);
    }
}
