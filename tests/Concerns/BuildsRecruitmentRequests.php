<?php

namespace Tests\Concerns;

use App\Enums\Currency;
use App\Enums\RecruitmentRequestType as Type;
use App\Enums\SalaryPer;
use App\Models\ContractType;
use Livewire\Features\SupportTesting\Testable;

/**
 * A complete answer to the request form.
 *
 * Every field on the form is required before a draft will save, so a test that
 * is about scope, duplicates or availability would otherwise have to restate
 * a dozen unrelated fields to get past validation. It fills them in once here
 * and lets each test override only what it is actually testing.
 */
trait BuildsRecruitmentRequests
{
    /**
     * @return array<string, mixed>
     */
    protected function recruitmentFormDefaults(int $type = Type::FillVacancy, array $overrides = []): array
    {
        $values = [];

        // The three types that end in somebody being hired share the form.
        if (in_array($type, [Type::FillVacancy, Type::IncreaseHeadcount, Type::CreatePosition], true)) {
            $values = [
                'recruitment_reason' => 'operational_need',
                'volunteers_required' => 1,
                'priority' => 'normal',
                'volunteer_type' => 'onsite',
                'weekly_hours' => 40,
                'needed_by_date' => '2026-12-01',
                'volunteer_period_start_date' => '2026-12-01',
                'volunteer_period_end_date' => '2027-06-01',
                'salary_amount' => 500,
                'currency' => Currency::USD,
                'salary_per' => SalaryPer::Month,
                'contract_type_id' => $this->recruitmentContractTypeId(),
                'detailed_explanation' => 'Why this is needed in more detail.',
            ];
        }

        if ($type === Type::CreatePosition) {
            $values += [
                'title_en' => 'Proposed position',
                'title_ar' => 'منصب مقترح',
                'qualifications' => 'The qualifications required.',
                'responsibilities' => 'The duties of the role.',
                'max_emp' => 1,
            ];
        }

        if ($type === Type::IncreaseHeadcount) {
            $values['additional_count'] = 1;
        }

        if ($type === Type::DecreaseHeadcount) {
            $values += ['reduce_by' => 1, 'reason' => 'Restructuring.', 'effective_date' => '2026-12-01'];
        }

        if ($type === Type::ClosePosition) {
            $values += ['reason' => 'No longer needed.', 'effective_date' => '2026-12-01'];
        }

        if ($type === Type::CloseVacancy) {
            $values += [
                'reason' => 'No longer recruiting.',
                'effective_date' => '2026-12-01',
            ];
        }

        return array_merge($values, $overrides);
    }

    /** The same defaults, typed onto a Livewire screen. */
    protected function fillRecruitmentForm(Testable $screen, int $type = Type::FillVacancy, array $overrides = []): Testable
    {
        foreach ($this->recruitmentFormDefaults($type, $overrides) as $field => $value) {
            $screen->set('data.'.$field, $value);
        }

        return $screen;
    }

    /** Use one of the two contract types recruitment is allowed to propose. */
    protected function recruitmentContractTypeId(): int
    {
        return (int) ContractType::query()->firstOrCreate(
            ['name_en' => 'Trial Volunteer Contract'],
            ['name_ar' => 'عقد تطوّع تجريبي'],
        )->id;
    }
}
