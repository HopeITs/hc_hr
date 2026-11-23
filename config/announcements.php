<?php

/*
|--------------------------------------------------------------------------
| Ready-made announcements (App\Support\Announcements)
|--------------------------------------------------------------------------
|
| Who each announcement goes to by default. HR can change To and CC in the
| composer before opening the message; these are only what it starts with.
| When the IT Specialist or IT Manager changes, change the addresses here
| (or in .env) and the greeting below.
|
*/

return [
    'recipients' => [
        'welcome' => [
            'to' => env('ANNOUNCE_TEAM_EMAIL', 'team@hcsyria.org'),
        ],
        'volunteer_resignation' => [
            'to' => env('ANNOUNCE_IT_SPECIALIST_EMAIL', 'mai.youssef@hcsyria.org'),
            'cc' => env('ANNOUNCE_IT_MANAGER_EMAIL', 'judy.dadaghlian@hcsyria.org'),
        ],
        'administrative_change' => [
            'to' => env('ANNOUNCE_TEAM_EMAIL', 'team@hcsyria.org'),
        ],
        'holiday_closure' => [
            'to' => env('ANNOUNCE_TEAM_EMAIL', 'team@hcsyria.org'),
            'cc' => env('ANNOUNCE_HOLIDAY_CC_EMAIL', 'fredy.youssef@hcsyria.org'),
        ],
    ],

    // How the volunteer-resignation request greets the IT Specialist ("العزيزة مي").
    'it_specialist' => [
        'greeting' => env('ANNOUNCE_IT_SPECIALIST_GREETING', 'مي'),
        'gender' => env('ANNOUNCE_IT_SPECIALIST_GENDER', 'f'),
    ],
];
