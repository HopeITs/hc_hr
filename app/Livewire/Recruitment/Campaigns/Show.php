<?php

namespace App\Livewire\Recruitment\Campaigns;

use App\Enums\HiringCampaignStatus as Status;
use App\Models\ApplicationForm;
use App\Models\HiringCampaign;
use App\Models\JobPost;
use App\Models\PublicRecruitmentLink;
use App\Services\HiringCampaignService;
use App\Services\HiringCampaignStateMachine;
use App\Services\PublicRecruitmentLinkService;
use App\Traits\WithAlerts;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The one page a campaign is prepared and run from.
 *
 * Preparation reads as a checklist — details, job post, application form,
 * link — and the status panel beside it says in one sentence where the
 * campaign stands and offers only the moves that are possible now. Which moves
 * those are is asked of the state machine and the service, never decided
 * here, and every action is re-checked by the service when it arrives.
 */
class Show extends Component
{
    use WithAlerts;

    #[Locked]
    public int $campaignId;

    public string $reason = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public string $linkMode = HiringCampaign::PUBLIC_OPEN;

    public string $minimumAge = '';

    public string $maximumAge = '';

    public bool $editingDetails = false;

    public function mount(int $campaignId): void
    {
        $this->campaignId = $campaignId;
        $this->fillDetails($this->resolve());

        // Carried across the redirect from the create screen, so the first
        // thing HR sees is the same alert every other save shows.
        if ($message = session()->pull('recruitment_toast')) {
            $this->successToast($message);
        }
    }

    /** Publish and open in one step — the usual "go live". */
    public function launch(HiringCampaignService $campaigns): void
    {
        $this->run(function () use ($campaigns): void {
            $campaign = $campaigns->launch(auth()->user(), $this->resolve());

            $this->successToast((int) $campaign->status === Status::Open
                ? __('recruitment-campaign.launched_open')
                : __('recruitment-campaign.launched_scheduled', [
                    'date' => $campaign->starts_at?->translatedFormat('j M Y'),
                ]));
        });
    }

    /** Publish, open, close, reopen, disable or reactivate. */
    public function act(string $action, HiringCampaignService $campaigns): void
    {
        $campaign = $this->resolve();
        Gate::authorize('manage', $campaign);

        $reason = trim($this->reason) ?: null;

        if (in_array($action, ['close', 'disable'], true) && $reason === null) {
            $this->errorToast(__('recruitment-campaign.reason_required'));

            return;
        }

        $this->run(function () use ($action, $campaigns, $campaign, $reason): void {
            match ($action) {
                'publish' => $campaigns->publish(auth()->user(), $campaign),
                'open' => $campaigns->open(auth()->user(), $campaign),
                'close' => $campaigns->close(auth()->user(), $campaign, $reason),
                'reopen' => $campaigns->reopen(auth()->user(), $campaign),
                'disable' => $campaigns->disable(auth()->user(), $campaign, $reason),
                'reactivate' => $campaigns->reactivate(auth()->user(), $campaign),
                default => throw new InvalidArgumentException('Unknown campaign action.'),
            };

            $this->reason = '';
            $this->successToast(__('recruitment-campaign.done_'.$action));
            $this->dispatch('campaign-status-changed');
        });
    }

    public function editDetails(): void
    {
        $this->fillDetails($this->resolve());
        $this->resetErrorBag();
        $this->editingDetails = true;
    }

    public function cancelDetails(): void
    {
        $this->fillDetails($this->resolve());
        $this->resetErrorBag();
        $this->editingDetails = false;
    }

    public function saveDetails(HiringCampaignService $campaigns): void
    {
        $this->resetErrorBag();

        try {
            $campaign = $campaigns->setWindow(auth()->user(), $this->resolve(), [
                'starts_at' => $this->startsAt,
                'ends_at' => $this->endsAt,
                'link_mode' => $this->linkMode,
                'minimum_age' => $this->minimumAge,
                'maximum_age' => $this->maximumAge,
            ]);
        } catch (ValidationException $exception) {
            // Field problems stay next to the field they are about.
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(match ($field) {
                    'starts_at' => 'startsAt',
                    'ends_at' => 'endsAt',
                    'link_mode' => 'linkMode',
                    'minimum_age' => 'minimumAge',
                    'maximum_age' => 'maximumAge',
                    default => $field,
                }, $messages[0]);
            }

            return;
        }

