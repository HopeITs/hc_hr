<?php

namespace App\Livewire\Basics;

use App\Livewire\Concerns\WithFeedback;
use App\Models\Holiday;
use App\Services\HolidayProjects;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

class HolidayTable extends LivewireTable
{
    use WithFeedback;

    protected string $model = Holiday::class;

    protected $listeners = [
        'refreshHolidayTable' => '$refresh',
        'delete',
    ];

    public function confirmDelete(int $id): void
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(array $payload): void
    {
        $holiday = Holiday::findOrFail($payload['id']);
        abort_unless($this->projects()->canChange(auth()->user(), $holiday), 403);

        $holiday->delete();

        $this->toast();

        $this->dispatch('refreshHolidayTable');
    }

    protected function rowClickAction(Model $model): ?string
    {
        return $this->projects()->canChange(auth()->user(), $model)
            ? "\$dispatch('openHolidayEdit', { id: {$model->getKey()} })"
            : null;
    }

    /**
     * National HR sees every holiday. A program manager sees the national
     * ones (read only) and those linked to any of their projects.
     */
    protected function query(): Builder
    {
        $projects = $this->projects();
        $user = auth()->user();
        abort_unless($projects->canManage($user), 403);

        $query = Holiday::query()->with('projects.program', 'projects.city')->orderByDesc('start_date');

        if ($projects->isNationalHr($user)) {
            return $query;
        }

        $mine = $projects->managedBy($user);

        return $query->where(fn (Builder $q) => $q
            ->where('scope', Holiday::SCOPE_NATIONAL)
            ->orWhereHas('projects', function (Builder $link) use ($mine) {
                $link->where(function (Builder $any) use ($mine) {
                    foreach ($mine as $p) {
                        $any->orWhere(fn (Builder $pair) => $pair->where('program_id', $p['program_id'])->where('city_id', $p['city_id']));
                    }
                });
            }));
    }

    protected function globalSearchColumns(): array
    {
        return ['name_en', 'name_ar'];
    }

    protected function columns(): array
    {
        $user = auth()->user();
        $projects = $this->projects();

        return [
            Column::make(__('general.name'), fn (Model $m): string => e($m->name))->asHtml(),

            Column::make(__('holidays.kind'), fn (Model $m): string => e(__('holidays.kinds.'.$m->kind)))->asHtml(),

            Column::make(__('holidays.applies-to'), function (Model $m): string {
                if ($m->isNational()) {
                    return e(__('holidays.national'));
                }

                $labels = $m->projects->map(fn ($l) => $l->program?->name.' — '.$l->city?->name);

                return $labels->isEmpty()
                    ? '<span class="text-primary">'.e(__('holidays.no-projects')).'</span>'
                    : e($labels->take(3)->join(app()->getLocale() === 'ar' ? '، ' : ', ')).($labels->count() > 3 ? ' +'.($labels->count() - 3) : '');
            })->asHtml(),

            Column::make(__('holidays.from'), 'start_date')->sortable()
                ->displayUsing(fn ($value) => $value?->toDateString()),

            Column::make(__('holidays.to'), 'end_date')->sortable()
                ->displayUsing(fn ($value) => $value?->toDateString()),

            Column::make(__('holidays.recurring'), fn (Model $m): string => $m->is_recurring ? __('general.yes') : __('general.no')),

            Column::make(__('general.actions'), function (Model $m) use ($user, $projects): string {
                if (! $projects->canChange($user, $m)) {
                    return '';
                }

                $id = $m->id;

                return '<div class="flex items-center justify-center gap-2">'
                    .Blade::render('<x-table.edit-btn wire:click="$dispatch(\'openHolidayEdit\', { id: '.$id.' })" />')
                    .Blade::render('<x-table.delete-btn wire:click="confirmDelete('.$id.')" :modelId="'.$id.'" />')
                    .'</div>';
            })->asHtml()->clickable(false),
        ];
    }

    private function projects(): HolidayProjects
    {
        return app(HolidayProjects::class);
    }
}
