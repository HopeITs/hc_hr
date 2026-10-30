<?php

namespace App\Livewire\Badges;

use App\Enums\BadgeType;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Badge;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

class BadgesTable extends LivewireTable
{
    use WithFeedback;

    protected string $model = Badge::class;

    protected $listeners = [
        'delete',
        'refreshBadgesTable' => '$refresh',
    ];

    public function boot(): void
    {
        Gate::authorize('viewAny', Badge::class);
    }

    public function confirmDelete(int $id): void
    {
        $badge = $this->findVisibleBadge($id);
        Gate::authorize('delete', $badge);

        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(array $payload): void
    {
        $validated = validator($payload, [
            'id' => ['required', 'integer', 'min:1'],
        ])->validate();

        $badge = $this->findVisibleBadge((int) $validated['id']);
        Gate::authorize('delete', $badge);

        DB::transaction(fn () => $badge->delete());

        $this->toast();

        $this->dispatch('refreshBadgesTable');
    }

    protected function rowClickAction(Model $model): ?string
    {
        return "\$dispatch('open-badge-modal', { id: {$model->getKey()} })";
    }

    protected function query(): Builder
    {
        return $this->model()::query()
            ->visibleTo(auth()->user())
            ->with([
                'employee.contracts.position.program',
                'employee.contracts.position.department',
            ]);
    }

    protected function columns(): array
    {
        $canDelete = auth()->user()?->hasActivePermission('badges.delete') ?? false;

        return [
            SelectColumn::make(trans_choice('general.employee', 1), 'employee_id')
                ->options($this->employeeOptions())
                ->displayUsing(fn ($v, Model $m) => $m->employee?->name ?? '—')
                ->sortable()
                ->searchable(),

            SelectColumn::make(trans('general.type'), 'type')
                ->options($this->badgeTypeOptions())
                ->displayUsing(fn ($v, Model $m) => BadgeType::getLocalizedName((int) ($m->type ?? 0)))
                ->sortable()
                ->searchable(),

            SelectColumn::make(trans_choice('general.program', 1), 'program_id')
                ->options($this->programOptions())
                ->displayUsing(fn ($v, Model $m) => $m->employee?->latestActiveContract()?->position?->program?->name ?? '—')
                // ->sortable()
                ->searchable(function (Builder $query, $value) {
                    $query->whereHas('employee.contracts.position.program', function ($q) use ($value) {
                        $q->where('id', $value);
                    });
                }),

            SelectColumn::make(trans_choice('general.department', 1), 'department_id')
                ->options($this->departmentOptions())
                ->displayUsing(fn ($v, Model $m) => $m->employee?->latestActiveContract()?->position?->department?->name ?? '—')
                // ->sortable()
                ->searchable(function (Builder $query, $value) {
                    $query->whereHas('employee.contracts.position.department', function ($q) use ($value) {
                        $q->where('id', $value);
                    });
                }),

            Column::make(trans('general.date'), 'date')
                ->displayUsing(fn ($v, Model $m) => optional($m->date)->format('Y-m-d') ?? '—')
                ->sortable(),

            Column::make(__('general.actions'), function (Model $m) use ($canDelete): string {

                $id = $m->getKey();

                $viewBtn = Blade::render(
                    '<x-table.show-btn wire:click="$dispatch(\'open-badge-modal\', { id: {{ $id }} })" :aria-label="__(\'general.view\')" />',
                    ['id' => $id],
                );

                $deleteBtn = $canDelete
                    ? Blade::render('<x-table.delete-btn :modelId="$id" />', ['id' => $id])
                    : '';

                return '<div class="flex items-center justify-center gap-2">'.$viewBtn.$deleteBtn.'</div>';
            })->asHtml()->clickable(false),
        ];
    }

    protected function badgeTypeOptions(): array
    {
        $values = BadgeType::getValues();
        $out = [];
        foreach ($values as $v) {
            $out[$v] = BadgeType::getLocalizedName((int) $v);
        }

        return $out;
    }

    protected function employeeOptions(): array
    {
        return Employee::query()
            ->select(['id', 'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar'])
            ->whereHas('badges', fn (Builder $query) => $query->visibleTo(auth()->user()))
            ->where('is_active', true)
            ->orderBy(transColumn('first_name'))
            ->get()
            ->mapWithKeys(fn ($e) => [$e->id => $e->name])
            ->all();
    }

    protected function programOptions(): array
    {
        return Program::query()
            // null is nationwide; an empty list (no such city) offers nothing (G-C-13).
            ->when(
                ($cityIds = auth()->user()->activeCityIds()) !== null,
                fn (Builder $query) => $query->whereHas(
                    'positions',
                    fn (Builder $query) => $query->inCity($cityIds),
                ),
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    protected function departmentOptions(): array
    {
        return Department::query()
            // null is nationwide; an empty list (no such city) offers nothing (G-C-13).
            ->when(
                ($cityIds = auth()->user()->activeCityIds()) !== null,
                fn (Builder $query) => $query->whereHas(
                    'positions',
                    fn (Builder $query) => $query->inCity($cityIds),
                ),
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    protected function findVisibleBadge(int $id): Badge
    {
        $badge = Badge::query()
            ->visibleTo(auth()->user())
            ->find($id);

        abort_unless($badge, 404);

        return $badge;
    }
}
