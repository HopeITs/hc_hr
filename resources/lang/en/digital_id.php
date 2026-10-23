<?php

// Public digital-ID card: the public "not valid" page, the card panel on the
// profile page, the lost-card notification and the city HR hotline.
// See docs/ux/access-policy.md §9 (A2D-Q5, A2D-Q6, A2D-Q7).

return [
    'not_valid' => [
        'title' => 'Card is not valid',
        'heading' => 'This card is not valid',
        'body' => 'This ID card is no longer in use. It may have been replaced or withdrawn.',
    ],

    'panel' => [
        'title' => 'Digital ID card',
        'subtitle' => 'Public card link and status',
        'link' => 'Card link',
        'link_help' => 'Use this link for the QR code on the printed card.',
        'copy' => 'Copy link',
        'copied' => 'Copied',
        'open' => 'Open card',
        'type' => 'Card',
        'cardboard' => 'Cardboard (temporary)',
        'plastic' => 'Plastic, card no. :index',
        'status' => 'Status',
        'status_valid' => 'Valid',
        'status_disabled' => 'Disabled',
        'status_inactive' => 'Not valid (inactive employee)',
        'last_issued' => 'Issued on',
        'self_help' => 'If you lose your card, report it so HR can stop it and give you a new one.',
    ],

    'link_missing' => [
        'no_city' => 'No card link yet: the employee has no city.',
        'city_code' => 'No card link: the city code must be a number of up to 3 digits.',
        'no_position' => 'No card link yet: the employee has no current position.',
        'program_code' => 'No card link: the program code must be a number of up to 3 digits.',
    ],

    'actions' => [
        'issue_plastic' => 'Issue plastic card',
        'reissue' => 'Re-issue card',
        'disable' => 'Disable card',
        'enable' => 'Enable card',
        'report_lost' => 'Report lost card',
    ],

    'confirm' => [
        'issue_plastic_title' => 'Issue the plastic card?',
        'issue_plastic' => 'The cardboard card stops working now. Plastic card no. 1 becomes the only valid card: print it with the new link.',
        'reissue_title' => 'Re-issue the card?',
        'reissue' => 'Card no. :index stops working immediately. Card no. :next becomes the only valid card: print it with the new link.',
        'disable_title' => 'Disable the card?',
        'disable' => 'Anyone who scans the current card will see "card is not valid" until it is enabled again.',
        'enable_title' => 'Enable the card?',
        'enable' => 'The current card will show the employee\'s details again.',
        'report_lost_title' => 'Report your card lost?',
        'report_lost' => 'HR in your city will be notified and will stop the card or give you a new one.',
    ],

    'done' => [
        'reissued' => 'Card no. :index issued. Older cards no longer work.',
        'disabled' => 'Card disabled.',
        'enabled' => 'Card enabled.',
        'reported' => 'HR has been notified.',
        'already_reported' => 'Already reported. HR has been notified.',
    ],

    'errors' => [
        'stale_index' => 'The card changed in the meantime. Check the details and try again.',
        'inactive_employee' => 'The employee is inactive, so the card cannot be issued or enabled.',
        'no_card_link' => 'A card link cannot be built for this employee yet.',
        'limit_reached' => 'This employee has reached the maximum number of cards.',
    ],

    'notification' => [
        'title' => 'ID card reported lost',
        'body' => ':name reported their ID card lost. Disable or re-issue it from the profile page.',
    ],

    'city' => [
        'edit' => 'Edit city',
        'hotline' => 'HR hotline',
        'hotline_help' => 'Shown as the emergency number on this city\'s public ID cards. While it is empty, the work phone of the first active HR employee in the city is shown.',
        'hotline_invalid' => 'Enter a phone number: digits, spaces, +, - and brackets only.',
    ],

    // The public card itself. It always renders in English (ShowDigitalId).
    'card' => [
        'label' => 'Employee digital ID',
        'contact_links' => 'Employee contact links',
        'call' => 'Call :name',
        'telegram' => 'Open Telegram for :name',
        'linkedin' => 'Open LinkedIn for :name',
        'emergency' => 'EMERGENCY CONTACT:',
    ],
];
