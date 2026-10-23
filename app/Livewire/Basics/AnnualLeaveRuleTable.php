<?php

namespace App\Livewire\Basics;

use App\Exports\AnnualLeaveRuleExport;
use App\Livewire\Concerns\WithFeedback;
use App\Models\AnnualLeaveRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AnnualLeaveRuleTable extends LivewireTable
{
    use WithFeedback;

    protected string $model = AnnualLeaveRule::class;

    protected $listeners = [
        'refreshAnnualLeaveRuleTable' => '$refresh',
        'delete',
        'exportExcel' => 'exportExcel',
    ];

    public function confirmDelete(int $id): void
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(array $payload): void
    {
        AnnualLeaveRule::findOrFail($payload['id'])->delete();

        $this->toast();

        $this->dispatch('refreshAnnualLeaveRuleTable');
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

        $filename = 'annual_leave_rules_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new AnnualLeaveRuleExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    protected function rowClickAction(Model $model): ?string
    {
        return auth()->user()?->hasActivePermission('project-basics.update')
            ? "\$dispatch('openAnnualLeaveRuleEdit', { id: {$model->getKey()} })"
            : null;
    }

    protected function query(): Builder
    {
        return AnnualLeaveRule::query();
    }

    protected function globalSearchColumns(): array
    {
        return ['id', 'days', 'day_per_month', 'years_of_volunteering'];
    }

    protected function columns(): array
    {
        $canUpdate = auth()->user()?->hasActivePermission('project-basics.update') ?? false;
        $canDelete = auth()->user()?->hasActivePermission('project-basics.delete') ?? false;

        return [
            Column::make(__('general.days_per_year'), 'days')
                ->displayUsing(
                    fn ($value) => self::countLabel('days', $value),
                )
                ->sortable()->searchable(),

            Column::make(__('general.day_per_month'), 'day_per_month')
                ->displayUsing(
                    fn ($value) => self::countLabel('days', $value),
                )
                ->sortable()->searchable(),

            Column::make(__('general.years_of_volunteering'), 'years_of_volunteering')
                ->displayUsing(
                    fn ($value) => self::countLabel('years', $value),
                )
                ->sortable()
                ->searchable(),

            Column::make(__('general.actions'), function (Model $m) use ($canUpdate, $canDelete): string {
                $id = $m->id;

                $editBtn = $canUpdate
                    ? Blade::render('<x-table.edit-btn wire:click="$dispatch(\'openAnnualLeaveRuleEdit\', { id: '.$id.' })" />')
                    : '';
                $deleteBtn = $canDelete
                    ? Blade::render('<x-table.delete-btn wire:click="confirmDelete('.$id.')" :modelId="'.$id.'" />')
                    : '';

                return '<div class="flex items-center justify-center gap-2">'.$editBtn.$deleteBtn.'</div>';
            })->asHtml()->visible($canUpdate || $canDelete)->clickable(false),
        ];
    }

    /**
     * "3 days" / "٣ أيام" with the right plural form. A fractional figure has
     * no plural category of its own, so it keeps the number with the plain unit.
     */
    private static function countLabel(string $unit, int|float|string|null $value): string
    {
        $number = (float) $value;

        return fmod($number, 1.0) === 0.0
            ? trans_choice("general.count.{$unit}", (int) $number)
            : $value.' '.trans_choice('general.'.rtrim($unit, 's'), 2);
    }
}
