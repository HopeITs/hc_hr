<?php

namespace App\Livewire\Recruitment\Campaigns;

use App\Models\HiringCampaign;
use App\Models\Vacancy;
use App\Services\HiringCampaignService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Opening a campaign against a seat.
 *
 * The seat is chosen first and everything else follows from it, because a
 * campaign with no seat advertises nothing. Several campaigns may run on one
 * seat in sequence: round the organisation first, then out to everybody, and
 * sometimes to a named list.
 */
class Create extends Component
{
    #[Locked]
    public ?int $sourceVacancyId = null;

    /**
     * Chosen from the list, so not locked.
     *
     * Nothing is trusted about it either: the seat is re-read through the
     * visibility boundary and re-checked for availability before anything is
     * created, and the policy is asked about that seat specifically.
     */
    public ?int $vacancyId = null;

    public string $linkMode = HiringCampaign::PUBLIC_OPEN;

    public string $startsAt = '';

    public string $endsAt = '';

    public string $minimumAge = '';

    public string $maximumAge = '';

    public function mount(?int $vacancyId = null): void
    {
        $this->sourceVacancyId = $vacancyId ?? request()->integer('vacancyId') ?: null;
        $this->vacancyId = $this->sourceVacancyId;

        // With only one seat to choose from there is nothing to choose.
        if ($this->vacancyId === null) {
            $only = $this->availableVacancies()->take(2)->get();
            $this->vacancyId = $only->count() === 1 ? $only->first()->getKey() : null;
        }
    }

    public function save(HiringCampaignService $campaigns)
    {
        $vacancy = $this->vacancy();

        if ($vacancy === null) {
            $this->addError('vacancyId', __('recruitment-campaign.choose_a_vacancy'));

            return null;
        }

        try {
            $campaign = $campaigns->create(auth()->user(), $vacancy, [
                'link_mode' => $this->linkMode,
                'starts_at' => $this->startsAt,
                'ends_at' => $this->endsAt,
                'minimum_age' => $this->minimumAge,
                'maximum_age' => $this->maximumAge,
            ]);
        } catch (ValidationException $exception) {
            // The service owns every rule; this only shows what it said.
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return null;
        }

        // Shown by the campaign page after the redirect, as a toast.
        session()->flash('recruitment_toast', __('recruitment-campaign.created'));

        return $this->redirectRoute('recruitment.campaigns.show',
            ['campaignId' => $campaign->getKey()], navigate: true);
    }

    public function render()
    {
        Gate::authorize('viewAny', HiringCampaign::class);

        return view('livewire.recruitment.campaigns.create', [
            // Only seats that are actually being recruited for, and only the
            // ones this user is allowed to see.
            'vacancies' => $this->availableVacancies()->get(),
            'linkModes' => HiringCampaign::LINK_MODES,
        ]);
    }

    private function availableVacancies()
    {
        return Vacancy::query()->available()
            ->whereDoesntHave('campaigns', fn ($query) => $query->ongoing())
            ->visibleTo(auth()->user())
            ->when($this->sourceVacancyId !== null, fn ($query) => $query->whereKey($this->sourceVacancyId))
            ->with(['position.city', 'position.program', 'createdFromRequest'])
            ->withCount('campaigns')
            ->orderByDesc('id');
    }

    private function vacancy(): ?Vacancy
    {
        if ($this->vacancyId === null) {
            return null;
        }

        return $this->availableVacancies()
            ->whereKey($this->vacancyId)
            ->first();
    }
}
