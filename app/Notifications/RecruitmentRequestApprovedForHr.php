<?php

namespace App\Notifications;

use App\Models\RecruitmentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An approved request HR now has work on.
 *
 * Separate from the requester's own notice because HR acts on it rather than
 * merely learns of it: a campaign gets built, a post gets advertised, or an
 * execution date gets watched. The deferred case has its own notice
 * (RecruitmentRequestAwaitingExecution) because it asks HR to decide whether
 * to bring the effect forward; this one only says the decision is final.
 *
 * Delivered as it happens rather than queued. The database row is what the
 * bell in the application reads, and a queued notification is written only
 * when a worker runs — this deployment has none, so a queued notice is a
 * notice nobody receives. Five hundred jobs had piled up unprocessed before
 * anybody noticed. The rest of the application sends database and mail
 * synchronously for the same reason. Nothing here can cost an approval:
 * every dispatch goes through RecruitmentNotifier, which catches and logs.
 */
class RecruitmentRequestApprovedForHr extends Notification
{
    use Queueable;

    public function __construct(
        public RecruitmentRequest $request,
        public bool $alreadyApplied = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('recruitment.notify.approved_subject', ['number' => $this->request->request_number]))
            ->line(__('recruitment.notify.approved_line', ['number' => $this->request->request_number]))
            ->action(__('recruitment.notify.open_in_system'), $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => [
                'en' => 'A recruitment request was approved',
                'ar' => 'اعتُمد طلب توظيف',
            ],
            'body' => [
                'en' => "Request {$this->request->request_number} was approved".
                    ($this->alreadyApplied ? ' and has been carried out.' : '.'),
                'ar' => "اعتُمد الطلب {$this->request->request_number}".
                    ($this->alreadyApplied ? ' ونُفِّذ أثره.' : '.'),
            ],
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        return route('recruitment.requests.show', $this->request->getKey());
    }
}
