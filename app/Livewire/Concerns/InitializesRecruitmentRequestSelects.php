<?php

namespace App\Livewire\Concerns;

use App\Enums\RecruitmentRequestType;

trait InitializesRecruitmentRequestSelects
{
    /**
     * Select2 binds through Alpine entanglement, which requires every nested
     * array key to exist before the control is initialized.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withInitializedRequestSelects(array $data): array
    {
        return array_replace([
            'request_type' => RecruitmentRequestType::FillVacancy,
            'position_id' => null,
            'direct_manager_id' => null,
            'vacancy_id' => null,
            'city_id' => null,
            'program_id' => null,
            'center_id' => null,
            'department_id' => null,
            'job_level_id' => null,
            'recruitment_reason' => null,
            'priority' => null,
            'volunteer_type' => null,
            'currency' => null,
            'salary_per' => null,
            'contract_type_id' => null,
        ], $data);
    }
}
