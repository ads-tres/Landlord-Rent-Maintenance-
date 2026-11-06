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

// SMS reminders to tenants: 3 days before and on the due date (after the morning rush).
Schedule::command('rent:send-reminders')->dailyAt('10:05')->withoutOverlapping()->onOneServer();

// Database backup, kept 14 days in storage/app/private/backups (copy offsite, inside Ethiopia).
Schedule::command('backup:database')->dailyAt('02:10')->withoutOverlapping()->onOneServer();

// Generator / water tank / pump service reminders to landlords (Telegram).
Schedule::command('assets:remind')->dailyAt('08:10')->withoutOverlapping()->onOneServer();

// Listings: expire after 30 days, delete old applications, remind about long vacancies.
Schedule::command('listings:daily')->dailyAt('03:10')->withoutOverlapping()->onOneServer();

// Chapa: catch payments whose webhook didn't arrive.
Schedule::command('chapa:reconcile')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// Leases ending in 60 and 30 days: tell the landlord in time to agree a renewal.
Schedule::command('leases:renewal-reminders')->dailyAt('09:10')->withoutOverlapping()->onOneServer();

// Payment plan instalment reminders (the day before) and plan status.
Schedule::command('arrears:plans')->dailyAt('10:20')->withoutOverlapping()->onOneServer();

// Accounts whose owner asked for deletion more than 30 days ago.
Schedule::command('accounts:purge')->dailyAt('03:40')->withoutOverlapping()->onOneServer();
