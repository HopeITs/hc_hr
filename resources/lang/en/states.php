<?php

/*
 * B3: empty / filtered / error copy for the leave, checklist and notification
 * screens. The generic wording (no results, retry, clear filters) is in
 * general.state-*; only screen-specific text lives here.
 */
return [
    'loading' => 'Loading…',

    'leave-empty-title' => 'No leave requests yet',
    'leave-empty-help' => 'When you send a request it appears here with its status and who acts next.',
    'leave-empty-help-readonly' => 'There are no leave requests to show.',
    'leave-all-empty-title' => 'No leave requests to review',
    'leave-all-empty-help' => 'Requests from the employees you manage will appear here.',
    'leave-balance-empty-title' => 'No leave balances yet',
    'leave-balance-empty-help' => 'Employees with an annual leave balance will appear here.',
    'leave-tab-empty-title' => 'No requests with this status',
    'leave-tab-empty-help' => 'Show all requests to see the others.',
    'balances-unavailable' => 'Your leave balances could not be loaded. Retry to try again.',

    'items-count' => '{0} No items|{1} 1 item|[2,*] :count items',

    'checklist-empty-title' => 'No checklist items yet',
    'checklist-empty-help' => 'Items HR assigns to you will appear here.',
    'pending-delivers-empty-title' => 'Nothing waiting for you to deliver',
    'pending-delivers-empty-help' => 'New delivery requests assigned to you will appear here.',
    'pending-returns-empty-title' => 'Nothing waiting for you to receive',
    'pending-returns-empty-help' => 'Return requests assigned to you will appear here.',
    'done-empty-title' => 'No completed requests yet',
    'done-empty-help' => 'Delivered and returned items will appear here.',

    'notifications-empty-title' => 'You are all caught up',
    'notifications-empty-help' => 'New notifications will appear here.',
    'notifications-summary' => '{0} No unread notifications|{1} 1 unread notification|[2,*] :count unread notifications',
    'notifications-loading' => 'Loading notifications…',
];
