<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewLeaveRequest extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public LeaveRequest $leaveRequest)
    {
        //
    }

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
        $employee = $leaveRequest->leaveBalance->contract->employee;

        $leaveType = $leaveRequest->leaveBalance->leaveType;
        $employeeNameEn = $employee->nameForLocale('en');
        $employeeNameAr = $employee->nameForLocale('ar');

        return [
            'title' => [
                'en' => 'New Leave Request',
                'ar' => 'طلب إجازة جديد',
            ],

            'body' => [
                'en' => "{$leaveType->name_en} leave request from {$employeeNameEn}",
                'ar' => "طلب إجازة ({$leaveType->name_ar}) من {$employeeNameAr}",
            ],

            // A direct manager may decide without the All leaves page
            // (DEC-05 revision 2): they open the request in the work inbox.
            'url' => $notifiable instanceof \App\Models\User && ! $notifiable->hasActivePermission('leaves.view-all')
                ? route('inbox', ['open' => "leave:{$leaveRequest->id}:decide"])
                : route('leaves.all', [
                    'leave' => $leaveRequest->id,
                    'mode' => 'decision',
                ]),
            'subject' => ['type' => 'leave_request', 'id' => $leaveRequest->id, 'action' => 'decide'],
        ];
    }
}
