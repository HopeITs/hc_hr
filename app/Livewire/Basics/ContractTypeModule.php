<?php

namespace App\Livewire\Basics;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\WithFeedback;
use App\Models\ContractType;
use Livewire\Component;

class ContractTypeModule extends Component
{
    use AuthorizesActivePermissions;
    use GuardsModalClose;
    use WithFeedback;

    public bool $showModal = false;

    public string $modalTitle = '';

    public array $item = [
        'id' => null,
        'name_ar' => '',
        'name_en' => '',
    ];

    protected $listeners = ['openContractTypeEdit' => 'edit'];

    public function render()
    {
        return view('livewire.basics.contract-type-module');
    }

    public function create(): void
    {
        $this->authorizeActivePermission('project-basics.create');
        $this->item = [
            'id' => null,
            'name_ar' => '',
            'name_en' => '',
        ];

        $this->resetValidation();
        $this->modalTitle = __('general.add-new');
        $this->markClean();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeActivePermission('project-basics.update');
        $model = ContractType::findOrFail($id);

        $this->modalTitle = __('general.edit');

        $this->item = [
            'id' => $model->id,
            'name_ar' => $model->name_ar,
            'name_en' => $model->name_en,
        ];

        $this->markClean();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizeActivePermission(
            empty($this->item['id']) ? 'project-basics.create' : 'project-basics.update',
        );
        $this->validate();

        $model = ! empty($this->item['id'])
            ? ContractType::findOrFail($this->item['id'])
            : new ContractType;

        $model->fill($this->item);
        $model->save();

        $this->showModal = false;

        $this->dispatch('refreshContractTypeTable');

        $this->toast();
    }

    public function delete(array $payload): void
    {
        $this->authorizeActivePermission('project-basics.delete');
        ContractType::findOrFail($payload['id'])->delete();

        $this->dispatch('refreshContractTypeTable');

        $this->toast();
    }

    protected function rules()
    {
        return [
            'item.name_ar' => 'required|string',
            'item.name_en' => 'required|string',
        ];
    }
}
