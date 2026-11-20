<?php

// Probation review: a trial in its last third (meeting 19/11, track 4).
// The manager's answer is a request to HR; nothing is applied to the contract.
return [
    'title' => 'Trial review',
    'lead' => 'The trial of :name is in its last third. Tell HR what you ask for and why.',
    'form-hint' => 'This is a request to HR. It does not change the contract: HR decides what happens next.',
    'send' => 'Send to HR',
    'sent' => 'Your request was sent to HR.',
    'already' => 'This review was already answered.',
    'nothing-applied' => 'The contract was not changed. HR decides what happens next.',
    'waiting-hint' => 'The direct manager has not answered yet.',
    'by' => 'By :name on :date',
    'fields' => [
        'employee' => 'Volunteer',
        'position' => 'Position',
        'last-third' => 'Last third starts',
        'ends' => 'Trial ends',
        'decision' => 'Request',
        'reason' => 'Reason',
    ],
    'decision' => [
        'extend' => 'Extend the trial period',
        'confirm' => 'Confirm (permanent contract)',
        'cancel' => 'Cancel (end the contract)',
    ],
    'status' => [
        'requested' => 'Request sent to HR',
        'waiting' => 'Waiting for the manager',
    ],
    'errors' => [
        'decision' => 'Choose extend, confirm or cancel.',
        'reason' => 'The reason is required.',
    ],
    'card' => [
        'title' => 'Trial review',
        'open' => 'Open the review',
    ],
];
