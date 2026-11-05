<?php

use Illuminate\Support\Facades\Schedule;

/*
| Run the scheduler every minute from cron on the server:
|   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
| Times are in APP_TIMEZONE (Africa/Addis_Ababa).
*/

// Rent charges: create the month's charge on the 1st (daily so missed runs catch up).
Schedule::command('rent:generate-charges')->dailyAt('00:15')->withoutOverlapping()->onOneServer();

// Charges past their due date and not fully paid become late.
Schedule::command('rent:mark-late')->dailyAt('00:30')->withoutOverlapping()->onOneServer();
