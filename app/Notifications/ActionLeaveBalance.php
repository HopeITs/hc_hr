<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ActionLeaveBalance extends Notification
{
    use Queueable;

    public function __construct(
        public $balance,
        public string $operation, // sum | sub
        public string $unit,      // day | hour
        public float $amount,
        public ?string $notes,
        public string $recipientType, // employee | manager
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->balance->contract->employee;
        $employeeNameAr = $employee->name_ar;
        $employeeNameEn = $employee->name_en;

        $isAdd = $this->operation === 'sum';

        $amountLabelAr = $this->amountLabel('ar');
        $amountLabelEn = $this->amountLabel('en');

        // رسائل الموظف
        if ($this->recipientType === 'employee') {
            return [
                'title' => [
                    'en' => $isAdd ? 'Leave Balance Increased' : 'Leave Balance Deducted',
                    'ar' => $isAdd ? 'تمت زيادة رصيد الإجازات' : 'تم الخصم من رصيد الإجازات',
                ],
                'body' => [
                    'en' => $isAdd
                        ? "Your annual leave balance has been increased by {$amountLabelEn}."
                        : "Your annual leave balance has been decreased by {$amountLabelEn}.",
                    'ar' => $isAdd
                        ? "تمت إضافة {$amountLabelAr} إلى رصيد إجازاتك السنوية."
                        : "تم خصم {$amountLabelAr} من رصيد إجازاتك السنوية.",
                ],
                'url' => route('leaves.index'),
            ];
        }

        // رسائل المدير
        if ($this->recipientType === 'manager') {
            return [
                'title' => [
                    'en' => $isAdd ? 'Employee Leave Balance Increased' : 'Employee Leave Balance Deducted',
                    'ar' => $isAdd ? 'زيادة رصيد إجازات موظف' : 'خصم من رصيد إجازات موظف',
                ],
                'body' => [
                    'en' => $isAdd
                        ? "HR increased the annual leave balance of {$employeeNameEn} by {$amountLabelEn}."
                        : "HR deducted {$amountLabelEn} from the annual leave balance of {$employeeNameEn}.",
                    'ar' => $isAdd
                        ? "قام قسم الموارد البشرية بزيادة رصيد الإجازات السنوية للموظف {$employeeNameAr} بمقدار {$amountLabelAr}."
                        : "قام قسم الموارد البشرية بخصم {$amountLabelAr} من رصيد الإجازات السنوية للموظف {$employeeNameAr}.",
                ],

            ];
        }

        return [];
    }

    /**
     * "3 days" / "3 أيام" in the given language, with the right plural form.
     * A fractional amount has no plural category, so it keeps the plain unit.
     */
    private function amountLabel(string $locale): string
    {
        $unit = $this->unit === 'day' ? 'days' : 'hours';
        $number = (float) $this->amount;

        if (fmod($number, 1.0) === 0.0) {
            return trans_choice("general.count.{$unit}", (int) $number, [], $locale);
        }

        return $this->amount.' '.match ([$unit, $locale]) {
            ['days', 'ar'] => 'يوم',
            ['hours', 'ar'] => 'ساعة',
            ['days', 'en'] => 'days',
            default => 'hours',
        };
    }
}
