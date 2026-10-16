<?php

namespace App\Livewire\Basics;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Models\LeaveType;
use Livewire\Component;

class LeaveTypeModule extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;

    public bool $showModal = false;

    public string $modalTitle = '';

    public array $item = [
        'id' => null,
        'name_ar' => '',
        'name_en' => '',
        'base_days' => null,
        'allowed_gender' => null,
        'marital_status' => null,
    ];

    protected $listeners = ['openLeaveTypeEdit' => 'edit'];

    public function render()
    {
        return view('livewire.basics.leave-type-module');
    }

    public function create(): void
    {
        $this->authorizeActivePermission('project-basics.create');
        $this->item = [
            'id' => null,
            'name_ar' => '',
            'name_en' => '',
            'base_days' => null,
            'allowed_gender' => null,
            'marital_status' => null,
        ];
        $this->resetValidation();
        $this->modalTitle = __('general.add-new');
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeActivePermission('project-basics.update');
        $model = LeaveType::findOrFail($id);

        $this->item = [
            'id' => $model->id,
            'name_ar' => $model->name_ar,
            'name_en' => $model->name_en,
            'base_days' => $model->base_days,
            'allowed_gender' => $model->allowed_gender,
            'marital_status' => $model->marital_status,
        ];

        $this->modalTitle = __('general.edit');
        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorizeActivePermission(
            empty($this->item['id']) ? 'project-basics.create' : 'project-basics.update',
        );
        $this->validate();

        $model = ! empty($this->item['id'])
            ? LeaveType::findOrFail($this->item['id'])
            : new LeaveType;

        $model->fill($this->item)->save();

        $this->showModal = false;

        $this->dispatch('refreshLeaveTypeTable');

        $this->toast();
    }

    protected function rules(): array
    {
        return [
            'item.name_ar' => 'required|string',
            'item.name_en' => 'required|string',
            'item.base_days' => 'required|integer',
            'item.allowed_gender' => 'required|integer',
            'item.marital_status' => 'required|integer',
        ];
    }
}
