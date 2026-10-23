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
        $program->load('centers.city');
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

        $employees = $program->employees()->paginate(15);

        return view('livewire.program-management.program.detail', compact('program', 'employees'));
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
