<?php

namespace App\Livewire\Basics;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Department;
use Illuminate\Validation\Rule;
use Livewire\Component;

class DepartmentModule extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;

    public bool $showModal = false;

    public string $modalTitle = '';

    public array $item = [
        'id' => null,
        'name' => '',
        'code' => '',
        'description_ar' => '',
        'description_en' => '',
        'email' => null,
    ];

    protected $listeners = [
        'openDepartmentEdit' => 'edit',
    ];

    public function render()
    {
        return view('livewire.basics.department-module');
    }

    public function create(): void
    {
        $this->authorizeActivePermission('project-basics.create');
        $this->item = [
            'id' => null,
            'name' => '',
            'code' => '',
            'description_ar' => '',
            'description_en' => '',
            'email' => null,
        ];

        $this->resetValidation();

        $this->modalTitle = __('general.add-new');
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeActivePermission('project-basics.update');
        $model = Department::findOrFail($id);

        $this->item = [
            'id' => $model->id,
            'name' => $model->name,
            'code' => $model->code,
            'description_ar' => $model->description_ar,
            'description_en' => $model->description_en,
            'email' => $model->email,
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
            ? Department::findOrFail($this->item['id'])
            : new Department;

        $model->fill($this->item)->save();

        $this->showModal = false;

        $this->dispatch('refreshDepartmentTable');

        $this->toast();
    }

    protected function rules(): array
    {
        return [
            'item.name' => 'required|string|max:255',

            'item.code' => [
                'required',
                'string',
                'max:5',
                Rule::unique('departments', 'code')
                    ->ignore($this->item['id'] ?? null),
            ],

            'item.description_ar' => 'nullable|string',
            'item.description_en' => 'nullable|string',
            'item.email' => 'nullable|email|max:255',
        ];
    }
}
