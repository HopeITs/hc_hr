<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chat switch
    |--------------------------------------------------------------------------
    |
    | The chat stays off (no dock, no routes for people to reach) until this is
    | turned on, so it can ship to the live site ahead of being opened up.
    |
    */

    'enabled' => (bool) env('CHAT_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Attachments live on their own private disk, encrypted, never on the
    | public media disk. See config/filesystems.php ("chat-files").
    |
    */

    'disk' => 'chat-files',

    // Encrypted in pieces of this size, so a large file never has to fit in
    // memory at once (memory_limit is 128M on the servers).
    'file_chunk_bytes' => 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    */

    'max_file_kb' => (int) env('CHAT_MAX_FILE_KB', 50 * 1024),

    'max_files_per_message' => 10,

    // Executable and script types are refused outright, whatever the browser
    // says the file is.
    'blocked_extensions' => [
        'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'pif', 'cpl', 'dll', 'sys',
        'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'hta', 'ps1', 'psm1', 'sh',
        'php', 'phtml', 'phar', 'jar', 'jsp', 'asp', 'aspx', 'py', 'pl', 'rb',
        'reg', 'lnk', 'iso', 'img', 'vhd', 'appx', 'msix', 'apk', 'dmg', 'app',
    ],

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */

    'max_message_length' => 4096,

    'max_group_members' => 500,

    'max_group_title_length' => 128,

];
