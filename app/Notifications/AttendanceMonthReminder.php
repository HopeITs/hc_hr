<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A nudge at the start of a month: a manager has timesheets to approve (and days to answer), HR has
 * approved months to lock. Sent each morning of the first days of the month until it is done.
 */
class AttendanceMonthReminder extends Notification
{
    use Queueable;

    public const APPROVE = 'approve';

    public const LOCK = 'lock';

    /**
     * @param  'approve'|'lock'  $kind
     */
    public function __construct(
        public string $kind,
        public string $month,
        public int $count,
        public int $toDecide = 0,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $lock = $this->kind === self::LOCK;

        return [
            'title' => [
                'en' => $lock ? 'Timesheets ready to lock' : 'Timesheets to approve',
                'ar' => $lock ? 'جداول دوام جاهزة للقفل' : 'جداول دوام بانتظار اعتمادك',
            ],
            'body' => [
                'en' => $lock
                    ? "{$this->count} approved timesheet(s) for {$this->month} can be locked."
                    : "{$this->count} timesheet(s) for {$this->month} are ready to approve".($this->toDecide > 0 ? ", and {$this->toDecide} day(s) wait for your answer." : '.'),
                'ar' => $lock
                    ? "يمكن قفل {$this->count} جدول دوام معتمد عن شهر {$this->month}."
                    : "{$this->count} جدول دوام عن شهر {$this->month} جاهز للاعتماد".($this->toDecide > 0 ? "، و{$this->toDecide} يوم بانتظار قرارك." : '.'),
            ],
            'kind' => $this->kind,
            'month' => $this->month,
            'count' => $this->count,
            'to_decide' => $this->toDecide,
            'url' => route('attendance.timesheets', ['month' => $this->month], false),
        ];
    }
}
