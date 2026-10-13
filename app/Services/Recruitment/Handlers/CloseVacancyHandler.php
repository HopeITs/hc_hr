<?php

namespace App\Services\Recruitment\Handlers;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestType;
use App\Models\Position;
use App\Models\RecruitmentRequest;
use Illuminate\Validation\ValidationException;

/**
 * Remove one unoccupied seat from the approved establishment.
 *
 * The post stays active and every occupied seat stays intact. Reducing
 * `max_emp` is what removes the vacant card from the organisation chart; an
 * open vacancy row is reduced or closed with it so the seat cannot still be
 * advertised after it no longer exists.
 */
class CloseVacancyHandler implements AppliesRecruitmentRequest
{
    use WritesVacancies;

    public function type(): int
    {
        return RecruitmentRequestType::CloseVacancy;
    }

    public function apply(RecruitmentRequest $request, array $snapshot): array
    {
        $position = Position::query()
            ->whereKey($request->target_position_id)
            ->lockForUpdate()
            ->firstOrFail();

        $before = (int) $position->max_emp;
        $after = $before - 1;

        if ($after < (int) $position->exist_emp) {
            throw ValidationException::withMessages([
                'request' => __('recruitment.headcount_below_staff'),
            ]);
        }

        $position->forceFill(['max_emp' => $after])->save();

        $reason = (string) ($snapshot['reason'] ?? __('recruitment.vacancy_closed'));
        $events = [[
            'event_type' => RecruitmentEventType::HeadcountChanged,
            'meta' => [
                'position_id' => $position->getKey(),
                'max_emp' => ['from' => $before, 'to' => $after],
                'reduced_by' => 1,
            ],
        ]];

        return array_merge($events, $this->reduceOpenSeats(
            (int) $position->getKey(),
            1,
            $reason,
        ));
    }
}
