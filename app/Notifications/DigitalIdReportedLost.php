<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A staff member reported their own digital-ID card lost. Sent to HR of the
 * employee's city, who disable or re-issue it from the profile page
 * (docs/ux/access-policy.md §9 A2D-Q5).
 *
 * Carries the name only: no card link, no phone, nothing a forwarded
 * notification could add to what HR already sees on the profile.
 */
class DigitalIdReportedLost extends Notification
{
    use Queueable;

    public function __construct(public Employee $employee) {}

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
                'en' => __('digital_id.notification.title', [], 'en'),
                'ar' => __('digital_id.notification.title', [], 'ar'),
            ],
            'body' => [
                'en' => __('digital_id.notification.body', ['name' => $this->employee->nameForLocale('en')], 'en'),
                'ar' => __('digital_id.notification.body', ['name' => $this->employee->nameForLocale('ar')], 'ar'),
            ],
            'url' => route('employees.show', $this->employee->username),
        ];
    }
}
