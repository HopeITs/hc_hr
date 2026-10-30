<?php

namespace App\Notifications;

use App\Models\OnboardingJourney;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The one reminder a new volunteer's orientation session gets once it is past
 * its due date (D4-I, docs/ux/journeys.md): to HR in the volunteer's city.
 * In-app only.
 */
class OrientationOverdue extends Notification
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
                'en' => 'Orientation Session Overdue',
                'ar' => 'جلسة التعريف متأخرة',
            ],
            'body' => [
                'en' => "{$employee->nameForLocale('en')} has not had their orientation session yet. It was due by {$due}.",
                'ar' => "لم تُعقد جلسة التعريف لـ {$employee->nameForLocale('ar')} بعد. كان موعدها الأقصى {$due}.",
            ],
            'url' => route('employees.edit', $employee),
            'subject' => ['type' => 'onboarding_journey', 'id' => $this->journey->id, 'action' => null],
        ];
    }
}
