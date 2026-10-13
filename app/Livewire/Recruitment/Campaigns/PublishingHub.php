<?php

namespace App\Livewire\Recruitment\Campaigns;

use App\Enums\HiringCampaignStatus;
use App\Models\HiringCampaign;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

class PublishingHub extends Component
{
    public HiringCampaign $campaign;

    public function mount(HiringCampaign $campaign): void
    {
        Gate::authorize('view', $campaign);
        $this->campaign = $campaign->load([
            'vacancy.position', 'vacancy.createdFromRequest',
            'jobPost.currentVersion', 'applicationForm.currentVersion',
        ]);
    }

    public function render()
    {
        return view('livewire.recruitment.campaigns.publishing-hub', [
            'campaignStatus' => HiringCampaignStatus::getLocalizedName((int) $this->campaign->status)['value'],
        ]);
    }

    #[On('campaign-status-changed')]
    public function refreshCampaignStatus(): void
    {
        $this->campaign->refresh();
    }
}
