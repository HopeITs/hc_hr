<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The one reminder a pending leave request gets (DEC-05 revision 2, G-C-1):
 * to the direct manager, who decides, and a copy to HR in the requester's
 * city, who follows up. When HR is the one who decides (no direct manager,
 * or every one on leave), HR gets the decide wording instead (G-C-18).
 * In-app only.
 */
class LeaveDecisionReminder extends Notification
{
    use Queueable;

    public function __construct(
        public LeaveRequest $leaveRequest,
        public string $recipientType = 'manager', // manager | hr | hr_decider
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $leaveRequest = $this->leaveRequest;
        $leaveType = $leaveRequest->leaveBalance->leaveType;
        $employee = $leaveRequest->leaveBalance->contract->employee;
        $nameEn = $employee->nameForLocale('en');
        $nameAr = $employee->nameForLocale('ar');
        $start = $leaveRequest->start_at?->format('d-m-Y');

        if ($this->recipientType === 'hr') {
            return [
                'title' => [
                    'en' => 'Leave Request Still Awaiting the Manager',
                    'ar' => 'طلب إجازة ما زال بانتظار قرار المدير',
                ],
                'body' => [
                    'en' => "{$leaveType->name_en} leave request from {$nameEn}, starting {$start}, has no decision yet. The manager was reminded.",
                    'ar' => "طلب إجازة ({$leaveType->name_ar}) من {$nameAr} يبدأ في {$start} ولم يُقرَّر بعد. تم تذكير المدير.",
                ],
                'url' => route('leaves.all', ['leave' => $leaveRequest->id, 'mode' => 'details']),
                'subject' => ['type' => 'leave_request', 'id' => $leaveRequest->id, 'action' => null],
            ];
        }

        if ($this->recipientType === 'hr_decider') {
            return [
                'title' => [
                    'en' => 'Reminder: Leave Request Awaiting Your Decision',
                    'ar' => 'تذكير: طلب إجازة بانتظار قرارك',
                ],
                'body' => [
                    'en' => "{$leaveType->name_en} leave request from {$nameEn}, starting {$start}, is still waiting for a decision. HR may decide it (no direct manager, or the manager is on leave).",
                    'ar' => "طلب إجازة ({$leaveType->name_ar}) من {$nameAr} يبدأ في {$start} وما زال بانتظار القرار. يمكن للموارد البشرية أن تقرّره (لا يوجد مدير مباشر، أو المدير في إجازة).",
                ],
                'url' => route('inbox', ['open' => "leave:{$leaveRequest->id}:decide"]),
                'subject' => ['type' => 'leave_request', 'id' => $leaveRequest->id, 'action' => 'decide'],
            ];
        }

        return [
            'title' => [
                'en' => 'Reminder: Leave Request Awaiting Your Decision',
                'ar' => 'تذكير: طلب إجازة بانتظار قرارك',
            ],
            'body' => [
                'en' => "{$leaveType->name_en} leave request from {$nameEn}, starting {$start}, is still waiting for your decision.",
                'ar' => "طلب إجازة ({$leaveType->name_ar}) من {$nameAr} يبدأ في {$start} وما زال بانتظار قرارك.",
            ],
            'url' => route('inbox', ['open' => "leave:{$leaveRequest->id}:decide"]),
            'subject' => ['type' => 'leave_request', 'id' => $leaveRequest->id, 'action' => 'decide'],
        ];
    }
}
