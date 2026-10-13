<?php

namespace App\Services\Recruitment\Handlers;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestType;
use App\Models\Position;
use App\Models\RecruitmentRequest;
use Illuminate\Validation\ValidationException;

/**
 * Narrowing a post, and closing the seats that no longer exist.
 *
 * The one rule that cannot bend: the establishment never falls below the
 * people already in it. RV-4 checks this at the final approval, and it is
 * checked again here because `exist_emp` moves with every contract and the
 * two moments are not the same one — more so now that a reduction can sit
 * approved for weeks waiting on its effective date.
 */
class DecreaseHeadcountHandler implements AppliesRecruitmentRequest
{
    use WritesVacancies;

    public function type(): int
    {
        return RecruitmentRequestType::DecreaseHeadcount;
    }

    public function apply(RecruitmentRequest $request, array $snapshot): array
    {
        $position = Position::query()
            ->whereKey($request->target_position_id)
            ->lockForUpdate()
            ->firstOrFail();

        $reduceBy = max(1, (int) ($snapshot['reduce_by'] ?? 1));
        $before = (int) $position->max_emp;
        $after = $before - $reduceBy;

        if ($after < (int) $position->exist_emp) {
            throw ValidationException::withMessages([
                'request' => __('recruitment.headcount_below_staff'),
            ]);
        }

        $position->forceFill(['max_emp' => $after])->save();

        $events = [[
            'event_type' => RecruitmentEventType::HeadcountChanged,
            'meta' => [
                'position_id' => $position->getKey(),
                'max_emp' => ['from' => $before, 'to' => $after],
                'reduced_by' => $reduceBy,
            ],
        ]];

        // Seats beyond the new establishment go, emptiest and newest first.
        // What may stay open is the new establishment less the people in it.
        $reason = (string) ($snapshot['reason'] ?? __('recruitment.headcount_reduced'));
        $keep = max(0, $after - (int) $position->exist_emp);
        $openSeats = $this->openVacanciesForClosing((int) $position->getKey())
            ->sum(fn ($vacancy): int => max(0, (int) $vacancy->needed_count - (int) $vacancy->filled_count));

        return array_merge($events, $this->reduceOpenSeats(
            (int) $position->getKey(),
            max(0, $openSeats - $keep),
            $reason,
        ));
    }
}
