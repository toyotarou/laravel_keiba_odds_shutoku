<?php
declare(strict_types=1);
namespace App\Support\Baganriki;

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\BaganrikiWorker as W;
use App\Http\Controllers\Api\BaganrikiPrompt;
use App\Http\Controllers\Api\BaganrikiRanking;
use App\Jobs\Baganriki\FreezeRaceJob;
use App\Jobs\Baganriki\RunRoleJob;
use App\Jobs\Baganriki\AggregateRaceJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Bus;

/** Proposal adapter for the exact supplied AiController. All options default off. */
final class R122Jobs
{
    private static ?array $roleContext = null;
    private static ?\PDO $roleLock = null;

    public static function isRoleJob(int $sid, string $role): bool
    {
        return self::$roleContext === [$sid,$role];
    }

    private static function lockName(string $key): string
    {
        return hash('sha256',DB::connection()->getDatabaseName().':baganriki-r122:'.$key);
    }

    /** MySQL connection-scoped lock is released when a killed worker disconnects. */
    private static function workLock(string $key): ?\PDO
    {
        $pdo = DB::connection()->getPdo();
        $query = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute([self::lockName($key)]);
        return (int)$query->fetchColumn() === 1 ? $pdo : null;
    }

    private static function workUnlock(\PDO $pdo, string $key): void
    {
        try {
            $query = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $query->execute([self::lockName($key)]);
        } catch (\Throwable $e) {
            // A disconnected session releases its locks; keep the original processing error.
            \Log::error('[R122-JOBS] lock release failed',['exception'=>get_class($e)]);
        }
    }

    private static function stillLocked(\PDO $pdo, string $key): bool
    {
        $query = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
        $query->execute([self::lockName($key)]);
        return (int)$query->fetchColumn() === 1;
    }

    public static function recordCall(string $role, mixed $payload, ?string $requestId, float $startedAt, ?string $token=null): void
    {
        if (self::$roleContext === null || !is_array($payload)) return;
        [$sid,$expectedRole] = self::$roleContext;
        if ($role !== $expectedRole) throw new \LogicException('Role metrics mismatch');
        try {
        $query = DB::table('baganriki_ai_attempts')->where('snapshot_id',$sid)->where('role',$role);
        $attempt = $token === null ? $query->where('status',W::SENT)->orderByDesc('attempt_no')->first()
                                  : $query->where('token',$token)->first();
        if ($attempt === null) return;
        DB::table('baganriki_r122_call_metrics')->updateOrInsert(
            ['attempt_id'=>(int)$attempt->id], [
                'actual_model'=>isset($payload['model']) ? substr((string)$payload['model'],0,160) : null,
                'response_id'=>isset($payload['id']) ? substr((string)$payload['id'],0,200) : null,
                'request_id'=>$requestId === null ? null : substr($requestId,0,200),
                'usage_json'=>json_encode($payload['usage'] ?? null,JSON_THROW_ON_ERROR),
                'stop_reason'=>$payload['stop_reason'] ?? ($payload['choices'][0]['finish_reason'] ?? null),
                'elapsed_ms'=>(int)round((microtime(true)-$startedAt)*1000),
                'recorded_at'=>W::toDb(W::nowUs()),
            ]
        );
        } catch (\Throwable $e) {
            // A metrics failure must not discard a successfully received AI body.
            \Log::error('[R122-JOBS] metrics failed', ['snapshot_id'=>$sid,'role'=>$role,'exception'=>get_class($e)]);
        }
    }

    public static function callMetadata(mixed $payload, ?string $requestId, float $startedAt): array
    {
        $p=is_array($payload) ? $payload : [];
        return ['payload'=>[
            'model'=>$p['model'] ?? null,'id'=>$p['id'] ?? null,'usage'=>$p['usage'] ?? null,
            'stop_reason'=>$p['stop_reason'] ?? ($p['choices'][0]['finish_reason'] ?? null),
        ],'request_id'=>$requestId,'started_at'=>$startedAt];
    }

    public static function recordCallResult(string $role,string $token,array $call): void
    {
        $m=$call['metrics'] ?? null;
        if (!is_array($m)) return;
        self::recordCall($role,$m['payload'],$m['request_id'],(float)$m['started_at'],$token);
    }

    public static function enabled(): bool { return config('baganriki_jobs.enabled', false) === true; }

    private static function loadLegacy(): void
    {
        // Helpers live inside AiController.php and have no independent PSR-4 file.
        class_exists(AiController::class);
    }

