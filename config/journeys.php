<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Exit journey: grace period before automatic deactivation
    |--------------------------------------------------------------------------
    |
    | DEC-09 increment 2 (product owner, 2026-10-30): a volunteer whose last
    | real contract has ended is deactivated automatically. This is how many
    | days after that end the daily `employees:deactivate-departed` command
    | waits, so a renewal entered a few days late does not cut anyone off.
    | City HR gets one heads-up when the last contract ends.
    |
    */

    'exit_grace_days' => (int) env('EXIT_GRACE_DAYS', 7),

];
