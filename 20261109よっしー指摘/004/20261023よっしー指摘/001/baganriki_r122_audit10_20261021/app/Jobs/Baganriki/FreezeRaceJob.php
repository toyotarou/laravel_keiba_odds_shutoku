<?php
namespace App\Jobs\Baganriki;
use App\Http\Controllers\Api\AiController;
use App\Support\Baganriki\R122Jobs;
final class FreezeRaceJob extends R122Job
{
    public $timeout = 120;
    public function __construct(public string $raceId) {}
    public function handle(AiController $controller): void { R122Jobs::freeze($controller,$this->raceId); }
}

