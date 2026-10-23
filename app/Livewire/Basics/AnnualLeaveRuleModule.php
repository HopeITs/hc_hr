<?php

namespace App\Livewire\Basics;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\WithFeedback;
use App\Models\AnnualLeaveRule;
use Livewire\Component;

class AnnualLeaveRuleModule extends Component
{
    use AuthorizesActivePermissions;
    use GuardsModalClose;
    use WithFeedback;

    public bool $showModal = false;

    public string $modalTitle = '';

    public array $item = [
        'id' => null,
        'days' => null,
        'day_per_month' => null,
        'years_of_volunteering' => null,
    ];

    protected $listeners = ['openAnnualLeaveRuleEdit' => 'edit'];

    public function render()
    {
        return view('livewire.basics.annual-leave-rule-module');
    }

    public function create(): void
    {
        $this->authorizeActivePermission('project-basics.create');
        $this->item = [
            'id' => null,
            'days' => null,
            'day_per_month' => null,
            'years_of_volunteering' => null,
        ];
        $this->resetValidation();
        $this->modalTitle = __('general.add-new');
        $this->markClean();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeActivePermission('project-basics.update');
        $model = AnnualLeaveRule::findOrFail($id);

        $this->item = [
            'id' => $model->id,
            'days' => $model->days,
            'day_per_month' => $model->day_per_month,
            'years_of_volunteering' => $model->years_of_volunteering,
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
            ? AnnualLeaveRule::findOrFail($this->item['id'])
            : new AnnualLeaveRule;

        $model->fill($this->item)->save();

        $this->showModal = false;

        $this->dispatch('refreshAnnualLeaveRuleTable');

        $this->toast();
    }

    protected function rules(): array
    {
        return [
            'item.days' => 'required|integer',
            'item.day_per_month' => 'required|numeric',
            'item.years_of_volunteering' => 'required|integer',
        ];
    }
}
