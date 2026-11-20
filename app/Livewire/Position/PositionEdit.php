<?php

namespace App\Livewire\Position;

use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\PositionForm;
use App\Livewire\Position\Concerns\ResolvesPositionFormOptions;
use App\Models\City;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class PositionEdit extends Component
{
    use ResolvesPositionFormOptions;
    use WithFeedback;

    public PositionForm $form;

    public ?Position $position;

    public function mount(Position $position): void
    {
        Gate::authorize('update', $position);
        $this->position = $position;
        $this->form->setPosition($position);
    }

    public function render()
    {
        $activeCityIds = auth()->user()->activeCityIds();
        $cities = City::where('is_active', true)
            ->when($activeCityIds !== null, fn ($query) => $query->whereKey($activeCityIds))
            ->get();
        $programs = Program::where('is_active', true)->orderBy('name')->get();
        $centers = $this->positionFormCenters();
        $jobLevels = JobLevel::orderBy('grade')->get();
        $positions = Position::with(['center', 'program'])
            ->when($activeCityIds !== null, fn ($query) => $query->inCity($activeCityIds))
            ->whereNot('id', $this->position->id)
            ->orderBy(transColumn('title'))->get();
        $departments = Department::orderby('name')->get();

        $title = $this->form->position->title;

        return view('livewire.position.form', compact('title', 'cities', 'programs', 'centers', 'jobLevels', 'departments', 'positions'));

    }

    /** Set or clear the critical tag (HR and the administrators only: PositionPolicy::markCritical). */
    public function toggleCritical(): void
    {
        Gate::authorize('markCritical', $this->position);

        $this->position->update(['is_critical' => ! $this->position->is_critical]);
        // The form holds its own copy of the model; keep what the page shows in step.
        $this->form->position?->forceFill(['is_critical' => $this->position->is_critical])->syncOriginal();
        $this->toast();
    }

    public function save()
    {
        Gate::authorize('update', $this->position);
        $this->form->update();
        $this->toast();

        return $this->redirectRoute('positions.index', navigate: true);
    }
}
