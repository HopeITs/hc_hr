<?php

namespace App\Notifications;

use App\Models\Employee;
use App\Models\Position;
use Illuminate\Notifications\Notification;

class SharedSeatDeparture extends Notification
{
    public function __construct(public Position $position, public int $contractId, public int $employeeId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $employee = Employee::query()->find($this->employeeId);
        $holderEn = $employee?->nameForLocale('en') ?? 'A holder';
        $holderAr = $employee?->nameForLocale('ar') ?? 'أحد شاغلي الوظيفة';

        return [
            'title' => [
                'en' => 'Shared seat needs an HR decision',
                'ar' => 'انتقال المقعد المشترك يحتاج إلى قرار الموارد البشرية',
            ],
            'body' => [
                'en' => "{$holderEn} left {$this->position->title_en}. Review who should take over their responsibilities.",
                'ar' => "غادر {$holderAr} وظيفة {$this->position->title_ar}. يرجى تحديد من سيتولى المسؤوليات.",
            ],
            'url' => route('positions.index'),
            'subject' => ['type' => 'position', 'id' => $this->position->id, 'contract_id' => $this->contractId, 'action' => null],
        ];
    }
}
