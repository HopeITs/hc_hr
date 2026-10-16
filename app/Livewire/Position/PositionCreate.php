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
use Livewire\Component;

class PositionCreate extends Component
{
    use ResolvesPositionFormOptions;
    use WithFeedback;

    public PositionForm $form;

    public function render()
    {
        $activeCityIds = auth()->user()->activeCityIds();
        $cities = City::where('is_active', true)
            ->when($activeCityIds !== null, fn ($query) => $query->whereKey($activeCityIds))
            ->get();
        $programs = Program::where('is_active', true)->orderBy('name')->get();
        $centers = $this->positionFormCenters();
        $jobLevels = JobLevel::orderBy('grade')->get();
        $positions = Position::where('is_active', true)
            ->when($activeCityIds !== null, fn ($query) => $query->inCity($activeCityIds))
            ->with(['center', 'program'])->orderBy(transColumn('title'))->get();

        $departments = Department::orderby('name')->get();
        $title = __('general.add-new');

        return view('livewire.position.form', compact('title', 'cities', 'programs', 'centers', 'jobLevels', 'departments', 'positions'));
    }

    public function save()
    {
        $this->form->store();
        $this->toast();

        return redirect()->route('positions.index');
    }
}