        $this->fillDetails($campaign);
        $this->editingDetails = false;
        $this->successToast(__('recruitment-campaign.window_saved'));
        $this->dispatch('campaign-details-changed');
    }

    /** The link manager changed something the checklist summarises. */
    #[On('campaign-status-changed')]
    public function refreshAfterLinkChange(): void
    {
        // Rendering re-reads everything; nothing to do but let it.
    }

    public function render()
    {
        $campaign = $this->resolve();
        $states = app(HiringCampaignStateMachine::class);
        $service = app(HiringCampaignService::class);
        $links = app(PublicRecruitmentLinkService::class);

        $effective = $states->effective($campaign);
        $mayManage = Gate::allows('manage', $campaign);
        $mayPublish = Gate::allows('publish', $campaign);
        $mayEdit = Gate::allows('update', $campaign);

        $jobPost = $campaign->jobPost;
        $form = $campaign->applicationForm;
        $postReady = $this->isPublished($jobPost);
        $formReady = $this->isPublished($form);

        $activeLink = $campaign->publicLinks
            ->filter(fn (PublicRecruitmentLink $link): bool => $link->status === PublicRecruitmentLink::STATUS_ACTIVE
                && $link->expires_at?->isFuture())
            ->sortByDesc('id')
            ->first();
        $linkReady = $activeLink !== null;
        $linkCurrent = $activeLink === null || $links->isCurrent($activeLink, $campaign);

        $readyCount = 1 + (int) $postReady + (int) $formReady + (int) $linkReady;
        $allReady = $postReady && $formReady && $linkReady;

        $isDraft = $effective === Status::Draft;
        $startsInFuture = ! $states->hasStarted($campaign);

        // Moves after launch. Publishing without opening stays available for
        // the rare "advert first, applications later" case (D3).
        $available = $mayManage ? array_keys(array_filter([
            'open' => $effective === Status::Published && $states->can($campaign, 'open')
                && ! $states->hasEnded($campaign) && ! $startsInFuture && $allReady,
            'close' => $states->can($campaign, 'close') && $effective === Status::Open,
            'reopen' => $states->can($campaign, 'reopen') && ! $states->hasEnded($campaign),
            'disable' => $states->can($campaign, 'disable') && $effective !== Status::Disabled,
            'reactivate' => $effective === Status::Disabled,
        ])) : [];

        return view('livewire.recruitment.campaigns.show', [
            'campaign' => $campaign,
            'effective' => $effective,
            'isDraft' => $isDraft,
            'isLive' => in_array($effective, [Status::Published, Status::Open], true),
            'startsInFuture' => $startsInFuture,
            'acceptsSubmissions' => $service->acceptsSubmissions($campaign),
            'mayManage' => $mayManage,
            'mayPublish' => $mayPublish,
            'mayEdit' => $mayEdit && ! in_array($effective, [Status::Expired, Status::Disabled], true),
            'canChangeAudience' => $isDraft,
            'jobPost' => $jobPost,
            'form' => $form,
            'postReady' => $postReady,
            'formReady' => $formReady,
            'linkReady' => $linkReady,
            'linkCurrent' => $linkCurrent,
            'activeLink' => $activeLink,
            'readyCount' => $readyCount,
            'allReady' => $allReady,
            'canLaunch' => $mayPublish && $isDraft && ! $states->hasEnded($campaign),
            'canPublishOnly' => $mayPublish && $isDraft && $postReady && $formReady,
            'available' => $available,
            'applicationsCount' => $campaign->applications()->count(),
            'canManageJobPost' => $jobPost
                ? Gate::allows('manage', $jobPost)
                : Gate::allows('create', [JobPost::class, $campaign]),
            'canManageForm' => $form
                ? Gate::allows('manage', $form)
                : Gate::allows('create', [ApplicationForm::class, $campaign]),
            'canSeeLinks' => auth()->user()?->hasActivePermission('recruitment-links.view')
                || auth()->user()?->hasActivePermission('recruitment-links.manage'),
            'linkModes' => HiringCampaign::LINK_MODES,
        ]);
    }

    /**
     * Every action shares the same failure handling: the service says why,
     * and the alert repeats it in the same style as a success.
     */
    private function run(callable $work): void
    {
        try {
            $work();
        } catch (ValidationException $exception) {
            $this->errorToast(collect($exception->errors())->flatten()->first()
                ?? __('recruitment-campaign.transition_not_allowed'));
        }
    }

    private function isPublished($asset): bool
    {
        return $asset?->status === 'published'
            && $asset?->currentVersion?->status === 'published'
            && $asset?->currentVersion?->published_at !== null;
    }

    private function fillDetails(HiringCampaign $campaign): void
    {
        $this->startsAt = $campaign->starts_at?->format('Y-m-d') ?? '';
        $this->endsAt = $campaign->ends_at?->format('Y-m-d') ?? '';
        $this->linkMode = $campaign->link_mode ?: HiringCampaign::PUBLIC_OPEN;
        $this->minimumAge = $campaign->minimum_age === null ? '' : (string) $campaign->minimum_age;
        $this->maximumAge = $campaign->maximum_age === null ? '' : (string) $campaign->maximum_age;
    }

    /** Re-resolved through the visibility boundary on every update. */
    private function resolve(): HiringCampaign
    {
        $campaign = HiringCampaign::query()->visibleTo(auth()->user())
            ->with([
                'vacancy.position',
                'vacancy.createdFromRequest',
                'creator.profile',
                'closedBy.profile',
                'jobPost.currentVersion',
                'applicationForm.currentVersion',
                'publicLinks',
            ])
            ->findOrFail($this->campaignId);

        Gate::authorize('view', $campaign);

        return $campaign;
    }
}
