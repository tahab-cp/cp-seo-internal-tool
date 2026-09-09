<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PDF storage
    |--------------------------------------------------------------------------
    |
    | Final report PDFs are written through the Laravel filesystem so the
    | disk can move from local storage to S3 without touching code. Files are
    | never exposed publicly; downloads go through an authorized route.
    |
    */

    'pdf_disk' => env('REPORT_PDF_DISK', env('FILESYSTEM_DISK', 'local')),

    'pdf_directory' => env('REPORT_PDF_DIRECTORY', 'reports'),

    // Report PDFs must never live on a public disk. Kept as documentation for the
    // production preflight (app:production-check), which fails when the disk is public.
    'pdf_disk_must_be_private' => true,

    /*
    |--------------------------------------------------------------------------
    | Chromium
    |--------------------------------------------------------------------------
    |
    | PDFs are rendered by a headless Chromium-based browser (Chrome, Edge,
    | Chromium) via its --print-to-pdf mode. Set CHROMIUM_PATH explicitly in
    | production; when empty the generator tries the candidates below.
    |
    */

    'chromium_path' => env('CHROMIUM_PATH'),

    'chromium_candidates' => [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    ],

    'chromium_timeout' => (int) env('CHROMIUM_TIMEOUT', 90),

    /*
    | Extra flags, e.g. --no-sandbox on hardened Linux containers.
    */
    'chromium_flags' => array_values(array_filter(explode(' ', (string) env('CHROMIUM_FLAGS', '')))),

];
