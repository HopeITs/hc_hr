<?php

namespace App\Observers;

use App\Jobs\SendRecruitmentCampaignInvite;
use App\Models\RecruitmentCampaignInvite;
use Illuminate\Validation\ValidationException;

/**
 * An invite that has been USED is final — whichever code path touches it.
 *
 * The person already applied through it, and used_at records when. Team 1's
 * PublicRecruitmentLinkService::addInvite re-saves an existing row with
 * updateOrCreate (status back to pending, used_at to null) and cancelInvite
 * cancels without looking; both would erase that record. This observer
 * closes the gap at the model, so Team 1's files stay untouched (Phase 2
 * integration rule) and Team 2's own service is covered too.
 *
 * The error is keyed "inviteEmail" because that is the field Team 1's
 * publishing page shows invite errors under (manager.blade.php), so HR sees
 * the reason instead of a failed request. Team 2's service rejects the same
 * cases before saving, so it never reaches this point.
 */
class RecruitmentCampaignInviteObserver
{
    /**
     * A new invitation is emailed its link when the campaign already has one.
     *
     * Here rather than in either service so that both screens that add invites
     * (and Team 1's publishing page, which we do not touch) behave the same.
     * Without an active link the invitation stays pending, and HR sends it once
     * the link exists.
     */
    public function created(RecruitmentCampaignInvite $invite): void
    {
        $this->send($invite);
    }

    /** Re-adding a cancelled invitation is inviting that person again. */
    public function updated(RecruitmentCampaignInvite $invite): void
    {
        if ($invite->wasChanged('status')
            && $invite->status === RecruitmentCampaignInvite::STATUS_PENDING
            && $invite->getOriginal('status') === RecruitmentCampaignInvite::STATUS_CANCELLED) {
            $this->send($invite);
        }
    }

    public function updating(RecruitmentCampaignInvite $invite): void
    {
        $wasUsed = $invite->getOriginal('used_at') !== null
            || $invite->getOriginal('status') === RecruitmentCampaignInvite::STATUS_USED;

        if ($wasUsed && ($invite->isDirty('status') || $invite->isDirty('used_at'))) {
            throw ValidationException::withMessages([
                'inviteEmail' => __('recruitment-applicants.invite_already_used'),
            ]);
        }
    }

    private function send(RecruitmentCampaignInvite $invite): void
    {
        if ($invite->status === RecruitmentCampaignInvite::STATUS_PENDING
            && SendRecruitmentCampaignInvite::activeUrl($invite) !== null) {
            SendRecruitmentCampaignInvite::dispatch($invite->getKey())->afterCommit();
        }
    }
}
