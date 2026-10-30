<?php

// The big clock (Dashboard\ClockCard, Dashboard\ClockPresence): the working day
// and who is online. :time is hours:minutes, e.g. 3:15. The short line under the
// time stays in dashboard.php ('clock').
return [
    'work' => [
        'progress' => 'Working day progress',
        'done' => 'At work for :time',
        'not-started' => 'Not started yet',
        'over' => 'Your day is over',
        'hours' => 'Hours :hours',
        'holiday' => 'Official holiday: :name',
    ],
    'presence' => [
        'title' => 'Online now',
        'count' => ':n of :all',
        'none' => 'No one else from your team is online.',
        'more' => '+:n',
        'more-label' => 'and :n more',
    ],
];
