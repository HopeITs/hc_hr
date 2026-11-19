<?php

return [

    /*
    | How many days ahead birthday:notify-conflicts looks for a birthday that
    | falls on a weekend or holiday and still has no day chosen instead.
    */
    'notify_days_ahead' => (int) env('BIRTHDAY_HOLIDAY_NOTIFY_DAYS_AHEAD', 14),

];
