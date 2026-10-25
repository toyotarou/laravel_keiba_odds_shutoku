<?php
namespace App\Jobs\Baganriki;
use App\Http\Controllers\Api\AiController;
use App\Support\Baganriki\R122Jobs;
final class RunRoleJob extends R122Job
{
    public function __construct(public int $snapshotId, public string $role) {}
    public function handle(AiController $controller): void { R122Jobs::runRole($controller,$this->snapshotId,$this->role); }
}

