<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HRSawLeaveRequest extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public LeaveRequest $leaveRequest,
        public string $recipientType = 'employee',// employee | manager
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->line('The introduction to the notification.')
            ->action('Notification Action', url('/'))
            ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $leaveRequest = $this->leaveRequest;
        $leaveType = $leaveRequest->leaveBalance->leaveType;

        $employee = $leaveRequest->leaveBalance->contract->employee;
        $employeeNameEn = $employee->nameForLocale('en');
        $employeeNameAr = $employee->nameForLocale('ar');

        if ($this->recipientType === 'employee') {
            return [
                'title' => [
                    'en' => 'HR Reviewed Your Leave Request',
                    'ar' => 'قام قسم الموارد البشرية بمراجعة طلب إجازتك',
                ],
                'body' => [
                    'en' => "HR reviewed your leave request: {$leaveType->name_en}",
                    'ar' => "قام قسم الموارد البشرية بمراجعة طلب إجازتك من نوع: {$leaveType->name_ar}",
                ],
                'url' => route('leaves.index', ['leave' => $leaveRequest->id]),
                'subject' => ['type' => 'leave_request', 'id' => $leaveRequest->id, 'action' => null],
            ];
        }

        if ($this->recipientType === 'manager') {
            return [
                'title' => [
                    'en' => 'HR Reviewed Leave Request',
                    'ar' => 'راجع قسم الموارد البشرية طلب إجازة',
                ],
                'body' => [
                    'en' => "HR reviewed {$leaveType->name_en} leave request for employee: {$employeeNameEn}",
                    'ar' => "قام قسم الموارد البشرية بمراجعة طلب إجازة ({$leaveType->name_ar}) للموظف: {$employeeNameAr}",
                ],
                'url' => route('leaves.all', [
                    'leave' => $leaveRequest->id,
                    'mode' => 'details',
                ]),
                'subject' => ['type' => 'leave_request', 'id' => $leaveRequest->id, 'action' => null],
            ];
        }

        return [];
    }
}
