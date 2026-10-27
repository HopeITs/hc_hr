<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * HR objected to a decided leave request (C3 G3): it is pending again, back
 * with the direct manager, with HR's note. The manager is asked to decide
 * again; the requester is told why their request is pending once more. The
 * note is visible to both by decision (DEC-05 revision, G5).
 */
class LeaveRequestObjected extends Notification
{
    use Queueable;

    public function __construct(
        public LeaveRequest $leaveRequest,
        public string $note,
        public string $recipientType = 'manager', // manager | employee
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
        $note = Str::limit($this->note, 160);

        if ($this->recipientType === 'employee') {
            return [
                'title' => [
                    'en' => 'HR sent your leave request back to your manager',
                    'ar' => 'أعادت الموارد البشرية طلب إجازتك إلى مديرك',
                ],
                'body' => [
                    'en' => "Your {$leaveType->name_en} leave request is pending again. HR's note: {$note}",
                    'ar' => "طلب إجازتك ({$leaveType->name_ar}) بانتظار القرار من جديد. ملاحظة الموارد البشرية: {$note}",
                ],
                'url' => route('leaves.index', ['leave' => $this->leaveRequest->id]),
                'subject' => ['type' => 'leave_request', 'id' => $this->leaveRequest->id, 'action' => null],
            ];
        }

        return [
            'title' => [
                'en' => 'HR objected to a leave decision',
                'ar' => 'اعترضت الموارد البشرية على قرار إجازة',
            ],
            'body' => [
                'en' => "{$employee->nameForLocale('en')}'s {$leaveType->name_en} leave request is back with you to decide. HR's note: {$note}",
                'ar' => "عاد إليك طلب إجازة ({$leaveType->name_ar}) للموظف {$employee->nameForLocale('ar')} لتقرّر فيه من جديد. ملاحظة الموارد البشرية: {$note}",
            ],
            'url' => route('inbox', ['open' => 'leave:'.$this->leaveRequest->id.':decide']),
            'subject' => ['type' => 'leave_request', 'id' => $this->leaveRequest->id, 'action' => 'decide'],
        ];
    }
}
