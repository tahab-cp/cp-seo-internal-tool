<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Scheduling only: business logic lives in app/Console/Commands and
| app/Actions. Run `php artisan schedule:work` locally or add
| `* * * * * php artisan schedule:run` to cron in production
| (docs/production-deployment.md). Inspect with `php artisan schedule:list`.
|
*/

// Give every active project its monthly cycle (with target snapshot) at month start.
Schedule::command('seo:ensure-monthly-cycles')
    ->monthlyOn(1, '00:05')
    ->withoutOverlapping();

// Retention for uploaded CSV files (history and row issues are kept).
Schedule::command('seo:prune-import-files')
    ->weeklyOn(0, '02:30')
    ->withoutOverlapping();
