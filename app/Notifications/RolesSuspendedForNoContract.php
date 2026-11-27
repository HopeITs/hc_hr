<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells HR Syria that a raised role (HR, general manager, administrator) was
 * stood down because its holder has no running contract, or given back when
 * one runs again (App\Services\Access\OrgRoleSync). In-app only.
 */
class RolesSuspendedForNoContract extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $suspended
     * @param  list<string>  $restored
     * @param  list<string>  $inherited  given with the position its last holder left
     */
    public function __construct(
        public Employee $employee,
        public array $suspended,
        public array $restored,
        public array $inherited = [],
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $en = $this->employee->nameForLocale('en');
        $ar = $this->employee->nameForLocale('ar');
        $body = ['en' => [], 'ar' => []];

        if ($this->suspended !== []) {
            $roles = implode(', ', $this->suspended);
            $body['en'][] = "{$en} has no running contract, so these roles were suspended: {$roles}. They come back by themselves when a contract runs again.";
            $body['ar'][] = "لا يوجد عقد ساري لـ {$ar}، فأُوقفت هذه الأدوار: {$roles}. تعود تلقائياً عندما يسري عقد جديد.";
        }

        if ($this->restored !== []) {
            $roles = implode(', ', $this->restored);
            $body['en'][] = "{$en} has a running contract again, so these roles were restored: {$roles}.";
            $body['ar'][] = "عاد عقد {$ar} سارياً، فأُعيدت هذه الأدوار: {$roles}.";
        }

        if ($this->inherited !== []) {
            $roles = implode(', ', $this->inherited);
            $body['en'][] = "{$en} took over a position whose previous holder had these roles, so they were given: {$roles}. Take them away from Access Management if they should not have them.";
            $body['ar'][] = "استلم {$ar} منصباً كان لشاغله السابق هذه الأدوار، فأُعطيت له: {$roles}. يمكن سحبها من إدارة الصلاحيات إن لم تكن مناسبة.";
        }

        return [
            'title' => [
                'en' => match (true) {
                    $this->suspended !== [] => 'Roles suspended',
                    $this->restored !== [] => 'Roles restored',
                    default => 'Roles given with a position',
                },
                'ar' => match (true) {
                    $this->suspended !== [] => 'إيقاف أدوار',
                    $this->restored !== [] => 'إعادة أدوار',
                    default => 'أدوار مع المنصب',
                },
            ],
            'body' => ['en' => implode(' ', $body['en']), 'ar' => implode(' ', $body['ar'])],
            'url' => route('employees.edit', $this->employee),
            'subject' => ['type' => 'employee', 'id' => $this->employee->id, 'action' => null],
        ];
    }
}
