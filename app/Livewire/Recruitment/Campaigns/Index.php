<?php

namespace App\Livewire\Recruitment\Campaigns;

use App\Models\HiringCampaign;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        Gate::authorize('viewAny', HiringCampaign::class);

        return view('livewire.recruitment.campaigns.index');
    }
}
