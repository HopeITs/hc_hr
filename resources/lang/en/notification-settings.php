<?php

// Notification settings page (settings.notifications) and the labels the
// NotificationCategory / NotificationChannel enums read. Delivery-side words
// (push titles, summaries, e-mail) are in notifications-delivery.php.
return [
    'menu' => 'Notifications',
    'title' => 'Notifications',
    'lead' => 'Choose what reaches you, where, and when. Every change is saved as you make it.',
    'back' => 'My account',

    'status' => [
        'saved' => 'Saved',
        'error' => 'Not saved — check the highlighted setting',
        'invalid' => 'That value is not allowed.',
    ],

    // Validation attribute names.
    'fields' => [
        'enabled' => 'notifications',
        'in_app' => 'in-app notifications',
        'desktop' => 'desktop push',
        'mobile' => 'mobile push',
        'email' => 'e-mail',
        'sound' => 'sound',
        'previews' => 'previews',
        'quiet_hours' => 'quiet hours',
        'urgent_bypass' => 'urgent notifications in quiet hours',
        'frequency' => 'frequency',
        'digest_time' => 'summary time',
        'quiet_start' => 'quiet hours start',
        'quiet_end' => 'quiet hours end',
        'timezone' => 'time zone',
        'working_days' => 'working days',
    ],

    'device' => [
        'title' => 'This device',
        'lead' => 'Notifications on this browser or installed app. The browser asks for permission only when you press Turn on.',
        'state' => [
            'on' => 'On for this device',
            'off' => 'Not on for this device',
            'default' => 'Permission not asked yet',
            'denied' => 'Blocked in this browser',
            'unsupported' => 'This browser cannot show notifications',
            'insecure' => 'Needs a secure (https) connection',
            'ios_install' => 'Add to Home Screen first',
            'not_configured' => 'Push is not set up on the server yet',
        ],
        'enable' => 'Turn on for this device',
        'disable' => 'Turn off on this device',
        'local-test' => 'Show a test on this device',
        'local-title' => 'HopeHR test',
        'local-body' => 'If you can see this, this device shows HopeHR notifications.',
        'local-result' => [
            'shown' => 'Test shown. If nothing appeared, check the device’s own notification settings below.',
            'denied' => 'Notifications are not allowed for this site yet.',
            'unsupported' => 'This browser cannot show notifications.',
            'failed' => 'The test could not be shown.',
        ],
        'working' => 'Working…',
        'installed' => 'Installed app',
        'ios-install' => 'On iPhone and iPad, notifications work only after you add HopeHR to the Home Screen and open it from there.',
        'ios-install-link' => 'How to add it',
        'os-note' => 'HopeHR cannot read or change your device’s own settings. Device-level notifications may be off even when everything here is on.',
        'errors' => [
            'denied' => 'Notifications are blocked for this site. Allow them in the browser’s settings, then try again.',
            'unsupported' => 'This browser does not support notifications. Try a recent Chrome, Edge, Firefox or Safari.',
            'not_configured' => 'Push notifications are not set up on the server yet. In-app notifications still work.',
            'insecure' => 'Notifications need a secure (https) connection.',
            'network' => 'Could not reach HopeHR. Check your connection and try again.',
            'failed' => 'Something went wrong while turning notifications on. Try again.',
            'ios_install' => 'Add HopeHR to the Home Screen and open it from there first.',
        ],
        'blocked' => [
            'title' => 'How to allow notifications again',
            'chromium' => 'Chrome or Edge: select the icon at the start of the address bar › Site settings › Notifications › Allow.',
            'safari' => 'Safari on Mac: Safari › Settings › Websites › Notifications, then set this site to Allow.',
            'firefox' => 'Firefox: select the icon at the start of the address bar and clear the “Blocked” notifications permission.',
            'android' => 'Android: long-press the browser or the HopeHR app icon › App info › Notifications, and turn them on.',
            'ios' => 'iPhone or iPad: Settings › Notifications › HopeHR (after adding it to the Home Screen).',
        ],
        'os' => [
            'title' => 'Still nothing on screen?',
            'windows' => 'Windows: Settings › System › Notifications — make sure your browser is allowed and Do not disturb (Focus assist) is off.',
            'macos' => 'Mac: System Settings › Notifications › your browser — allow notifications and check Focus.',
            'android' => 'Android: Settings › Apps › your browser or HopeHR › Notifications.',
            'ios' => 'iPhone or iPad: Settings › Notifications › HopeHR, and check Focus.',
        ],
    ],

    'explainer' => [
        'title' => 'How notifications reach you',
        'in_app' => [
            'title' => 'In-app',
            'body' => 'The bell inside HopeHR. They are always kept there, even when other channels are off or paused.',
        ],
        'push' => [
            'title' => 'Desktop and mobile',
            'body' => 'The same Web Push behind both. Your browser or installed app hands them to the system’s notification centre: Action Center on Windows, Notification Centre on Mac, the notification shade on Android, and Notification Centre on iPhone and iPad for Home Screen apps.',
        ],
        'split' => 'Desktop push goes to computers, mobile push to phones and tablets, so you can keep one quiet and the other on.',
        'email' => [
            'title' => 'E-mail',
            'body' => 'Sent to the address on your account.',
        ],
    ],

    'general' => [
        'title' => 'General',
        'master' => [
            'label' => 'Notifications',
            'description' => 'Turn everything off at once. Your other choices stay as they are.',
            'paused' => 'All notifications are paused. Your settings below are kept and come back when you turn this on.',
        ],
        'channels' => 'Where they reach you',
        'channel-hint' => [
            'in_app' => 'The bell inside HopeHR.',
            'desktop' => 'Pop-ups on computers where you turned notifications on.',
            'mobile' => 'Pop-ups on phones and tablets where you turned notifications on.',
            'email' => 'Messages to your e-mail address.',
        ],
        'sound' => [
            'label' => 'Sound',
            'description' => 'A short chime for new notifications in HopeHR. Off also makes push notifications silent.',
        ],
        'previews' => [
            'label' => 'Show details in pop-ups',
            'description' => 'Off hides names and details, so a lock screen only shows that something new arrived.',
        ],
        'frequency' => [
            'label' => 'How often push and e-mail arrive',
            'description' => 'Summaries group what arrived into one message.',
            'instant' => 'As they happen',
            'hourly' => 'Hourly summary',
            'daily' => 'Daily summary',
            'time' => 'Summary time',
        ],
    ],

    'matrix' => [
        'title' => 'What you hear about',
        'lead' => 'Choose channels for each kind of notification.',
        'none' => 'There is no attendance or payroll module, so there are no notifications for them.',
        'category' => 'Kind',
        'urgent' => 'Urgent',
        'channel-off' => ':channel is off above.',
        'paused' => 'Paused',
    ],

    'categories' => [
        'leave_actions' => [
            'label' => 'Leave requests to act on',
            'description' => 'New requests waiting for your decision, and reminders.',
        ],
        'leave_updates' => [
            'label' => 'My leave updates',
            'description' => 'Decisions, objections and balance changes on your leave.',
        ],
        'contracts' => [
            'label' => 'Contracts',
            'description' => 'Contracts renewed automatically or about to expire.',
        ],
        'employees' => [
            'label' => 'Employees and profiles',
            'description' => 'Welcomes and decisions on profile changes.',
        ],
        'checklists' => [
            'label' => 'Checklists',
            'description' => 'Checklists assigned to you.',
        ],
        'files' => [
            'label' => 'Files',
            'description' => 'Folders shared with you.',
        ],
        'organization' => [
            'label' => 'Centers',
            'description' => 'Centers opened or closed.',
        ],
        'security' => [
            'label' => 'Security',
            'description' => 'Lost digital IDs and other security alerts.',
        ],
        'system' => [
            'label' => 'System',
            'description' => 'Test notifications and general messages.',
        ],
    ],

    'channels' => [
        'in_app' => ['label' => 'In-app'],
        'desktop' => ['label' => 'Desktop push'],
        'mobile' => ['label' => 'Mobile push'],
        'email' => ['label' => 'E-mail'],
    ],

    'schedule' => [
        'title' => 'Quiet hours and schedule',
        'lead' => 'During quiet hours and on days off, push and e-mail wait and arrive afterwards. In-app notifications always arrive.',
        'quiet' => [
            'label' => 'Quiet hours',
            'description' => 'Hold push and e-mail between these times.',
        ],
        'start' => 'From',
        'end' => 'Until',
        'timezone' => 'Time zone',
        'timezone-default' => 'HopeHR’s time zone (:zone)',
        'use-device' => 'Use this device’s time zone',
        'device-unknown' => 'This device’s time zone is not in the list.',
        'days' => 'Working days',
        'days-description' => 'Push and e-mail go out only on these days.',
        'days-required' => 'Keep at least one working day.',
        'urgent' => [
            'label' => 'Let urgent notifications through',
            'description' => 'Security alerts arrive at once, even in quiet hours or on days off.',
        ],
    ],

    'days' => [
        0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat',
    ],
    'days-long' => [
        0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
    ],

    'devices' => [
        'title' => 'Your devices',
        'lead' => 'Browsers and installed apps where you turned notifications on.',
        'empty-title' => 'No devices yet',
        'empty-help' => 'Turn notifications on for this device above to see it here.',
        'name' => 'Device name',
        'rename' => 'Rename',
        'rename-label' => 'Rename :name',
        'save' => 'Save name',
        'cancel' => 'Cancel',
        'unnamed' => ':browser on :platform',
        'unknown' => 'Unknown',
        'this-device' => 'This device',
        'installed' => 'Installed app',
        'last-active' => 'Last active :time',
        'never' => 'Not seen yet',
        'type' => [
            'desktop' => 'Computer',
            'mobile' => 'Phone',
            'tablet' => 'Tablet',
        ],
        'state' => [
            'active' => 'Active',
            'off' => 'Off',
            'failing' => 'Failing',
            'expired' => 'Expired',
        ],
        'last-error' => 'Last error: :error',
        'enabled' => 'Notifications on this device',
        'urgent-only' => 'Urgent only',
        'urgent-only-hint' => 'Only security alerts reach this device.',
        'channel-off' => ':channel is off, so this device gets nothing for now.',
        'test' => 'Send test',
        'remove' => 'Remove',
        'remove-title' => 'Remove this device?',
        'remove-message' => ':name will stop getting notifications. You can turn them on again from that device.',
        'remove-confirm' => 'Remove device',
    ],

    'test' => [
        'title' => 'Send a test notification',
        'lead' => 'A real notification, so you can see exactly how it arrives.',
        'target' => 'Send to',
        'targets' => [
            'device' => 'This device',
            'devices' => 'All my devices',
            'in_app' => 'In-app (the bell)',
            'email' => 'My e-mail',
        ],
        'send' => 'Send test',
        'sent' => 'Sent',
        'not-sent' => 'Not sent',
        'no-device' => 'Turn notifications on for this device first.',
        'invalid-target' => 'Choose where to send the test.',
        'throttled' => 'Too many tests. Try again in :seconds seconds.',
        'failed' => 'The test could not be sent. Try again later.',
    ],
];
