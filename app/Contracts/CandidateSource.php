<?php

namespace App\Contracts;

use App\Models\Position;
use App\Models\User;
use App\Services\CriticalPositions\CandidateSuggestion;

interface CandidateSource
{
    /** @return list<CandidateSuggestion> */
    public function suggest(Position $position, User $viewer, int $limit): array;
}
