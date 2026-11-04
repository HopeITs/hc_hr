<?php

// News & Events (App\Models\News and its enums). Sections below the base
// keys are added per screen; append with care, never rewrite the file.
return [
    'title' => 'News & Events',

    'types' => [
        'news' => 'News',
        'announcement' => 'Announcement',
        'event' => 'Event',
    ],
    'types_hint' => [
        'news' => 'Something that happened or an update to share.',
        'announcement' => 'Important organizational information, optionally until a date.',
        'event' => 'Something scheduled to take place on a date.',
    ],

    'states' => [
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
        'expired' => 'Expired',
        'archived' => 'Archived',
    ],

    'related' => [
        'organization' => 'Organization',
        'city' => 'City',
        'center' => 'Center',
        'program' => 'Program / project',
        'department' => 'Department',
        'employee' => 'Employee',
    ],

    'audience' => [
        'all' => 'All employees',
        'cities' => 'Selected cities',
        'centers' => 'Selected centers',
        'programs' => 'Selected programs',
        'departments' => 'Selected departments',
        'employees' => 'Selected employees',
    ],

    'timing' => [
        'upcoming' => 'Upcoming',
        'today' => 'Today',
        'past' => 'Past',
    ],
];
