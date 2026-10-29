<?php

// Prompt 9: sidebar favourites and the recents shown in Ctrl+K.
return [
    'favourites' => [
        'title' => 'My favorites',
        'add' => 'Add :page to favorites',
        'remove' => 'Remove :page from favorites',
        'full' => 'You can keep up to :count favorite. Remove it to add another.|You can keep up to :count favorites. Remove one to add another.',
        'count' => ':count favorite|:count favorites',
        'move' => 'Move :page. Use the up and down arrow keys to reorder.',
        'moved' => ':page moved to place :position of :count.',
    ],
    'recent' => [
        'pages' => 'Recent pages',
        'people' => 'Recent people',
        'empty' => 'Pages and profiles you open will appear here.',
        'count' => ':count recent item|:count recent items',
    ],

    // Prompt 10: commands in Ctrl+K (App\Support\CommandPalette). A command matches its
    // label and keywords in English AND Arabic, whatever the interface language.
    'commands' => [
        'title' => 'Commands',
        'new' => [
            'leave' => ['label' => 'New leave request', 'keywords' => 'add create request leave vacation time off absence holiday'],
            'leave-for-employee' => ['label' => 'New leave request for an employee', 'keywords' => 'add create request leave vacation time off absence employee staff on behalf'],
            'employee' => ['label' => 'New employee', 'keywords' => 'add create hire staff person profile onboarding'],
            'contract' => ['label' => 'New contract', 'keywords' => 'add create contract agreement'],
            'news' => ['label' => 'New news item', 'keywords' => 'add create news announcement post'],
        ],
        'theme' => ['label' => 'Toggle dark mode', 'keywords' => 'dark light theme night appearance colors colours'],
        'locale' => [
            'label' => 'Switch language',
            'keywords' => 'language arabic english translate',
            // Each language named in itself (shown beside the command).
            'names' => ['en' => 'English', 'ar' => 'العربية'],
        ],
        'customise' => ['label' => 'Customize dashboard', 'keywords' => 'customise customize dashboard home edit arrange tiles layout'],
        'shortcuts' => ['label' => 'Keyboard shortcuts', 'keywords' => 'keyboard keys hotkeys help'],
    ],

    // Prompt 10: the "?" sheet (<x-keyboard-shortcuts>, App\Support\KeyboardShortcuts).
    'shortcuts' => [
        'title' => 'Keyboard shortcuts',
        'close' => 'Close keyboard shortcuts',
        'lead' => 'They work anywhere outside a text field.',
        'anywhere' => 'Anywhere',
        'go-to' => 'Go to a page',
        'then' => 'then',
        'search' => 'Search pages and people, and run commands',
        'sheet' => 'Show this list',
        'escape' => 'Close a dialog or this list',
        // Key caps as printed on the keyboard.
        'keys' => ['ctrl' => 'Ctrl', 'esc' => 'Esc'],
    ],

    // Prompt 10: the phone bar (livewire/layout/bottom-nav, App\Support\BottomBar) and its picker.
    'bottom-bar' => [
        'more' => 'More',
        'more-label' => 'More: open the menu',
        'title' => 'Phone bar',
        'lead' => 'Choose the shortcut at the bottom of your phone screen. The last place is always More, which opens the menu.|Choose the :count shortcuts at the bottom of your phone screen. The last place is always More, which opens the menu.',
        'place' => 'Place :number',
        'more-place' => 'Place :number: More, always',
        'pages' => 'Pages',
        'new' => 'Start something new',
        'reset' => 'Back to the default bar',
        'saved' => 'Saved. Your phone bar is updated.',
        'reset-done' => 'The default bar is back.',
        'custom' => 'Your own choice',
        'default' => 'The default bar',
    ],
];
