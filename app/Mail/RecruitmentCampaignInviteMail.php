<?php

namespace App\Mail;

use App\Models\RecruitmentCampaignInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The application link, sent to the person an invitation-only campaign names.
 *
 * Written in both languages in one message: nothing is known about the
 * invitee's language before they apply, and the page they land on offers both.
 */
class RecruitmentCampaignInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly RecruitmentCampaignInvite $invite,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        $position = $this->position();

        return new Envelope(
            subject: __('recruitment-applicants.invite_mail.subject', ['position' => $position['en'] ?: $position['ar']], 'en')
                .' | '.__('recruitment-applicants.invite_mail.subject', ['position' => $position['ar'] ?: $position['en']], 'ar'),
        );
    }

    public function content(): Content
    {
        $closes = $this->invite->campaign?->ends_at;

        return new Content(
            view: 'mail.recruitment.campaign-invite',
            with: [
                'name' => $this->invite->full_name,
                'email' => $this->invite->email_normalized,
                'url' => $this->url,
                'position' => $this->position(),
                'closes' => $closes?->copy()->setTimezone(config('app.timezone')),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    /** @return array{en: string, ar: string} */
    private function position(): array
    {
        $position = $this->invite->campaign?->vacancy?->position;

        return [
            'en' => (string) ($position?->title_en ?: $position?->title_ar),
            'ar' => (string) ($position?->title_ar ?: $position?->title_en),
        ];
    }
}
