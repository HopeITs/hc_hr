<?php

namespace App\Livewire\Basics;

use App\Enums\LeavingCategory;
use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\EmbedsInSettings;
use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\WithFeedback;
use App\Models\TerminationReason;
use Illuminate\Validation\Rule;
use Livewire\Component;

class TerminationReasonModule extends Component
{
    use AuthorizesActivePermissions;
    use EmbedsInSettings;
    use GuardsModalClose;
    use WithFeedback;

    public bool $showModal = false;

    public string $modalTitle = '';

    public array $item = [
        'id' => null,
        'title_en' => null,
        'title_ar' => null,
        'category' => null,
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
            'category' => null,
        ];
        $this->resetValidation();
        $this->modalTitle = __('general.add-new');
        $this->markClean();
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
            'category' => $model->category?->value,
        ];

        $this->modalTitle = __('general.edit');
        $this->resetValidation();
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
            ? TerminationReason::findOrFail($this->item['id'])
            : new TerminationReason;

        $model->fill(array_merge($this->item, ['category' => $this->item['category'] ?: null]))->save();

        $this->showModal = false;

        $this->dispatch('refreshTerminationReasonTable');

        $this->toast();
    }

    protected function rules(): array
    {
        return [
            'item.title_en' => 'required|string|max:255',
            'item.title_ar' => 'required|string|max:255',
            'item.category' => ['nullable', Rule::enum(LeavingCategory::class)],
        ];
    }
}
