<?php

namespace App\Services\CriticalPositions;

use App\Contracts\CandidateAiRanker;
use App\Models\Position;

final class NullCandidateAiRanker implements CandidateAiRanker
{
    public function rank(Position $position, array $suggestions): array
    {
        return $suggestions;
    }
}
