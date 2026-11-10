<?php

namespace App\Livewire\ProgramManagement\Center;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\ScopesToAuthorizedCity;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Center;
use App\Models\City;
use App\Models\User;
use App\Notifications\NewCenterCreated;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

class CentersModule extends Component
{
    use AuthorizesActivePermissions, ScopesToAuthorizedCity;
    use GuardsModalClose;
    use WithFeedback;

    public bool $showModal = false;

    public string $modalTitle = '';

    public array $item = [
        'id' => null,
        'name_en' => '',
        'name_ar' => '',
        'city_id' => '',
        'address_ar' => '',
        'address_en' => '',
        'is_active' => true,
        'weekends' => [],
        'open_at' => null,
        'close_at' => null,
    ];

    protected $rules = [
        'item.name_en' => 'required|string|max:255',
        'item.name_ar' => 'required|string|max:255',
        'item.city_id' => 'required|exists:cities,id',
        'item.address_en' => 'nullable|string|max:255',
        'item.address_ar' => 'nullable|string|max:255',
        'item.weekends' => 'required|array',
        'item.open_at' => 'required',
        'item.close_at' => 'required',
    ];

    public function render()
    {
        // The dropdown and save() both resolve the permitted city from
        // ScopesToAuthorizedCity, so they cannot disagree.
        $cities = $this->authorizedCityQuery()->get(['id', transColumn('name')]);

        return view('livewire.program-management.centers-module', compact('cities'));
    }

    public function create(): void
    {
        $this->authorizeActivePermission('centers.create');

        $this->item = [
            'id' => null,
            'name_en' => '',
            'name_ar' => '',
            'city_id' => '',
            'address_ar' => '',
            'address_en' => '',
            'is_active' => true,
            'weekends' => [],
            'open_at' => null,
            'close_at' => null,
        ];

        $this->resetValidation();
        $this->modalTitle = __('general.add-new');
        $this->markClean();
        $this->showModal = true;
    }

    #[On('openCenterEdit')]
    public function edit(int $id): void
    {
        $this->authorizeActivePermission('centers.update');

        $model = Center::findOrFail($id);

        // A Livewire listener is directly invokable; the id is untrusted even
        // though the only button that dispatches it is city-filtered.
        Gate::authorize('update', $model);

        $this->item = [
            'id' => $model->id,
            'name_en' => $model->name_en,
            'name_ar' => $model->name_ar,
            'city_id' => $model->city_id,
            'address_ar' => $model->address_ar,
            'address_en' => $model->address_en,
            'is_active' => (bool) $model->is_active,
            'weekends' => explode(',', $model->weekends),
            'open_at' => $model->open_at,
            'close_at' => $model->close_at,
        ];

        $this->resetValidation();
        $this->modalTitle = __('general.edit');
        $this->markClean();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizeActivePermission(
            empty($this->item['id']) ? 'centers.create' : 'centers.update',
        );

        $this->validate();

        // Validated against the active role's city, not against the dropdown
        // that produced it — the submitted value is what actually gets stored.
        $this->assertCityIsAuthorized($this->item['city_id']);

        $this->item['weekends'] = implode(',', $this->item['weekends']);
        $model = Center::findOrNew($this->item['id']);
        $isNew = ! $model->exists;

        Gate::authorize(
            $isNew ? 'create' : 'update',
            $isNew ? [Center::class, (int) $this->item['city_id']] : $model,
        );

        $model->fill($this->item);
        $model->save();

        if ($isNew) {
            $model->load('city');
            User::sendBroadcast(new NewCenterCreated($model), $model->city_id);
            $this->dispatch('center-created');
        }

        $this->showModal = false;
        $this->resetErrorBag();
        $this->resetValidation();
        $this->dispatch('closeModal');

        $this->toast();
    }

    private function authorizedCityQuery()
    {
        $cityIds = $this->authorizedCityIds();

        return $cityIds === null ? City::query() : City::query()->whereKey($cityIds);
    }
}
