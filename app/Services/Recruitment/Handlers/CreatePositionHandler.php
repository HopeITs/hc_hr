<?php

namespace App\Services\Recruitment\Handlers;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestType;
use App\Models\Position;
use App\Models\RecruitmentRequest;

/**
 * Writing a new post into the organisation chart.
 *
 * Until this runs, the post exists only as fields on the request: nothing is
 * written into HR master data before it is approved, so a proposal cannot be
 * mistaken for an established post. The post and its vacancy are created in
 * one transaction — a post standing with no seats would read as fully staffed
 * and nobody would ever be hired into it.
 */
class CreatePositionHandler implements AppliesRecruitmentRequest
{
    use WritesVacancies;

    public function type(): int
    {
        return RecruitmentRequestType::CreatePosition;
    }

    public function apply(RecruitmentRequest $request, array $snapshot): array
    {
        $maxEmp = max(1, (int) ($snapshot['max_emp'] ?? 1));

        $position = Position::query()->forceCreate([
            'program_id' => $snapshot['program_id'],
            'center_id' => $snapshot['center_id'] ?? null,
            'city_id' => $snapshot['city_id'],
            'job_level_id' => $snapshot['job_level_id'],
            'department_id' => $snapshot['department_id'],
            'direct_manager_id' => $snapshot['direct_manager_id'] ?? null,
            'title_en' => $snapshot['title_en'],
            'title_ar' => $snapshot['title_ar'],
            'qualifications' => $snapshot['qualifications'] ?? null,
            'responsibilities' => $snapshot['responsibilities'] ?? null,
            'max_emp' => $maxEmp,
            'exist_emp' => 0,
            'is_active' => true,
        ]);

        // The request carried no position until now. Pointing it at the new
        // one is what lets the timeline, the campaign and any later request
        // find what this one produced.
        $request->forceFill(['target_position_id' => $position->getKey()])->save();

        $events = [[
            'event_type' => RecruitmentEventType::PositionChanged,
            'meta' => [
                'position_id' => $position->getKey(),
                'created' => true,
                'max_emp' => $maxEmp,
            ],
        ]];

        $events[] = $this->vacancyCreated(
            $this->openVacancy($request, (int) $position->getKey(), max(1, (int) ($snapshot['volunteers_required'] ?? $maxEmp))),
        );

        return $events;
    }
}
