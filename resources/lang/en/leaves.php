<?php

return [

    // Leave request workflow status: one term per status, used everywhere.
    'status' => [
        'pending' => 'Pending',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
    ],

    // Unit of a leave request / balance adjustment (radio options, table type column).
    'unit' => [
        'legend' => 'Leave unit',
        'day' => 'Day',
        'hour' => 'Hour',
    ],

    // Leave type eligibility labels (App\Enums\LeaveType).
    'gender' => [
        'any' => 'All',
        'male' => 'Male',
        'female' => 'Female',
    ],

    'marital' => [
        'any' => 'All',
        'single' => 'Single',
        'married' => 'Married',
    ],

    'select-employee' => 'Select employee…',

    // Separator between the start and end of a period ("from → to").
    'range-separator' => '→',

    // Counted durations. Always pass the number as :count.
    'duration' => [
        'days' => '{0} :count days|{1} :count day|[2,*] :count days',
        'days-fraction' => ':count days',
        'hours' => '{0} :count hours|{1} :count hour|[2,*] :count hours',
        'minutes' => '{0} :count minutes|{1} :count minute|[2,*] :count minutes',
        'and' => ':first and :second',
    ],

    'validation' => [
        'start-hour-within-working-hours' => 'The start time must be within the center\'s working hours.',
        'end-hour-within-working-hours' => 'The end time must be within the center\'s working hours.',
        'end-hour-after-start-hour' => 'The end time must be after the start time.',
        'max-hours' => 'The duration must not exceed :max.',
        'max-days' => 'The total leave duration (:days) exceeds the allowed maximum (:max).',
        'range-too-long' => 'A single request can cover at most :days days. Split it into shorter requests.',
        'end-before-start' => 'The end date is before the start date. Choose an end date on or after the start date.',
        'overlap' => 'You already have a :status leave request for :period. Change these dates, or edit that request instead.',
    ],

    // Result of sending or updating a request (A3).
    'submit' => [
        'sent' => 'Leave request sent. Status: :status.',
        'updated' => 'Leave request updated. Status: :status.',
        'sent-title' => 'Your leave request was sent',
        'updated-title' => 'Your leave request was updated',
        'view-details' => 'View details',
        'dismiss' => 'Hide this message',
        'in-progress' => 'This request is still being saved. Wait a moment, then check your list before sending it again.',
    ],

    // Who acts next on a request, read from the real approval rules.
    // C3: HR's note on a reviewed or objected request (field label in errors).
    'review' => [
        'notes-title' => 'Notes from HR',
        'kind' => [
            'note' => 'Note',
            'objection' => 'Objection',
        ],
        'note' => 'note',
    ],

    'next-step' => [
        'title' => 'Next step',
        // C3 (DEC-05 revision): only the direct manager decides; HR is informed.
        'pending-managers-and-hr' => 'Waiting for a decision from :managers, who has been sent a notification. The HR team in :city has been informed.',
        'pending-managers' => 'Waiting for a decision from :managers, who has been sent a notification.',
        'pending-hr' => 'Waiting for a decision from the HR team in :city, which has been sent a notification.',
        'pending-unassigned' => 'Waiting for a decision from HR. No manager or HR account was found to notify, so please let your HR team know.',
        'managers' => '{1} your manager :names|[2,*] your managers :names',
        'or' => ':first or :last',
        'list-separator' => ', ',
        'decided-by' => 'Decided by :name on :date.',
        'decided-on-behalf' => 'Decided by :name (HR) on behalf of :manager on :date.',
        'decided-no-manager' => 'Decided by :name (HR, no direct manager) on :date.',
        'decided' => 'Decided on :date.',
        'awaiting-hr' => 'Next: HR marks the decision as seen. Nothing is needed from you.',
        'hr-seen' => 'HR has seen the decision. There are no further steps.',
    ],

    // G-B-1: a decision opened or saved after the request was already decided.
    'decision' => [
        'already' => [
            'accepted-by' => 'This request was already accepted by :name on :date. Your decision was not saved.',
            'rejected-by' => 'This request was already rejected by :name on :date. Your decision was not saved.',
            'accepted' => 'This request was already accepted on :date. Your decision was not saved.',
            'rejected' => 'This request was already rejected on :date. Your decision was not saved.',
            'accepted-by-you' => 'Your decision to accept this request was already saved on :date. Nothing more is needed.',
            'rejected-by-you' => 'Your decision to reject this request was already saved on :date. Nothing more is needed.',
        ],
    ],

    // A5-M: the leave request dialog (new and edit). The close guard's wording is general.unsaved.*.
    'modal' => [
        'new-title' => 'Request leave',
        'submit' => 'Submit request',
        'edit-title' => 'Edit leave request',
        'save-changes' => 'Save changes',
    ],

];
