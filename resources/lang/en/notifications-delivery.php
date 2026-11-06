<?php

// What push notifications, notification emails and "send a test" say
// (App\Services\Notifications, docs/notifications/architecture.md).
return [
    'push' => [
        // Shown instead of the notification when previews are off: no names, no HR data.
        'generic_body' => 'You have a new notification. Open HopeHR to read it.',
    ],

    'summary' => [
        'title' => 'You have :count new notifications',
        'body' => 'Open HopeHR to see them.',
    ],

    'email' => [
        'open' => 'Open in HopeHR',
        'open_all' => 'Open notifications',
        'footer' => 'You get this email because email notifications are on in your notification settings.',
        'test_footer' => 'This is a test sent from your notification settings.',
    ],

    'test' => [
        'title' => 'Test notification',
        'body' => 'If you can read this, notifications reach you here.',
    ],

    'tester' => [
        'unknown_target' => 'This kind of test does not exist.',
        'push_not_configured' => 'Push notifications are not set up on this server yet. Please tell the IT team.',
        'device_not_found' => 'This device is no longer connected to your account.',
        'device_accepted' => 'Accepted by the push service. The test should appear on this device in a moment.',
        'device_accepted_push_off' => 'Accepted by the push service. Note: notifications for this device are turned off in your settings, so real ones will not arrive here.',
        'device_gone' => 'The push service says this device is no longer subscribed, so it was removed. Turn notifications on again on that device.',
        'device_failed' => 'The push service did not accept the test (:reason). Try again later.',
        'no_devices' => 'No device is set to receive notifications yet.',
        'devices_accepted' => 'Accepted by the push service for :accepted of :total devices.',
        'devices_none_accepted' => 'The push service did not accept the test for any of your :total devices.',
        'in_app_off' => 'In-app notifications are turned off in your settings, so the test was not sent.',
        'in_app_failed' => 'The test could not be added to your notifications. Try again later.',
        'in_app_sent' => 'A test was added to your notifications (the bell).',
        'no_email_address' => 'Your account has no email address.',
        'email_off' => 'Email notifications are turned off in your settings, so the test was not sent.',
        'email_failed' => 'The mail server did not accept the test email (:reason).',
        'email_sent' => 'The test email was handed to the mail server for :email. It may take a few minutes to arrive.',
    ],
];
