<?php

namespace App\Livewire\Position;

use App\Contracts\CandidateAiRanker;
use App\Models\Position;
use App\Services\CriticalPositions\CandidateSuggestion;
use App\Services\CriticalPositions\CriticalPositionAlerts;
use App\Services\CriticalPositions\InternalCandidateRanker;
use App\Services\CriticalPositions\PastApplicantsSource;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Suggested replacements for one critical position. Suggestions only: nothing here
 * promotes anyone or creates a contract, and the panel is closed to direct
 * managers and to the candidates themselves (PositionPolicy::viewReplacements).
 */
class Replacements extends Component
{
    public Position $position;

    public function mount(Position $position): void
    {
        Gate::authorize('viewReplacements', $position);
        $this->position = $position;
    }

    public function render(
        InternalCandidateRanker $internal,
        PastApplicantsSource $cvs,
        CandidateAiRanker $ai,
        CriticalPositionAlerts $alerts,
    ) {
        Gate::authorize('viewReplacements', $this->position);

        $user = auth()->user();
        $limit = (int) config('critical-positions.suggestions.limit', 10);

        $internalList = $internal->suggest($this->position, $user, $limit);

        if (config('critical-positions.ai_ranking.enabled')) {
            $internalList = $ai->rank($this->position, $internalList);
        }

        return view('livewire.position.replacements', [
            'internal' => $internalList,
            'cvs' => $cvs->suggest($this->position, $user, $limit),
            'needs' => $alerts->needsReplacement($this->position),
            'reasons' => $alerts->reasonsFor($this->position),
            'isCv' => CandidateSuggestion::CV,
        ]);
    }
}
