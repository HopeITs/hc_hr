<?php

// Employees area: the employees table, the employee form tabs, the profile
// page and the shared file-input preview. Common words (Save, Delete, Active,
// ...) stay in general.php; only what this area adds lives here.

return [
    'table' => [
        'id' => 'ID',
    ],

    'form' => [
        'username-placeholder' => 'username',
        'work-channel' => ':channel (work)',
        'add-skill' => 'Add a skill',
        'add-interest' => 'Add an interest',
    ],

    'courses' => [
        'organization-hope-center' => 'Hope Center',
        'organization-personal' => 'Personal',
        'organization-other' => 'Other',
    ],

    'items' => [
        'title' => 'All Items',
        'delivered' => 'Delivered',
        'received' => 'Received',
    ],

    'file-input' => [
        'staged' => 'Not saved yet. This file stays attached while this page is open, even if saving fails. If you leave or reload the page, choose it again.',
        'new-profile-photo' => 'New profile photo',
        'current-profile-photo' => 'Current profile photo',
        'new-photo' => 'New photo',
        'current-photo' => 'Current photo',
        'no-image' => 'No image',
        'no-file' => 'No file',
        'file' => 'File',
    ],

    'statistics' => [
        'quarter' => 'Q:number',
    ],

    'validation' => [
        'city-scope' => 'You may only manage employees in your active city.',
        'manager-city' => 'The selected manager must belong to the employee\'s city.',
        'work-phone-format' => 'The work phone must be exactly 10 digits and start with 09.',
        'personal-phone-format' => 'The personal phone must be exactly 10 digits and start with 09.',
        'national-number-syrian' => 'The national number must be exactly 11 digits for Syrian employees.',
    ],
    'drawer' => [
        'pending-requests' => 'Pending requests',
        'leave-request' => 'Leave request',
        'position-n' => 'Position :n',
    ],
];
