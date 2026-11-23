<?php

return [

    'map_by_id' => [
        1 => 'annual',
        3 => 'trial',
        5 => 'service',
    ],

    /*
     * An auto-renewing contract that ends in one of these months is renewed on
     * 1 January for the whole new year, not the day after it ends (which made
     * renewals of a few days). City HR is told when it ends. A contract ending
     * on 31 December renews on 1 January either way.
     */
    'renew_at_new_year_months' => [10, 11, 12],
];
