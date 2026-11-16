<?php

return [

    /*
     * A device number is the employee's ID in HC-HR unless HR has linked it to someone by hand
     * (attendance_device_links). Turn off to match only linked numbers.
     */
    'device_number_is_employee_id' => true,

    /*
     * Weekend days when a center has none set: PHP day numbers, 0 = Sunday ... 6 = Saturday.
     * The printed form uses Saturday and Sunday.
     */
    'default_weekends' => '0,6',

    /*
     * Daily hours when a contract has none.
     */
    'default_daily_hours' => 8,

    /*
     * Overtime is not paid: approved hours are given back as free days (hours / the contract's
     * daily hours). A day counts as overtime only when the time at work (first to last punch)
     * is at least `threshold_minutes` beyond the contract's day, then it is counted in blocks
     * of `block_minutes`, rounded down. Work on a weekend or a holiday counts in full when
     * `weekend_counts_fully` is on.
     */
    'overtime' => [
        'threshold_minutes' => 60,
        'block_minutes' => 30,
        'weekend_counts_fully' => true,
    ],

];
