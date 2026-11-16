<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\MessageTemplate;
use App\Models\User;

/**
 * Fills a MessageTemplate in for one person and builds the link that opens it
 * as a new message (mailto: or wa.me). Nothing is sent from the server: the
 * user reads and edits the message first.
 *
 * What a variable may reveal follows what the viewer may already see. Name,
 * position, city and manager are Directory tier, like the org chart. The
 * contract and leave variables resolve only for a contract the viewer's city
 * scope covers (Contract::visibleTo) and are empty otherwise. Pay is never a
 * variable.
 */
class MessageTemplateRenderer
{
    /** The placeholders a template may use, for the editor's help text. */
    public const VARIABLES = [
        'name', 'first_name', 'position', 'city', 'manager_name',
        'contract_end_date', 'contract_number', 'leave_days',
        'hr_phone', 'sender_name', 'today',
    ];

    public function __construct(private readonly LeaveRequestPricing $pricing) {}

    /**
     * @return array<string, string>
     */
    public function variables(Employee $employee, ?User $viewer): array
    {
        $employee->loadMissing('city');

        $contract = $this->contractFor($employee, $viewer);
        $position = $contract?->position;
        $manager = $position?->directManager?->employee();

        return [
            'name' => (string) $employee->name,
            'first_name' => (string) $employee->first_name,
            'position' => (string) ($position?->title ?? ''),
            'city' => (string) ($employee->city?->name ?? ''),
            'manager_name' => (string) ($manager?->name ?? ''),
            'contract_end_date' => $contract?->end_date?->format('d/m/Y') ?? '',
            'contract_number' => $contract ? (string) $contract->id : '',
            'leave_days' => $contract ? $this->annualLeaveDays($contract) : '',
            'hr_phone' => (string) ($employee->city?->hr_hotline ?? ''),
            'sender_name' => (string) ($viewer?->profile?->name ?? ''),
            'today' => now()->format('d/m/Y'),
        ];
    }

    /** Replace every known {placeholder}; an unknown one is left as written. */
    public function fill(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            fn (array $match): string => array_key_exists($match[1], $variables) ? $variables[$match[1]] : $match[0],
            $text,
        );
    }

    /**
     * The link that opens this template for this person, or null when the
     * person has no address for its channel.
     */
    public function url(MessageTemplate $template, Employee $employee, ?User $viewer): ?string
    {
        $variables = $this->variables($employee, $viewer);
        $body = $this->fill($template->body, $variables);

        if ($template->channel === MessageTemplate::WHATSAPP) {
            $number = $this->whatsappNumber($employee);

            return $number ? 'https://wa.me/'.$number.'?text='.rawurlencode($body) : null;
        }

        $to = $employee->work_email;

        if (! filled($to) || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $query = [];

        if ($template->cc_manager) {
            $cc = Employee::directManagerEmails([$employee])[$employee->id] ?? null;

            if ($cc && strcasecmp($cc, $to) !== 0 && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                $query[] = 'cc='.rawurlencode($cc);
            }
        }

        $query[] = 'subject='.rawurlencode($this->fill((string) $template->subject, $variables));
        $query[] = 'body='.rawurlencode(str_replace("\n", "\r\n", $body));

        return 'mailto:'.$to.'?'.implode('&', $query);
    }

    /**
     * Digits for wa.me, from the work WhatsApp number or, failing that, the
     * work phone. A local Syrian number (09…) becomes international (963 9…),
     * as the dashboard's Telegram link does.
     */
    public function whatsappNumber(Employee $employee): ?string
    {
        $raw = filled($employee->whatsapp_work) ? $employee->whatsapp_work : $employee->work_phone;
        $digits = preg_replace('/\D+/', '', (string) $raw);

        if ($digits === '') {
            return null;
        }

        return match (true) {
            str_starts_with($digits, '00') => substr($digits, 2),
            str_starts_with($digits, '0') => '963'.substr($digits, 1),
            default => $digits,
        };
    }

    private function contractFor(Employee $employee, ?User $viewer): ?Contract
    {
        if (! $viewer) {
            return null;
        }

        return Contract::query()
            ->visibleTo($viewer)
            ->activeNow()
            ->where('employee_id', $employee->id)
            ->with(['position.directManager.activeContracts.employee'])
            ->latest('start_date')
            ->first();
    }

    /** Annual leave left, in whole or half days, or '' when it cannot be told. */
    private function annualLeaveDays(Contract $contract): string
    {
        $balance = $contract->leaveBalances()->with('leaveType')->get()
            ->first(fn ($balance) => $balance->isAnnual());
        $perDay = $this->pricing->hoursPerDayFor($contract);

        if (! $balance || ! $perDay) {
            return '';
        }

        $days = max(0, $balance->availableHours()) / $perDay;

        return rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.') ?: '0';
    }
}
