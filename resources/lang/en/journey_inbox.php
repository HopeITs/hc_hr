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
        'manager' => 'Needs you',
    ],
    'probation' => [
        'title' => 'Trial review waiting on you',
        'open' => 'Open the review',
        'step' => [
            'request' => 'Tell HR: extend, confirm or cancel the trial',
        ],
    ],
    'onboarding' => [
        'title' => 'Onboarding step waiting on you',
        'open-manager' => 'Open the session',
        'step' => [
            'account' => 'Login account missing',
            'card' => 'Hand over the printed ID card',
            'manager-schedule' => 'Schedule your orientation session with the new volunteer',
            'manager-record' => 'Record your orientation session with the new volunteer',
            'electronics' => 'Hand over the electronics',
            'other_items' => 'Hand over the remaining items',
            'mug' => 'Hand over the mug (the trial is over)',
            'policies' => 'Read the policies and agree to them',
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
