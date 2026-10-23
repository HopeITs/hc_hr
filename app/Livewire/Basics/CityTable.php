<?php

namespace App\Livewire\Basics;

use App\Exports\CityExport;
use App\Livewire\Concerns\WithFeedback;
use App\Models\City;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\BooleanColumn;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CityTable extends LivewireTable
{
    use WithFeedback;

    protected string $model = City::class;

    protected $listeners = [
        'refreshCityTable' => '$refresh',
        'toggleItemStatusConfirmed',
        'exportExcel' => 'exportExcel',
    ];

    public function confirmToggleItemStatus($id): void
    {
        $this->confirm('toggleItemStatusConfirmed', ['id' => $id], title: __('general.toggle-status'), message: __('general.confirm-status-change'), confirmLabel: __('general.save'), danger: false);
    }

    public function toggleItemStatusConfirmed($payload): void
    {
        $item = City::findOrFail($payload['id']);
        $item->is_active = ! $item->is_active;
        $item->save();

        $this->toast(__('general.item-updated'));

        $this->dispatch('refreshCityTable');
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

        $filename = 'cities_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new CityExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    protected function query(): Builder
    {
        return City::query();
    }

    protected function globalSearchColumns(): array
    {
        return ['id', 'name_ar', 'name_en', 'code', 'slug', 'hr_hotline'];
    }

    protected function columns(): array
    {
        $canUpdate = auth()->user()?->hasActivePermission('project-basics.update') ?? false;

        return [

            Column::make('ID', 'id')->sortable()->searchable(),

            Column::make(__('general.name').' (AR)', 'name_ar')
                ->sortable()->searchable(),

            Column::make(__('general.name').' (EN)', 'name_en')
                ->sortable()->searchable(),

            Column::make(__('general.code'), 'code')
                ->sortable()->searchable(),

            Column::make(__('general.slug'), 'slug')
                ->sortable()->searchable(),

            Column::make(__('digital_id.city.hotline'), 'hr_hotline')
                ->sortable()->searchable(),

            BooleanColumn::make(__('general.active'), 'is_active')
                ->searchable(),

            Column::make(__('general.actions'), function (Model $m): string {
                $id = (int) $m->getKey();

                // Opens CityModule's modal, which authorizes again.
                $editBtn = Blade::render('<x-table.edit-btn wire:click="$dispatch(\'openCityEdit\', { id: '.$id.' })" />');

                $toggle = Blade::render(
                    '<x-forms.toggle-input
            class="!me-2 text-xl p-2 mb-3"
            active="'.($m->is_active ? 1 : 0).'"
            :model-id="'.$id.'"
            wire-event="confirmToggleItemStatus" />',
                );

                return '<div class="flex items-center justify-center gap-2">'.$editBtn.$toggle.'</div>';
            })
                ->asHtml()
                ->visible($canUpdate)
                ->clickable(false),
        ];
    }
}
