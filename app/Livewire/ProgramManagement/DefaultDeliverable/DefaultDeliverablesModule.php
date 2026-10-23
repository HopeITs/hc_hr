<?php

namespace App\Livewire\ProgramManagement\DefaultDeliverable;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\ScopesToAuthorizedCity;
use App\Livewire\Concerns\WithFeedback;
use App\Models\ChecklistItem;
use App\Models\City;
use App\Models\DefaultDeliverable as DefaultDeliverableModel;
use App\Models\Employee;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class DefaultDeliverablesModule extends Component
{
    use AuthorizesActivePermissions, ScopesToAuthorizedCity;
    use GuardsModalClose;
    use WithFeedback;

    public bool $showModal = false;

    public string $modalTitle = '';

    public array $item = [
        'id' => null,
        'employee_id' => '',
        'city_id' => '',
        'item_id' => '',
    ];

    protected $listeners = ['openDefaultDeliverableEdit' => 'edit'];

    /**
     * The three role branches this method used to carry all computed the same
     * thing — "which city am I confined to" — by string-splitting the role
     * name, and `$roleArr[1]` was an undefined index for any role without a
     * hyphen. activeCityIds() answers that question once, and returns null for
     * the roles that legitimately span every city.
     */
    public function render()
    {
        $locale = app()->getLocale();
        $cityCol = "name_{$locale}";
        $cityIds = $this->authorizedCityIds();

        $employeesQuery = Employee::whereHas('contracts', function ($query) {
            $query->whereHas('position', function ($subQuery) {
                $subQuery->whereHas('department', function ($q) {
                    $q->whereIn('code', ['IT', 'CT', 'HR']);
                });
            });
        });

        $citiesQuery = City::whereHas('centers');

        if ($cityIds !== null) {
            $employeesQuery->whereIn('city_id', $cityIds);
            $citiesQuery->whereKey($cityIds);
        }

        $employees = $employeesQuery
            ->orderBy(transColumn('first_name'))
            ->get()
            ->mapWithKeys(fn ($e) => [$e->id => $e->name]);

        $cities = $citiesQuery
            ->get(['id', $cityCol])
            ->map(fn ($c) => tap($c, fn () => $c->name = $c->{$cityCol}));

        $checklistItems = ChecklistItem::orderBy('order')->get(['id', 'name']);

        return view('livewire.program-management.default-deliverables-module', compact(
            'employees',
            'cities',
            'checklistItems',
        ));
    }

    public function create(): void
    {
        $this->authorizeActivePermission('checklists.manage');

        $this->item = ['id' => null, 'employee_id' => '', 'city_id' => '', 'item_id' => ''];
        $this->resetValidation();
        $this->modalTitle = __('general.add-new');
        $this->markClean();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeActivePermission('checklists.manage');

        $model = DefaultDeliverableModel::findOrFail($id);

        Gate::authorize('update', $model);

        $this->item = [
            'id' => $model->id,
            'employee_id' => $model->employee_id,
            'city_id' => $model->city_id,
            'item_id' => $model->item_id,
        ];

        $this->resetValidation();
        $this->modalTitle = __('general.edit');
        $this->markClean();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizeActivePermission('checklists.manage');

        // Same check as before, but via the shared scope rather than a role-name
        // split that assumed every role name contains a hyphen.
        $this->assertCityIsAuthorized($this->item['city_id']);

        // Validated after the city check, because the employee rule depends on
        // the selected city being one this user may actually write to.
        $this->validate();

        $model = ! empty($this->item['id'])
            ? DefaultDeliverableModel::findOrFail($this->item['id'])
            : new DefaultDeliverableModel;

        Gate::authorize(
            $model->exists ? 'update' : 'create',
            $model->exists ? $model : [DefaultDeliverableModel::class, (int) $this->item['city_id']],
        );

        $model->employee_id = $this->item['employee_id'];
        $model->city_id = $this->item['city_id'];
        $model->item_id = $this->item['item_id'];
        $model->save();

        $this->showModal = false;
        $this->resetErrorBag();
        $this->resetValidation();

        $this->dispatch('closeModal');
        $this->dispatch('refreshDefaultDeliverablesTable');

        $this->toast();
    }

    protected function rules()
    {
        $emp = $this->item['employee_id'] ?? null;
        $city = $this->item['city_id'] ?? null;

        $uniqueCombo = Rule::unique('default_deliverables', 'item_id')
            ->where(fn ($q) => $q->where('employee_id', $emp)
                ->where('city_id', $city));

        if (! empty($this->item['id'])) {
            $uniqueCombo->ignore($this->item['id']);
        }

        return [
            // The employee must belong to the selected city, and (via
            // assertCityIsAuthorized in save()) that city must be the actor's.
            // 'exists:employees,id' alone let any employee in the country be
            // nominated as the deliverer for a city.
            'item.employee_id' => [
                'required',
                Rule::exists('employees', 'id')->where(
                    fn ($q) => $q->where('city_id', $this->item['city_id'] ?: 0),
                ),
            ],
            'item.city_id' => ['required', 'exists:cities,id'],
            'item.item_id' => ['required', 'exists:checklist_items,id', $uniqueCombo],
        ];
    }
}
