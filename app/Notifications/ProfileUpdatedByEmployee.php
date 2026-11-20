<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An employee changed their own profile (meeting 19/11, track 2). Sent to HR
 * of the employee's city by ProfileEditNotifier, which also writes the lines:
 * one per changed field or added entry, already in both languages, with a
 * sensitive value reported as "changed" only.
 */
class ProfileUpdatedByEmployee extends Notification
{
    use Queueable;

    /**
     * @param  array<int, array{en: string, ar: string}>  $lines
     */
    public function __construct(public Employee $employee, public array $lines) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $name = [
            'en' => $this->employee->nameForLocale('en'),
            'ar' => $this->employee->nameForLocale('ar'),
        ];

        return [
            'title' => [
                'en' => "{$name['en']} updated their profile",
                'ar' => "عدّل {$name['ar']} ملفه الشخصي",
            ],
            'body' => [
                'en' => implode('; ', array_column($this->lines, 'en')),
                'ar' => implode('؛ ', array_column($this->lines, 'ar')),
            ],
            'changes' => $this->lines,
            'url' => route('employees.show', $this->employee->username),
            'subject' => ['type' => 'employee_profile', 'id' => $this->employee->id, 'action' => null],
        ];
    }
}
