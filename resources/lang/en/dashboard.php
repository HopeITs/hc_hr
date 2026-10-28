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

    // C4: the "For you now" section at the top of the home page.
    'for-you' => [
        'title' => 'For you now',
        'failed' => 'This part could not be loaded.',
        'empty' => 'Nothing is waiting for you right now.',
        'open' => 'Open',
        'see-all' => 'See all',
        'starts-soon' => '{1} 1 starts within two days|[2,*] :count start within two days',
        'already-started' => '{1} 1 has already started|[2,*] :count have already started',
        'decisions' => [
            'title' => 'Needs my decision',
            'count' => '{1} 1 leave request is waiting for you|[2,*] :count leave requests are waiting for you',
        ],
        'inbox' => [
            'title' => 'Work inbox',
            'count' => '{1} 1 item is waiting for you|[2,*] :count items are waiting for you',
        ],
        'inbox-items' => [
            'title' => 'Items to confirm',
            'count' => '{1} 1 item to hand over or take back|[2,*] :count items to hand over or take back',
        ],
        'team-off' => [
            'title' => 'Who in my team is off',
            'today' => 'Today',
            'week' => 'Later this week',
            'nobody-today' => 'Nobody is off today.',
        ],
        'balance' => [
            'title' => 'My leave',
            'remaining' => ':type left: :amount',
            'pending' => 'Reserved by pending requests: :amount',
            'holiday' => 'Next holiday: :date',
            'request' => 'Request leave',
            'my-leaves' => 'My leaves',
        ],
        'my-requests' => [
            'title' => 'My open requests',
        ],
        'my-items' => [
            'title' => 'My items',
            'receive' => '{1} 1 item to receive|[2,*] :count items to receive',
            'return' => '{1} 1 item to hand back|[2,*] :count items to hand back',
        ],
        'contracts-ending' => [
            'title' => 'Contracts ending in 30 days',
            'count' => '{1} 1 contract|[2,*] :count contracts',
        ],
        'employees-waiting' => [
            'title' => 'Employees waiting on HR',
            'awaiting' => '{1} 1 volunteer awaiting a contract|[2,*] :count volunteers awaiting a contract',
            'incomplete' => '{1} 1 incomplete record|[2,*] :count incomplete records',
        ],
        'key-figures' => [
            'title' => 'Key figures',
            'employees' => 'Active employees',
            'contracts' => 'Running contracts',
            'statistics' => 'Statistics',
        ],
        'admin-shortcuts' => [
            'title' => 'Settings',
        ],
    ],
];
