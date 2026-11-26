<?php

/*
 * The board's meetings: when they happen by default and who is told when.
 * The chair moves or cancels any single meeting from its page; these are only
 * the starting point the scheduler uses when it opens the next one.
 */
return [
    // 1 = Monday ... 4 = Thursday ... 7 = Sunday (ISO). The board settled on Thursday at noon.
    'weekday' => 4,

    'time' => '12:00',

    // On the meeting's weekday, at this time (after the meeting), the next week's meeting is opened and the
    // board is told about it: "next Thursday at 12". A daily run at 07:30 catches any week that was missed.
    'announce_time' => '14:00',

    'duration_minutes' => 90,

    // 1 = every week; 2 = every other week (when the pressure is low).
    'every_weeks' => 1,

    // The reminder goes out this many days before the meeting, once.
    'remind_days_before' => 1,

    'default_location' => null,

    // Who holds the two board permissions out of the box (granted by migration, when the account exists).
    // Later changes are made from Access Management; the migration only ever adds.
    'chair_email' => 'fredy.youssef@hcsyria.org',

    'secretary_email' => 'fadi.dabbagh@hcsyria.org',

    // How far ahead the scheduler looks for the next meeting's week.
    'look_ahead_days' => 7,
];
