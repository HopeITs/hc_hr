<?php

// DEC-09 increment 2: employee-journey steps in HR's work inbox
// (docs/ux/journey-inbox-implementation.md). One group per source.
return [
    'filter' => 'Journeys',
    'open' => 'Open employee page',
    'due' => 'Due by :date',
    'late' => 'Late',
    'state' => [
        'todo' => 'Needs HR',
        'problem' => 'Blocked',
    ],
    'onboarding' => [
        'title' => 'Onboarding step waiting on you',
        'step' => [
            'account' => 'Login account missing',
            'card' => 'Hand over the printed ID card',
            'orientation-schedule' => 'Schedule the orientation session',
            'orientation-record' => 'Record the orientation session',
        ],
    ],
    'renewal' => [
        'title' => 'Renewal decision waiting on you',
        'step' => [
            'decide' => 'Decide: renew or stop',
        ],
    ],
    'exit' => [
        'title' => 'Exit step waiting on you',
        'step' => [
            'equipment' => 'Request the equipment back',
        ],
    ],
];
