<?php

return [
    // Shared frame of every email the application sends.
    'organization' => 'Christian Hope Center',
    'greeting' => 'Hello,',
    'greeting_error' => 'Something needs your attention',
    'regards' => 'Regards,',
    'signature' => ':app team',
    'action_fallback' => 'If the ":action" button does not work, copy this link into your browser:',
    'automated' => 'This is an automated message from :app. Please do not reply to it.',
    'copyright' => '© :year :organization',
    'security_notice' => 'Security notice',

    // One-time codes (sign-in, verification). The preheader is the inbox
    // preview line: it must never contain the code itself.
    'code' => [
        'label' => 'Your code',
        'expires' => '{1} This code expires in 1 minute.|[2,*] This code expires in :count minutes.',
        'preheader' => '{1} Your one-time code is inside. It expires in 1 minute.|[2,*] Your one-time code is inside. It expires in :count minutes.',
        'never_share' => 'Never share this code with anyone. :organization staff will never ask you for it.',
    ],

    'reset_password' => [
        'subject' => 'Reset your password',
        'preheader' => 'Use the link inside to choose a new password.',
        'intro' => 'We received a request to reset the password for your :app account.',
        'action' => 'Reset password',
        'expires' => '{1} This link expires in 1 minute.|[2,*] This link expires in :count minutes.',
        'notice' => 'If you did not ask to reset your password, ignore this email and your password will stay the same. Never share this link with anyone.',
    ],

    'verify_email' => [
        'subject' => 'Verify your email address',
        'preheader' => 'Confirm your email address to finish setting up your account.',
        'intro' => 'Please confirm that this is your email address by pressing the button below.',
        'action' => 'Verify email address',
        'expires' => '{1} This link expires in 1 minute.|[2,*] This link expires in :count minutes.',
        'notice' => 'If you did not create a :app account, ignore this email. No account will be verified without this link.',
    ],
];
