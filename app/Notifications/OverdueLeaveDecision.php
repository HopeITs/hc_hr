<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Notifications\Notification;

class OverdueLeaveDecision extends Notification
{
    public function __construct(public LeaveRequest $leaveRequest, public bool $canDecide) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $leave = $this->leaveRequest;
        $employee = $leave->leaveBalance->contract->employee;
        $nameEn = $employee->nameForLocale('en');
        $nameAr = $employee->nameForLocale('ar');
        $start = $leave->start_at->format('d-m-Y');

        return [
            'title' => [
                'en' => 'Overdue Leave Request',
                'ar' => 'طلب إجازة متأخر',
            ],
            'body' => [
                'en' => "The leave request from {$nameEn} started on {$start} and is still pending. Please follow up on the decision.",
                'ar' => "بدأت فترة طلب الإجازة المقدّم من {$nameAr} في {$start} ولا يزال الطلب قيد المراجعة. يرجى متابعة اتخاذ القرار.",
            ],
            'url' => $this->canDecide
                ? route('inbox', ['open' => "leave:{$leave->id}:decide"])
                : route('leaves.all', ['leave' => $leave->id, 'mode' => 'details']),
            'subject' => ['type' => 'leave_request', 'id' => $leave->id, 'action' => $this->canDecide ? 'decide' : null],
        ];
    }
}
