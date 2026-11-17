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

        // The email is written in Arabic: frame it right-to-left, in Arabic.
        $this->locale = 'ar';
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);
        // The sender renders mail in the recipient's preferredLocale(), or the
        // app default when they have none, so the whole email (greeting
        // included) is in one language.
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';

        $message = (new MailMessage)
            ->subject($data['title'][$locale])
            ->line($data['body'][$locale]);

        foreach ($data['employee_names'] as $name) {
            $message->line('- '.($name[$locale] ?: $name['en']));
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
                'ar' => "تم اليوم تجديد العقود التالية بنجاح ({$count}): {$namesAr}.",
            ],
            'contract_ids' => $this->contractIds,
            'employee_names' => $this->employeeNames,
        ];
    }
}
