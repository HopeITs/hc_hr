<?php

namespace App\Mail;

use App\Models\BoardMeeting;
use App\Services\Board\BoardMeetingIcs;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The day-before reminder by email, with the meeting as a calendar invitation (.ics). */
class BoardMeetingReminderMail extends Mailable
{
    use Queueable;

    public function __construct(private readonly BoardMeeting $meeting) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تذكير: اجتماع مجلس الإدارة رقم '.$this->meeting->number.' غداً | Board meeting tomorrow');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.board.reminder', with: [
            'number' => $this->meeting->number,
            'dateAr' => $this->meeting->starts_at->copy()->locale('ar')->translatedFormat('l j F Y'),
            'dateEn' => $this->meeting->starts_at->copy()->locale('en')->translatedFormat('l, j F Y'),
            'time' => $this->meeting->starts_at->format('H:i'),
            'place' => trim(($this->meeting->location ?? '').($this->meeting->room ? ' - '.$this->meeting->room : '')),
            'url' => route('board.meetings.show', ['meeting' => $this->meeting->id]),
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => BoardMeetingIcs::make($this->meeting), BoardMeetingIcs::fileName($this->meeting))
                ->withMime('text/calendar'),
        ];
    }
}
