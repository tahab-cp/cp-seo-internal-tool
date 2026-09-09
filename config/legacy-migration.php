<?php

/*
|--------------------------------------------------------------------------
| Legacy spreadsheet migration mapping (Milestone 17)
|--------------------------------------------------------------------------
|
| Explicit, office-specific mapping for `php artisan seo:migrate-legacy`.
| Nothing here is guessed at runtime: a legacy key that is not listed is
| either created (clients/projects with enough data) or reported as a
| conflict / warning. A JSON file passed with --mapping=... is merged over
| these values, so office names never need to live in PHP. Keys are
| compared case-insensitively after trimming.
|
| No credentials belong in this file.
|
*/

return [

    // Legacy account / sheet name  =>  existing client id (reuse instead of creating).
    'clients' => [
        // 'Afzal' => 3,
    ],

    // Legacy project reference (project name or website as written in the
    // workbook)  =>  existing project id.
    'projects' => [
        // 'casabotanica.co.uk' => 12,
    ],

    // Legacy owner / assignee text  =>  user email. Emails written directly
    // in the workbook resolve without an entry here.
    'users' => [
        // 'Hassan' => 'hassan@example.com',
    ],

    // Legacy package label  =>  package id. An exact package name match
    // (case-insensitive) resolves without an entry here.
    'packages' => [
        // 'Gold' => 2,
    ],

    // Year applied to ranking date columns written without one ("Jul 01").
    'ranking_year' => null,

    // Cell values that mean "not ranking" in legacy ranking sheets. Blank
    // cells always mean not ranking; 0 is listed because old sheets used it
    // that way (a warning is issued so the choice stays visible).
    'not_ranking' => ['-', '–', '—', 'nr', 'n/r', 'not ranking', '100+', '>100', '0'],

    // Legacy wording  =>  application enum value. Enum values themselves
    // (e.g. "completed") always resolve. Unknown wording is an issue, never
    // a guess and never a new enum case.
    'values' => [
        'project_status' => [
            'live' => 'active',
            'ongoing' => 'active',
            'on hold' => 'paused',
            'ended' => 'completed',
            'finished' => 'completed',
        ],
        'task_status' => [
            'done' => 'completed',
            'complete' => 'completed',
            'to do' => 'pending',
            'todo' => 'pending',
            'not started' => 'pending',
            'wip' => 'in_progress',
            'in progress' => 'in_progress',
            'on hold' => 'blocked',
            'dropped' => 'cancelled',
        ],
        'task_priority' => [
            'medium' => 'normal',
        ],
        'backlink_status' => [
            'published / live' => 'live',
            'published' => 'live',
            'approved' => 'live',
            'pending' => 'submitted',
            'in review' => 'submitted',
            'lost' => 'removed',
            'declined' => 'rejected',
        ],
        'backlink_type' => [
            'guest post' => 'guest_post',
            'gp' => 'guest_post',
            'blog comment' => 'blog_comment',
            'web 2.0' => 'other',
            'social profile' => 'profile',
            'local citation' => 'citation',
        ],
        'content_status' => [
            'live' => 'published',
            'done' => 'published',
            'draft' => 'writing',
            'in progress' => 'writing',
            'scheduled' => 'approved',
            'idea' => 'idea',
        ],
        'content_type' => [
            'article' => 'blog',
            'blog post' => 'blog',
            'service' => 'service_page',
            'landing' => 'landing_page',
            'location' => 'location_page',
            'guest post' => 'guest_content',
        ],
        'note_type' => [
            'wins' => 'win',
            'win this month' => 'win',
            'challenges' => 'challenge',
            'recommendations' => 'recommendation',
            'next month focus' => 'next_month_focus',
            "next month's focus" => 'next_month_focus',
        ],
    ],

];
