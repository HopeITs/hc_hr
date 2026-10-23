<?php

namespace App\Livewire\ChecklistItems;

use App\Livewire\Concerns\HasScreenListStates;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Enums\Direction;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

class DoneRequestsTable extends LivewireTable
{
    use HasScreenListStates;

    protected string $model = Checklist::class;

    protected bool $showFilters = true;

    protected function emptyScreenState(): array
    {
        return [
            'title' => __('states.done-empty-title'),
            'description' => __('states.done-empty-help'),
        ];
    }

    protected function query(): Builder
    {
        return $this->scopedChecklistQuery()
            ->with(['employee', 'item', 'delivered', 'receiver']);
    }

    protected function columns(): array
    {
        $context = $this->departmentContext();

        $employeeOptions = $context
            ? Employee::query()
                ->select(['id', 'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar'])
                ->whereIn('id', $context['deptEmployeeIds'])
                ->orderBy(transColumn('first_name'))
                ->get()
                ->pluck('name', 'name')
                ->unique()
                ->sort()
                ->toArray()
            : [];

        $itemOptions = ChecklistItem::query()
            ->orderBy('name')
            ->pluck('name', 'name')
            ->toArray();

        $actionOptions = [
            'delivered' => __('general.delivered'),
            'received' => __('general.received'),
        ];

        $counterpartyOptions = $context
            ? $this->scopedChecklistQuery()
                ->with(['employee', 'receiver', 'delivered'])
                ->get()
                ->map(function ($row) {
                    if ($row->returned_status === 'received') {
                        return $row->employee?->name ?? $row->delivered?->name;
                    }
                    if ($row->delivered_status === 'delivered') {
                        return $row->receiver?->name ?? $row->employee?->name;
                    }

                    return null;
                })
                ->filter()
                ->unique()
                ->sort()
                ->mapWithKeys(fn ($name) => [$name => $name])
                ->toArray()
            : [];

        return [
            SelectColumn::make(trans_choice('general.employee', 1))
                ->options($employeeOptions)
                ->displayUsing(function (mixed $value, Model $row): string {
                    if ($row->returned_status === 'received') {
                        return $row->receiver?->name ?? '—';
                    }
                    if ($row->delivered_status === 'delivered') {
                        return $row->delivered?->name ?? '—';
                    }

                    return '—';
                })
                ->sortable()
                ->searchable(function (Builder $builder, string $value): void {
                    $builder->where(function (Builder $query) use ($value) {
                        $query->whereHas('delivered', function (Builder $q) use ($value) {
                            $q->where('name_en', $value)->orWhere('name_ar', $value);
                        })->orWhereHas('receiver', function (Builder $q) use ($value) {
                            $q->where('name_en', $value)->orWhere('name_ar', $value);
                        });
                    });
                }),

            SelectColumn::make(__('general.action'))
                ->options($actionOptions)
                ->displayUsing(function (mixed $value, Model $row): string {
                    return $row->returned_status === 'received'
                        ? __('general.received')
                        : ($row->delivered_status === 'delivered' ? __('general.delivered') : '-');
                })
                ->sortable(function (Builder $builder, Direction $direction): void {
                    $dir = $direction->value;
                    $builder->orderByRaw("
                        CASE
                            WHEN returned_status = 'received' THEN 1
                            WHEN delivered_status = 'delivered' THEN 2
                            ELSE 3
                        END {$dir}
                    ");
                })
                ->searchable(function (Builder $builder, string $value): void {
                    if ($value === 'delivered') {
                        $builder->where('delivered_status', 'delivered');
                    } elseif ($value === 'received') {
                        $builder->where('returned_status', 'received');
                    }
                }),

            SelectColumn::make(__('general.item'), 'item.name')
                ->options($itemOptions)
                ->sortable()
                ->searchable(),

            SelectColumn::make(__('general.counterparty'))
                ->options($counterpartyOptions)
                ->displayUsing(function (mixed $value, Model $row): string {
                    if ($row->returned_status === 'received') {
                        return $row->employee?->name ?? ($row->delivered?->name ?? '—');
                    }
                    if ($row->delivered_status === 'delivered') {
                        return $row->receiver?->name ?? ($row->employee?->name ?? '—');
                    }

                    return '—';
                })
                ->sortable()
                ->searchable(function (Builder $builder, string $value): void {
                    $builder->where(function (Builder $query) use ($value) {
                        $query->orWhere(function (Builder $subQuery) use ($value) {
                            $subQuery->where('returned_status', 'received')
                                ->where(function (Builder $nested) use ($value) {
                                    $nested->whereHas('employee', function (Builder $q) use ($value) {
                                        $q->where('name_en', $value)->orWhere('name_ar', $value);
                                    })
                                        ->orWhereHas('delivered', function (Builder $q) use ($value) {
                                            $q->where('name_en', $value)->orWhere('name_ar', $value);
                                        });
                                });
                        })->orWhere(function (Builder $subQuery) use ($value) {
                            $subQuery->where('delivered_status', 'delivered')
                                ->where(function (Builder $nested) use ($value) {
                                    $nested->whereHas('receiver', function (Builder $q) use ($value) {
                                        $q->where('name_en', $value)->orWhere('name_ar', $value);
                                    })
                                        ->orWhereHas('employee', function (Builder $q) use ($value) {
                                            $q->where('name_en', $value)->orWhere('name_ar', $value);
                                        });
                                });
                        });
                    });
                }),

            Column::make(__('general.dates'))
                ->displayUsing(function (mixed $value, Model $row): string {
                    $requestDate = $row->created_at?->format('Y-m-d') ?? '-';

                    if ($row->returned_status === 'received') {
                        $actionDate = $row->returned_at?->format('Y-m-d') ?? '-';

                        return "{$requestDate} → ".__('general.received_at').": {$actionDate}";
                    }

                    if ($row->delivered_status === 'delivered') {
                        $actionDate = $row->delivered_at?->format('Y-m-d') ?? '-';

                        return "{$requestDate} → ".__('general.delivered_at').": {$actionDate}";
                    }

                    return "{$requestDate} → -";
                })
                ->sortable(function (Builder $builder, Direction $direction): void {
                    $dir = $direction->value;
                    $builder->orderByRaw("COALESCE(returned_at, delivered_at) {$dir}");
                }),

            Column::make(__('general.note'), 'notes')
                ->sortable()
                ->searchable(),
        ];
    }

    /**
     * @return array{department: string, deptEmployeeIds: Collection<int, int>}|null
     */
    private function departmentContext(): ?array
    {
        $employee = auth()->user()?->profile;

        if (! $employee) {
            return null;
        }

        $department = $employee->latestActiveContract()?->position?->department?->name;

        if (! in_array($department, ['IT', 'Communication', 'HR'], true)) {
            return null;
        }

        $deptEmployeeIds = Employee::whereHas('contracts.position.department', function ($q) use ($department) {
            $q->where('name', $department);
        })->pluck('id');

        return [
            'department' => $department,
            'deptEmployeeIds' => $deptEmployeeIds,
        ];
    }

    private function scopedChecklistQuery(): Builder
    {
        $context = $this->departmentContext();

        if (! $context) {
            return Checklist::query()->whereRaw('1 = 0');
        }

        $deptEmployeeIds = $context['deptEmployeeIds'];

        return Checklist::query()
            ->where(function (Builder $q) use ($deptEmployeeIds) {
                $q->whereIn('delivered_id', $deptEmployeeIds)->where('delivered_status', 'delivered')
                    ->orWhereIn('receiver_id', $deptEmployeeIds)->where('returned_status', 'received');
            });
    }
}
