<?php

namespace App\Livewire\Basics;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\WithFeedback;
use App\Models\City;
use Livewire\Component;

/**
 * The cities page. Cities themselves are seeded; the one field HR edits here
 * is the city's HR hotline, the emergency number on its public ID cards
 * (access-policy.md §9 A2D-Q7). Same permission as the existing status toggle
 * on this page: project-basics.update.
 */
class CityModule extends Component
{
    use AuthorizesActivePermissions;
    use GuardsModalClose;
    use WithFeedback;

    public bool $showModal = false;

    public string $modalTitle = '';

    public array $item = [
        'id' => null,
        'name' => '',
        'hr_hotline' => '',
    ];

    protected $listeners = [
        'openCityEdit' => 'edit',
    ];

    public function render()
    {
        return view('livewire.basics.city-module');
    }

    public function edit(int $id): void
    {
        $this->authorizeActivePermission('project-basics.update');
        $city = City::findOrFail($id);

        $this->item = [
            'id' => $city->id,
            'name' => $city->name,
            'hr_hotline' => (string) $city->hr_hotline,
        ];

        $this->modalTitle = __('digital_id.city.edit');

        $this->resetValidation();
        $this->markClean();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizeActivePermission('project-basics.update');
        $this->item['hr_hotline'] = trim((string) ($this->item['hr_hotline'] ?? ''));
        $this->validate();

        $city = City::findOrFail($this->item['id']);
        $hotline = $this->item['hr_hotline'];

        // Only the hotline: the id in $item comes back from the browser, the
        // other city fields are not editable here.
        $city->forceFill(['hr_hotline' => $hotline === '' ? null : $hotline])->save();

        $this->showModal = false;

        $this->dispatch('refreshCityTable');

        $this->toast();
    }

    protected function rules(): array
    {
        return [
            'item.id' => 'required|integer|exists:cities,id',
            'item.hr_hotline' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()\-]{3,32}\z/'],
        ];
    }

    protected function messages(): array
    {
        return [
            'item.hr_hotline.regex' => __('digital_id.city.hotline_invalid'),
        ];
    }
}
