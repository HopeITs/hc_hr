<?php

namespace App\Notifications;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use LogicException;

class ActionLeaveRequest extends Notification
{
    use Queueable;

    public function __construct(
        public LeaveRequest $leaveRequest,
        public string $recipientType = 'employee',// employee | hr
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $leaveRequest = $this->leaveRequest;
        $leaveType = $leaveRequest->leaveBalance->leaveType;
        $status = $leaveRequest->status;

        $isAccepted = match ($status) {
            LeaveRequestStatus::Accepted => true,
            LeaveRequestStatus::Rejected => false,
            default => throw new LogicException(
                "Cannot create a leave decision notification for status [{$status}].",
            ),
        };

        $rejectReason = trim((string) $leaveRequest->reject_reason);

        if (! $isAccepted && $rejectReason === '') {
            throw new LogicException('A rejected leave notification requires a rejection reason.');
        }

        if ($this->recipientType === 'employee') {
            return [
                'title' => [
                    'en' => $isAccepted ? 'Leave Approved' : 'Leave Rejected',
                    'ar' => $isAccepted ? 'تم قبول الإجازة' : 'تم رفض الإجازة',
                ],
                'body' => [
                    'en' => $isAccepted
                        ? "Your leave request has been approved: {$leaveType->name_en}"
                        : "Your leave request has been rejected: {$leaveType->name_en} — Reason: {$rejectReason}",
                    'ar' => $isAccepted
                        ? "تم قبول طلب إجازتك من نوع: {$leaveType->name_ar}"
                        : "تم رفض طلب إجازتك من نوع: {$leaveType->name_ar} — سبب الرفض: {$rejectReason}",
                ],
                'url' => route('leaves.index', ['leave' => $leaveRequest->id]),
                'subject' => ['type' => 'leave_request', 'id' => $leaveRequest->id, 'action' => null],
            ];
        }

        if ($this->recipientType === 'hr') {
            $employee = $leaveRequest->leaveBalance->contract->employee;
            $employeeNameEn = $employee->nameForLocale('en');
            $employeeNameAr = $employee->nameForLocale('ar');

            return [
                'title' => [
                    'en' => $isAccepted
                        ? 'Leave Request Approved'
                        : 'Leave Request Rejected',

                    'ar' => $isAccepted
                        ? 'تم قبول طلب إجازة'
                        : 'تم رفض طلب إجازة',
                ],

                'body' => [
                    'en' => $isAccepted
                        ? "{$leaveType->name_en} leave has been approved for employee: {$employeeNameEn}"
                        : "{$leaveType->name_en} leave has been rejected for employee: {$employeeNameEn} — Reason: {$rejectReason}",

                    'ar' => $isAccepted
                        ? "تم قبول إجازة ({$leaveType->name_ar}) للموظف: {$employeeNameAr}"
                        : "تم رفض إجازة ({$leaveType->name_ar}) للموظف: {$employeeNameAr} — سبب الرفض: {$rejectReason}",
                ],

                'url' => route('leaves.all', [
                    'leave' => $leaveRequest->id,
                    'mode' => 'details',
                ]),
                'subject' => ['type' => 'leave_request', 'id' => $leaveRequest->id, 'action' => 'review'],
            ];
        }

        // fallback (optional)
        return [];
    }
}
