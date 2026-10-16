<?php

namespace App\Livewire\ProgramManagement\Center;

use App\Exports\CenterExport;
use App\Livewire\Concerns\ScopesToAuthorizedCity;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Center;
use App\Models\City;
use App\Models\User;
use App\Notifications\CenterClosed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\BooleanColumn;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CentersTable extends LivewireTable
{
    use ScopesToAuthorizedCity;
    use WithFeedback;

    protected string $model = Center::class;

    protected $listeners = ['delete', 'toggleItemStatusConfirmed', 'exportExcel' => 'exportExcel'];

    public function confirmToggleItemStatus($id): void
    {
        $this->confirm('toggleItemStatusConfirmed', ['id' => $id], title: __('general.toggle-status'), message: __('general.confirm-status-change'), confirmLabel: __('general.save'), danger: false);
    }

    public function toggleItemStatusConfirmed($payload): void
    {
        $item = Center::findOrFail($payload['id']);

        // The id comes from a browser-dispatched confirmation payload.
        Gate::authorize('update', $item);

        $item->is_active = ! $item->is_active;
        $item->save();

        if (! $item->is_active) {
            $item->load('city');
            User::sendBroadcast(new CenterClosed($item), $item->city_id);
            $this->dispatch('center-closed');
        }

        $this->toast(__('general.item-updated'));
    }

    public function confirmDelete($id): void
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete($payload): void
    {
        $center = Center::findOrFail($payload['id']);

        Gate::authorize('delete', $center);

        $center->delete();

        $this->toast();
    }

    public function exportExcel(): BinaryFileResponse
    {
        Gate::authorize('export', Center::class);

        // appliedQuery() derives from query(), which is already city-scoped, so
        // the export cannot contain rows the table would not have shown.
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

        $filename = 'centers_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new CenterExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    protected function rowClickAction(Model $model): ?string
    {
        return auth()->user()?->hasActivePermission('centers.update')
            ? "\$dispatch('openCenterEdit', { id: {$model->getKey()} })"
            : null;
    }

    protected function query(): Builder
    {
        // Applied at the query, so it covers the table, the global search, the
        // filters, and exportExcel() (which builds on appliedQuery()) at once.
        return $this->scopeToAuthorizedCity(
            $this->model()::query()
                ->with(['city', 'managerContracts.employee'])
                ->withCount('positions'),
        );
    }

    protected function globalSearchColumns(): array
    {
        return [transColumn('name')];
    }

    protected function cityOptions(): array
    {
        $locale = app()->getLocale();

        return $this->authorizedCities()
            ->whereHas('centers')
            ->orderBy(transColumn('name'))
            ->get(['id', transColumn('name')])
            ->mapWithKeys(fn ($c) => [$c->id => $c->name])
            ->all();
    }

    /**
     * Cities this user may filter by.
     *
     * The dropdown and the row query are both derived from authorizedCityIds(),
     * so a filter option can never select rows the query would exclude — and,
     * more importantly, the option list itself stops disclosing which other
     * cities exist and have centers.
     */
    protected function authorizedCities(): Builder
    {
        $query = City::query();
        $cityIds = $this->authorizedCityIds();

        return $cityIds === null ? $query : $query->whereKey($cityIds);
    }

    protected function columns(): array
    {
        $locale = app()->getLocale();
        $user = auth()->user();
        $canViewPositions = $user?->hasActivePermission('positions.view') ?? false;
        $canUpdate = $user?->hasActivePermission('centers.update') ?? false;
        $canDelete = $user?->hasActivePermission('centers.delete') ?? false;

        return [
            Column::make('ID', 'id')->sortable(),

            Column::make(__('general.center-name'), transColumn('name'))
                ->sortable()->searchable(),
            Column::make(__('general.center-manager'), fn (Model $m): string => $m->managerNames(), 'center_manager')
                ->searchable(function (Builder $query, $value): void {
                    $query->whereHas('managerContracts.employee', fn (Builder $employee) => $employee->whereLikeAny([
                        'first_name_en', 'last_name_en', 'first_name_ar', 'last_name_ar',
                    ], (string) $value));
                }),

            Column::make(__('general.working-hours'), 'open_at')
                ->displayUsing(fn ($v, Model $m) => __('general.from-hour-to-hour', [
                    'from' => $m->open_at_formatted,
                    'to' => $m->close_at_formatted,
                ]))->sortable(),

            SelectColumn::make(trans_choice('general.city', 1), 'city.'.transColumn('name'))
                ->options(
                    $this->authorizedCities()
                        ->whereNot('code', '000')
                        ->where('is_active', true)
                        ->get()
                        ->pluck(transColumn('name'), transColumn('name'))
                        ->toArray(),
                )
                ->sortable()
                ->searchable(),

            Column::make(__('general.address'), 'address')
                ->displayUsing(fn ($v, Model $m) => $m->address ?: '—')
                ->sortable()
                ->searchable(),

            BooleanColumn::make(__('general.is-active'), 'is_active')->searchable(),

            // ✅ Positions button with count
            // Column::make(__('general.positions'), function (Model $m): string {
            //     $url = route('positions.index', ['center' => $m->id]);

            //     $classes = 'inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full text-xs md:text-sm font-medium ' . 'border shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-secondary-light ' . ($m->is_active ? 'bg-secondary-soft text-secondary-dark shadow-[0px_0px_25px_-14px_rgba(88,_89,_91,_0.30)] ' : 'bg-white text-neutral-700 border-neutral-200 hover:bg-neutral-50 hover:border-neutral-300');

            //     $count = $m->positions_count ?? 0;
            //     $badge = '<span class="inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-[11px] md:text-xs bg-current/10">' . $count . '</span>';

            //     return Blade::render(
            //         '<a href="' . $url . '" class="' . $classes . '" title="' . e(__('general.positions')) . '">
            //             <i class="ti ti-briefcase text-base"></i>
            //             <span>' . e(trans_choice('general.positions', 2)) . '</span>' .
            //         $badge .
            //         '</a>'
            //     );
            // })->asHtml()->visible($user->activeRoleIs('HR') || $user->activeRoleIs('super-admin')),
            Column::make(__('general.positions'), 'positions_count')
                ->displayUsing(function ($v, Model $m): string {

                    $url = route('positions.index', ['center' => $m->id]);

                    $classes = 'inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full text-xs md:text-sm font-medium '.'border shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-secondary-light '.($m->is_active ? 'bg-secondary-soft text-secondary-dark shadow-[0px_0px_25px_-14px_rgba(88,_89,_91,_0.30)] ' : 'bg-white text-neutral-700 border-neutral-200 hover:bg-neutral-50 hover:border-neutral-300');

                    $count = $m->positions_count ?? 0;
                    $badge = '<span class="inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-[11px] md:text-xs bg-current/10">'.$count.'</span>';

                    return Blade::render(
                        '<a href="'.$url.'" class="'.$classes.'" title="'.e(__('general.positions')).'">
                <i class="ti ti-briefcase text-base"></i>
                <span>'.e(trans_choice('general.positions', 2)).'</span>'.
                            $badge.
                            '</a>',
                    );
                })->asHtml()->visible($canViewPositions)->clickable(false),

            Column::make(__('general.actions'), function (Model $m) use ($canUpdate, $canDelete): string {
                $viewUrl = route('centers.show', $m->getKey());
                $viewBtn = Blade::render(
                    '<a href="'.$viewUrl.'"
                                class="inline-flex items-center justify-center p-2 text-xl rounded-lg  text-black"
                                title="'.e(__('general.view')).'">
                                <svg xmlns="http://www.w3.org/2000/svg"
                                     fill="none" viewBox="0 0 24 24"
                                     stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M2.25 12s3.75-7.5 9.75-7.5 9.75 7.5 9.75 7.5-3.75 7.5-9.75 7.5S2.25 12 2.25 12z" />
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </a>',
                );

                if (! $canUpdate && ! $canDelete) {
                    return $viewBtn;
                }

                $toggleBtn = $canUpdate
                    ? Blade::render(
                        '<x-forms.toggle-input class="!me-2 text-xl p-2 mb-3" active="'.($m->is_active ? 1 : 0).'" :model-id="'.$m->getKey().'" wire-event="confirmToggleItemStatus" />',
                    )
                    : '';

                $editBtn = $canUpdate
                    ? Blade::render('<x-table.edit-btn wire:click="$dispatch(\'openCenterEdit\', { id: '.$m->getKey().' })" />')
                    : '';

                $deleteBtn = $canDelete
                    ? Blade::render('<x-table.delete-btn :modelId="'.$m->getKey().'" />')
                    : '';

                return $toggleBtn.$viewBtn.$editBtn.$deleteBtn;
            })->asHtml()->clickable(false),
        ];
    }
}