    public static function ready(): bool
    {
        return Schema::hasTable('baganriki_r122_job_runs')
            && Schema::hasTable('baganriki_r122_role_states')
            && Schema::hasTable('baganriki_r122_leases')
            && Schema::hasTable('baganriki_r122_jobs')
            && Schema::hasTable('baganriki_r122_call_metrics')
            && Schema::hasTable('baganriki_validation_results')
            && Schema::hasColumn('baganriki_r122_leases','job_id');
    }

    public static function selected(string $raceId): bool
    {
        $ids = config('baganriki_jobs.race_ids', []);
        return self::enabled() && count($ids) >= 1 && count($ids) <= 12
            && in_array($raceId, $ids, true);
    }

    public static function owns(string $raceId): bool
    {
        return Schema::hasTable('baganriki_r122_job_runs')
            && DB::table('baganriki_r122_job_runs')->where('race_id', $raceId)->exists();
    }

    public static function intercept(string $raceId): bool
    {
        return self::enabled() || self::owns($raceId);
    }

    public static function parts(string $id): array
    {
        if (!preg_match('/\A(\d{4})(\d{2})(\d{2})-(\d+)-(\d+)-(\d+)-(\d+)\z/D', $id, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException('Invalid race_id');
        }
        return [$m[1].'-'.$m[2].'-'.$m[3], $m[4], $m[5], $m[6], (int) $m[7]];
    }

    /** Leases throttle enqueueing; connection locks protect processing; journal protects sends. */
    public static function lease(string $key, int $seconds): ?string
    {
        $key = hash('sha256', $key);
        DB::table('baganriki_r122_leases')->insertOrIgnore(['lease_key' => $key, 'owner' => '', 'until_epoch' => 0]);
        $token = bin2hex(random_bytes(16)); $now = time();
        $n = DB::table('baganriki_r122_leases')->where('lease_key', $key)
            ->where('until_epoch', '<=', $now)->update(['owner' => $token, 'until_epoch' => $now + $seconds]);
        return $n === 1 ? $token : null;
    }

    public static function release(string $key, string $token): void
    {
        DB::table('baganriki_r122_leases')->where('lease_key', hash('sha256', $key))
            ->where('owner', $token)->update(['until_epoch' => 0]);
    }

    private static function enqueue(object $job, string $key, string $queue, int $delay = 0): void
    {
        // Queue insertion and its marker MUST commit on the same DB connection.
        $cfg='queue.connections.baganriki_database.';
        if (config($cfg.'driver') !== 'database' || config($cfg.'table') !== 'baganriki_r122_jobs'
            || config($cfg.'connection') !== DB::connection()->getName()) {
            throw new \RuntimeException('QUEUE_CONNECTION_MISMATCH: use the default writer connection');
        }
        if (DB::connection()->transactionLevel() !== 0) throw new \LogicException('Dispatch must start outside caller transaction');
        $workKey = match(true) {
            $job instanceof RunRoleJob => 'role-run:'.$job->snapshotId.':'.$job->role,
            $job instanceof FreezeRaceJob => 'freeze-run:'.$job->raceId,
            $job instanceof AggregateRaceJob => 'aggregate-run:'.$job->snapshotId,
            default => null,
        };
        $leaseKey=hash('sha256','enqueue:'.$key);
        DB::transaction(function() use($job,$leaseKey,$queue,$delay,$workKey) {
            DB::table('baganriki_r122_leases')->insertOrIgnore([
                'lease_key'=>$leaseKey,'owner'=>'','until_epoch'=>0,'job_id'=>null,
            ]);
            $marker=DB::table('baganriki_r122_leases')->where('lease_key',$leaseKey)->lockForUpdate()->first();
            $queued = $marker->job_id === null ? null : DB::table('baganriki_r122_jobs')->where('id',$marker->job_id)->first();
            if ($queued !== null) {
                if ($queued->reserved_at === null) return; // still pending, however long it waits
                if ((int)$queued->reserved_at + 2 >= time()) return; // popped but handler not entered yet
                if ($workKey !== null) {
                    $q=DB::connection()->getPdo()->prepare('SELECT IS_USED_LOCK(?)');
                    $q->execute([self::lockName($workKey)]);
                    if ($q->fetchColumn() !== null) return; // worker is alive in this handler
                }
                // Popped job with no processing lock: recovery may resume its journal safely.
            }
            if ((int)$marker->until_epoch > time()) return;
            $job->onConnection('baganriki_database')->onQueue($queue)->beforeCommit();
            if ($delay > 0) $job->delay($delay);
            $id=Bus::dispatch($job);
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) throw new \RuntimeException('QUEUE_ID_MISSING');
            DB::table('baganriki_r122_leases')->where('lease_key',$leaseKey)->update([
                'owner'=>bin2hex(random_bytes(16)),'until_epoch'=>time()+2,'job_id'=>(int)$id,
            ]);
        });
    }

