<?php

namespace App\Notifications;

use App\Models\RecruitmentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A request has reached somebody and is waiting on them.
 *
 * A pointer, not a record. The approver still has to open the system, where
 * the six checks run against live data: by the time they click, the step may
 * have moved on, the request may have been withdrawn, or somebody else may
 * have been assigned. Nothing here grants anything, and a mail that never
 * arrives costs the workflow nothing — the pending list is read from the
 * database, never from an inbox.
 *
 * The mail carries a request number and a link and nothing else. What is being
 * recruited for, at what salary, and why, stays inside the system.
 *
 * Delivered as it happens rather than queued. The database row is what the
 * bell in the application reads, and a queued notification is written only
 * when a worker runs — this deployment has none, so a queued notice is a
 * notice nobody receives. Five hundred jobs had piled up unprocessed before
 * anybody noticed. The rest of the application sends database and mail
 * synchronously for the same reason. Nothing here can cost an approval:
 * every dispatch goes through RecruitmentNotifier, which catches and logs.
 */
class RecruitmentApprovalAwaiting extends Notification
{
    use Queueable;

    public function __construct(
        public RecruitmentRequest $request,
        public ?string $stepName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('recruitment.notify.awaiting_subject', ['number' => $this->request->request_number]))
            ->line(__('recruitment.notify.awaiting_line', ['number' => $this->request->request_number]))
            ->action(__('recruitment.notify.open_in_system'), $this->url())
            ->line(__('recruitment.notify.decide_inside'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => [
                'en' => 'A recruitment request needs your approval',
                'ar' => 'طلب توظيف بانتظار موافقتك',
            ],
            'body' => [
                'en' => "Request {$this->request->request_number} is waiting on you".
                    ($this->stepName ? " ({$this->stepName})." : '.'),
                'ar' => "الطلب {$this->request->request_number} بانتظار قرارك".
                    ($this->stepName ? " ({$this->stepName})." : '.'),
            ],
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        return route('recruitment.requests.show', $this->request->getKey());
    }
}
