<?php

/*
 * In-app feedback (D3, DEC-08). Read by the development (IT) team only, text
 * only, deleted after one year. See docs/ux/d3-feedback-notes.md.
 */
return [
    // DEC-08: deleted after one year (InAppFeedback::prunable(), model:prune).
    'retention_days' => 365,

    'max_length' => 2000,

    'categories' => ['problem', 'idea', 'question'],

    // Submissions per user per window.
    'rate_limit' => [
        'max_attempts' => 5,
        'decay_seconds' => 3600,
    ],

    /*
     * Who may read feedback (User::canReadFeedback()): DEC-08's "IT team" is
     * anyone with a contract in force on a position of this department
     * (departments.code). Not a role or permission.
     */
    'reader_department_code' => 'IT',
];
