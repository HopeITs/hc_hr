<?php

namespace App\Services\Recruitment\Handlers;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestType;
use App\Models\Position;
use App\Models\RecruitmentRequest;

/**
 * Widening an existing post, and opening the seats that buys.
 *
 * One vacancy carrying the added seats, because `created_from_request_id` is
 * unique: a request produces one vacancy, and `needed_count` is how the schema
 * says that vacancy needs more than one person.
 */
class IncreaseHeadcountHandler implements AppliesRecruitmentRequest
{
    use WritesVacancies;

    public function type(): int
    {
        return RecruitmentRequestType::IncreaseHeadcount;
    }

    public function apply(RecruitmentRequest $request, array $snapshot): array
    {
        $position = Position::query()
            ->whereKey($request->target_position_id)
            ->lockForUpdate()
            ->firstOrFail();

        $added = max(1, (int) ($snapshot['additional_count'] ?? 1));
        $before = (int) $position->max_emp;

        // RV-3: the arithmetic is done on the establishment as it stands now,
        // not on the snapshot's copy of it, which may be months stale.
        $position->forceFill(['max_emp' => $before + $added])->save();

        $events = [[
            'event_type' => RecruitmentEventType::HeadcountChanged,
            'meta' => [
                'position_id' => $position->getKey(),
                'max_emp' => ['from' => $before, 'to' => $before + $added],
                'added' => $added,
            ],
        ]];

        $events[] = $this->vacancyCreated(
            $this->openVacancy($request, (int) $position->getKey(), $added),
        );

        return $events;
    }
}
