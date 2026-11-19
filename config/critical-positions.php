<?php

/*
 * Critical positions (meeting 19/11, track 6).
 *
 * poor_evaluation: a critical position also "needs a replacement" when its
 * holder's latest evaluation is poor. DISABLED and not wired to any column on
 * purpose: the evaluations table holds only contract_id, date, current_manager_id
 * and free-text notes, so there is no rating to compare with a threshold. When
 * a rating column exists, set enabled and the minimum acceptable value and add
 * the check to CriticalPositionAlerts::reasonsFor().
 *
 * ai_ranking: the AI layer sits behind App\Contracts\CandidateAiRanker. It is off
 * and nothing in this feature calls an external provider; no volunteer data
 * leaves the system.
 */
return [
    'poor_evaluation' => [
        'enabled' => false,
        'minimum_rating' => null,
    ],

    'ai_ranking' => [
        'enabled' => env('CRITICAL_POSITIONS_AI_RANKING', false),
    ],

    'suggestions' => [
        'limit' => 10,
        // Internal suggestions below this score are not shown.
        'minimum_score' => 20,
    ],
];
