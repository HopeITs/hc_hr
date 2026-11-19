<?php

namespace App\Notifications;

use App\Models\OnboardingJourney;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A step of a new volunteer's onboarding journey (login account, electronics,
 * ID card) is past its two-day deadline: a reminder to the person who owns it,
 * repeated weekly until it is done (SendOnboardingStepReminders). In-app only.
 */
class OnboardingStepOverdue extends Notification
{
    use Queueable;

    /** @param  'account'|'electronics'|'card'  $step */
    public function __construct(public OnboardingJourney $journey, public string $step) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $employee = $this->journey->employee;
        $steps = [
            'account' => ['en' => 'the login account', 'ar' => 'حساب الدخول'],
            'electronics' => ['en' => 'the electronics (laptop, mouse, work email...)', 'ar' => 'الإلكترونيات (لابتوب، ماوس، إيميل العمل...)'],
            'card' => ['en' => 'the ID card', 'ar' => 'البطاقة'],
        ];
        $what = $steps[$this->step];
        $due = $this->journey->dueAfterStart(2)?->format('d-m-Y');

        return [
            'title' => [
                'en' => 'Onboarding Step Overdue',
                'ar' => 'خطوة في مسيرة المتطوع متأخرة',
            ],
            'body' => [
                'en' => "{$what['en']} for {$employee->nameForLocale('en')} is not ready yet. It was due by {$due}.",
                'ar' => "{$what['ar']} للمتطوع {$employee->nameForLocale('ar')} غير جاهزة بعد. كان موعدها الأقصى {$due}.",
            ],
            'url' => route('employees.edit', $employee),
            'subject' => ['type' => 'onboarding_journey', 'id' => $this->journey->id, 'action' => null],
        ];
    }
}
