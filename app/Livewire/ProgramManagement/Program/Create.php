<?php

namespace App\Livewire\ProgramManagement\Program;

use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\ProgramManagement\ProgramForm;
use Livewire\Component;
use Livewire\WithFileUploads;

class Create extends Component
{
    use WithFeedback, WithFileUploads;

    public ProgramForm $form;

    public $existingLogoUrl = '';

    public function save()
    {
        $this->form->store();
        $this->toast();

        return $this->redirectRoute('programs.index', navigate: true);
    }

    public function render()
    {

        return view('livewire.program-management.program.form', [
            'title' => __('general.add-new'),

        ]);
    }
}
