<?php

namespace App\Livewire\Contract;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\SalaryPer;
use App\Exports\ContractExport;
use App\Livewire\Concerns\HasListStates;
use App\Livewire\Concerns\HasSavedViews;
use App\Livewire\Concerns\RemembersTableState;
use App\Livewire\Concerns\WithFeedback;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Columns\SelectColumn;
use RamonRietdijk\LivewireTables\Enums\Direction;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContractsTable extends LivewireTable
{
    use HasListStates;
    use HasSavedViews;

    // Remembers search/filters/sort/page/columns per user + ACTIVE role + city
    // scope (B4-D). Aliased so the preset can join the chips and "Clear all".
    use RemembersTableState {
        initialize as protected initializeRememberedState;
        canClearAll as protected rememberedCanClearAll;
        activeFilterChips as protected rememberedChips;
        removeChip as protected rememberedRemoveChip;
    }
    use WithFeedback;

    public const PRESET_DEFAULT = 'active';

    /** "Ending soon" threshold, decided B4D-Q3. */
    public const ENDING_SOON_DAYS = 30;

    /** Presets other than the default are for HR / general manager only (table-contract.md §5). */
    private const PRESETS = ['active', 'ending-soon', 'ended', 'draft'];

    // Open alphabetically by employee name; clicking the header still toggles
    // desc and then clears back to the package's id order.
    public string $sortColumn = 'employee';

    public string $sortDirection = 'asc';

    /** Named view (`?view=`). It only ever narrows Contract::visibleTo(). */
    #[Url(as: 'view')]
    public string $preset = self::PRESET_DEFAULT;

    // The preset bar wraps the package's own table view.
    protected string $view = 'livewire.contract.contracts-table';

    protected string $model = Contract::class;

    protected $listeners = [
        'delete',
        'toggleAutoRenewConfirmed',
        'refreshContractsTable' => '$refresh',
        'exportExcel' => 'exportExcel',
    ];

    public function confirmDelete(int $id): void
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(array $payload): void
    {
        $contract = Contract::findOrFail($payload['id']);
        Gate::authorize('delete', $contract);

        DB::transaction(fn () => $contract->delete());

        $this->toast();

        $this->dispatch('refreshContractsTable');
    }

    public function confirmToggleAutoRenew(int $id): void
    {
        $contract = Contract::findOrFail($id);
        Gate::authorize('manageAutoRenew', $contract);

        $this->confirm('toggleAutoRenewConfirmed', ['id' => $id], title: __('general.toggle-auto-renew'), message: __('general.confirm-auto-renew-change'), confirmLabel: __('general.save'), danger: false);
    }

    public function toggleAutoRenewConfirmed(array $payload): void
    {
        $contract = Contract::findOrFail($payload['id']);
        Gate::authorize('manageAutoRenew', $contract);
        // The toggle is not rendered for a terminated or trial contract, and
        // ContractObserver::saving() would force the flag straight back off.
        abort_unless($contract->canAutoRenew(), 403);

        $contract->update(['auto_renew' => ! $contract->auto_renew]);

        $this->toast(__('general.item-updated'));

        $this->dispatch('refreshContractsTable');
    }

    /**
     * Exports exactly the columns the table shows (B4D-Q6): the picker's
     * choice, in the table's order. Nothing pay-related is a column, so no
     * figure can reach the file.
     */
    public function exportExcel(): BinaryFileResponse
    {
        $query = $this->appliedQuery();
        $data = $query->get();

        $shown = array_map('strval', $this->columns);

        $visibleColumnCodes = $this->resolveColumns()
            ->filter(fn ($column) => in_array($column->code(), $shown, true))
            ->filter(fn ($column) => $column->label() !== __('general.actions'))
            ->map(fn ($column) => $column->code())
            ->values()
            ->toArray();

        $filename = 'contracts_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new ContractExport($data, $visibleColumnCodes),
            $filename,
        );
    }

    // ------------------------------------------------------------- presets

    /** Choose a named view. Only the ones this role may use are accepted. */
    public function setPreset(string $preset): void
    {
        abort_unless(array_key_exists($preset, $this->presetOptions()), 403);

        $this->preset = $preset;
        $this->stateReset = false;
        $this->resetPage();
        $this->updateSession();
    }

    public function canClearAll(): bool
    {
        return $this->preset !== self::PRESET_DEFAULT || $this->rememberedCanClearAll();
    }

    public function activeFilterChips(): array
    {
        $chips = $this->rememberedChips();

        if ($this->preset !== self::PRESET_DEFAULT) {
            array_unshift($chips, [
                'group' => 'preset',
                'key' => '',
                'label' => __('contracts.views-label'),
                'value' => $this->presetOptions()[$this->preset] ?? $this->preset,
            ]);
        }

        return $chips;
    }

    public function removeChip(string $group, string $key = ''): void
    {
        if ($group === 'preset') {
            $this->clearPreset();
            $this->resetPage();
            $this->updateSession();

            return;
        }

        $this->rememberedRemoveChip($group, $key);
    }

    public function cardView(): ?string
    {
        return 'livewire.contract._card';
    }

    /**
     * Whole days until a running contract ends, when that is within the
     * ending-soon window; null otherwise (no end, past, draft, terminated).
     */
    public static function endsInDays(Model $contract): ?int
    {
        if (! $contract->is_active || $contract->isTerminated() || (int) $contract->type === ContractTypeEnum::Draft || ! $contract->end_date) {
            return null;
        }

        $days = (int) today()->diffInDays($contract->end_date->copy()->startOfDay(), false);

        return $days >= 0 && $days <= self::ENDING_SOON_DAYS ? $days : null;
    }

    /**
     * The status word and badge colour for one row. A draft and a terminated
     * contract are both inactive, but they are not the same state.
     *
     * @return array{0: string, 1: string}
     */
    public static function statusOf(Model $contract): array
    {
        return match (true) {
            (int) $contract->type === ContractTypeEnum::Draft => [ContractTypeEnum::getLocalizedName(ContractTypeEnum::Draft)['value'], 'gray'],
            $contract->isTerminated() => [__('contracts.status-terminated'), 'red'],
            (bool) $contract->is_active => [__('general.active'), 'green'],
            $contract->end_date !== null && $contract->end_date->isPast() => [__('contracts.status-expired'), 'gray'],
            default => [__('general.inactive'), 'gray'],
        };
    }

    /** @return array<string, string> preset => label, as the active role may use them */
    protected function presetOptions(): array
    {
        $labels = [
            'active' => __('contracts.view-active'),
            'ending-soon' => __('contracts.view-ending-soon', ['days' => self::ENDING_SOON_DAYS]),
            'ended' => __('contracts.view-ended'),
            'draft' => __('contracts.view-draft'),
        ];

        return $this->canUseAllPresets() ? $labels : ['active' => $labels['active']];
    }

    protected function canUseAllPresets(): bool
    {
        return once(fn () => auth()->user()?->activeRoleIs('HR', 'general-manager', 'super-admin') ?? false);
    }

    protected function clearPreset(): void
    {
        $this->preset = self::PRESET_DEFAULT;
    }

    /** A preset this role may not use (stale URL, other role's link) falls back with the usual notice. */
    protected function initialize(): void
    {
        $this->initializeRememberedState();

        if (! array_key_exists($this->preset, $this->presetOptions())) {
            $this->preset = self::PRESET_DEFAULT;
            $this->stateReset = true;
        }
    }

    /** Narrow (never widen) what Contract::visibleTo() already allowed. */
    protected function applyPreset(Builder $query): void
    {
        $query->inView($this->preset, self::ENDING_SOON_DAYS);
    }

    /** Add action only for roles that may open the create route (contracts.create). */
    protected function emptyStateCreate(): ?array
    {
        return auth()->user()?->hasActivePermission('contracts.create')
            ? [__('general.state-add-contract'), route('contracts.create')]
            : null;
    }

    /**
     * Rows have no URL: a click opens the read-only drawer (never the editor,
     * B4D-Q2). Editing is the deliberate "Edit" item in the More menu.
     */
    protected function link(Model $model): ?string
    {
        return null;
    }

    protected function rowClickAction(Model $model): ?string
    {
        return $this->openDrawerJs($model->getKey());
    }

    protected function openDrawerJs(int|string $id): string
    {
        $id = (int) $id;

        return "window.dispatchEvent(new CustomEvent('contract-drawer-opening', { detail: { id: {$id} } })); Livewire.dispatch('open-contract-drawer', { contractId: {$id} })";
    }

    protected function query(): Builder
    {
        $query = $this->model()::query()
            ->with([
                'employee',
                'position.department',
                'position.program',
                'position.jobLevel',
                'position.city',
                'position.center.city',
                'contractType',
                'leaveBalances',
                'terminationReason',
            ])
            ->visibleTo(auth()->user());

        $this->applyPreset($query);

        return $query;
    }

    protected function globalSearchColumns(): array
    {
        $loc = app()->getLocale();

        return [
            'id',
            'employee.'.($loc === 'ar' ? 'first_name_ar' : 'first_name_en'),
            'position.'.($loc === 'ar' ? 'title_ar' : 'title_en'),
            'contractType.'.($loc === 'ar' ? 'name_ar' : 'name_en'),
            'position.department.name',
        ];
    }

    /**
     * Resolved once per request. query() has already restricted the whole
     * result set to what ContractPolicy can see, so only the role's permissions
     * and the row's own state are left to check per row, without touching the
     * database (the server re-authorizes every action when it is used).
     *
     * @return array{update: bool, delete: bool, create: bool, export: bool, autoRenew: bool}
     */
    protected function abilities(): array
    {
        return once(function (): array {
            $user = auth()->user();
            $canUpdate = $user?->hasActivePermission('contracts.update') ?? false;

            return [
                'update' => $canUpdate,
                'delete' => $user?->hasActivePermission('contracts.delete') ?? false,
                'create' => $user?->hasActivePermission('contracts.create') ?? false,
                // Mirrors ContractPolicy::export, which also refuses a role that
                // may not see pay figures - the PDF carries the stipend clause.
                // Hoisted so the item is absent rather than present and 403-ing.
                'export' => ($user?->hasActivePermission('contracts.export') ?? false) && ($user?->canViewSalary() ?? false),
                'autoRenew' => ($user?->activeRoleIs('HR') ?? false) && $canUpdate,
            ];
        });
    }

    /** The View button and More menu of one row (or only the menu, on a card). */
    protected function rowActions(Model $contract, string $context = 'row'): string
    {
        $can = $this->abilities();

        return view('livewire.contract._row-actions', [
            'contract' => $contract,
            'context' => $context,
            'name' => $contract->employee?->name ?? __('general.na'),
            'canEdit' => $can['update'],
            'canDelete' => $can['delete'],
            // Mirrors ContractPolicy::renew: permission (hoisted), visibility
            // (guaranteed by query()), and the contract's own state.
            'canRenew' => $can['create'] && $contract->is_active && $contract->terminated_at === null,
            'canExport' => $can['export'],
            'canAutoRenew' => $can['autoRenew'] && $contract->canAutoRenew(),
            'openJs' => $this->openDrawerJs($contract->getKey()),
        ])->render();
    }

    /** @return array<int, string> city ids this role may filter by (all of them for nationwide roles) */
    protected function cityOptions(string $name): array
    {
        $cityIds = auth()->user()?->activeCityIds();

        return City::query()
            ->when($cityIds !== null, fn ($q) => $q->whereIn('id', $cityIds))
            ->orderBy($name)->pluck($name, 'id')->toArray();
    }

    protected function columns(): array
    {
        $loc = app()->getLocale();
        $name = $loc === 'ar' ? 'name_ar' : 'name_en';
        $title = $loc === 'ar' ? 'title_ar' : 'title_en';
        // A city-scoped officer sees one city on every row; only roles that
        // span cities get the column by default (it stays in the picker).
        $nationwide = auth()->user()?->activeCityIds() === null;

        return [
            // Identity: the name with the position as a second line.
            Column::make(trans_choice('general.employee', 1), 'employee')
                ->displayUsing(fn ($v, Model $m) => Blade::render(
                    '<div class="min-w-0"><button type="button" x-on:click.stop="{{ $open }}" aria-haspopup="dialog" data-contract-view="{{ $id }}" aria-label="{{ $label }}" class="block max-w-full truncate text-start font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" dir="auto">{{ $name }}</button><div class="truncate text-small text-neutral-600" dir="auto">{{ $position }}</div></div>',
                    [
                        'name' => $m->employee?->name ?? '—',
                        'position' => $m->position?->{$title} ?? '',
                        'open' => $this->openDrawerJs($m->getKey()),
                        'id' => $m->getKey(),
                        'label' => __('contracts.view-named', ['name' => $m->employee?->name ?? __('general.na')]),
                    ],
                ))->asHtml()
                // Same order as the displayed name: first name, then last name,
                // in the active locale. Subqueries rather than a join so the
                // select stays contracts.* for the table and the Excel export.
                ->sortable(function (Builder $query, Direction $direction) {
                    foreach (['first_name', 'last_name'] as $base) {
                        $query->orderBy(
                            Employee::select(transColumn($base))->whereColumn('employees.id', 'contracts.employee_id'),
                            $direction->value,
                        );
                    }
                    $query->orderBy('contracts.id');
                })
                ->searchable(function (Builder $query, $value) {
                    $parts = array_filter(explode(' ', trim($value)));

                    $query->whereHas('employee', function (Builder $q) use ($parts) {
                        foreach ($parts as $part) {
                            $q->where(function (Builder $inner) use ($part) {
                                $inner->where('first_name_en', 'LIKE', "%{$part}%")
                                    ->orWhere('first_name_ar', 'LIKE', "%{$part}%")
                                    ->orWhere('last_name_en', 'LIKE', "%{$part}%")
                                    ->orWhere('last_name_ar', 'LIKE', "%{$part}%");
                            });
                        }
                    });
                }),
            SelectColumn::make(trans_choice('general.program', 1), 'position.program.slug')
                ->options(Program::orderBy('slug')->pluck('slug', 'slug')->toArray())
                ->sortable()->searchable()->hide(),

            SelectColumn::make(__('general.position'), 'position.'.$title)
                ->options(Position::orderBy($title)->pluck($title, $title)->toArray())
                ->sortable()->searchable()->hide(),

            SelectColumn::make(trans_choice('general.contract-type', 1), 'contractType.'.$name)
                ->options(ContractType::orderBy($name)->pluck($name, $name)->toArray())
                ->sortable()->searchable(),

            SelectColumn::make(trans_choice('general.city', 1), 'city')
                ->options($this->cityOptions($name))
                ->displayUsing(fn ($v, Model $m) => $m->position?->city?->name ?? $m->position?->center?->city?->name ?? '—')
                ->searchable(function (Builder $query, $value) {
                    $query->whereHas('position', function (Builder $q) use ($value) {
                        $q->where('city_id', $value)
                            ->orWhereHas('center', fn (Builder $c) => $c->where('city_id', $value));
                    });
                })
                ->visible($nationwide),

            SelectColumn::make(trans_choice('general.department', 1), 'position.department.name')
                ->options(Department::orderBy('name')->pluck('name', 'name')->toArray())
                ->sortable()->searchable()->hide(),

            SelectColumn::make(trans_choice('general.job-level', 1), 'position.jobLevel.'.$name)
                ->options(JobLevel::orderBy('grade')->pluck($name, $name)->toArray())
                ->sortable()->searchable()->hide(),

            Column::make(__('general.duration'), 'duration')
                ->displayUsing(fn ($v, Model $m) => self::countLabel('months', $m->duration))
                ->sortable()->hide(),

            // Start - end, plus "ends in N days" when the end is within the
            // ending-soon window.
            Column::make(__('general.period'), 'period')
                ->displayUsing(fn ($v, Model $m) => Blade::render(
                    '<div><x-period :start="$start" :end="$end" />'
                    .'@if($days !== null)<p class="mt-1 text-center text-small font-medium text-amber-800">{{ trans_choice(\'contracts.ends-in-days\', $days, [\'count\' => $days]) }}</p>@endif</div>',
                    ['start' => $m->start_date, 'end' => $m->end_date, 'days' => self::endsInDays($m)],
                ))->asHtml(),
            // The reason rides along as a tooltip rather than its own column:
            // it only means something next to the date it explains.
            Column::make(__('general.terminated-at'), 'terminated_at')
                ->displayUsing(fn ($v, Model $m) => $m->isTerminated()
                    ? Blade::render(
                        '<span title="{{ $reason }}" class="px-2 py-1 font-medium text-red-800 bg-red-100 whitespace-nowrap rounded-inset text-small"><bdi>{{ $date }}</bdi></span>',
                        [
                            'date' => $m->terminated_at?->format('Y-m-d') ?? __('general.terminated-at'),
                            'reason' => $m->terminationReason?->title ?? $m->reason_of_termination ?? '',
                        ],
                    )
                    : '—')
                ->sortable()
                ->asHtml()->hide(),

            Column::make(__('general.yearly-leave-days'), 'leaveBalances')
                // The balance reads its day length through the contract, already
                // loaded here with position.center: no query per row.
                ->displayUsing(fn ($m, $contract) => $m?->first()?->setRelation('contract', $contract)->deserved_formatted ?? 0)
                ->sortable()->asHtml()->hide(),

            // The pay UNIT only ("per month"); no amount is a column anywhere.
            SelectColumn::make(__('general.salary-per'), 'salary_per')
                ->options(
                    collect(SalaryPer::getValues())->mapWithKeys(fn ($value) => [
                        $value => SalaryPer::getLocalizedName((int) $value)['value'],
                    ])->toArray(),
                )
                ->displayUsing(function ($value) {
                    $value = $value instanceof SalaryPer ? $value->value : (int) $value;

                    $per = SalaryPer::getLocalizedName($value);

                    return Blade::render('<x-badge :color="$color">{{$text}}</x-badge>', [
                        'color' => $per['color'] ?? 'secondary-light',
                        'text' => $per['value'] ?? $value,
                    ]);
                })
                ->sortable()
                ->searchable()
                ->asHtml()->hide(),

            Column::make(__('general.weekly-hours'), 'weekly_hours')
                ->displayUsing(fn ($v) => $v ? self::countLabel('hours', $v) : '—')
                ->sortable()->hide(),

            // Text plus colour rather than the boolean column's bare dot, so the
            // state reads in either language and without relying on colour.
            // Filtering stays on the stored flag (active / inactive).
            SelectColumn::make(__('general.status'), 'is_active')
                ->options([
                    1 => __('general.active'),
                    0 => __('general.inactive'),
                ])
                ->displayUsing(function ($v, Model $m) {
                    [$label, $color] = self::statusOf($m);

                    return Blade::render('<x-badge :color="$color">{{ $text }}</x-badge>', [
                        'color' => $color,
                        'text' => $label,
                    ]);
                })
                ->sortable()
                ->searchable()
                ->asHtml(),

            // Same reasoning as the status column: a word, not just a dot, and
            // a filter whose Yes/No follow the interface language.
            SelectColumn::make(__('general.auto-renew'), 'auto_renew')
                ->options([
                    1 => __('general.yes'),
                    0 => __('general.no'),
                ])
                ->displayUsing(fn ($v) => Blade::render('<x-badge :color="$color">{{ $text }}</x-badge>', [
                    'color' => $v ? 'green' : 'gray',
                    'text' => $v ? __('general.yes') : __('general.no'),
                ]))
                ->sortable()
                ->searchable()
                ->asHtml(),

            // Main action View (the read-only drawer) + a More menu. The label
            // is matched by exportExcel() to keep this column out of the file.
            Column::make(__('general.actions'), fn (Model $m): string => $this->rowActions($m))
                ->asHtml()->clickable(false),
        ];
    }

    /**
     * "3 months" / "٣ أشهر" with the right plural form; a fractional figure
     * (2.5 months) has no plural category of its own, so it gets the plain one.
     */
    private static function countLabel(string $unit, int|float|string $value): string
    {
        $number = (float) $value;

        return fmod($number, 1.0) === 0.0
            ? trans_choice("contracts.{$unit}-count", (int) $number)
            : __("contracts.{$unit}-decimal", ['count' => $value]);
    }
}
