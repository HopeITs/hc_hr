<?php

namespace App\Livewire\Recruitment\Offers;

use App\Services\RecruitmentJobOfferService;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 2 Task 9: revise an offer into a new version — after HR changes the
 * terms of a sent offer, or after a refusal, an expiry or a cancellation.
 *
 * The same form and save() as Form; its own entry point so the /revise route
 * passes offerId only, exactly like /edit (see Form::mount() for why a third
 * route parameter or route default must not be used).
 */
class Revise extends Form
{
    public function mount(?int $applicationId = null, ?int $offerId = null): void
    {
        abort_if($offerId === null, 404);

        $offer = $this->resolveOffer($offerId);
        Gate::authorize('manage', $offer);
        abort_unless(app(RecruitmentJobOfferService::class)->canRevise($offer), 404);

        $this->mode = 'revise';
        $this->loadCurrentVersion($offer);
    }
}
