<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Position;
use App\Models\Program;

/**
 * The day(s) off Study Zone's optional "Friday is considered a day off…"
 * sentence names (contracts.show_weekend_clause).
 *
 * A Study Zone position usually has no center of its own, so the day is the
 * weekend of the centers it is shared by in its city — LeaveRequestPricing's
 * weekendFor(), so the contract never names a day off that leave requests
 * then charge for. The composer, the amendment page and the print all ask
 * here, so the checkbox only appears where the print would state something.
 */
class ContractWeekendClause
{
    public function __construct(private LeaveRequestPricing $pricing) {}

    /**
     * Null outside Study Zone, and when no center weekend is set (the code
     * default): the sentence would state a day nobody chose.
     *
     * @return array<int, int>|null Carbon day numbers, Sunday = 0
     */
    public function days(Contract $contract): ?array
    {
        if ((int) $contract->position?->program_id !== Program::STUDY_ZONE_ID) {
            return null;
        }

        [$weekend, $source] = $this->pricing->weekendFor($contract);

        return $source === 'default' ? null : $weekend;
    }

    /** @return array<int, int>|null */
    public function daysForPosition(Position $position): ?array
    {
        return $this->days((new Contract)->setRelation('position', $position)->setRelation('employee', null));
    }
}
