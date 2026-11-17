<?php

namespace App\Livewire\Recruitment\PublicLinks;

use App\Models\HiringCampaign;
use App\Models\PublicRecruitmentLink;
use App\Models\RecruitmentCampaignInvite;
use App\Services\HiringCampaignService;
use App\Services\PublicRecruitmentLinkService;
use App\Traits\WithAlerts;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The shareable link, and the invitation list when the campaign has one.
 *
 * Who may apply is the campaign's setting, chosen once in its details; the
 * link follows it rather than asking again. The expiry follows the campaign
 * window unless HR deliberately sets another.
 *
 * Only a hash of the token is stored, so the address cannot be rebuilt from
 * the database. It is kept, encrypted, in the generating user's own session so
 * they can copy it again while they work, instead of having to regenerate —
 * which would break every copy already shared.
 */
class Manager extends Component
{
    use WithAlerts;

    public HiringCampaign $campaign;

    public bool $customExpiry = false;

    public string $expiresAt = '';

    public string $inviteName = '';

    public string $inviteEmail = '';

    /** Kept for callers and tests that read the last outcome. */
    public ?string $notice = null;

    public string $noticeType = 'success';

    /** The URL just generated; also recoverable from the session. */
    public string $generatedUrl = '';

    public function mount(HiringCampaign $campaign): void
    {
        Gate::authorize('view', $campaign);
        abort_unless(
            auth()->user()?->hasActivePermission('recruitment-links.view')
                || auth()->user()?->hasActivePermission('recruitment-links.manage'),
            403,
        );

        $this->campaign = $campaign;
        $this->resetExpiry();
    }

    #[On('campaign-details-changed')]
    public function refreshCampaign(): void
    {
        $this->campaign->refresh();

        if (! $this->customExpiry) {
            $this->resetExpiry();
        }
    }

    #[On('campaign-status-changed')]
    public function refreshCampaignStatus(): void
    {
        $this->campaign->refresh();
    }

    public function generate(PublicRecruitmentLinkService $service): void
    {
        Gate::authorize('create', [PublicRecruitmentLink::class, $this->campaign]);

        if ($this->customExpiry) {
            $this->validate(['expiresAt' => ['required', 'date', 'after_or_equal:today']]);
        }

        try {
            $result = $service->generate(
                $this->campaign->fresh(),
                auth()->user(),
                $this->customExpiry ? $this->expiresAt : null,
            );
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        $this->campaign->refresh();
        $this->generatedUrl = $result['url'];
        $this->rememberUrl($result['link'], $result['url']);
        $this->success(__('recruitment.public_link.generated'));
        $this->dispatch('campaign-status-changed');
    }

    /** Point the existing address at the newest published post and form. */
    public function refreshVersions(PublicRecruitmentLinkService $service): void
    {
        $link = $this->activeLink();
        abort_if($link === null, 404);
        Gate::authorize('update', $link);

        try {
            $service->refreshVersions($link, auth()->user());
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        $this->success(__('recruitment.public_link.refreshed'));
        $this->dispatch('campaign-status-changed');
    }

    public function disable(int $linkId, PublicRecruitmentLinkService $service): void
    {
        $link = $this->campaign->publicLinks()->findOrFail($linkId);
        Gate::authorize('update', $link);
        $service->disable($link, auth()->user());
        session()->forget($this->sessionKey($link));
        $this->generatedUrl = '';
        $this->success(__('recruitment.public_link.disabled'));
        $this->dispatch('campaign-status-changed');
    }

    public function addInvite(PublicRecruitmentLinkService $service): void
    {
        Gate::authorize('create', [PublicRecruitmentLink::class, $this->campaign]);
        $validated = $this->validate([
            'inviteName' => ['required', 'string', 'max:191'],
            'inviteEmail' => ['required', 'email:rfc', 'max:191'],
        ]);

        try {
            $service->addInvite(
                $this->campaign->fresh(),
                auth()->user(),
                $validated['inviteName'],
                $validated['inviteEmail'],
            );
            $this->reset('inviteName', 'inviteEmail');
            $this->success(__('recruitment.public_link.invite_saved'));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());
        }
    }

    public function cancelInvite(int $inviteId, PublicRecruitmentLinkService $service): void
    {
        Gate::authorize('create', [PublicRecruitmentLink::class, $this->campaign]);
        $invite = $this->campaign->invites()->findOrFail($inviteId);
        $service->cancelInvite($invite, auth()->user());
        $this->success(__('recruitment.public_link.invite_cancelled'));
    }

    public function useCampaignExpiry(): void
    {
        $this->customExpiry = false;
        $this->resetValidation('expiresAt');
        $this->resetExpiry();
    }

    public function render(PublicRecruitmentLinkService $service, HiringCampaignService $campaigns)
    {
        $this->campaign->loadMissing(['jobPost.currentVersion', 'applicationForm.currentVersion']);
        $activeLink = $this->activeLink();

        return view('livewire.recruitment.public-links.manager', [
            'activeLink' => $activeLink,
            'shareUrl' => $this->generatedUrl ?: ($activeLink ? $this->recalledUrl($activeLink) : ''),
            'linkCurrent' => $activeLink === null || $service->isCurrent($activeLink, $this->campaign),
            'acceptsSubmissions' => $campaigns->acceptsSubmissions($this->campaign),
            'mayManage' => $activeLink
                ? Gate::allows('update', $activeLink)
                : Gate::allows('create', [PublicRecruitmentLink::class, $this->campaign]),
            'isInvitationOnly' => $this->campaign->isInvitationOnly(),
            'invites' => $this->campaign->isInvitationOnly()
                ? $this->campaign->invites()
                    ->where('status', '!=', RecruitmentCampaignInvite::STATUS_CANCELLED)
                    ->latest('id')
                    ->get()
                : collect(),
        ]);
    }

    private function activeLink(): ?PublicRecruitmentLink
    {
        return $this->campaign->publicLinks()
            ->where('status', PublicRecruitmentLink::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    private function resetExpiry(): void
    {
        $expiry = $this->campaign->ends_at?->isFuture() ? $this->campaign->ends_at : now()->addDays(30);
        $this->expiresAt = $expiry->format('Y-m-d');
    }

    private function sessionKey(PublicRecruitmentLink $link): string
    {
        return 'recruitment.public_link_url.'.$link->getKey();
    }

    private function rememberUrl(PublicRecruitmentLink $link, string $url): void
    {
        session()->put($this->sessionKey($link), Crypt::encryptString($url));
    }

    private function recalledUrl(PublicRecruitmentLink $link): string
    {
        // First try the session — it was written at generation time and is
        // faster than decrypting a database value on every render.
        $stored = session()->get($this->sessionKey($link));

        if (is_string($stored)) {
            try {
                return Crypt::decryptString($stored);
            } catch (DecryptException) {
                // fall through to the database copy below
            }
        }

        // The session is gone (e.g. HR logged out and back in). Recover the
        // URL from the encrypted copy stored on the link row. This lets HR
        // copy the link again without having to regenerate — which would break
        // every copy already shared on social media.
        if (! is_string($link->url_encrypted) || $link->url_encrypted === '') {
            return '';
        }

        try {
            return Crypt::decryptString($link->url_encrypted);
        } catch (DecryptException) {
            return '';
        }
    }

    private function success(string $message): void
    {
        $this->notice = $message;
        $this->noticeType = 'success';
        $this->resetValidation();
        $this->successToast($message);
    }

    private function error(string $message): void
    {
        $this->notice = $message;
        $this->noticeType = 'error';
        $this->errorToast($message);
    }
}
