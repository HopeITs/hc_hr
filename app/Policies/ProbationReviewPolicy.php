<?php

namespace App\Policies;

use App\Models\ProbationReview;
use App\Models\User;
use App\Services\ProbationReviews;

/**
 * Who sees and answers a trial's last-third review (meeting 19/11, track 4).
 * The scope is the service's: the direct manager of the trial's position, and
 * HR of the employee's city (EmployeePolicy::update); HR may answer only when
 * the position has no manager running it.
 */
class ProbationReviewPolicy
{
    public function view(User $user, ProbationReview $review): bool
    {
        return app(ProbationReviews::class)->canView($user, $review);
    }

    public function decide(User $user, ProbationReview $review): bool
    {
        return app(ProbationReviews::class)->canDecide($user, $review);
    }
}
