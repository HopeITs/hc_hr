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

    'onboarding' => [
        'new-record' => 'New volunteer',
        'state-draft' => 'Incomplete record (draft)',
        'state-awaiting' => 'Ready, awaiting contract',
        'awaiting-help' => 'This record is complete. The volunteer becomes active, and gets a login account, when their first contract starts.',
        'create-contract' => 'Create contract',
        'saved-at' => 'Saved :time by :name',
        'to-save-draft' => 'To save a draft:',
        'draft-has' => 'Needed for a draft:',
        'to-complete' => 'Also needed to complete the record:',
        'complete-help-draft' => 'Complete record finishes it. Save draft keeps it incomplete; you or another HR officer in this city can finish it later.',
        'complete-help' => 'Save completes the record. Save draft keeps it incomplete; you or another HR officer in this city can finish it later.',
        'sections-after-draft' => 'Education, courses, experience, relatives, checklist, attachments and items open once the record is saved (as a draft or complete). Nothing is saved before the draft fields are filled in; leaving the page with typed entries asks first.',
        'need-names' => 'Names (first, last, father, mother; Arabic and English)',
        'filled' => 'filled in',
        'missing' => 'missing',
        'save-complete' => 'Save',
        'complete-record' => 'Complete record',
        'save-draft' => 'Save draft',
        'saved-draft' => 'Draft saved. It is listed under Incomplete until it is completed.',
        'saved-complete' => 'Record saved. It is ready for a contract.',
        'completed' => 'Record completed. It is ready for a contract.',
        'save-in-progress' => 'This record is still being saved. Wait a moment before saving again.',
        'discard' => 'Discard draft',
        'discard-title' => 'Discard this draft?',
        'discard-message' => 'The incomplete record and any position reservation made for it will be deleted. This cannot be undone.',
        'discarded' => 'Draft discarded.',
        'account-at-contract' => 'A login account is created when this volunteer\'s first contract starts.',
        'account-created' => 'A login account was created and a password-setup email sent.',
        'account-email-taken' => 'No login account was created: this username\'s work email is already in use. Change the username or create the account in Access Management.',
        'view-awaiting' => 'Awaiting contract',
        'view-incomplete' => 'Incomplete',
        'status-incomplete' => 'Incomplete',
        'status-awaiting' => 'Awaiting contract',
        'needs-review' => 'Review',
    ],

    'validation' => [
        'national-number-taken' => 'This national ID is already registered for :name (:city).',
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
