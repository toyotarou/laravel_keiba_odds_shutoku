<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261021-R122-JOBROLE  RunRoleJob（010の2-2）
 * ═══════════════════════════════════════════════════════════════════════════
 * なるの指定：
 *   「snapshot_idとroleを単位にし、既存の予約→送信許可→初回送信→
 *     原応答保存→診断→必要な訂正→採否確定を行います。
 *     現行の sendInitialAiRequest は内部で afterAiResponseReceived まで呼んでいます。
 *     Job側から同じ後処理をさらに呼ばないようにします。
 *     afterAiResponseReceived末尾の直接公開は、roleの処理完了を保存して
 *     AggregateRaceJobへ渡す形に変更します。」
 *
 * ■ 後処理を二度呼びません
 *   この handle() が呼ぶのは <b>sendInitialAiRequest だけ</b>です。
 *   inspectAiResponse／sendCorrectionRequest／acceptedHorsesOf は、
 *   その中の afterAiResponseReceived が1回ずつ呼びます。ここでは呼びません。
 *
 * ■ 公開はここでしません
 *   afterAiResponseReceived の末尾は、Jobの段が上がっているときは
 *   公開へ進まず、<b>採否確定の印だけ</b>を残します
 *   （@ANCHOR-20261021-R122-SETTLE）。公開は AggregateRaceJob です。
 *   このJobの最後に、受領時の起動として AggregateRaceJob を積みます。
 *
 * ■ 1回しか試しません
 *   $tries = 1 です。やり直すと外部APIへ二度送るおそれがあります。
 *   送信権（token）が二重送信を止めますが、Jobの側でも1回に固定します。
 *   worker が落ちて同じJobがもう一度動いたときは、
 *   sendInitialAiRequest が「その role の初回の枠はすでにあります」（ALREADY）で
 *   何もせずに返ります。これが「worker再実行と外部API再送の区別」です。
 */
class PredictionRunRoleJob extends PredictionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * 入力凍結（T−330秒）から応答期限（T−150秒）までは 180秒です。
     * その内側で終わるように 175秒にします。
     * worker の --timeout はこれ以上（180秒）にします。
     * 接続の retry_after はさらに長く（300秒）します。
     */
    public $timeout = 175;

    /** やり直しません（外部APIへ二度送らないため）*/
    public $tries = 1;

    /** 上限に達したら失敗として記録します（黙って消しません）*/
    public $failOnTimeout = true;

    /** @var int */
    public $snapshotId;
    /** @var string '1st' | '2nd' */
    public $role;

    public function __construct(int $snapshotId, string $role)
    {
        $this->snapshotId = $snapshotId;
        $this->role       = $role;

        $this->onConnection(self::CONN);
        $this->onQueue(self::queueForRole($role));
    }

    public function handle(): void
    {
        $A = \App\Http\Controllers\Api\AiController::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;

        if (!self::enabled()) {
            $this->note('Jobの段が下りているので何もしません',
                ['snapshot_id' => $this->snapshotId, 'role' => $this->role]);
            return;
        }
        if ($this->role !== '1st' && $this->role !== '2nd') {
            $this->note('role が違います', ['role' => $this->role]);
            return;
        }

        $snap = \DB::table('baganriki_snapshots')->where('id', $this->snapshotId)->first();
        if ($snap === null) {
            $this->note('凍結入力がありません', ['snapshot_id' => $this->snapshotId]);
            return;
        }
        $raceId = (string) $snap->race_id;

        // ── ① このレースの経路が Job に固定されているか ────────────────
        //   旧経路で始まったレースを、途中からJobで触りません（010の2-5）。
        $path = $A::processingPathOf($this->snapshotId);
        if ($path !== 'JOB') {
            $this->note('このレースは旧経路で始まっているので触りません', [
                'race_id' => $raceId, 'role' => $this->role, '経路' => $path,
            ]);
            return;
        }

        // ── ② 12レース限定（010の2-5・7章⑤）───────────────────────
        //   「送信許可の直前でも確認します」。sendInitialAiRequest の中でも
        //   見ますが、待ち行列に積まれてから時間が経つので、ここでも見ます。
        $tg = $A::checkPredictionTarget($raceId);
        if (!$tg['ok']) {
            $this->note('検証の対象レースではないので送りません', [
                'race_id' => $raceId, 'role' => $this->role,
                'code' => $tg['code'], 'reason' => $tg['reason'],
            ]);
            return;
        }

        // ── ③ 応答期限までの残りを見ます ───────────────────────────
        $live = \DB::table('baganriki_live_states')
                  ->where('snapshot_id', $this->snapshotId)->first();
        $dl = $W::deadlines($snap, $live);
        if (!$dl['ok'] || $dl['reply'] === null) {
            $this->note('期限が出せないので送りません',
                ['race_id' => $raceId, 'code' => $dl['code'] ?? null]);
            return;
        }
        $left = (int) floor(($dl['reply'] - $W::nowUs()) / 1000000);
        $this->note('初回を送ります', [
            'race_id' => $raceId, 'role' => $this->role, '応答期限まで秒' => $left,
        ]);

        // ── ④ 既存の経路をそのまま通します（ここだけです）────────────
        //   予約 → 送信許可 → 初回送信 → 原応答保存 → 診断 →
        //   必要な訂正 → 採否確定（afterAiResponseReceived が中でやります）
        // @ANCHOR-20261023-NARU-JOBTIME  この Job が打ち切られる時刻を渡します（なる 10/23 重要6）
        //   初回と訂正の送信直前に、応答期限とこの残り時間の両方で待ち時間を決めます。
        $A::setJobDeadline($W::nowUs() + ((int) $this->timeout - 2) * 1000000);
        try {
            $r = app($A)->sendInitialAiRequest($this->snapshotId, $this->role);
        } finally {
            $A::setJobDeadline(null);
        }

        $this->note('初回の結果', [
            'race_id' => $raceId, 'role' => $this->role,
            'sent' => $r['sent'] ?? false, 'ok' => $r['ok'] ?? false,
            'code' => $r['code'] ?? null, 'reason' => $r['reason'] ?? null,
        ]);

        // ── ⑤ 受領時の起動として、集計を積みます（010の2-3）────────────
        //   期限時の起動は FreezeRaceJob が別に積んでいます。
        //   両方を持たせます。集計の側で、両roleの採否確定を見ます。
        PredictionAggregateRaceJob::dispatch($this->snapshotId, 'RECEIVED');
    }

    /**
     * 失敗しても商品は止めません。
     * 枠が SENT のまま残ると「結果不明」なので、そのことを記録します。
     * <b>旧経路からの再送はしません</b>（010の2-5）。
     */
    public function failed(\Throwable $e): void
    {
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;
        $st = null;
        try {
            $st = (string) \DB::table('baganriki_ai_attempts')
                    ->where('snapshot_id', $this->snapshotId)
                    ->where('role', $this->role)
                    ->where('attempt_no', $W::FIRST)->value('status');
        } catch (\Throwable $x) {
            // 読めなくても何もしません
        }
        $this->note('RunRoleJob が失敗しました（旧経路からの再送はしません）', [
            'snapshot_id' => $this->snapshotId, 'role' => $this->role,
            '枠の状態' => $st, 'message' => $e->getMessage(),
            'at' => $e->getFile() . ':' . $e->getLine(),
        ]);
        // 期限での集計は動くので、片側だけでも公開判定へ進めます
        try {
            PredictionAggregateRaceJob::dispatch($this->snapshotId, 'ROLE_FAILED');
        } catch (\Throwable $x) {
            // 積めなくても、期限での予約が残っています
        }
    }
}
