<?php

namespace App\Services;

use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;

/**
 * "Don't renew": the mailto: link a manager uses to ask HR to cancel the
 * renewal of one of their team's contracts. It opens a new message (nothing is
 * sent from the server), addressed to the HR of the employee's city, with the
 * sender's own direct manager on CC, a fixed subject and a message that already
 * says what is needed. HR decides in the renewal journey as before.
 *
 * Reads names, position, city and dates only. Pay is never loaded.
 */
class ContractNonRenewalMail
{
    /** Is $viewer the direct manager of the contract's position, today? */
    public function isManagerOf(Contract $contract, ?User $viewer): bool
    {
        if (! $viewer) {
            return false;
        }

        $contract->loadMissing('position.directManager.activeContracts.employee.account');

        $manager = $contract->position?->directManager?->employee()?->account;

        return $manager !== null && $manager->is($viewer);
    }

    /**
     * The mailto: URL, or null when no HR address can be found for the city
     * (the caller then shows no link rather than a message to nobody).
     */
    public function url(Contract $contract, ?User $sender = null): ?string
    {
        $contract->loadMissing([
            'employee.city',
            'employee.account',
            'position.city',
            'position.center.city',
            'position.directManager.activeContracts.employee.account',
        ]);

        $employee = $contract->employee;

        if (! $employee) {
            return null;
        }

        $city = $employee->city ?? $contract->position?->city ?? $contract->position?->center?->city;
        $to = $this->hrEmails($city?->slug);

        if ($to === []) {
            return null;
        }

        // CC: the direct manager of whoever presses the button (Judy presses
        // for Mai: HR is the To, Judy's own manager is the CC), not the
        // employee's manager, who is the sender.
        $cc = $sender?->profile ? (Employee::directManagerEmails([$sender->profile])[$sender->profile->id] ?? null) : null;
        $cc = filled($cc) && filter_var($cc, FILTER_VALIDATE_EMAIL) && ! in_array(strtolower($cc), array_map('strtolower', $to), true)
            ? $cc
            : null;

        $name = $employee->name;
        $position = (string) $contract->position?->title;
        $senderName = $sender?->profile?->name;

        $subject = __('renewal.dont_renew.subject', ['name' => $name, 'position' => $position]);
        $body = __('renewal.dont_renew.body', [
            'name' => $name,
            'position' => $position,
            'city' => (string) $city?->name,
            'end' => $contract->end_date?->format('d/m/Y') ?? '',
            'number' => $contract->id,
            'sender' => $senderName ?? '',
        ]);

        // The addresses stay literal (the notification link filter expects an
        // @ in the recipient list); everything after the ? is encoded.
        $query = array_filter([
            'cc' => $cc ? rawurlencode($cc) : null,
            'subject' => rawurlencode($subject),
            'body' => rawurlencode(str_replace("\n", "\r\n", $body)),
        ]);

        return 'mailto:'.implode(',', $to).'?'.collect($query)->map(fn ($v, $k) => "$k=$v")->implode('&');
    }

    /**
     * Work emails of the active HR users responsible for the city, falling
     * back to national HR when the city has none (as the ID-card flow does).
     *
     * @return array<int, string>
     */
    private function hrEmails(?string $slug): array
    {
        $emails = $this->emailsForRoles($slug ? City::hrRolesForSlug($slug) : []);

        return $emails !== [] ? $emails : $this->emailsForRoles(['HR-'.City::NATIONAL_SLUG]);
    }

    /**
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    private function emailsForRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))
            ->whereHas('profile', fn (\Illuminate\Database\Eloquent\Builder $employee) => $employee->where('is_active', true))
            ->pluck('email')
            ->filter(fn ($email) => filled($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }
}
