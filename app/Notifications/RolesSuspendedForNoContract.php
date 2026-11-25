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
     */
    public function __construct(
        public Employee $employee,
        public array $suspended,
        public array $restored,
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

        return [
            'title' => [
                'en' => $this->suspended !== [] ? 'Roles suspended' : 'Roles restored',
                'ar' => $this->suspended !== [] ? 'إيقاف أدوار' : 'إعادة أدوار',
            ],
            'body' => ['en' => implode(' ', $body['en']), 'ar' => implode(' ', $body['ar'])],
            'url' => route('employees.edit', $this->employee),
            'subject' => ['type' => 'employee', 'id' => $this->employee->id, 'action' => null],
        ];
    }
}
