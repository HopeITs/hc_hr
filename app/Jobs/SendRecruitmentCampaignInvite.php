<?php

namespace App\Jobs;

use App\Mail\RecruitmentCampaignInviteMail;
use App\Models\PublicRecruitmentLink;
use App\Models\RecruitmentCampaignInvite;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Emails the campaign's application link to one invitee, then marks the
 * invitation sent.
 *
 * The invitation is looked up again here rather than trusted from when the job
 * was queued: it may have been cancelled or used since, and the link may have
 * been regenerated, so what is sent is whatever is current now. Doing nothing
 * is the right answer to every one of those, and a campaign with no active
 * link yet leaves the invitation pending for HR to send later.
 *
 * A message the mail server refuses for good (a mailbox that does not exist)
 * is not retried: it is recorded on the invitation, with a reason HR can read,
 * and the job ends. Anything that might pass on its own (a connection that
 * dropped) is retried, and recorded only once the retries are spent.
 */
class SendRecruitmentCampaignInvite implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $inviteId) {}

    public function handle(): void
    {
        $invite = RecruitmentCampaignInvite::query()
            ->with(['campaign.vacancy.position'])
            ->find($this->inviteId);

        if ($invite === null
            || ! in_array($invite->status, [RecruitmentCampaignInvite::STATUS_PENDING, RecruitmentCampaignInvite::STATUS_SENT], true)
            || $invite->campaign === null
            || ! $invite->campaign->isInvitationOnly()
            || ! filter_var($invite->email_normalized, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $url = $this->activeUrl($invite);
        if ($url === null) {
            return;
        }

        try {
            Mail::to($invite->email_normalized)->send(new RecruitmentCampaignInviteMail($invite, $url));
        } catch (Throwable $exception) {
            $reason = self::permanentFailure($exception);

            if ($reason === null) {
                throw $exception;
            }

            $this->record($invite, $reason, $exception);

            return;
        }

        $invite->forceFill([
            'status' => RecruitmentCampaignInvite::STATUS_SENT,
            'sent_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
        ])->save();
    }

    /** Called once the retries are spent. */
    public function failed(Throwable $exception): void
    {
        $invite = RecruitmentCampaignInvite::query()->find($this->inviteId);

        if ($invite !== null && in_array($invite->status, [RecruitmentCampaignInvite::STATUS_PENDING, RecruitmentCampaignInvite::STATUS_SENT], true)) {
            $this->record($invite, RecruitmentCampaignInvite::FAILURE_NOT_SENT, $exception);
        }
    }

    /**
     * Is this a refusal that trying again cannot change? Returns its reason, or
     * null when it might pass.
     *
     * Only a reply from the mail server counts. A dropped connection has no
     * reply, so it stays a retry.
     */
    public static function permanentFailure(Throwable $exception): ?string
    {
        if (! $exception instanceof TransportExceptionInterface) {
            return null;
        }

        $code = (int) $exception->getCode();
        if ($code < 500 || $code > 599) {
            return null;
        }

        return preg_match('/no mailbox|no such (user|mailbox)|user unknown|unknown user|does not exist|invalid recipient|mailbox unavailable|\b5\.1\.[1-6]\b/i', $exception->getMessage()) === 1 || $code === 511
            ? RecruitmentCampaignInvite::FAILURE_MAILBOX_MISSING
            : RecruitmentCampaignInvite::FAILURE_REJECTED;
    }

    /** The link as HR would copy it: stored encrypted, so read back, never rebuilt. */
    public static function activeUrl(RecruitmentCampaignInvite $invite): ?string
    {
        $link = PublicRecruitmentLink::query()
            ->where('campaign_id', $invite->campaign_id)
            ->where('status', PublicRecruitmentLink::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($link === null || blank($link->url_encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($link->url_encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    /** The server's own words go to the log; HR is shown the reason, not those. */
    private function record(RecruitmentCampaignInvite $invite, string $reason, Throwable $exception): void
    {
        $invite->forceFill(['failed_at' => now(), 'failure_reason' => $reason])->save();

        Log::warning('A campaign invitation email could not be delivered.', [
            'invite_id' => $invite->getKey(),
            'campaign_id' => $invite->campaign_id,
            'reason' => $reason,
            'exception' => $exception->getMessage(),
        ]);
    }
}
