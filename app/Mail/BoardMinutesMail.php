<?php

namespace App\Mail;

use App\Models\BoardMeeting;
use App\Services\Board\BoardMinutesView;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The published minutes of a board meeting, with the PDF attached. */
class BoardMinutesMail extends Mailable
{
    use Queueable;

    public function __construct(private readonly BoardMeeting $meeting, private readonly string $pdf) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'محضر اجتماع مجلس الإدارة رقم '.$this->meeting->number.' | Board meeting minutes no. '.$this->meeting->number);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.board.minutes', with: [
            'number' => $this->meeting->number,
            'dateAr' => $this->meeting->starts_at->copy()->locale('ar')->translatedFormat('l j F Y'),
            'dateEn' => $this->meeting->starts_at->copy()->locale('en')->translatedFormat('l, j F Y'),
            'url' => route('board.meetings.show', ['meeting' => $this->meeting->id]),
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, BoardMinutesView::fileName($this->meeting))->withMime('application/pdf')];
    }
}
