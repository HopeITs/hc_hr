<?php

namespace App\Livewire\Recruitment\Requests;

use App\Models\RecruitmentRequest;
use App\Services\RecruitmentApproverEligibility;
use App\Services\RecruitmentRequestListQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * The requests a person may see, grouped by their relationship to the viewer.
 */
class Index extends Component
{
    public string $viewFilter = RecruitmentRequestListQuery::ALL;

    public function setRequestView(string $view): void
    {
        $viewCounts = $this->viewCounts();

        if (! in_array($view, $this->availableViews($viewCounts), true)) {
            return;
        }

        $this->viewFilter = $view;
    }

    public function render(): View
    {
        Gate::authorize('viewAny', RecruitmentRequest::class);

        $viewCounts = $this->viewCounts();

        return view('livewire.recruitment.requests.index', [
            'viewCounts' => $viewCounts,
            'views' => $this->availableViews($viewCounts),
        ]);
    }

    /** @return array<string, int> */
    private function viewCounts(): array
    {
        $lists = app(RecruitmentRequestListQuery::class);
        $counts = [];

        foreach ($lists->filters() as $view) {
            $counts[$view] = $lists->forUser(auth()->user(), $view)->count();
        }

        return $counts;
    }

    /**
     * Only show relationship choices that can be useful to this person.
     *
     * Approvers keep their review queue even when it is empty. Team requests
     * appear once the viewer has a team request in scope. When neither can
     * apply, the page is already their own list and needs no tabs at all.
     *
     * @param  array<string, int>  $viewCounts
     * @return array<int, string>
     */
    private function availableViews(array $viewCounts): array
    {
        $views = [];

        if (app(RecruitmentApproverEligibility::class)->mayReceiveApprovals(auth()->user())) {
            $views[] = RecruitmentRequestListQuery::NEEDS_REVIEW;
        }

        $views[] = RecruitmentRequestListQuery::MINE;

        if (($viewCounts[RecruitmentRequestListQuery::TEAM] ?? 0) > 0) {
            $views[] = RecruitmentRequestListQuery::TEAM;
        }

        if (count($views) > 1) {
            $views[] = RecruitmentRequestListQuery::ALL;
        }

        return $views;
    }
}
