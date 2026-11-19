<?php

namespace App\Notifications;

use App\Models\ProbationReview;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A trial contract entered its last third (meeting 19/11, track 4). One text
 * per audience: the volunteer is told, the direct manager is asked to answer
 * (and reminded every week until they do), HR of the city is informed.
 */
class ProbationFinalThird extends Notification
{
    use Queueable;

    /** @param  string  $audience  employee | manager | hr */
    public function __construct(
        public ProbationReview $review,
        public string $audience = 'employee',
        public bool $reminder = false,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $review = $this->review->loadMissing('employee', 'contract');
        $employee = $review->employee;
        $end = $review->contract?->end_date;
        $names = ['en' => $employee->nameForLocale('en'), 'ar' => $employee->nameForLocale('ar')];

        $date = [
            'en' => $end?->locale('en')->translatedFormat('d M Y'),
            'ar' => $end?->locale('ar')->translatedFormat('d M Y'),
        ];

        [$title, $body] = match ($this->audience) {
            'manager' => [
                [
                    'en' => ($this->reminder ? 'Reminder: ' : '')."Trial review: {$names['en']}",
                    'ar' => ($this->reminder ? 'تذكير: ' : '')."مراجعة الفترة التجريبية: {$names['ar']}",
                ],
                [
                    'en' => "{$names['en']}'s trial is in its last third (ends {$date['en']}). Tell HR what you ask for: extend, confirm or cancel, with the reason.",
                    'ar' => "دخلت الفترة التجريبية لـ {$names['ar']} ثلثها الأخير (تنتهي {$date['ar']}). أرسل إلى الموارد البشرية طلبك: تمديد أو تثبيت أو إلغاء، مع السبب.",
                ],
            ],
            'hr' => [
                [
                    'en' => "Trial in its last third: {$names['en']}",
                    'ar' => "فترة تجريبية في ثلثها الأخير: {$names['ar']}",
                ],
                [
                    'en' => "{$names['en']}'s trial ends {$date['en']}. The direct manager was asked for a decision.",
                    'ar' => "تنتهي الفترة التجريبية لـ {$names['ar']} في {$date['ar']}. طُلب قرار من المدير المباشر.",
                ],
            ],
            default => [
                [
                    'en' => 'Your trial period is in its last third',
                    'ar' => 'فترتك التجريبية في ثلثها الأخير',
                ],
                [
                    'en' => "Your trial ends {$date['en']}. Your manager will review it with HR.",
                    'ar' => "تنتهي فترتك التجريبية في {$date['ar']}. سيراجعها مديرك مع الموارد البشرية.",
                ],
            ],
        };

        return [
            'title' => $title,
            'body' => $body,
            'url' => $this->audience === 'employee'
                ? route('employees.show', $employee->username)
                : route('probation.show', $review),
            'subject' => ['type' => 'probation_review', 'id' => $review->id, 'action' => null],
        ];
    }
}
