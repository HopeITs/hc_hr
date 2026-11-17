<?php

namespace App\Mail;

use App\Enums\RecruitmentInterviewLocationType;
use App\Models\RecruitmentInterviewNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * The candidate's interview email, in Arabic and English: scheduled, moved,
 * cancelled, or the reminder.
 *
 * Built from public facts only — the candidate's name, the position, the
 * kind of interview, when, how long, and where or how to join. The view never
 * receives the interview model: no cancellation reason or note, no outcome
 * note, no participant list, no evaluation (Task 8) can reach it through a
 * template change.
 *
 * Times are written in the interview's own timezone, with the timezone named,
 * and in an unambiguous numeric format alongside the spelled-out one.
 */
class RecruitmentInterviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(private readonly RecruitmentInterviewNotice $notice) {}

    public function envelope(): Envelope
    {
        $key = 'recruitment-applicant.interviews.mail.subject.'.$this->notice->kind;

        return new Envelope(subject: __($key, [], 'ar').' | '.__($key, [], 'en'));
    }

    public function content(): Content
    {
        $interview = $this->notice->interview;
        $application = $interview->application;
        $position = $application?->campaign?->vacancy?->position;
        $timezone = $interview->timezone ?: config('app.timezone');
        $starts = Carbon::parse($this->notice->scheduled_for)->setTimezone($timezone);
        $online = $interview->location_type === RecruitmentInterviewLocationType::Online;

        return new Content(
            markdown: 'mail.recruitment.interview',
            with: [
                'kind' => $this->notice->kind,
                'name' => $application?->applicant?->full_name,
                'positionAr' => $position?->title_ar,
                'positionEn' => $position?->title_en,
                'type' => $interview->interview_type,
                'dateAr' => $starts->copy()->locale('ar')->translatedFormat('l j F Y'),
                'dateEn' => $starts->copy()->locale('en')->translatedFormat('l, j F Y'),
                'time' => $starts->format('H:i'),
                'numeric' => $starts->format('Y-m-d H:i'),
                'timezone' => $timezone.' (UTC'.$starts->format('P').')',
                'duration' => $interview->ends_at ? (int) $interview->scheduled_at->diffInMinutes($interview->ends_at) : null,
                'online' => $online,
                // Only for an interview that is still on: a cancelled one
                // needs no address and no link.
                'location' => $this->notice->kind === RecruitmentInterviewNotice::KIND_CANCELLED ? null : $interview->location_value,
                'meetingUrl' => $online && $this->notice->kind !== RecruitmentInterviewNotice::KIND_CANCELLED ? $interview->meeting_url : null,
            ],
        );
    }
}
