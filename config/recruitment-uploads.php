<?php

return [
    // Uploads enter quarantine first. Nothing on either disk is web-accessible.
    'quarantine_disk' => env('RECRUITMENT_QUARANTINE_DISK', 'recruitment-quarantine'),
    'clean_disk' => env('RECRUITMENT_FILES_DISK', 'recruitment-files'),

    /*
     * Antivirus. Every upload waits in quarantine until clamd (ClamAV, free
     * and open source) reports it clean; until then it cannot be downloaded.
     *
     *   clamd     talk to the ClamAV daemon over a unix socket or TCP
     *   disabled  scan nothing: files stay pending and undownloadable. For
     *             machines without ClamAV, never a way to skip the check.
     *
     * On Linux: apt install clamav clamav-daemon, then check the wiring with
     * `php artisan recruitment:scanner-check`. clamd's StreamMaxLength must
     * be at least max_bytes below (its default, 25M, is).
     */
    'scanner' => [
        'driver' => env('RECRUITMENT_SCANNER', 'clamd'),
        // A socket path wins over host/port when set.
        'socket' => env('CLAMD_SOCKET', '/var/run/clamav/clamd.ctl'),
        'host' => env('CLAMD_HOST', '127.0.0.1'),
        'port' => (int) env('CLAMD_PORT', 3310),
        'timeout' => (int) env('CLAMD_TIMEOUT', 30),
        'chunk_bytes' => 64 * 1024,
        // Files still pending after this long are picked up by the sweep.
        'sweep_after_minutes' => 5,
    ],

    'max_bytes' => 10 * 1024 * 1024,
    'inspection_bytes' => 512 * 1024,
    // Total inflated size allowed while searching a PDF's streams for active content.
    'pdf_max_inflated_bytes' => 50 * 1024 * 1024,

    'docx' => [
        'max_entries' => 1000,
        'max_total_uncompressed_bytes' => 50 * 1024 * 1024,
        'max_entry_uncompressed_bytes' => 20 * 1024 * 1024,
        'max_compression_ratio' => 50,
    ],

    'types' => [
        'cv' => [
            'extensions' => ['pdf', 'doc', 'docx'],
            'max_files' => 1,
        ],
        'photo' => [
            'extensions' => ['jpg', 'jpeg', 'png'],
            'max_files' => 1,
        ],
        'portfolio' => [
            'extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
            'max_files' => 5,
        ],
        'recommendations' => [
            'extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
            'max_files' => 5,
        ],
    ],

    // Checked against every dot-delimited part of the original name, not only
    // its final extension. This blocks names such as cv.php.pdf.
    'blocked_extensions' => [
        'app', 'asp', 'aspx', 'bat', 'bin', 'cgi', 'cmd', 'com', 'cpl',
        'dll', 'dmg', 'exe', 'hta', 'htaccess', 'htm', 'html', 'jar', 'js',
        'jse', 'msi', 'msp', 'phtml', 'phar', 'php', 'php3', 'php4', 'php5',
        'php7', 'php8', 'pl', 'ps1', 'py', 'scr', 'sh', 'svg', 'vbe', 'vbs',
        'wsf', 'xhtml', 'xml',
    ],
];
