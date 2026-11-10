<?php

namespace Tests\Fixtures;

use App\Livewire\Concerns\HasListStates;
use App\Livewire\Concerns\HasSavedViews;
use App\Livewire\Concerns\RemembersTableState;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\View;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Filters\SelectFilter;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

/** Minimal consumer of RemembersTableState, used only by the B4 state tests. */
class StateTestTable extends LivewireTable
{
    use HasListStates, HasSavedViews, RemembersTableState;

    protected string $model = Employee::class;

    public function cardView(): ?string
    {
        View::addLocation(base_path('tests/Fixtures/views'));

        return 'state-test-card';
    }

    protected function emptyStateCreate(): ?array
    {
        return null;
    }

    protected function query(): Builder
    {
        return Employee::query();
    }

    protected function columns(): array
    {
        $user = auth()->user();
        $cityIds = $user->activeCityIds();
        $cities = \App\Models\City::query()
            ->when($cityIds !== null, fn ($q) => $q->whereIn('id', $cityIds))
            ->pluck('name_en', 'id')->all();

        return [
            Column::make('Username', 'username')->sortable()->searchable(),
            SelectColumn::make('City', 'city_id')->options($cities)->searchable(),
            Column::make('Seen by HR', 'is_active')->canSee($user->activeRoleIs('HR-SYR')),
        ];
    }

    protected function filters(): array
    {
        return [SelectFilter::make('Active', 'is_active')->options([1 => 'Active', 0 => 'Inactive'])];
    }
}
