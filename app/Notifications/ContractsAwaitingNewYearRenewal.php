<?php

namespace App\Notifications;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * To city HR: auto-renewing contracts that ended in October to December and
 * will be renewed on 1 January (ContractAutoRenewalService::waitsForNewYear),
 * so the volunteers in between are not a surprise. Sent once per contract.
 */
class ContractsAwaitingNewYearRenewal extends Notification
{
    use Queueable;

    /** @var array<int, int> */
    public array $contractIds;

    /** @var array<int, array{en: string, ar: string, ended_on: string}> */
    public array $employees;

    public string $renewsOn;

    /**
     * @param  Collection<int, Contract>  $contracts
     */
    public function __construct(Collection $contracts)
    {
        $this->contractIds = $contracts->pluck('id')->values()->all();

        $this->employees = $contracts
            ->map(fn (Contract $contract): array => [
                'en' => trim("{$contract->employee?->first_name_en} {$contract->employee?->last_name_en}"),
                'ar' => trim("{$contract->employee?->first_name_ar} {$contract->employee?->last_name_ar}"),
                'ended_on' => $contract->end_date->toDateString(),
            ])
            ->values()
            ->all();

        $this->renewsOn = $contracts->first()?->end_date?->copy()->addYear()->startOfYear()->toDateString() ?? '';

        // See ContractsAutoRenewedSummary: the email is framed in Arabic.
        $this->locale = 'ar';
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';

        $message = (new MailMessage)
            ->subject($data['title'][$locale])
            ->line($data['body'][$locale]);

        foreach ($this->employees as $employee) {
            $name = $employee[$locale] ?: $employee['en'];
            $message->line($locale === 'ar'
                ? "- {$name} (انتهى في {$employee['ended_on']})"
                : "- {$name} (ended {$employee['ended_on']})");
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $count = count($this->contractIds);
        $namesEn = implode(', ', array_column($this->employees, 'en'));
        $namesAr = implode('، ', array_column($this->employees, 'ar'));

        return [
            'title' => [
                'en' => 'Contracts ended, renewing in the new year',
                'ar' => 'عقود انتهت وستتجدد مع بداية السنة',
            ],
            'body' => [
                'en' => "{$count} contract(s) have ended and will be renewed automatically on {$this->renewsOn}: {$namesEn}.",
                'ar' => "انتهت العقود التالية ({$count}) وستتجدد تلقائياً في {$this->renewsOn}: {$namesAr}.",
            ],
            'contract_ids' => $this->contractIds,
            'employee_names' => array_map(fn (array $e) => ['en' => $e['en'], 'ar' => $e['ar']], $this->employees),
            'renews_on' => $this->renewsOn,
        ];
    }
}
