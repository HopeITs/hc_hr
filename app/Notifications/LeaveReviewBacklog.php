<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Weekly nudge to HR of a city (T3): decided leave requests still waiting
 * for "Seen by HR", with how long the oldest has waited.
 */
class LeaveReviewBacklog extends Notification
{
    use Queueable;

    public function __construct(public int $count, public int $oldestDays) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => [
                'en' => 'Leave decisions waiting for HR review',
                'ar' => 'قرارات إجازة بانتظار مراجعة الموارد البشرية',
            ],
            'body' => [
                'en' => "{$this->count} decided leave request(s) still wait for \"Seen by HR\". The oldest has waited {$this->oldestDays} day(s).",
                'ar' => "{$this->count} طلب(طلبات) إجازة مقرَّرة ما زالت بانتظار مراجعة الموارد البشرية. أقدمها ينتظر منذ {$this->oldestDays} يومًا.",
            ],
            'url' => route('inbox', ['show' => 'review']),
            'subject' => ['type' => 'leave_review_backlog', 'id' => null, 'action' => null],
        ];
    }
}
