<?php

namespace App\Livewire\Basics;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Models\CourseCategory;
use Livewire\Component;

class CourseCategoryModule extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;

    public bool $showModal = false;

    public string $modalTitle = '';

    public array $item = [
        'id' => null,
        'name_en' => '',
        'name_ar' => '',
    ];

    protected $listeners = [
        'openCourseCategoryEdit' => 'edit',
    ];

    protected array $rules = [
        'item.name_en' => ['required', 'string', 'max:255'],
        'item.name_ar' => ['required', 'string', 'max:255'],
    ];

    public function render()
    {
        return view('livewire.basics.course-category-module');
    }

    public function create(): void
    {
        $this->authorizeActivePermission('project-basics.create');
        $this->item = [
            'id' => null,
            'name_en' => '',
            'name_ar' => '',
        ];

        $this->resetValidation();

        $this->modalTitle = __('general.add-new');
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeActivePermission('project-basics.update');
        $cat = CourseCategory::findOrFail($id);

        $this->item = [
            'id' => $cat->id,
            'name_en' => $cat->name_en,
            'name_ar' => $cat->name_ar,
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
            ? CourseCategory::findOrFail($this->item['id'])
            : new CourseCategory;

        $model->fill($this->item)->save();

        $this->showModal = false;

        $this->dispatch('refreshCourseCategoryTable');

        $this->toast();
    }
}
