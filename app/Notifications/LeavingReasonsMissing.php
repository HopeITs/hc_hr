<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Monthly nudge to HR: people left more than a month ago and their contract
 * still has no termination reason, so the "Why people leave" report is short
 * of figures.
 */
class LeavingReasonsMissing extends Notification
{
    use Queueable;

    public function __construct(public int $count) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => [
                'en' => 'Leavers with no reason recorded',
                'ar' => 'مغادرون بلا سبب مسجّل',
            ],
            'body' => [
                'en' => "{$this->count} contract(s) ended more than a month ago with no termination reason. Add it so the leaving report is complete.",
                'ar' => "{$this->count} عقد(عقود) انتهت منذ أكثر من شهر دون سبب إنهاء مسجّل. أضف السبب ليكتمل تقرير أسباب ترك العمل.",
            ],
            'url' => route('reports.leaving-reasons'),
            'subject' => ['type' => 'leaving_reasons', 'id' => null, 'action' => null],
        ];
    }
}
