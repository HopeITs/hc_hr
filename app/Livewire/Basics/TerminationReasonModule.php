<?php

namespace App\Livewire\Basics;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Models\TerminationReason;
use Livewire\Component;

class TerminationReasonModule extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;

    public bool $showModal = false;

    public string $modalTitle = '';

    public array $item = [
        'id' => null,
        'title_en' => null,
        'title_ar' => null,
    ];

    protected $listeners = ['openTerminationReasonEdit' => 'edit'];

    public function render()
    {
        return view('livewire.basics.termination-reason-module');
    }

    public function create(): void
    {
        $this->authorizeActivePermission('project-basics.create');
        $this->item = [
            'id' => null,
            'title_en' => null,
            'title_ar' => null,
        ];
        $this->resetValidation();
        $this->modalTitle = __('general.add-new');
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeActivePermission('project-basics.update');
        $model = TerminationReason::findOrFail($id);

        $this->item = [
            'id' => $model->id,
            'title_en' => $model->title_en,
            'title_ar' => $model->title_ar,
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
            ? TerminationReason::findOrFail($this->item['id'])
            : new TerminationReason;

        $model->fill($this->item)->save();

        $this->showModal = false;

        $this->dispatch('refreshTerminationReasonTable');

        $this->toast();
    }

    protected function rules(): array
    {
        return [
            'item.title_en' => 'required|string|max:255',
            'item.title_ar' => 'required|string|max:255',
        ];
    }
}
