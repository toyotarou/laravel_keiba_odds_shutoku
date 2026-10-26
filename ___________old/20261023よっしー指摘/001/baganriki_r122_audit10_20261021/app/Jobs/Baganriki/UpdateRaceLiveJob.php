<?php
namespace App\Jobs\Baganriki;
use App\Support\Baganriki\R122Jobs;
final class UpdateRaceLiveJob extends R122Job
{
    public $timeout = 20;
    public $tries = 3; // State-only retries never perform an HTTP AI call.
    public $backoff = 1;
    public function __construct(
        public int $snapshotId, public array $cancelled, public ?string $close,
        public int $expectedRevision, public string $sourceEventId
    ) {}
    public function handle(): void
    {
        $revision=$this->expectedRevision;
        for ($n=0;$n<3;$n++) {
            try {
                R122Jobs::updateLive($this->snapshotId,$this->cancelled,$this->close,$revision,$this->sourceEventId);
                return;
            } catch (\RuntimeException $e) {
                if (!str_starts_with($e->getMessage(),'STATE_CHANGED:')) throw $e;
                $live=\Illuminate\Support\Facades\DB::table('baganriki_live_states')->where('snapshot_id',$this->snapshotId)->first();
                if ($live===null) throw $e;
                // Only confirmed monotonic deltas are retried: union cancellations / earlier close.
                $revision=(int)$live->state_revision;
            }
        }
        throw new \RuntimeException('STATE_CHANGED: concurrent live updates did not settle');
    }
}

