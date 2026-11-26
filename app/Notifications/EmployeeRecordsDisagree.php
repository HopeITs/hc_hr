<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells HR Syria that employees, contracts, roles or positions disagree
 * (employees:integrity-check --check, nightly). Counts only; the command's
 * output and the log carry the ids. In-app only.
 */
class EmployeeRecordsDisagree extends Notification
{
    use Queueable;

    private const LINES = [
        'roles' => ['en' => 'inactive employees whose account still has an active role', 'ar' => 'موظفون غير فعّالين ما زال لحسابهم دور فعّال'],
        'ended' => ['en' => 'contracts flagged active after their end date', 'ar' => 'عقود مُعلَّمة فعّالة بعد تاريخ انتهائها'],
        'inactive' => ['en' => 'running contracts of inactive employees', 'ar' => 'عقود سارية لموظفين غير فعّالين'],
        'never_contracted' => ['en' => 'active employees who never had a contract', 'ar' => 'موظفون فعّالون لم يكن لهم عقد قط'],
        'last_contract_ended' => ['en' => 'active employees whose last contract ended', 'ar' => 'موظفون فعّالون انتهى آخر عقد لهم'],
        'no_manager' => ['en' => 'active positions without a direct manager', 'ar' => 'وظائف فعّالة بلا مدير مباشر'],
    ];

    /** @param  array<string, int>  $counts  per check of employees:integrity-check */
    public function __construct(public array $counts) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $body = ['en' => [], 'ar' => []];

        foreach (self::LINES as $kind => $line) {
            if (($n = $this->counts[$kind] ?? 0) > 0) {
                $body['en'][] = "{$n} {$line['en']}";
                $body['ar'][] = "{$line['ar']}: {$n}";
            }
        }

        return [
            'title' => ['en' => 'Employee records disagree', 'ar' => 'بيانات موظفين غير متطابقة'],
            'body' => [
                'en' => implode('; ', $body['en']).'. Run php artisan employees:integrity-check for the list.',
                'ar' => implode('؛ ', $body['ar']).'. القائمة في مخرجات الأمر employees:integrity-check.',
            ],
            'url' => route('employees.index'),
            'subject' => ['type' => 'employee', 'id' => null, 'action' => null],
        ];
    }
}
