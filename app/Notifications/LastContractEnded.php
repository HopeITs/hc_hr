<?php

namespace App\Notifications;

use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The one heads-up city HR gets when a volunteer's last real contract has
 * ended (DEC-09 increment 2): they will be deactivated automatically after the
 * grace period unless a new contract starts. In-app only; no pay, no contract
 * terms.
 */
class LastContractEnded extends Notification
{
    use Queueable;

    public function __construct(
        public Employee $employee,
        public int $contractId,
        public CarbonInterface $endedOn,
        public CarbonInterface $deactivateOn,
        public ?int $journeyId = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $ended = $this->endedOn->format('d-m-Y');
        $deactivate = $this->deactivateOn->format('d-m-Y');

        return [
            'title' => [
                'en' => 'Last Contract Ended',
                'ar' => 'انتهى العقد الأخير',
            ],
            'body' => [
                'en' => "{$this->employee->nameForLocale('en')}'s last contract ended on {$ended}. They will be deactivated on {$deactivate} unless a new contract starts.",
                'ar' => "انتهى العقد الأخير لـ {$this->employee->nameForLocale('ar')} في {$ended}. سيُلغى تفعيله في {$deactivate} ما لم يبدأ عقد جديد.",
            ],
            'url' => route('employees.edit', $this->employee),
            'subject' => ['type' => 'offboarding_journey', 'id' => $this->journeyId, 'action' => null],
        ];
    }
}
