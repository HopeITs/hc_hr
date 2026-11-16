<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\RecruitmentHiringConversion;
use App\Models\User;

/**
 * What is left to do for a person just hired from an accepted offer: the
 * guided flow after the conversion. The employee and the contract are made by
 * the conversion itself (RecruitmentHiringConversionService); this reads what
 * is still open around them — a login account, the ID card (which exists as
 * soon as the employee has a city and a current position, so "issuing" it is
 * making sure it can be built) and the welcome message.
 *
 * It reads and links only. Nothing is created here, and every link points to a
 * page that checks its own permission.
 */
class OnboardingChecklist
{
    public function __construct(private readonly DigitalIdManager $cards) {}

    /**
     * @return array<int, array{key: string, done: bool, url: ?string, problem: ?string}>
     */
    public function for(RecruitmentHiringConversion $conversion, ?User $viewer): array
    {
        $employee = $conversion->employee;

        if (! $conversion->isConverted() || ! $employee) {
            return [];
        }

        $employee->loadMissing(['account', 'city']);

        $canEdit = $viewer?->can('update', $employee) ?? false;
        $editUrl = $canEdit ? route('employees.edit', $employee->id) : null;

        return [
            [
                'key' => 'employee',
                'done' => true,
                'url' => $employee->username ? route('employees.show', $employee->username) : null,
                'problem' => null,
            ],
            [
                'key' => 'contract',
                'done' => $conversion->contract !== null,
                'url' => $conversion->contract ? route('contracts.show', $conversion->contract->id) : null,
                'problem' => null,
            ],
            [
                'key' => 'account',
                'done' => $employee->account !== null,
                'url' => $employee->account ? null : $editUrl,
                'problem' => null,
            ],
            $this->cardStep($employee, $editUrl),
            [
                // The message itself is chosen with the template menu on the page.
                'key' => 'welcome',
                'done' => false,
                'url' => null,
                'problem' => null,
            ],
        ];
    }

    /** @return array{key: string, done: bool, url: ?string, problem: ?string} */
    private function cardStep(Employee $employee, ?string $editUrl): array
    {
        $reason = $this->cards->missingLinkReason($employee);
        $code = $reason === null ? $this->cards->cardCode($employee) : null;

        return [
            'key' => 'card',
            'done' => $code !== null,
            'url' => $code !== null ? route('digital-id.show', ['card' => $code]) : $editUrl,
            'problem' => $reason !== null ? __($reason) : null,
        ];
    }
}
