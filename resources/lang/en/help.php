<?php

/*
|--------------------------------------------------------------------------
| Page-context help (D3)
|--------------------------------------------------------------------------
|
| Copy for the help panel (<x-help.panel>) and the "?" buttons beside
| difficult fields (<x-help.trigger>). Which topics belong to which page is
| decided in App\Support\HelpTopics; every topic listed there must have a
| title, summary, keywords and the stated number of points here AND in
| resources/lang/ar/help.php.
|
| Status: PROPOSED, pending content review (docs/ux/d3-help-notes.md §5).
| Each line was checked against the code that applies the rule. Never put
| amounts of pay, balances or names of real people in this file.
|
*/

return [
    'panel' => [
        'title' => 'Help',
        'open' => 'Help for this page',
        'close' => 'Close help',
        'search-label' => 'Search help',
        'search-placeholder' => 'Search help topics…',
        'on-this-page' => 'On this page',
        'all-topics' => 'All topics',
        'other-topics' => 'Other topics',
        'results' => '{0} No topics match|{1} :count topic matches|[2,*] :count topics match',
        'no-results' => 'No help topic matches “:query”. Try a shorter word.',
        'back' => 'All help topics',
        'stays-open' => 'Your work on this page stays as it is while help is open.',
    ],

    // aria-label of a "?" button beside a field: "Help: Leave unit".
    'trigger' => 'Help: :field',

    'topics' => [
        'leave-units' => [
            'title' => 'Days or hours: how leave is counted',
            'summary' => 'How a request is measured, and when you can ask for hours instead of days.',
            'points' => [
                1 => 'Leave is kept in hours. A day is the length of your working day: your weekly hours divided by the days you work each week.',
                2 => 'You choose Day or Hour only for annual leave. Every other leave type is asked for in days.',
                3 => 'A request in days counts working days only. Your center’s weekend and official holidays inside the dates are not counted. Maternity leave is counted in calendar days.',
                4 => 'A request in hours is for one date, with a start and end time inside working hours. The shortest request is 15 minutes.',
                5 => 'If you work full time (40 hours a week or more), a request in hours longer than 4 hours counts as one full day. Part-time requests count the exact hours.',
                6 => 'Other leave types have a maximum number of days per request. Leave in December is only accepted when you mark it as an exceptional December leave.',
            ],
            'keywords' => 'unit day hour hours days duration working day weekend holiday maternity minimum full time part time december',
        ],

        'leave-balance' => [
            'title' => 'Your leave balance',
            'summary' => 'What “remaining”, “reserved” and “used” mean on your balance.',
            'points' => [
                1 => 'Annual leave becomes available in three equal parts over your contract: one third at the start, the next third after a third of the contract, and the rest after two thirds.',
                2 => 'Other leave types are available in full from the start of the contract.',
                3 => 'A request waiting for a decision reserves its amount straight away, so two pending requests cannot together go over your balance.',
                4 => 'The amount is taken from your balance when the request is accepted. A rejected or cancelled request gives its reserved amount back.',
                5 => 'Specific voluntary service contracts and drafts have no leave balance.',
            ],
            'keywords' => 'balance remaining reserved pending used entitlement annual third accepted rejected',
        ],

        'contract-types' => [
            'title' => 'Contract types',
            'summary' => 'What each contract type sets, and what a draft is.',
            'points' => [
                1 => 'Volunteer contract: by default it starts on the first day of next month and runs to the end of the current year.',
                2 => 'Trial volunteer contract: lasts 3 months by default and never renews automatically.',
                3 => 'Specific voluntary service contract: always freelance, with no leave balance.',
                4 => 'Any contract may run for one year at most from its start date. Full time, part time and place belong to the single contract, not to its type.',
                5 => 'A draft only reserves a position: it has no dates or pay, does not take a seat and is not active. Saving it as a real contract takes the seat.',
            ],
            'keywords' => 'contract type volunteer trial service freelance draft full time part time duration year',
        ],

        'auto-renew' => [
            'title' => 'Automatic renewal',
            'summary' => 'What happens when a contract with automatic renewal ends.',
            'points' => [
                1 => 'When the contract ends without a termination, a nightly check creates a new contract with the same terms, pay schedule included.',
                2 => 'The new contract starts the day after the old end date and runs to the end of that year.',
                3 => 'It is on by default for new contracts. Trial contracts and terminated contracts never renew.',
                4 => 'Turning it off starts the return of the equipment the person still holds.',
                5 => 'Only Governorate HR can change automatic renewal.',
            ],
            'keywords' => 'auto renew renewal automatic end date terminate trial equipment return',
        ],

        'position-dependencies' => [
            'title' => 'City, program and position',
            'summary' => 'Why the lists depend on each other, and what “Full” means.',
            'points' => [
                1 => 'Choose the city first. Programs list only those with positions in that city, and positions only those in the chosen program. The center comes from the position.',
                2 => 'A position belongs to a city directly or through its center. The list and the save check use the same rule, so a position outside your city cannot be saved.',
                3 => 'Each position has a number of seats, and every active contract takes one. A position marked “Full” cannot be chosen: pick another or ask for more seats.',
                4 => 'A draft does not take a seat. A renewal on the same position keeps the person’s seat.',
                5 => 'Moving a contract to another position frees the old seat and takes the new one. A seat is freed when the contract ends.',
            ],
            'keywords' => 'position city program center seat seats capacity full draft governorate',
        ],
        // Item 19 (UX review): recruitment, attendance, first days, probation.
        'hiring-flow' => [
            'title' => 'How hiring works',
            'summary' => 'From a recruitment request to a new employee, step by step.',
            'points' => [
                1 => 'A manager raises a recruitment request for a position in their team, and it goes through approvals. A reviewer may ask for changes before approving.',
                2 => 'An approved request becomes a vacancy, and a campaign opens for it with its own application link to publish.',
                3 => 'Each applicant is reviewed; the shortlisted ones get an interview. After the interviews a candidate is selected and an offer is made.',
                4 => 'Once the applicant accepts, HR converts them to an employee from the offer\'s page: their record and contract are created from the accepted terms, and their first-days steps begin.',
                5 => 'Direct opening is for a hire agreed outside the system: the approvals given outside are recorded, and the vacancy opens without the approvals here.',
            ],
            'keywords' => 'hiring recruitment request approval vacancy campaign application link applicant shortlist interview offer convert direct opening',
        ],
        'hiring-convert' => [
            'title' => 'Converting an applicant to an employee',
            'summary' => 'What confirming the hire does, and why not to add the employee by hand.',
            'points' => [
                1 => 'The conversion creates the employee record and the contract together; the contract takes the offer terms as accepted.',
                2 => 'If the person is already on file, their record is suggested so you link them instead of creating a duplicate. A national number on file under another name stops the hire.',
                3 => 'If anything fails (a full position, a closed vacancy…) nothing is created, and the reason shows so you can fix it and try again.',
                4 => 'Adding someone through Add employee neither links them to their recruitment file nor writes their contract from the offer, so that page lists the accepted offers waiting to be converted.',
            ],
            'keywords' => 'convert hire employee accepted offer contract record link duplicate national number confirm add employee',
        ],
        'attendance-month' => [
            'title' => 'The monthly timesheet',
            'summary' => 'How punches become a timesheet, and when a month is done.',
            'points' => [
                1 => 'Punches come from the fingerprint devices, or from a report HR uploads. Each punch is kept once, so uploading the same file twice does not double it.',
                2 => 'A working day with no punch, or one punch, is never assumed: it waits for Worked or Absent, and the sheet is a draft until every day is answered.',
                3 => 'Leave is never created from attendance: it is recorded in leaves first, and then shows on the sheet.',
                4 => 'The employee confirms their month, their manager or HR approves it (nobody approves their own), then HR locks it.',
                5 => 'Overtime is not paid; it comes back as days off: locking adds its hours to the annual leave balance.',
            ],
            'keywords' => 'attendance punch fingerprint device timesheet month decision worked absent draft approve lock overtime balance',
        ],
        'first-days' => [
            'title' => 'A new volunteer\'s first days',
            'summary' => 'What happens after the first contract, and who follows each step.',
            'points' => [
                1 => 'The steps start with the first real contract. After it no step waits on another: they all run side by side.',
                2 => 'The sign-in account, the electronics and the card are due within two days of the contract start; the two orientation sessions (with HR and with the direct manager) within 14 days.',
                3 => 'The IT team of the city hands over the electronics, and HR hands over the card.',
                4 => 'Reading the policies and reviewing the Media guideline are done from the volunteer\'s own account, on the My first days page.',
            ],
            'keywords' => 'onboarding first days new volunteer account electronics card orientation session manager policies media guideline',
        ],
        'probation-review' => [
            'title' => 'The probation review',
            'summary' => 'What is asked of the manager near the end of a trial contract.',
            'points' => [
                1 => 'In the last third of a trial contract, the direct manager is asked for their view of the volunteer.',
                2 => 'The manager picks what they ask HR for: extend the trial, confirm, or cancel, and gives the reason.',
                3 => 'The request goes to HR of the city and changes nothing on the contract by itself: HR carries out the decision.',
            ],
            'keywords' => 'probation trial review extend confirm cancel direct manager trial contract',
        ],
    ],
];
