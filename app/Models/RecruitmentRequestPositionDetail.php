<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentRequestPositionDetail extends Model
{
    protected $guarded = ['id'];

    public function requestVersion(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequestVersion::class, 'request_version_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobLevel(): BelongsTo
    {
        return $this->belongsTo(JobLevel::class);
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class);
    }

    public function directSupervisorPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'direct_supervisor_position_id');
    }

    protected function casts(): array
    {
        return [
            'current_headcount' => 'integer',
            'requested_headcount' => 'integer',
            'weekly_hours' => 'integer',
            'salary_amount' => 'integer',
            'currency' => 'integer',
            'salary_per' => 'integer',
            'required_experience_years' => 'integer',
            'role_description_ready' => 'boolean',
            'finance_consulted' => 'boolean',
            'org_chart_updated' => 'boolean',
            'volunteer_period_start_date' => 'date',
            'volunteer_period_end_date' => 'date',
            'expected_start_date' => 'date',
        ];
    }
}
