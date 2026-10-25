<?php
// Add this one connection to the existing config/queue.php connections array.
// Do not replace the application's entire queue.php.
return [
    'driver' => 'database',
    'connection' => config('database.default', 'mysql'),
    'table' => 'baganriki_r122_jobs',
    'queue' => 'r122-deadline',
    'retry_after' => 300,
    'after_commit' => true,
];

