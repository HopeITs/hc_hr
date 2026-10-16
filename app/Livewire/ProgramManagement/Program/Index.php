<?php

namespace App\Livewire\ProgramManagement\Program;

use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\ProgramManagement\ProgramForm;
use App\Models\Program;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Index extends Component
{
    use WithFeedback;
    use WithFileUploads, WithPagination;

    public Program $program;

    public ProgramForm $form;

    public $perPage = 6;

    protected $listeners = ['load-more' => 'loadMore'];

    public function loadMore(): void
    {
        $this->perPage += 6;
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

        $this->resetPage();
    }

    public function show(Program $program)
    {
        $this->form->setProgram($program);
    }

    public function confirmDelete($id)
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(Program $program)
    {
        $this->form->setProgram($program);
        $this->form->program->delete();

        $this->toast();
    }

    public function render()
    {
        $items = Program::with('media')
            ->withCardCounts()
            ->with('contracts')
            ->orderBy('is_active', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($this->perPage);

        $items->each(function ($item) {
            $programManager = $item->manager();
            if ($programManager) {
                $item->program_manager_name = $programManager->name;
            }
        });

        return view('livewire.program-management.program.index', compact('items'));
    }
}
