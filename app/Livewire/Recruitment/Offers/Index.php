<?php

namespace App\Livewire\Recruitment\Offers;

use App\Models\RecruitmentJobOffer;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Phase 2 Task 9: the job offers page. Nothing is changed from here. */
class Index extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', RecruitmentJobOffer::class);
    }

    public function render()
    {
        return view('livewire.recruitment.offers.index');
    }
}
