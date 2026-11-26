<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An accepted leave was withdrawn before it started (HR-027): its hours went
 * back to the balance. The employee is told when HR cancelled it for them;
 * the direct manager and the city's HR are told so they no longer plan on it.
 */
class LeaveRequestCancelled extends Notification
{
    use Queueable;

    public function __construct(
        public LeaveRequest $leaveRequest,
        public string $recipientType = 'manager', // employee | manager | hr
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $leaveType = $this->leaveRequest->leaveBalance->leaveType;
        $employee = $this->leaveRequest->leaveBalance->contract->employee;
        $startsOn = $this->leaveRequest->start_at?->format('Y-m-d') ?? '';
        $subject = ['type' => 'leave_request', 'id' => $this->leaveRequest->id, 'action' => null];

        if ($this->recipientType === 'employee') {
            return [
                'title' => [
                    'en' => 'Your leave was cancelled',
                    'ar' => 'أُلغيت إجازتك',
                ],
                'body' => [
                    'en' => "HR cancelled your {$leaveType->name_en} leave starting {$startsOn}. Its hours are back in your balance.",
                    'ar' => "ألغت الموارد البشرية إجازتك ({$leaveType->name_ar}) التي تبدأ في {$startsOn}، وعادت ساعاتها إلى رصيدك.",
                ],
                'url' => route('leaves.index', ['leave' => $this->leaveRequest->id]),
                'subject' => $subject,
            ];
        }

        return [
            'title' => [
                'en' => 'A leave was cancelled',
                'ar' => 'أُلغيت إجازة',
            ],
            'body' => [
                'en' => "{$employee->nameForLocale('en')}'s {$leaveType->name_en} leave starting {$startsOn} was cancelled.",
                'ar' => "أُلغيت إجازة ({$leaveType->name_ar}) للموظف {$employee->nameForLocale('ar')} التي كانت تبدأ في {$startsOn}.",
            ],
            'url' => route('leaves.all', [
                'leave' => $this->leaveRequest->id,
                'mode' => 'details',
            ]),
            'subject' => $subject,
        ];
    }
}
