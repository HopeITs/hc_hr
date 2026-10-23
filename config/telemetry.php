<?php

/*
|--------------------------------------------------------------------------
| Task-attempt telemetry (X2-I)
|--------------------------------------------------------------------------
|
| Counts attempts and outcomes of the four X2 usability tasks so X2-R can
| compare moderated sessions with everyday use. DEC-10 (2026-10-16): anonymous,
| no names or personal data, stored on our own server only, deleted after
| 90 days. See docs/ux/x2-i-implementation.md.
|
| Off unless TASK_TELEMETRY_ENABLED=true: writing the table must not start
| collecting data on any host by itself. Turning it on in production is a
| separate, authorized decision.
|
*/

return [

    'enabled' => (bool) env('TASK_TELEMETRY_ENABLED', false),

    // DEC-10: deleted after 90 days (telemetry:prune, scheduled daily).
    'retention_days' => 90,

    // An attempt with no outcome after this long counts as abandoned in
    // reports. Nothing is written when it expires; the report decides.
    'abandon_after_minutes' => 30,

    // Report cells with fewer attempts than this are shown as "<5" so a small
    // office cannot be singled out by role and language.
    'min_cell' => 5,

    // The four X2 tasks (docs/ux/x2/task-scripts.md). Nothing else is recorded.
    'tasks' => [
        'leave_request',   // A
        'employee_lookup', // B
        'leave_review',    // C
        'contract_create', // D
    ],

    'outcomes' => ['completed', 'cancelled'],
];
