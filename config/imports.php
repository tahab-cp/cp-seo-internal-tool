<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CSV storage
    |--------------------------------------------------------------------------
    |
    | Uploaded CSV files are kept on a PRIVATE disk under the directory below
    | (imports/{project}/{batch}-{random}.csv) so a batch stays reviewable
    | and re-readable between the wizard steps. Files are never served by
    | path; nothing under this directory is public.
    |
    */

    'disk' => env('IMPORT_DISK', env('FILESYSTEM_DISK', 'local')),

    'directory' => env('IMPORT_DIRECTORY', 'imports'),

    /*
    |--------------------------------------------------------------------------
    | V1 limits
    |--------------------------------------------------------------------------
    |
    | Imports run synchronously in the request, so the file and row limits
    | keep one import comfortably inside PHP/HTTP limits. A larger monthly
    | export should be split. CSV only: no spreadsheets in this milestone.
    |
    */

    'max_file_bytes' => (int) env('IMPORT_MAX_FILE_BYTES', 5 * 1024 * 1024),

    'max_rows' => (int) env('IMPORT_MAX_ROWS', 5000),

    'allowed_extensions' => ['csv', 'txt'],

    'allowed_mime_types' => [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'text/x-csv',
        'application/x-csv',
    ],

    /*
    | Rows shown in the validation preview sample.
    */
    'preview_rows' => 10,

];
