<?php

namespace App\Notifications;

use App\Models\ProbationReview;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The direct manager asked HR to extend, confirm or cancel a trial, with the
 * reason (meeting 19/11, track 4). Informational for HR ("seen"): the system
 * applies nothing; what HR does with the request is decided outside it.
 */
class ProbationRequested extends Notification
{
    use Queueable;

    public function __construct(public ProbationReview $review) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $review = $this->review->loadMissing('employee');
        $employee = $review->employee;
        $names = ['en' => $employee->nameForLocale('en'), 'ar' => $employee->nameForLocale('ar')];

        $ask = [
            'en' => __('probation.decision.'.$review->decision, [], 'en'),
            'ar' => __('probation.decision.'.$review->decision, [], 'ar'),
        ];

        return [
            'title' => [
                'en' => "Trial review request: {$names['en']}",
                'ar' => "طلب بشأن الفترة التجريبية: {$names['ar']}",
            ],
            'body' => [
                'en' => "Request: {$ask['en']}. Reason: {$review->reason}",
                'ar' => "الطلب: {$ask['ar']}. السبب: {$review->reason}",
            ],
            'url' => route('probation.show', $review),
            'subject' => ['type' => 'probation_review', 'id' => $review->id, 'action' => null],
        ];
    }
}
