<?php

// D3 in-app feedback (DEC-08). Not the toast/confirmation "feedback" of WithFeedback.
return [
    'menu' => 'Send feedback to the IT team',

    'categories' => [
        'problem' => 'Problem',
        'idea' => 'Idea',
        'question' => 'Question',
    ],

    'form' => [
        'title' => 'Send feedback to the IT team',
        'category' => 'Type',
        'category-none' => 'Not specified',
        'message' => 'Your feedback',
        'placeholder' => 'What happened, or what would help? Describe it in words.',
        'help' => 'Text only. Files and screenshots cannot be attached.',
        'send' => 'Send',
        'sent' => 'Thank you. Your feedback was sent to the IT team.',
        'rate-limited' => '{1} You have sent several notes in a short time. Try again in :minutes minute.|[2,*] You have sent several notes in a short time. Try again in :minutes minutes.',
        'role-changed' => 'Your active role changed since this form opened. Check what will be sent above, then send again.',
    ],

    'disclosure' => [
        'title' => 'Sent with your note',
        'page' => 'Page',
        'page-unknown' => 'Not recorded',
        'language' => 'Language',
        'role' => 'Active role',
        'account' => 'Sender',
        'account-value' => 'Your account, so the IT team can follow up',
        'text-only' => 'Only the text you type is sent: nothing from the page or its forms, and no files or screenshots.',
        'no-personal' => 'Do not include personal data or pay figures (salaries, ID numbers, phone numbers).',
        'retention' => 'Kept for one year, then deleted.',
        'readers' => 'Read only by the IT team.',
    ],

    'inbox' => [
        'title' => 'User feedback',
        'intro' => 'Notes sent from the app. Visible to the IT team only; each note is deleted one year after it was sent.',
        'all' => 'All types',
        'count' => '{0} No notes|{1} 1 note|[2,*] :count notes',
        'empty' => 'No feedback yet.',
        'deleted-user' => 'Removed account',
    ],
];
