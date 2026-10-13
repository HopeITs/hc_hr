<?php

namespace App\Livewire\Recruitment\Offers;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentJobOfferStatus as Status;
use App\Models\HiringCampaign;
use App\Models\RecruitmentJobOffer;
use App\Models\Vacancy;
use App\Services\HiringCampaignStateMachine;
use App\Services\RecruitmentJobOfferService;
use App\Traits\WithAlerts;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Phase 2 Task 9: one job offer — the internal offer view, every version as
 * it was offered, and what happened to it.
 *
 * Seen by users with view-offers in scope. The salary is rendered only for
 * those who may see pay figures (viewSalary) and never kept on the
 * component, so it never reaches another browser in a Livewire payload.
 *
 * HR with manage-offers can send the draft, record the candidate's answer,
 * cancel, and start a revision or edit the draft (on the form). Send, answer
 * and cancel open in a popup — the Leaves decision popup, <x-modal.dialog> —
 * and end with the same success toast (WithAlerts). Each action locks the
 * version the user was looking at; the service refuses if the offer moved on
 * since.
 */
class Show extends Component
{
    use WithAlerts;

    #[Locked]
    public int $offerId;

    /** 'send' | 'response' | 'cancel' | null */
    #[Locked]
    public ?string $panel = null;

    #[Locked]
    public ?int $expectedVersionId = null;

    /**
     * Whether the popup is showing. Not locked: the popup closes itself on
     * Escape or a click outside, and writes that back. Which action it holds
     * is $panel, which stays locked.
     */
    public bool $panelOpen = false;

    public string $expiresOn = '';

    public string $response = '';

    /** Internal: the candidate's reasons, as HR recorded them. */
    public string $responseNote = '';

    public string $cancelReason = '';

    public string $cancelNote = '';

    public function mount(int $offerId): void
    {
        $this->offerId = $offerId;
        $this->resolveOffer();

        if ($message = session()->pull('recruitment_toast')) {
            $this->successToast($message);
        }
    }

    public function beginSend(): void
    {
        $this->openPanel('send');
        $this->expiresOn = today()->addDays((int) config('recruitment.offers.validity_days', 7))->toDateString();
    }

    public function send(RecruitmentJobOfferService $offers): void
    {
        abort_unless($this->panel === 'send', 404);
        $offer = $this->authorizedOffer();

        $this->attempt(fn () => $offers->send(auth()->user(), $offer, (int) $this->expectedVersionId, $this->expiresOn), 'sent');
    }

    public function beginResponse(string $response): void
    {
        abort_unless(in_array($response, [Status::Accepted, Status::Rejected], true), 403);
        $this->openPanel('response');
        $this->response = $response;
    }

    public function recordResponse(RecruitmentJobOfferService $offers): void
    {
        abort_unless($this->panel === 'response', 404);
        $offer = $this->authorizedOffer();

        $this->attempt(fn () => $offers->recordResponse(auth()->user(), $offer, (int) $this->expectedVersionId, $this->response, $this->responseNote), 'response_recorded');
    }

    public function beginCancel(): void
    {
        $this->openPanel('cancel');
    }

    public function cancelOffer(RecruitmentJobOfferService $offers): void
    {
        abort_unless($this->panel === 'cancel', 404);
        $offer = $this->authorizedOffer();

        $this->attempt(fn () => $offers->cancel(auth()->user(), $offer, (int) $this->expectedVersionId, $this->cancelReason, $this->cancelNote), 'cancelled');
    }

    public function closePanel(): void
    {
        $this->reset('panel', 'panelOpen', 'expectedVersionId', 'expiresOn', 'response', 'responseNote', 'cancelReason', 'cancelNote');
        $this->resetErrorBag();
    }

    public function render(RecruitmentJobOfferService $offers)
    {
        $offer = $this->resolveOffer()->load([
            'application.applicant',
            'position',
            'vacancy',
            'currentVersion.contractType',
            'versions' => fn ($versions) => $versions->orderByDesc('version_no'),
            'versions.contractType',
            'versions.creator.profile',
            'creator.profile',
            'responseRecordedBy.profile',
            'cancelledBy.profile',
            'hiringConversion.employee',
            'hiringConversion.convertedBy.profile',
        ]);
        $conversion = $offer->hiringConversion;

        return view('livewire.recruitment.offers.show', [
            'conversion' => $conversion,
            'canConvert' => $conversion !== null && ! $conversion->isConverted() && Gate::allows('convert', $conversion),
            'campaignsStillOpen' => $conversion?->isConverted() && $offer->vacancy?->status === Vacancy::FILLED
                ? $this->campaignsStillOpen($offer->vacancy)
                : [],
            'offer' => $offer,
            'canManage' => Gate::allows('manage', $offer),
            'canSeeSalary' => Gate::allows('viewSalary', $offer),
            'canSeeApplicant' => Gate::allows('viewApplicant', $offer),
            'seatsLeft' => $offer->vacancy ? $offers->seatsLeft($offer->vacancy, $offer) : 0,
            'canRevise' => $offers->canRevise($offer),
            'cancellationReasons' => $offers->cancellationReasons(),
        ]);
    }

    /**
     * Task 10: campaigns on a filled vacancy that still take — or may yet
     * take — applications, which the hire did not close for HR.
     *
     * @return array<int, int>
     */
    private function campaignsStillOpen(Vacancy $vacancy): array
    {
        $states = app(HiringCampaignStateMachine::class);

        return HiringCampaign::query()
            ->where('vacancy_id', $vacancy->getKey())
            ->whereIn('status', [HiringCampaignStatus::Open, HiringCampaignStatus::Published])
            ->get()
            ->filter(fn (HiringCampaign $campaign): bool => in_array($states->effective($campaign), [HiringCampaignStatus::Open, HiringCampaignStatus::Published], true))
            ->map(fn (HiringCampaign $campaign): int => (int) $campaign->getKey())
            ->values()
            ->all();
    }

    private function openPanel(string $panel): void
    {
        $offer = $this->authorizedOffer();

        $this->closePanel();
        $this->panel = $panel;
        $this->panelOpen = true;
        $this->expectedVersionId = (int) $offer->current_version_id;
    }

    private function attempt(callable $action, string $messageKey): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->closePanel();
        $this->successToast(__('recruitment-applicant.offers.'.$messageKey));
    }

    private function authorizedOffer(): RecruitmentJobOffer
    {
        $offer = $this->resolveOffer();
        Gate::authorize('manage', $offer);

        return $offer;
    }

    /** Out of reach is a 404, like everywhere else on these pages. */
    private function resolveOffer(): RecruitmentJobOffer
    {
        $offer = RecruitmentJobOffer::query()->visibleTo(auth()->user())->findOrFail($this->offerId);
        Gate::authorize('view', $offer);

        return $offer;
    }
}
