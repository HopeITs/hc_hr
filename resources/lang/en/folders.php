<?php

// Folder sharing, the Google Drive way (App\Services\Folders\FolderAccess).
return [
    'roles' => [
        'viewer' => 'Viewer',
        'editor' => 'Editor',
        'owner' => 'Owner',
    ],
    'roles-help' => 'Viewer: opens and downloads. Editor: also uploads, makes subfolders, renames and shares. Only the owner deletes.',

    'share' => [
        'groups-title' => 'Share with a group',
        'groups-hint' => 'Everyone in the group has access now, and so will whoever joins it later. Whoever leaves it loses access.',
        'group-type' => 'Group type',
        'group-value' => 'Group',
        'role' => 'Role',
        'add-group' => 'Add group',
        'remove-group' => 'Remove :group',
        'pick-group' => 'Choose a group first.',
        'no-groups' => 'Not shared with any group.',
        'members-now' => '{0} nobody in it now|{1} 1 person in it now|[2,*] :count people in it now',
        'people-title' => 'Share with chosen people',
        'people-hint' => 'Tick the people you want: no group needed. The filter only helps you find them, it does not share with the whole group.',
        'people-any' => 'Anyone in the organisation can be chosen, whatever their department or city.',
        'people-in-group' => 'People in :group',
        'through-group' => 'Has access through: :group',
        'role-of' => 'Role of :name',
        'inherits' => 'Whoever can open the folder above it can also open this one.',
    ],

    'shared-root-hint' => 'These folders were shared with you. To share your own files, make a folder in My folders and share it.',
];
