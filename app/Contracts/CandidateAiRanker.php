<?php

namespace App\Contracts;

use App\Models\Position;
use App\Services\CriticalPositions\CandidateSuggestion;

/**
 * Optional AI re-ranking of the rule-based suggestions. Disabled by default
 * (config critical-positions.ai_ranking.enabled) and bound to a no-op: this track
 * sends nothing about volunteers to an external provider.
 */
interface CandidateAiRanker
{
    /**
     * @param  list<CandidateSuggestion>  $suggestions
     * @return list<CandidateSuggestion>
     */
    public function rank(Position $position, array $suggestions): array;
}
