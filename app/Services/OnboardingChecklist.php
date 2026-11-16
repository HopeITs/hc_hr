<?php

namespace App\Services;

use App\Models\Contract;
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
 * The hire may be an existing employee taking a new position, often in
 * another project (an officer of one project becoming the manager of
 * another): no new employee exists then. The conversion adds the new contract
 * but does not end the old one, so this lists any other contract still
 * running and warns when the program changed, because the ID card's link is
 * built from the program of the newest contract and the old printed card
 * stops resolving.
 *
 * It reads and links only. Nothing is created or ended here, and every link
 * points to a page that checks its own permission.
 */
class OnboardingChecklist
{
    public function __construct(private readonly DigitalIdManager $cards) {}

    /**
     * @return array<int, array{key: string, done: bool, url: ?string, problem: ?string, params: array<string, string>, note: ?string}>
     */
    public function for(RecruitmentHiringConversion $conversion, ?User $viewer): array
    {
        $employee = $conversion->employee;

        if (! $conversion->isConverted() || ! $employee) {
            return [];
        }

        $employee->loadMissing(['account', 'city']);

        $existing = ($conversion->employee_payload_json['mode'] ?? null) === 'link';
        $canEdit = $viewer?->can('update', $employee) ?? false;
        $editUrl = $canEdit ? route('employees.edit', $employee->id) : null;
        $others = $this->otherRunningContracts($employee, $conversion);

        $steps = [
            $this->step($existing ? 'employee_existing' : 'employee', true, $employee->username ? route('employees.show', $employee->username) : null),
            $this->step('contract', $conversion->contract !== null, $conversion->contract ? route('contracts.show', $conversion->contract->id) : null),
        ];

        // A transfer or promotion: the old contract still runs next to the new one.
        foreach ($others as $other) {
            $steps[] = $this->step('previous', false, route('contracts.show', $other->id), params: [
                'position' => (string) $other->position?->title,
                'program' => (string) ($other->position?->program?->name ?? '—'),
            ]);
        }

        $steps[] = $this->step('account', $employee->account !== null, $employee->account ? null : $editUrl);
        $steps[] = $this->cardStep($employee, $conversion, $others->first(), $editUrl);
        // The message itself is chosen with the template menu on the page.
        $steps[] = $this->step($existing ? 'announce' : 'welcome', false, null);

        return $steps;
    }

    /** @return array{key: string, done: bool, url: ?string, problem: ?string, params: array<string, string>, note: ?string} */
    private function step(string $key, bool $done, ?string $url, ?string $problem = null, array $params = [], ?string $note = null): array
    {
        return compact('key', 'done', 'url', 'problem', 'params', 'note');
    }

    /**
     * The employee's other contracts that are still running, on any other
     * position than the one just given: what a transfer leaves behind.
     *
     * @return \Illuminate\Support\Collection<int, Contract>
     */
    private function otherRunningContracts(Employee $employee, RecruitmentHiringConversion $conversion)
    {
        return Contract::query()
            ->activeNow()
            ->where('employee_id', $employee->id)
            ->when($conversion->contract_id, fn ($query, $id) => $query->whereKeyNot($id))
            ->with('position.program')
            ->orderByDesc('start_date')
            ->get();
    }

    /** @return array{key: string, done: bool, url: ?string, problem: ?string, params: array<string, string>, note: ?string} */
    private function cardStep(Employee $employee, RecruitmentHiringConversion $conversion, ?Contract $previous, ?string $editUrl): array
    {
        // A fresh read: the card is built from the employee's contracts as they are now.
        $employee->unsetRelation('contracts');

        $reason = $this->cards->missingLinkReason($employee);
        $code = $reason === null ? $this->cards->cardCode($employee) : null;

        // The program in the card's link changed: the old printed card no longer resolves.
        $note = null;
        $newProgram = $conversion->contract?->position?->program;
        $oldProgram = $previous?->position?->program;

        if ($code !== null && $newProgram && $oldProgram && $newProgram->getKey() !== $oldProgram->getKey()) {
            $note = __('recruitment-applicant.conversions.onboarding.card_changed', ['from' => $oldProgram->name, 'to' => $newProgram->name]);
        }

        return $this->step(
            'card',
            // A changed program is a task: the old printed card stops working.
            $code !== null && $note === null,
            $code !== null ? ($note && $employee->username ? route('employees.show', $employee->username) : route('digital-id.show', ['card' => $code])) : $editUrl,
            $reason !== null ? __($reason) : null,
            note: $note,
        );
    }
}
