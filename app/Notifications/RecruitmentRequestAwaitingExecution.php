<?php

namespace App\Notifications;

use App\Models\RecruitmentRequest;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The decision is made; the change has not happened yet.
 *
 * A reduction or a closure dated in the future stays approved and waits. HR
 * needs to know that, and needs to know the date, because the alternative is a
 * post that still looks open with nothing on any screen saying why.
 *
 * Delivered as it happens rather than queued. The database row is what the
 * bell in the application reads, and a queued notification is written only
 * when a worker runs — this deployment has none, so a queued notice is a
 * notice nobody receives. Five hundred jobs had piled up unprocessed before
 * anybody noticed. The rest of the application sends database and mail
 * synchronously for the same reason. Nothing here can cost an approval:
 * every dispatch goes through RecruitmentNotifier, which catches and logs.
 */
class RecruitmentRequestAwaitingExecution extends Notification
{
    use Queueable;

    public function __construct(
        public RecruitmentRequest $request,
        public ?CarbonInterface $effectiveDate = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $number = $this->request->request_number;
        $date = $this->effectiveDate?->toDateString() ?? '—';

        return [
            'title' => [
                'en' => 'Approved request awaiting execution',
                'ar' => 'طلب معتمد بانتظار التنفيذ',
            ],
            'body' => [
                'en' => "Request {$number} is approved and will take effect on {$date}. You may execute it now instead.",
                'ar' => "الطلب {$number} معتمد وسينفَّذ تلقائياً بتاريخ {$date}. يمكنك تنفيذه الآن بدلاً من ذلك.",
            ],
            'url' => route('recruitment.requests.show', $this->request->getKey()),
        ];
    }
}
