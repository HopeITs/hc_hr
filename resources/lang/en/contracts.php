<?php

/*
 * Contracts area: list table, composer (create/edit), renewal and the
 * on-screen contract viewer's chrome. The contract document itself (the
 * bilingual clauses under livewire/contract/details/*) is not translated
 * through here: it prints both languages side by side by design.
 */

return [
    'contract' => 'Contract',
    'edit-contract' => 'Edit contract',

    // Contract viewer pager (screen only; the PDF has its own footer).
    'pager-label' => 'Contract pages',
    'page-of' => 'Page :page of :total',
    'page-short' => ':page of :total',
    'go-to-page' => 'Page :page',

    // List table status column.
    'status-terminated' => 'Terminated',
    'status-expired' => 'Expired',

    // Counts. Decimal variants are used for fractional values (e.g. 2.5 months).
    'months-count' => '{1} :count month|[0,*] :count months',
    'months-decimal' => ':count months',
    'days-count' => '{1} :count day|[0,*] :count days',
    'days-decimal' => ':count days',
    'hours-count' => '{1} :count hour|[0,*] :count hours',
    'hours-decimal' => ':count hours',
    'hours-short' => ':count h',
    'tiers-count' => '{0} No conditions|{1} :count condition|[2,*] :count conditions',

    // Composer: labels next to the "include in printed contract" checkboxes.
    'include-days-per-week' => '{1} Include ":count day/week" in the printed contract|[0,*] Include ":count days/week" in the printed contract',
    'include-hours-per-week' => '{1} Include ":count hour/week" in the printed contract|[0,*] Include ":count hours/week" in the printed contract',
    'include-hours-per-day' => '{1} Include ":count hour/day" in the printed contract|[0,*] Include ":count hours/day" in the printed contract',

    'amount' => 'Amount',

    // Composer: short explanations next to the controls they describe.
    'composer-help' => [
        'location' => 'Choose the city first. Programs list only those with active positions in that city, and positions list only those in the chosen program. The center comes from the position.',
        'capacity' => 'A position marked “Full” already has a contract in every seat, so it cannot be chosen. Pick another position, or ask for its number of seats to be raised.',
        'draft' => 'A draft only reserves the position: it has no dates or pay, does not take one of the position’s seats, and is not an active contract. Saving here turns it into an active contract and takes the seat.',
        'status' => 'A saved contract is active. It stops being active when its end date passes or when a termination date is recorded. New contracts renew automatically by default, except trial contracts.',
        'auto-renew' => 'When this is on and the contract ends without a termination, a new contract with the same terms, pay included, is created automatically. It starts the day after the end date and runs to the end of that year. Trial contracts never renew.',
    ],

    // Contract PDF export (details page and list): loading, failure and retry.
    // export-failed is also the plain-text body the server sends with a 503.
    'export-loading' => 'Preparing PDF…',
    'export-failed' => 'The contract PDF could not be generated. Your contract is unchanged — try again.',
    'export-forbidden' => 'You are not allowed to export this contract.',
    'export-error' => 'The PDF could not be downloaded. Check your connection and try again.',
    'export-retry' => 'Try again',
    'export-retry-label' => 'Try the PDF export again',

    // Server-side checks raised by the composer.
    'errors' => [
        'city-not-allowed' => 'You may only manage contracts in your active city.',
        'employee-city-mismatch' => 'The selected employee must belong to the contract city.',
        'position-not-found' => 'The selected position no longer exists.',
        'position-not-in-city' => 'The selected position is not in the contract city.',
        'position-program-mismatch' => 'The selected position does not belong to the selected program.',
        'position-center-mismatch' => 'The selected position does not belong to the selected center.',
        'position-full' => 'This position has no free seats left.',
    ],

    // Validation attribute names for ContractForm.
    'attributes' => [
        'employee_id' => 'employee',
        'position_id' => 'position',
        'contract_type_id' => 'contract type',
        'type' => 'type',
        'place' => 'place',
        'is_active' => 'status',
        'start_date' => 'start date',
        'end_date' => 'end date',
        'terminated_at' => 'termination date',
        'termination_reason_id' => 'termination reason',
        'reason_of_termination' => 'termination note',
        'weekly_hours' => 'weekly hours',
        'schedule_mode' => 'schedule',
        'work_day_from' => 'from day',
        'work_day_to' => 'to day',
        'work_hours_from' => 'work hours from',
        'work_hours_to' => 'work hours to',
        'work_hours_2_from' => 'second shift from',
        'work_hours_2_to' => 'second shift to',
        'flex_days_per_week' => 'days per week',
        'flex_hours_per_week' => 'hours per week',
        'flex_hours_per_month' => 'hours per month',
        'flex_custom_en' => 'custom description (EN)',
        'flex_custom_ar' => 'custom description (AR)',
        'pay_mode' => 'pay mode',
        'currency' => 'currency',
        'salary' => 'salary',
        'salary_per' => 'salary per',
        'session_duration' => 'session duration',
        'pay_tiers' => 'pay conditions',
        'pay_tiers.*.condition_en' => 'condition (EN)',
        'pay_tiers.*.condition_ar' => 'condition (AR)',
        'pay_tiers.*.amount' => 'amount',
    ],

    // B4 contracts list: named views, row actions, details drawer.
    'views-label' => 'View',
    'view-active' => 'Active',
    'view-ending-soon' => 'Ending soon (:days days)',
    'view-ended' => 'Terminated / ended',
    'view-draft' => 'Draft reservations',
    'ends-in-days' => '{0} Ends today|{1} Ends tomorrow|[2,*] Ends in :count days',
    'view-named' => 'View contract of :name',
    'more-actions-for' => 'More actions for :name',
    'auto-renew-turn-on' => 'Turn auto-renew on',
    'auto-renew-turn-off' => 'Turn auto-renew off',
    'drawer-title' => 'Contract details',
    'drawer-close' => 'Close contract details',
    'drawer-open-full' => 'Open full contract',
    'export-columns-shown' => 'Exports the columns shown',
];
