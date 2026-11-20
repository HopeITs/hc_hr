<?php

namespace App\Livewire\Probation;

use App\Livewire\Concerns\WithFeedback;
use App\Models\ProbationReview;
use App\Services\ProbationReviews;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The last-third review of a trial contract. The direct manager picks what
 * they ask HR for (extend, confirm or cancel) and must give the reason; the
 * request goes to HR and changes nothing on the contract. HR of the city can
 * read the request. Every action re-authorizes through ProbationReviewPolicy.
 */
class Review extends Component
{
    use WithFeedback;

    #[Locked]
    public int $reviewId;

    public string $decision = '';

    public string $reason = '';

    public function mount(ProbationReview $review, ProbationReviews $reviews): void
    {
        Gate::authorize('view', $review);

        $this->reviewId = $review->id;
        $reviews->markSeenByHr($review, auth()->user());
    }

    public function send(ProbationReviews $reviews): void
    {
        $review = ProbationReview::query()->findOrFail($this->reviewId);

        Gate::authorize('decide', $review);

        $this->validate([
            'decision' => ['required', 'in:'.implode(',', ProbationReview::DECISIONS)],
            'reason' => ['required', 'string', 'max:2000', 'regex:/\S/'],
        ], [], [
            'decision' => __('probation.fields.decision'),
            'reason' => __('probation.fields.reason'),
        ]);

        $sent = $reviews->request($review, $this->decision, $this->reason, auth()->user());

        $this->toast($sent ? __('probation.sent') : __('probation.already'), $sent ? 'success' : 'info');
        $this->reset('decision', 'reason');
    }

    public function render(ProbationReviews $reviews): View
    {
        $review = ProbationReview::query()
            ->with(['employee.city', 'contract.position', 'contract.contractType', 'decidedBy.profile'])
            ->findOrFail($this->reviewId);

        Gate::authorize('view', $review);

        return view('livewire.probation.review', [
            'review' => $review,
            'canDecide' => ! $review->isDecided() && $reviews->canDecide(auth()->user(), $review),
            'lastThirdStart' => $reviews->lastThirdStart($review->contract),
        ])->title(__('probation.title'));
    }
}
