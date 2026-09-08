<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Scheduling only: business logic lives in app/Console/Commands and
| app/Actions. Run `php artisan schedule:work` locally or add
| `php artisan schedule:run` to cron in production.
|
*/

// Give every active project its monthly cycle (with target snapshot) at month start.
Schedule::command('seo:ensure-monthly-cycles')
    ->monthlyOn(1, '00:05')
    ->withoutOverlapping();
