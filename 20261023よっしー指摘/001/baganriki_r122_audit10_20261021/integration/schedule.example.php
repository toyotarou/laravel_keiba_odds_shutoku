<?php
// Laravel 8-10: inside app/Console/Kernel.php schedule(Schedule $schedule).
// Laravel 11+: use the corresponding Schedule::command(...) in routes/console.php.
$schedule->command('baganriki:r122-tick --seconds=55')
    ->everyMinute()->withoutOverlapping(2)->runInBackground();
// Server cron still runs php artisan schedule:run every minute.
// Delayed AggregateRaceJob runs independently of this tick through its own worker.

