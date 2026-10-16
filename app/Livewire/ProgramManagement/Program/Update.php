<?php

namespace App\Livewire\ProgramManagement\Program;

use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\ProgramManagement\ProgramForm;
use App\Models\Program;
use Livewire\Component;
use Livewire\WithFileUploads;

class Update extends Component
{
    use WithFeedback, WithFileUploads;

    public ProgramForm $form;

    public $existingLogoUrl = '';

    public function mount(Program $program)
    {
        $this->form->setProgram($program);
    }

    public function save()
    {
        $this->validate();
        $this->form->update();
        $this->toast();

        return redirect()->route('programs.index');
    }

    public function render()
    {

        return view('livewire.program-management.program.form', [
            'title' => $this->form->program->name,

        ]);
    }
}
