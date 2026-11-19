<?php

use Illuminate\Support\Facades\Schedule;

// Run by the nhc-scheduler container (`php artisan schedule:work`).
// Sends anything LoggerAI has waiting even when the API is quiet...
Schedule::command('loggerai:flush')->everyMinute();
// ...and checks the database and cache, so LoggerAI can tell when NHC is down.
Schedule::command('loggerai:heartbeat')->everyMinute();

// Evening reminders for tomorrow's visits (Hospify app push).
Schedule::command('nhc:appointments:remind')->dailyAt('18:00')->timezone('Africa/Lagos');

// Bookable hours (spec 0001): old holds, requests the facility never confirmed, staff alerts.
Schedule::command('nhc:schedule:expire')->everyFiveMinutes()->withoutOverlapping();
