<?php

namespace App\Livewire\Badges;

use App\Enums\BadgeType;
use App\Models\Badge;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

class BadgesTable extends LivewireTable
{
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
        $validated = validator($payload, [
            'id' => ['required', 'integer', 'min:1'],
        ])->validate();

        $badge = $this->findVisibleBadge((int) $validated['id']);
        Gate::authorize('delete', $badge);

        DB::transaction(fn () => $badge->delete());

        LivewireAlert::title(__('general.success-message'))
            ->success()->toast()->position('top-start')->show();

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
                    '<button
                        type="button"
                        wire:click="$dispatch(\'open-badge-modal\', { id: {{ $id }} })"
                        class="inline-flex items-center justify-center w-8 h-8 rounded hover:bg-gray-100 dark:hover:bg-gray-800"
                        title="{{ __(\'general.view\') }}">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none"
                            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                            class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12
                                   s-3.75 6.75-9.75 6.75S2.25 12 2.25 12z" />
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </button>', ['id' => $id],
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
            ->when(
                auth()->user()->activeCityIds(),
                fn (Builder $query, array $cityIds) => $query->whereHas(
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
            ->when(
                auth()->user()->activeCityIds(),
                fn (Builder $query, array $cityIds) => $query->whereHas(
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
