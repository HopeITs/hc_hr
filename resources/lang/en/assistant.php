<?php

// The AI assistant page (App\Livewire\Assistant\Chat).
return [
    'nav' => 'Ask the assistant',
    'title' => 'Assistant',
    'lead' => 'Ask about the organisation\'s policies or your own record: leave, requests, contract and shared files.',
    'disclaimer' => 'Answers are written by AI and may be wrong. HR has the final word: check with them before you act on an answer.',

    'empty' => [
        'title' => 'What would you like to know?',
        'help' => 'Pick a question below or write your own.',
    ],

    'suggestions' => [
        'How much leave do I have left?',
        'What is the complaints policy?',
        'Which files are shared with me?',
        'How do I request sick leave?',
    ],

    'question' => 'Your question',
    'placeholder' => 'Type your question…',
    'hint' => 'Enter sends, Shift+Enter starts a new line.',
    'send' => 'Send',
    'clear' => 'New conversation',
    'thinking' => 'Writing an answer…',

    'errors' => [
        'limit' => 'You have asked a lot of questions this hour. Please try again in :minutes min.',
        'busy' => 'The assistant is busy right now. Please try again in a minute.',
        'failed' => 'The assistant could not answer just now. Please try again in a moment.',
        'submit' => 'The request could not be sent just now. Please try again in a moment.',
    ],

    // The floating button and small chat panel on every page.
    'widget' => [
        'open' => 'Ask the assistant',
        'close' => 'Close the assistant',
        'expand' => 'Open the conversation in a full page',
        'loading' => 'Loading…',
    ],

    // A leave request the assistant drafted; sent only once the employee confirms it.
    'leave' => [
        'title' => 'Leave request draft',
        'errors' => [
            'forbidden' => 'You are not allowed to request leave.',
            'hours-annual-only' => 'Only annual leave can be taken by the hour.',
            'hours-one-day' => 'An hourly leave must start and end on the same day.',
        ],
        'check' => 'Check the details before you send it. Nothing is sent until you press "Confirm and submit".',
        'confirm' => 'Confirm and submit',
        'cancel' => 'Cancel',
        'fields' => [
            'type' => 'Leave type',
            'from' => 'From',
            'to' => 'To',
            'duration' => 'Duration',
            'reason' => 'Reason',
            'alternative' => 'Alternative',
        ],
        'states' => [
            'pending' => 'Waiting for you',
            'submitted' => 'Submitted',
            'cancelled' => 'Discarded',
            'failed' => 'Not sent',
        ],
        'submitted' => 'Your leave request was submitted and is now waiting for approval.',
        'open-my-leaves' => 'Open My leaves',
        'cancelled' => 'Draft discarded. No request was sent.',
        'failed' => 'The request was not sent:',
    ],
];