    public static function request(string $raceId): array
    {
        self::loadLegacy();
        if (!self::selected($raceId)) return ['code' => 'NOT_SELECTED', 'queued' => false];
        if (!self::ready()) return ['code' => 'JOB_TABLES_MISSING', 'queued' => false];
        ExactStorage::assertReady();
        if (!BaganrikiPrompt::sending() || !W::blocking()) return ['code' => 'STRICT_FLAGS_REQUIRED', 'queued' => false];
        self::parts($raceId);
        // Sticky ownership prevents automatic legacy fallback after a send.
        DB::table('baganriki_r122_job_runs')->insertOrIgnore(['race_id' => $raceId, 'created_at' => W::toDb(W::nowUs())]);
        $snap = DB::table('baganriki_snapshots')->where('race_id', $raceId)->where('generation', 0)->first();
        if ($snap === null) {
            self::enqueue(new FreezeRaceJob($raceId), 'freeze:'.$raceId, 'r122-freeze');
        } else self::wakeSnapshot((int) $snap->id);
        return ['code' => 'QUEUED', 'queued' => true];
    }

    public static function freeze(AiController $ctrl, string $raceId): void
    {
        if (!self::selected($raceId)) return;
        $key = 'freeze-run:'.$raceId; $lock = self::workLock($key);
        if ($lock === null) return;
        try {
            $result = $ctrl->r122Freeze(...self::parts($raceId));
            if (!($result['ok'] ?? false)) {
                \Log::info('[R122-JOBS] freeze pending/failed', ['race_id'=>$raceId, 'code'=>$result['code'] ?? null]);
                return; // tick retries the freeze only, never an API send
            }
            $snap = DB::table('baganriki_snapshots')->where('race_id', $raceId)->where('generation', 0)->first();
            if ($snap !== null) self::wakeSnapshot((int) $snap->id);
        } finally { self::workUnlock($lock, $key); }
    }

    public static function wakeSnapshot(int $sid): void
    {
        self::loadLegacy();
        $snap = DB::table('baganriki_snapshots')->where('id', $sid)->first();
        if ($snap === null || !self::selected((string) $snap->race_id) || (int)$snap->generation !== 0) return;
        W::ensureLive($sid, W::nowUs());
        $live = DB::table('baganriki_live_states')->where('snapshot_id', $sid)->first();
        $dl = W::deadlines($snap, $live);
        if (!$dl['ok'] || W::nowUs() > $dl['publish']) return;
        // Queue the deadline independently, before either role can block in HTTP.
        $delay = max(0, (int) floor(($dl['reply'] - W::nowUs()) / 1000000) + 1);
        self::enqueue(new AggregateRaceJob($sid), 'deadline:'.$sid.':'.$dl['reply'], 'r122-deadline', $delay);
        foreach (['1st', '2nd'] as $role) {
            DB::table('baganriki_r122_role_states')->insertOrIgnore([
                'snapshot_id'=>$sid, 'role'=>$role, 'settled'=>false, 'fingerprint'=>null,
                'updated_at'=>W::toDb(W::nowUs()),
            ]);
            if (W::nowUs() <= $dl['reply'] && !self::roleSettled($sid, $role)) {
                self::enqueue(new RunRoleJob($sid, $role), 'role:'.$sid.':'.$role, 'r122-'.$role);
            }
        }
        self::aggregateSoon($sid);
    }

    public static function aggregateSoon(int $sid): void
    {
        if (!self::ready()) return;
        self::enqueue(new AggregateRaceJob($sid), 'aggregate:'.$sid, 'r122-deadline');
    }

    private static function fingerprint(int $sid, string $role): string
    {
        $rows = DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)->where('role', $role)
            ->orderBy('attempt_no')->get(['attempt_no','status','complete','response_hash']);
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private static function roleSettled(int $sid, string $role): bool
    {
        $r = DB::table('baganriki_r122_role_states')->where('snapshot_id', $sid)->where('role', $role)->first();
        return $r !== null && (bool)$r->settled && hash_equals((string)$r->fingerprint, self::fingerprint($sid, $role));
    }

