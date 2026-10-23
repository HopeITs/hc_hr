<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewEmployeeWelcome extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Employee $employee) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $emp = $this->employee;

        return [
            'title' => [
                'en' => "Welcome $emp->name_en!",
                'ar' => "مرحبًا بـ$emp->name_ar!",
            ],

            'body' => [
                'en' => "Let's welcome $emp->name_en to the team, we’re excited to have them join us!",
                'ar' => "أهلًا وسهلًا بـ$emp->name_ar في فريقنا!",
            ],
            'url' => route('employees.show', $this->employee->username),
        ];
    }
}
