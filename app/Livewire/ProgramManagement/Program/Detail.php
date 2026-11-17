<?php

namespace App\Livewire\ProgramManagement\Program;

use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\ProgramManagement\ProgramForm;
use App\Models\Program;
use Livewire\Component;

class Detail extends Component
{
    use WithFeedback;

    public $slug;

    public ProgramForm $form;

    public function mount(Program $program)
    {
        $program->load(['centers.city', 'linkedCenters.city']);
        $this->form->setProgram($program);
    }

    public function confirmToggleItemStatus($id)
    {
        $this->confirm('toggleItemStatusConfirmed', ['id' => $id], title: __('general.toggle-status'), message: __('general.confirm-status-change'), confirmLabel: __('general.save'), danger: false);
    }

    public function toggleItemStatusConfirmed(Program $program)
    {
        $this->form->setProgram($program);
        $this->form->statusToggle();

        $this->toast(__('general.item-updated'));
    }

    public function render()
    {
        $program = $this->form->program;

        // Positions' centers and the centers linked to the program (Study Zone's
        // positions have none), with what each card's manager line reads.
        $centers = $program->loadMissing(['centers.city', 'linkedCenters.city'])->allCenters()
            ->load(['programs', 'managerContracts.employee', 'managerContracts.position.jobLevel']);

        return view('livewire.program-management.program.detail', [
            'program' => $program,
            'centers' => $centers,
            'totalEmployees' => $program->employees()->count(),
            'canViewEmployees' => auth()->user()?->hasActivePermission('employees.view') ?? false,
            // Back on a later page or a filter of the employees tab (refresh, back/forward): open that tab.
            'initialTab' => request()->hasAny([ProgramEmployees::PAGE_NAME, 'pe_center', 'pe_dept', 'pe_level']) ? 'employees' : 'details',
        ]);
    }

    public function confirmDelete($id)
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(Program $program)
    {
        $program->delete();

        $this->toast();

        return $this->redirectRoute('programs.index', navigate: true);
    }
}