    /** Called by reserve AND immediately before allowSend commits SENT. */
    public static function canSend(int $sid, string $role): bool
    {
        self::loadLegacy();
        $snap = DB::table('baganriki_snapshots')->where('id', $sid)->first();
        if ($snap === null) return false;
        if (!self::intercept((string)$snap->race_id)) return true; // unchanged legacy mode
        $allowed = self::selected((string)$snap->race_id) && self::ready() && (int)$snap->generation === 0
            && BaganrikiPrompt::sending() && W::blocking()
            && self::$roleContext === [$sid, $role];
        if (!$allowed || self::$roleLock === null || DB::connection()->getPdo() !== self::$roleLock) return false;
        if (!self::stillLocked(self::$roleLock,'role-run:'.$sid.':'.$role)) return false;
        ExactStorage::assertReady();
        return true;
    }

    public static function runRole(AiController $ctrl, int $sid, string $role): void
    {
        self::loadLegacy();
        if (!in_array($role, ['1st','2nd'], true)) throw new \InvalidArgumentException('Invalid role');
        $snap = DB::table('baganriki_snapshots')->where('id', $sid)->first();
        if ($snap === null || !self::ready() || (int)$snap->generation !== 0 || !self::selected((string)$snap->race_id) || self::roleSettled($sid, $role)) return;
        $key = 'role-run:'.$sid.':'.$role; $lock = self::workLock($key);
        if ($lock === null) return;
        $old = self::$roleContext; $oldLock=self::$roleLock;
        self::$roleContext = [$sid, $role]; self::$roleLock=$lock;
        $completed = false;
        try {
            if (self::roleSettled($sid,$role)) return; // recheck after acquiring the lock
            DB::table('baganriki_r122_role_states')->where('snapshot_id', $sid)->where('role', $role)
                ->update(['settled'=>false, 'updated_at'=>W::toDb(W::nowUs())]);
            $first = DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)->where('role', $role)->where('attempt_no', 0)->first();
            if ($first === null || $first->status === W::RESERVED) {
                $ctrl->r122SendFirst($sid, $role); // includes AfterReceive exactly once
            } elseif ($first->status === W::RECEIVED) {
                [$d,$k,$b,$y,$r] = self::parts((string)$snap->race_id);
                $ctrl->r122AfterReceive($d,$k,$b,$y,$r,$role,['skip'=>false,'ok'=>true,'snapshot_id'=>$sid]);
            }
            $rows = DB::table('baganriki_ai_attempts')->where('snapshot_id',$sid)->where('role',$role)->get();
            $completed = count($rows) > 0; // no reservation after a transient error is still retryable
            foreach ($rows as $row) if (in_array($row->status,[W::SENT,W::RESERVED],true)) $completed = false;
            if (!self::stillLocked($lock,$key)) throw new \RuntimeException('Worker lock lost');
            if ($completed) DB::table('baganriki_r122_role_states')->where('snapshot_id',$sid)->where('role',$role)
                ->update(['settled'=>true,'fingerprint'=>self::fingerprint($sid,$role),'updated_at'=>W::toDb(W::nowUs())]);
        } finally {
            self::$roleContext = $old;
            self::$roleLock = $oldLock;
            self::workUnlock($lock, $key);
            self::aggregateSoon($sid);
        }
    }

    /** null = legacy path; true/false = strict job publication gate. */
    public static function publicationGate(int $sid): ?bool
    {
        self::loadLegacy();
        $snap = DB::table('baganriki_snapshots')->where('id', $sid)->first();
        if ($snap === null) return false;
        if (!self::intercept((string)$snap->race_id)) return null;
        if (!self::ready() || (int)$snap->generation !== 0 || !self::selected((string)$snap->race_id)) return false;
        $live = DB::table('baganriki_live_states')->where('snapshot_id',$sid)->first();
        $dl = W::deadlines($snap,$live);
        if (!$dl['ok']) return false;
        $roles = [];
        foreach (['1st','2nd'] as $role) $roles[$role] = [
            'settled'=>self::roleSettled($sid,$role), 'fingerprint_matches'=>true,
        ];
        return JobPolicy::mayAggregate(W::nowUs(),(int)$dl['reply'],(int)$dl['publish'],$roles);
    }

    public static function aggregate(AiController $ctrl, int $sid): void
    {
        self::loadLegacy();
        if (self::publicationGate($sid) !== true) return;
        $key = 'aggregate-run:'.$sid; $lock = self::workLock($key);
        if ($lock === null) return;
        try {
            $snap = DB::table('baganriki_snapshots')->where('id',$sid)->first();
            $live = DB::table('baganriki_live_states')->where('snapshot_id',$sid)->first();
            $dl = W::deadlines($snap,$live);
            if ($dl['ok'] && W::nowUs() > $dl['reply']) W::resume($sid,W::nowUs());
            $live = DB::table('baganriki_live_states')->where('snapshot_id',$sid)->first();
            if ($live !== null && $live->latest_publication_id !== null) {
                $published=DB::table('baganriki_publications')->where('publication_id',$live->latest_publication_id)->first();
                if ($published !== null && (int)$published->used_state_revision === (int)$live->state_revision
                    && (int)$published->used_response_revision === (int)$live->response_revision) return;
            }
            $result = $ctrl->r122PublishWhenReady($sid); // existing commit-time revision/deadline checks
            \Log::info('[R122-JOBS] aggregate', ['snapshot_id'=>$sid,'code'=>$result['code'] ?? null]);
        } finally { self::workUnlock($lock,$key); }
    }

    /** Internal trusted ingestion only. expectedRevision makes stale updates fail. */
    public static function updateLive(int $sid, array $cancelled, ?string $close, int $expectedRevision, string $eventId): void
    {
        self::loadLegacy();
        if ($eventId === '' || strlen($eventId) > 128) throw new \InvalidArgumentException('Source event id required');
        DB::transaction(function () use ($sid,$cancelled,$close,$expectedRevision,$eventId) {
            $live = DB::table('baganriki_live_states')->where('snapshot_id',$sid)->lockForUpdate()->first();
            $snap = DB::table('baganriki_snapshots')->where('id',$sid)->first();
            if ($snap === null || $live === null || (int)$snap->generation !== 0 || !self::selected((string)$snap->race_id)) throw new \RuntimeException('Not a selected live snapshot');
            $old = (array)json_decode((string)$live->cancellations,true,512,JSON_THROW_ON_ERROR);
            sort($old,SORT_NUMERIC);
            $input = json_decode((string)$snap->input_json_1st,true,512,JSON_THROW_ON_ERROR);
            $band = [];
            foreach ($input['runners'] as $h) $band[(int)$h['horse_no']] = $h['band'];
            foreach ($cancelled as $n) if (!is_int($n) || !isset($band[$n])) throw new \InvalidArgumentException('Unknown cancellation');
            $next = array_values(array_unique(array_merge($old,$cancelled))); sort($next,SORT_NUMERIC);
            $newClose = $live->live_close;
            if ($close !== null) {
                $us = W::dbUs($close);
                if (!preg_match('/\A(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?\z/D',$close,$parts)
                    || $us === null || W::toDb($us) !== $parts[1].' '.$parts[2].'.'.str_pad($parts[3] ?? '',6,'0')) {
                    throw new \InvalidArgumentException('Invalid close timestamp');
                }
                $frozenClose=W::dbUs($snap->sales_close_at);
                if ($frozenClose===null) throw new \RuntimeException('Missing frozen close');
                $effective = min($frozenClose, W::dbUs($live->live_close) ?? PHP_INT_MAX);
                if ($us < $effective) $newClose = W::toDb($us); // never extend deadline
            }
            if ($next === $old && $newClose === $live->live_close) return; // idempotent replay
            if ((int)$live->state_revision !== $expectedRevision) throw new \RuntimeException('STATE_CHANGED: reread trusted source');
            $allocation = BaganrikiRanking::afterCancellation($band,$next,(array)$input['role_quota'],$input['allocation_mode'] ?? null);
            DB::table('baganriki_live_states')->where('snapshot_id',$sid)->update([
                'cancellations'=>json_encode($next,JSON_THROW_ON_ERROR),
                'active_count'=>$allocation['active_count'], 'live_target_count'=>$allocation['target_count'],
                'live_quota'=>json_encode($allocation['live_quota'],JSON_THROW_ON_ERROR), 'live_close'=>$newClose,
                'state_revision'=>$expectedRevision+1, 'updated_at'=>W::toDb(W::nowUs()),
            ]);
            W::event((string)$snap->race_id,$sid,'LIVE_UPDATE',$expectedRevision,$expectedRevision+1,
                ['source_event_id'=>$eventId,'cancelled'=>$next,'live_close'=>$newClose],$eventId,W::nowUs());
        });
        self::wakeSnapshot($sid);
    }

    /** No HTTP waits here. Also covers races with no snapshot and no web access. */
    public static function tick(): array
    {
        $out = [];
        if (!self::enabled() || !self::ready()) return $out;
        foreach (config('baganriki_jobs.race_ids',[]) as $id) {
            try { $out[$id] = self::request($id); }
            catch (\Throwable $e) { \Log::error('[R122-JOBS] tick', ['race_id'=>$id,'exception'=>get_class($e)]); $out[$id]=['code'=>'DISPATCH_ERROR']; }
        }
        return $out;
    }
}

