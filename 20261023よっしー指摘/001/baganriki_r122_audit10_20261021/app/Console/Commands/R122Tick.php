<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Support\Baganriki\R122Jobs;
final class R122Tick extends Command
{
    protected $signature = 'baganriki:r122-tick {--seconds=0 : Repeat for at most 55 seconds}';
    protected $description = 'Dispatch selected r12.2 jobs; never wait for AI HTTP responses';
    public function handle(): int
    {
        $seconds = max(0,min(55,(int)$this->option('seconds')));
        $end = microtime(true)+$seconds;
        do {
            R122Jobs::tick();
            if (microtime(true)+2 > $end) break;
            usleep(2000000);
        } while (true);
        return 0;
    }
}

