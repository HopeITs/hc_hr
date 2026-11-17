<?php

namespace App\Livewire\Recruitment\Campaigns;

use App\Enums\HiringCampaignStatus;
use App\Models\HiringCampaign;
use App\Services\HiringCampaignStateMachine;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    /** Order the tiles read in: what is live first, then what is being prepared, then what is over. */
    public const TILE_ORDER = [
        HiringCampaignStatus::Open,
        HiringCampaignStatus::Published,
        HiringCampaignStatus::Draft,
        HiringCampaignStatus::Closed,
        HiringCampaignStatus::Expired,
        HiringCampaignStatus::Disabled,
    ];

    /** Effective status the list is narrowed to; null shows everything. */
    #[Url(as: 'status', except: null)]
    public ?int $statusFilter = null;

    public function filterByStatus(?int $status): void
    {
        Gate::authorize('viewAny', HiringCampaign::class);

        $this->statusFilter = $this->statusFilter === $status || ! in_array($status, self::TILE_ORDER, true)
            ? null
            : $status;
    }

    public function render()
    {
        Gate::authorize('viewAny', HiringCampaign::class);

        $states = app(HiringCampaignStateMachine::class);

        // Counted by the clock, the same way the table reads status: a window that shut is Expired here too.
        $campaigns = HiringCampaign::query()->visibleTo(auth()->user())->get(['id', 'status', 'ends_at']);
        $counts = $campaigns
            ->countBy(fn (HiringCampaign $campaign): int => $states->effective($campaign))
            ->all();

        // Live campaigns whose window shuts within a week: the one thing on this page worth a nudge.
        $closingSoon = $campaigns->filter(fn (HiringCampaign $campaign): bool => in_array($states->effective($campaign), [HiringCampaignStatus::Published, HiringCampaignStatus::Open], true)
            && $campaign->ends_at !== null
            && $campaign->ends_at->isFuture()
            && $campaign->ends_at->lte(now()->addDays(7)))->count();

        return view('livewire.recruitment.campaigns.index', [
            'counts' => $counts,
            'total' => $campaigns->count(),
            'closingSoon' => $closingSoon,
        ]);
    }
}
