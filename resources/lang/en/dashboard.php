<?php

/*
 * Dashboard and statistics cards (A4).
 * Same key set and order as resources/lang/ar/dashboard.php.
 */

return [
    // Greeting (Arabic uses its own comma)
    'greeting' => 'Hello :name,',

    // Calendar card
    'previous-month' => 'Previous month',
    'next-month' => 'Next month',
    // Column headers, keyed by ISO weekday (1 = Monday … 7 = Sunday).
    'weekdays-min' => [1 => 'Mo', 2 => 'Tu', 3 => 'We', 4 => 'Th', 5 => 'Fr', 6 => 'Sa', 7 => 'Su'],

    // Notes card
    'close' => 'Close',
    'mark-done' => 'Mark as done',
    'mark-not-done' => 'Mark as not done',
    'new-note' => 'New note',

    // Contact-the-team card
    'call' => 'Call',
    'chat-on-telegram' => 'Chat on Telegram',
    'send-email' => 'Send email',

    // News / events / birthdays
    'just-now' => 'Just now',
    'happy-birthday' => 'Happy birthday!',
    'in-days' => '{0} Today|{1} Tomorrow|[2,*] In :count days',
    'ceo-title' => 'Chief Executive Officer',

    // Personal inventory card
    'item-fallback' => 'Item #:id',

    // Statistics cards
    'employees-count' => '{0} No employees|{1} :count employee|[2,*] :count employees',
    'no-leaves-employees-empty' => 'No employees without annual leave requests.',
    'no-contracts-ending-soon' => 'No active contracts are set to end in the next 30 days.',
    'updating-statistics' => 'Updating statistics…',
    'all-cities' => 'All cities',
    'all-programs' => 'All programs',

    'contract-groups' => [
        'annual' => 'Annual',
        'trial' => 'Trial Period',
        'service' => 'Service Agreement',
    ],

    'salary-per' => [
        'month' => 'Per Month',
        'session' => 'Per Session',
        'hour' => 'Per Hour',
    ],

    'employee-chart' => [
        'new' => 'New employees',
        'ended' => 'Ended',
        'terminated' => 'Terminated',
    ],
];
