<?php
namespace App\Jobs\Baganriki;
use App\Http\Controllers\Api\AiController;
use App\Support\Baganriki\R122Jobs;
final class AggregateRaceJob extends R122Job
{
    public $timeout = 20;
    public function __construct(public int $snapshotId) {}
    public function handle(AiController $controller): void { R122Jobs::aggregate($controller,$this->snapshotId); }
}

