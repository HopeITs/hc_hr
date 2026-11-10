<?php

return [
    'title' => 'Team calendar',
    'intro' => 'Who is away, so you can plan. Only the people you may see are shown; private reasons never are.',
    'nav' => 'Team calendar',

    'views' => [
        'label' => 'View',
        'month' => 'Month',
        'week' => 'Week',
        'day' => 'Day',
        'agenda' => 'List',
    ],

    'scopes' => [
        'label' => 'Team',
        'colleagues' => 'All my colleagues',
        'department' => 'My department',
        'center' => 'My center',
        'directManager' => 'My manager’s team',
        'programManagers' => 'Project managers of my program',
        'serviceDepartments' => 'Service departments in my governorate',
        'reports' => 'My direct reports',
        'city' => 'My city',
        'org' => 'Everyone',
    ],

    'status' => [
        'label' => 'Status',
        'all' => 'Accepted and pending',
        'accepted' => 'Accepted',
        'pending' => 'Pending',
    ],

    'city' => [
        'label' => 'City',
        'all' => 'All cities',
    ],

    'nav_buttons' => [
        'previous' => 'Previous',
        'next' => 'Next',
        'today' => 'Today',
    ],

    'on_leave' => 'On leave',
    'partial' => 'Part of the day',
    'you' => 'You',
    'substitute' => 'Substitute: :name',
    'holiday' => 'Holiday',
    'weekend' => 'Weekend',
    'more' => '+:count more',
    'away_of' => ':away of :expected away',
    'pending_count' => ':count pending',
    'nobody' => 'Nobody is away.',
    'failed' => 'The calendar could not be loaded.',
    'empty' => 'Nobody you can see is away in this period.',
    'range' => ':from – :to',
    'legend_label' => 'Legend',
    'add_holiday' => 'Add holiday',
    'birthday' => 'Birthday',
    'birthday_of' => ":name's birthday",
    'your_birthday' => 'Your birthday',
    'request_leave' => 'Request leave',
    'request_leave_on' => 'Request leave on :date',
    'add_holiday_on' => 'Add a holiday on :date',
    'legend' => 'Accepted leave has a solid border and a tick; pending leave has a dashed border and a clock. Part-day leave is marked with a half circle.',

    'hint' => [
        'colleagues_away' => '{1} 1 colleague is already away on some of these days.|[2,*] :count colleagues are already away on some of these days.',
        'manager_away' => 'Your direct manager is on leave on some of these days. HR may decide on their behalf.',
        'advisory' => 'For your information only: it does not stop the request.',
    ],

    'overlap' => [
        'title' => 'Others in this team are away on these days',
        'line' => ':name (:status): :dates',
        'advisory' => 'For your information only: you can still accept or reject.',
    ],
];
