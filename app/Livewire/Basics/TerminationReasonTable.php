<?php

namespace App\Livewire\Basics;

use App\Livewire\Concerns\WithFeedback;
use App\Models\TerminationReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

class TerminationReasonTable extends LivewireTable
{
    use WithFeedback;

    protected string $model = TerminationReason::class;

    protected $listeners = [
        'refreshTerminationReasonTable' => '$refresh',
        'delete',
    ];

    public function confirmDelete(int $id): void
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(array $payload): void
    {
        TerminationReason::findOrFail($payload['id'])->delete();

        $this->toast();

        $this->dispatch('refreshTerminationReasonTable');
    }

    protected function rowClickAction(Model $model): ?string
    {
        return auth()->user()?->hasActivePermission('project-basics.update')
            ? "\$dispatch('openTerminationReasonEdit', { id: {$model->getKey()} })"
            : null;
    }

    protected function query(): Builder
    {
        return TerminationReason::query();
    }

    protected function globalSearchColumns(): array
    {
        return ['id', 'title_en', 'title_ar'];
    }

    protected function columns(): array
    {
        $canUpdate = auth()->user()?->hasActivePermission('project-basics.update') ?? false;
        $canDelete = auth()->user()?->hasActivePermission('project-basics.delete') ?? false;

        return [
            Column::make(__('general.title').' (EN)', 'title_en')
                ->sortable()
                ->searchable(),

            Column::make(__('general.title').' (AR)', 'title_ar')
                ->sortable()
                ->searchable(),

            Column::make(__('general.actions'), function (Model $m) use ($canUpdate, $canDelete): string {
                $id = $m->id;

                $editBtn = $canUpdate
                    ? Blade::render('<x-table.edit-btn wire:click="$dispatch(\'openTerminationReasonEdit\', { id: '.$id.' })" />')
                    : '';
                $deleteBtn = $canDelete
                    ? Blade::render('<x-table.delete-btn wire:click="confirmDelete('.$id.')" :modelId="'.$id.'" />')
                    : '';

                return '<div class="flex items-center justify-center gap-2">'.$editBtn.$deleteBtn.'</div>';
            })->asHtml()->visible($canUpdate || $canDelete)->clickable(false),
        ];
    }
}
