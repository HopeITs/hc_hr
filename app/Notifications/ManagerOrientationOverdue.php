<?php

namespace App\Notifications;

use App\Models\OnboardingJourney;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The direct manager's orientation session (programs, how work runs) with a
 * new volunteer is past its due date: a reminder to the manager, repeated
 * weekly until the session is recorded. In-app only.
 */
class ManagerOrientationOverdue extends Notification
{
    use Queueable;

    public function __construct(public OnboardingJourney $journey) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $employee = $this->journey->employee;
        $due = $this->journey->orientation_due_on?->format('d-m-Y');

        return [
            'title' => [
                'en' => 'Manager Orientation Session Overdue',
                'ar' => 'جلسة المدير مع المتطوع الجديد متأخرة',
            ],
            'body' => [
                'en' => "You have not held the orientation session (programs and how work runs) with {$employee->nameForLocale('en')} yet. It was due by {$due}.",
                'ar' => "لم تُعقد جلسة المدير (البرامج وآلية العمل) مع {$employee->nameForLocale('ar')} بعد. كان موعدها الأقصى {$due}.",
            ],
            'url' => route('employees.edit', $employee),
            'subject' => ['type' => 'onboarding_journey', 'id' => $this->journey->id, 'action' => 'manager_orientation'],
        ];
    }
}
