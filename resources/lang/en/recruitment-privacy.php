<?php

return [
    'export' => [
        'empty' => 'There are no applications to export for these filters.',
        'too_large' => 'This export would hold :count applications and the limit is :max. Narrow the filters and try again.',
        'rate_limited' => 'You have reached the hourly limit for exports. Try again later.',
    ],

    'erasure' => [
        'completed' => 'The applicant\'s personal data has been anonymized.',
        'pending' => 'The request is recorded. It will be carried out once :blocker.',
        'blockers' => [
            'active_application' => 'their open application is closed',
            'hired' => 'the hire is resolved',
        ],
    ],
];
