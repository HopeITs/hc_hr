<?php

return [

    'errors' => [
        'self_chat' => "You can't start a chat with yourself.",
        'inactive_user' => "This person's account is deactivated, so they can't receive messages.",
        'empty_message' => 'Write a message or attach a file.',
        'too_long' => 'A message can be at most :max characters.',
        'reply_missing' => 'The message you replied to is no longer there.',
        'not_your_message' => 'You can only edit your own messages.',
        'cannot_delete' => "You can't delete this message for everyone.",
        'nothing_to_forward' => 'Choose at least one message and one chat.',
        'too_many_files' => 'You can send at most :max files at once.',
        'upload_failed' => "A file didn't upload. Try again.",
        'blocked_type' => "\":name\" can't be sent: this type of file isn't allowed.",
        'too_large' => '":name" is larger than :max MB.',
        'group_full' => 'A group can have at most :max members.',
        'cannot_remove' => "This member can't be removed.",
        'cannot_promote' => "This member can't be made an admin.",
        'not_your_admin' => 'Only the owner or the admin who promoted them can change this admin.',
        'right_not_held' => 'You can only give rights you have yourself.',
        'not_a_member' => 'This person is not in the group.',
        'not_a_group' => 'This is not a group.',
        'title_required' => 'Give the group a name.',
        'title_too_long' => 'A group name can be at most :max characters.',
    ],

];
