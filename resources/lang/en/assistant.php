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
    ],
];
