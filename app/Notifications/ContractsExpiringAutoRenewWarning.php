<?php

namespace App\Notifications;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class ContractsExpiringAutoRenewWarning extends Notification
{
    use Queueable;

    /**
     * @var array<int, array{id: int, employee_name_en: string, employee_name_ar: string, end_date: string}>
     */
    public array $contracts;

    /**
     * @param  Collection<int, Contract>  $contracts
     */
    public function __construct(
        Collection $contracts,
        public int $daysUntilExpiration = 7,
    ) {
        $this->contracts = $contracts
            ->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'employee_name_en' => $contract->employee?->nameForLocale('en') ?? '',
                'employee_name_ar' => $contract->employee?->nameForLocale('ar') ?? '',
                'end_date' => $contract->end_date?->toDateString() ?? '',
            ])
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
        // See ContractsAutoRenewedSummary::toMail(): one language per email.
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';

        return (new MailMessage)
            ->subject($data['title'][$locale])
            ->line($data['body'][$locale]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $count = count($this->contracts);

        return [
            'title' => [
                'en' => $count === 1 ? 'Contract renewal reminder' : 'Contract renewal reminders',
                'ar' => $count === 1 ? 'تذكير بتجديد عقد' : 'تذكير بتجديد عقود',
            ],
            'body' => [
                'en' => $count === 1
                    ? "Automatic renewal is enabled for {$this->contracts[0]['employee_name_en']}'s contract, which ends on {$this->contracts[0]['end_date']} in {$this->daysUntilExpiration} days. If you want to cancel automatic renewal and not renew this contract, please inform Governorate HR."
                    : "Automatic renewal is enabled for the following {$count} contracts ending in {$this->daysUntilExpiration} days: {$this->contractListInline('en')}. If you want to cancel automatic renewal and not renew any of them, please inform Governorate HR.",
                'ar' => $count === 1
                    ? $this->singleContractBodyAr()
                    : $this->multipleContractsBodyAr($count),
            ],
            'contracts' => $this->contracts,
            'days_until_expiration' => $this->daysUntilExpiration,
        ];
    }

    private function singleContractBodyAr(): string
    {
        $contract = $this->contracts[0];
        $name = $contract['employee_name_ar'] ?: $contract['employee_name_en'];
        $date = $contract['end_date'];

        if ($this->daysUntilExpiration === 7) {
            return "عقد المتطوع {$name} لديه خاصية التجديد التلقائي مفعّلة، وسينتهي في الأسبوع القادم بتاريخ {$date}. في حال رغبتك بإلغاء خاصية التجديد التلقائي وعدم تجديد العقد يرجى إعلام مسؤول الموارد البشرية ضمن محافظتك";
        }

        return "عقد المتطوع {$name} لديه خاصية التجديد التلقائي مفعّلة، وسينتهي بعد {$this->daysAr()} بتاريخ {$date}. في حال رغبتك بإلغاء خاصية التجديد التلقائي وعدم تجديد العقد يرجى إعلام مسؤول الموارد البشرية ضمن محافظتك";
    }

    private function multipleContractsBodyAr(int $count): string
    {
        $list = $this->contractListInline('ar');

        if ($this->daysUntilExpiration === 7) {
            return "العقود التالية لديها خاصية التجديد التلقائي مفعّلة وستنتهي خلال الأسبوع القادم: {$list}. في حال رغبتك بإلغاء خاصية التجديد التلقائي لأي من هذه العقود وعدم تجديدها يرجى إعلام مسؤول الموارد البشرية ضمن محافظتك";
        }

        return "العقود التالية لديها خاصية التجديد التلقائي مفعّلة وستنتهي بعد {$this->daysAr()}: {$list}. في حال رغبتك بإلغاء خاصية التجديد التلقائي لأي من هذه العقود وعدم تجديدها يرجى إعلام مسؤول الموارد البشرية ضمن محافظتك";
    }

    /** "30 يومًا" / "3 أيام" with the right Arabic plural form. */
    private function daysAr(): string
    {
        return trans_choice('general.count.days', $this->daysUntilExpiration, [], 'ar');
    }

    private function contractListInline(string $locale): string
    {
        return collect($this->contracts)
            ->map(fn (array $contract): string => sprintf(
                '%s (%s)',
                $contract["employee_name_{$locale}"] ?: $contract['employee_name_en'],
                $contract['end_date'],
            ))
            ->implode($locale === 'ar' ? '، ' : '; ');
    }
}
