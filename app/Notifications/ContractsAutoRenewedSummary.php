<?php

namespace App\Notifications;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class ContractsAutoRenewedSummary extends Notification
{
    use Queueable;

    /**
     * @var array<int, int>
     */
    public array $contractIds;

    /**
     * @var array<int, array{en: string, ar: string}>
     */
    public array $employeeNames;

    /**
     * @param  Collection<int, Contract>  $contracts
     */
    public function __construct(Collection $contracts)
    {
        $this->contractIds = $contracts->pluck('id')->values()->all();

        $this->employeeNames = $contracts
            ->map(function (Contract $contract): array {
                $employee = $contract->employee;

                return [
                    'en' => trim("{$employee?->first_name_en} {$employee?->last_name_en}"),
                    'ar' => trim("{$employee?->first_name_ar} {$employee?->last_name_ar}"),
                ];
            })
            ->values()
            ->all();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        $message = (new MailMessage)
            ->subject($data['title']['ar'])
            ->line($data['body']['ar']);

        foreach ($data['employee_names'] as $name) {
            $message->line('- '.($name['ar'] ?: $name['en']));
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $count = count($this->contractIds);
        $namesEn = implode(', ', array_column($this->employeeNames, 'en'));
        $namesAr = implode('، ', array_column($this->employeeNames, 'ar'));

        return [
            'title' => [
                'en' => 'Contracts renewed',
                'ar' => 'تم تجديد العقود',
            ],
            'body' => [
                'en' => "{$count} contract(s) were renewed successfully today: {$namesEn}.",
                'ar' => "تم تجديد {$count} عقد بنجاح اليوم: {$namesAr}.",
            ],
            'contract_ids' => $this->contractIds,
            'employee_names' => $this->employeeNames,
        ];
    }
}
