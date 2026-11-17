<?php

/*
|--------------------------------------------------------------------------
| Applicant data governance (Phase 2 Task 11)
|--------------------------------------------------------------------------
|
| Every number below is a PLACEHOLDER, not a legal position. The retention
| periods in particular must be confirmed by whoever owns data protection for
| the organization before 'retention.enforce' is switched on. Until then the
| nightly sweep only stamps retention_until and reports what it would
| anonymize; an explicit erasure request from a candidate is still carried out.
|
*/

return [
    'retention' => [
        'enforce' => (bool) env('RECRUITMENT_RETENTION_ENFORCE', false),

        // Days kept after the last of an applicant's applications closed
        // (rejected, offer rejected, withdrawn, archived).
        'closed_application_days' => (int) env('RECRUITMENT_RETENTION_CLOSED_DAYS', 365),

        // Days kept after last_seen_at for an applicant who has no application.
        'no_application_days' => (int) env('RECRUITMENT_RETENTION_IDLE_DAYS', 365),
    ],

    'anonymized' => [
        'name' => '[anonymized]',
        'text' => '[redacted]',
        // Reserved by RFC 2606: can never be delivered, so a redacted address
        // that leaks into a mail queue bounces instead of reaching anyone.
        'email_domain' => 'anonymized.invalid',
    ],

    'erasure' => [
        'channels' => ['email', 'phone', 'in_person', 'written', 'other'],
    ],

    'export' => [
        'max_rows' => (int) env('RECRUITMENT_EXPORT_MAX_ROWS', 5000),
        // At or above this many rows an export is recorded as a monitoring event.
        'alert_rows' => (int) env('RECRUITMENT_EXPORT_ALERT_ROWS', 1000),
        'max_per_hour' => (int) env('RECRUITMENT_EXPORT_MAX_PER_HOUR', 5),
        'chunk' => 500,
    ],

    'monitoring' => [
        'channel' => env('SECURITY_LOG_CHANNEL', 'security'),

        // A repeated event is written at its 1st, 10th, 100th... occurrence
        // within this window, so an attacker cannot turn the log into a sink.
        'dedupe_window' => 300,

        // Each burst fires when its counter reaches the threshold within the
        // window, and again at every further multiple of it.
        'downloads' => ['threshold' => 30, 'window' => 600],
        'submission_failures' => ['threshold' => 10, 'window' => 900],
        'token_probes' => ['threshold' => 15, 'window' => 600],
        'rate_limit_hits' => ['threshold' => 20, 'window' => 600],
    ],
];
