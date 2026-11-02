<?php

// The assistant's draft of a leave request HR files for an employee
// (App\Services\Assistant\Drafts\LeaveForEmployeeDraft).
return [
    'title' => 'Leave request for an employee',
    'fields' => [
        'employee' => 'Employee',
        'type' => 'Leave type',
        'from' => 'From',
        'to' => 'To',
        'duration' => 'Duration',
        'reason' => 'Reason',
        'alternative' => 'Alternative employee',
    ],
    'submitted' => 'The leave request for :name was created. It is waiting for a decision.',
    'open' => 'Open in All leaves',
    'errors' => [
        'employee' => 'You cannot file a leave request for this employee.',
        'hours-annual-only' => 'Only annual leave can be taken by the hour.',
        'hours-one-day' => 'An hourly leave must start and end on the same day.',
    ],
];
