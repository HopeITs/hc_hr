<?php

// A news item the assistant drafted (App\Services\Assistant\Drafts\NewsDraft).
return [
    'title' => 'News item draft',
    'fields' => [
        'title_ar' => 'Title (Arabic)',
        'title_en' => 'Title (English)',
        'description_ar' => 'Text (Arabic)',
        'description_en' => 'Text (English)',
        'event_date' => 'Event date',
        'display_until' => 'Shown until',
        'target_type' => 'Audience type',
        'target' => 'Audience',
    ],
    'targets' => [
        'all' => 'Everyone',
        'city' => 'City',
        'center' => 'Center',
        'program' => 'Program',
        'employee' => 'Employee',
    ],
    'errors' => [
        'target' => 'Choose the audience from the list the news page offers.',
    ],
    'created' => 'The news item was published. You can add an image on its page.',
    'open' => 'Open the news item',
    'open-list' => 'Open the news',
];
