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

    // Prompt 8 tiles: who is out, next holiday, leave balance, birthdays.
    'out-today' => [
        'calendar' => 'Team calendar',
        'empty' => 'Everyone is in today',
        'more' => '{1} and 1 more person|[2,*] and :count more people',
    ],
    'next-holiday' => [
        'today' => 'Today',
        'days-left' => '{1} day to go|[2,*] days to go',
        'length' => '{1} 1 day|[2,*] :count days',
        'none' => 'No official holiday in the next :count days',
    ],
    'leave-balance' => [
        'request' => 'Request leave',
        'none' => 'No annual leave balance on your current contract',
        'days-left' => '{0} No days left|{1} 1 day left|[2,*] :count days left',
        'left-of' => '{0} No annual leave days left of :total|{1} 1 annual leave day left of :total|[2,*] :count annual leave days left of :total',
    ],
    'birthdays' => [
        'empty' => 'No birthdays in your team this week',
        'today' => 'Today',
    ],

    // Customisable dashboard: tile names (DashboardLayout::label)
    'tiles' => [
        'late' => 'Past the deadline',
        'decide' => 'Work inbox',
        'my-leaves' => 'My leaves',
        'notes' => 'Notes',
        'team' => 'Team',
        'news' => 'News',
        'calendar' => 'Calendar',
        'upcoming-leaves' => 'Upcoming leaves',
        'today-events' => "Today's events",
        'upcoming-events' => 'Upcoming events',
        'pending-requests' => 'Pending requests',
        'checklist' => 'My items',
        'badges' => 'My badges',
        'soon-ending-contracts' => 'Contracts ending soon',
        'stats-department' => 'Employees by department',
        'stats-job-level' => 'Employees by job level',
        'stats-contract-type' => 'Employees by contract type',
        'stats-no-leaves' => 'Employees with no leave taken',
        'stats-salary' => 'Employees by pay basis',
        'who-is-out' => 'Out today',
        'next-holiday' => 'Next holiday',
        'leave-balance' => 'Leave balance',
        'birthdays' => 'Birthdays this week',
        'clock' => 'Clock',
    ],

    // The clock tile (Damascus time) and its HR line. :time is hours:minutes to go, e.g. 1:20.
    'clock' => [
        'aria' => 'Time in Damascus: :time, :weekday :date',
        'starts' => 'Your day starts in :time',
        'resumes' => 'Your day resumes in :time',
        'break' => 'Your break starts in :time',
        'ends' => 'Your day ends in :time',
        'outside' => 'Outside working hours',
        'off' => 'Day off today',
    ],

    // Customising the dashboard (resources/js/dashboard/home-screen.js). :tile is a tile's name.
    'home' => [
        'lead' => 'Your own home screen. Press and hold a tile, or choose Customise, to move, resize, pin or hide tiles.',
        'edit-lead' => 'Drag a tile to move it, or select it and use the arrow keys. Drag the dark corner to resize it cell by cell. × hides a tile, the pin keeps it first.',
        'customise' => 'Customise',
        'done' => 'Done',
        'reset' => 'Reset',
        'reset-title' => 'Go back to the default dashboard?',
        'reset-message' => 'Every tile returns to its usual place and size, and hidden tiles come back. Your notes stay.',
        'add' => 'Add',
        'add-title' => 'Add a tile',
        'cards' => 'Cards',
        'shortcuts' => 'Shortcuts',
        'close' => 'Close',
        'hide' => 'Hide :tile',
        'pin' => 'Pin :tile first',
        'look' => 'Change the look of :tile',
        'looks' => [
            'digital' => 'Digital',
            'analog' => 'Analogue',
        ],
        'resize' => 'Resize :tile, now :size',
        'resize-hint' => 'Drag to resize, cell by cell. Arrow keys work too.',
        'size' => ':cols by :rows cells',
        'tile' => ':tile, :size, position :n of :total',
        'tile-hint' => 'Arrow keys move this tile earlier or later. Home and End move it to the start or the end.',
        'moved' => ':tile moved to position :n of :total.',
        'resized' => ':tile is now :size.',
        'hidden' => ':tile hidden. Add brings it back.',
        'added' => ':tile added.',
        'pinned' => ':tile pinned first.',
        'unpinned' => ':tile no longer pinned.',
        // Ported from media-request: closed tiles, icons, the side panel, double-click.
        'nothing' => 'Nothing here right now',
        'page-open' => 'Double-click to open the page',
        'page-hint' => 'Double-click to open its page.',
        'page-open-link' => 'Open the page',
        'panel-close' => 'Close :tile',
    ],

    // The tiles that were fixed above the grid (Dashboard\LateCard, Dashboard\DecisionsCard).
    'late-tile' => [
        'none' => 'Nothing is past its deadline.',
    ],
    'decide-tile' => [
        'none' => 'Your inbox is clear.',
        'open' => 'Open inbox',
        'items' => '{1} 1 item to hand over or take back|[2,*] :count items to hand over or take back',
    ],

    // A shortcut made big: its data as a small card (Dashboard\AppCard).
    'app-card' => [
        'in-inbox' => '{0} in your inbox|{1} item in your inbox|[2,*] items in your inbox',
        'inbox-empty' => 'Your inbox is clear.',
        'ending-soon' => '{0} contracts ending within 30 days|{1} contract ending within 30 days|[2,*] contracts ending within 30 days',
        'not-renewed' => 'Ended without renewal',
    ],

    // Prompt 7: the top of the dashboard (Dashboard\Header), fixed above the tiles.
    'header' => [
        'headline' => [
            'leave' => '{1} 1 leave request needs your decision|[2,*] :count leave requests need your decision',
            'decisions' => '{1} 1 request needs your decision|[2,*] :count requests need your decision',
            'inbox' => '{1} 1 item in your inbox|[2,*] :count items in your inbox',
            'late' => '{1} 1 thing is late|[2,*] :count things are late',
            'clear' => 'Nothing is waiting on you today',
        ],
        'late' => [
            'leave' => '{1} 1 leave started with no decision|[2,*] :count leaves started with no decision',
            'contract' => '{1} 1 contract ended without renewal|[2,*] :count contracts ended without renewal',
            'open' => 'Open the oldest',
        ],
        'decide' => [
            'title' => '{1} 1 decision waits on you|[2,*] :count decisions wait on you',
            'prev' => 'Previous decision',
            'next' => 'Next decision',
            'all' => 'Show all',
            'one' => 'One at a time',
            'pick' => 'Choose a decision',
            'since' => 'Waiting :time',
            'more' => '{1} 1 more in the inbox|[2,*] :count more in the inbox',
            'verb' => [
                'decide' => 'Review leave',
                'review' => 'Check the decision',
                'approve' => 'Review the change',
            ],
        ],
    ],
];
