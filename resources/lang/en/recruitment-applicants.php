<?php

/*
 * Team 2 — Phase 2 strings (campaign invites).
 * Kept apart from recruitment.php, which Team 1 edits constantly: when both
 * teams appended to that file the merge dropped every one of these keys.
 */
return [
    // Campaign invites screen.
    'invites_title' => 'Campaign invitations',
    'invites_description' => 'Only people added here may submit an application when this campaign is invitation-only.',
    'invite_full_name' => 'Full name',
    'invite_email' => 'Email address',
    'invite_add' => 'Add invite',
    'invite_added' => 'Invitation saved.',
    'invite_status' => 'Status',
    'invite_used_at' => 'Applied at',
    'invite_cancel' => 'Cancel',
    'invite_cancel_confirm' => 'Cancel this invitation? The person will no longer be able to apply.',
    'invite_cancelled' => 'Invitation cancelled.',
    'invite_status_pending' => 'Pending',
    'invite_status_sent' => 'Sent',
    'invite_status_used' => 'Applied',
    'invite_status_cancelled' => 'Cancelled',
    'invite_already_exists' => 'This email is already invited to this campaign.',
    'invite_already_used' => 'This person has already applied using this invitation.',
    'invite_not_invitation_only' => 'Invites apply only to invitation-only campaigns.',
    'invite_status_failed' => 'Not delivered',
    'invite_failure' => [
        'mailbox_missing' => 'This email address does not exist. Check it and add the invitation again.',
        'rejected' => 'The mail server refused the message. Check the address, or contact IT if every address fails.',
        'not_sent' => 'The email could not be sent. Try Resend, and contact IT if it keeps failing.',
    ],
    'invites_failed_notice' => ':count invitations were not delivered. The reason is shown on each row.',
    'invite_send' => 'Send link',
    'invite_resend' => 'Resend link',
    'invite_send_pending' => 'Send to all pending',
    'invite_sent_toast' => 'The application link was sent.',
    'invites_sent_toast' => ':count application links were sent.',
    'invites_none_pending' => 'There are no pending invitations to send.',
    'invite_send_no_link' => 'Generate the application link first, from the publishing page. Nothing can be sent before it exists.',
    'invite_send_unavailable' => 'This invitation can no longer be sent.',
    'invite_mail' => [
        'subject' => 'You are invited to apply: :position',
        'heading' => 'You are invited to apply',
        'greeting' => 'Hello :name,',
        'intro' => 'You have been invited to apply for the position ":position" at Christian Hope Center. The application is open to invited people only.',
        'use_email' => 'Apply with this email address',
        'closes' => 'Applications close on',
        'apply' => 'Apply now',
        'no_reply' => 'This message was sent automatically. Please do not reply to it.',
    ],
];
