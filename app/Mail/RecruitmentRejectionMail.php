<?php

namespace App\Mail;

use App\Models\RecruitmentApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The apology a rejected candidate receives, in Arabic and English.
 *
 * Built from public facts only — the candidate's name and the position they
 * applied for. The decision, its reason code and every internal note are
 * never passed in, so no template change can leak them.
 */
class RecruitmentRejectionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(private readonly RecruitmentApplication $application) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('recruitment-applicant.decisions.mail.subject', [], 'ar').' | '.__('recruitment-applicant.decisions.mail.subject', [], 'en'),
        );
    }

    public function content(): Content
    {
        $position = $this->application->campaign?->vacancy?->position;

        return new Content(
            markdown: 'mail.recruitment.rejection',
            with: [
                'name' => $this->application->applicant?->full_name,
                'positionAr' => $position?->title_ar,
                'positionEn' => $position?->title_en,
            ],
        );
    }
}
