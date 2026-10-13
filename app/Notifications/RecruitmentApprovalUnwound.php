<?php

namespace App\Notifications;

use App\Models\RecruitmentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Telling an earlier approver that what they approved is being changed.
 *
 * Somebody further down the chain sent the request back, so the document this
 * person read is about to become a different document. They are not being
 * asked to do anything yet — the request goes back to its author first, and
 * comes round again afterwards — but they approved something on the strength
 * of what it said, and finding out only when it lands in front of them again
 * would mean the record moved under them silently.
 *
 * Delivered as it happens rather than queued: nothing here processes a queue,
 * so a queued notice is a notice nobody receives. Every dispatch goes through
 * RecruitmentNotifier, which catches and logs rather than raising.
 */
class RecruitmentApprovalUnwound extends Notification
{
    use Queueable;

    public function __construct(
        public RecruitmentRequest $request,
        public ?string $askedBy = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('recruitment.notify.unwound_subject', ['number' => $this->request->request_number]))
            ->line(__('recruitment.notify.unwound_line', ['number' => $this->request->request_number]))
            ->action(__('recruitment.notify.open_in_system'), $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $number = $this->request->request_number;
        $by = $this->askedBy;

        return [
            'title' => [
                'en' => 'A request you approved is being changed',
                'ar' => 'طلب وافقت عليه يُعاد تعديله',
            ],
            'body' => [
                'en' => "Request {$number} was sent back for changes".($by ? " by {$by}." : '.')
                    .' It will come round again once it has been revised.',
                'ar' => "أُعيد الطلب {$number} للتعديل".($by ? " بطلب من {$by}." : '.')
                    .' وسيعود إليك بعد تعديله.',
            ],
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        return route('recruitment.requests.show', $this->request->getKey());
    }
}
