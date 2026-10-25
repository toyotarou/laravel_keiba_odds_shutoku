<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261021-R122-JOBLIVE  UpdateRaceLiveJob（010の2-2）
 * ═══════════════════════════════════════════════════════════════════════════
 * なるの指定：
 *   「正式取消・発走時刻等の実状態変更を既存の版管理に接続し、必要な
 *     再判定を起動します。AIの新規送信や、凍結済み本文の無断変更は
 *     担当させません。実効期限が変わったときは、その期限に対する起動も
 *     再確認します。」
 *
 * ■ AIへは1回も送りません
 *   このJobは RunRoleJob を積みません。sendInitialAiRequest も sendCorrectionRequest も
 *   呼びません。やるのは
 *     ・実状態（取消・発走時刻）を既存の版管理へ入れる
 *     ・期限が前倒しになったら、その期限での集計を積み直す
 *     ・再判定（集計）を積む
 *   だけです。
 *
 * ■ 凍結済み本文は触りません
 *   input_json_1st / input_json_2nd には書きません。
 *   取消は baganriki_live_states 側で表します（既存の作りのままです）。
 *
 * ■ 期限は前倒しだけです
 *   live_close は「締切の前倒し。延長はしない」列です（表の注記のとおり）。
 *   前倒しになったときだけ、新しい期限での集計を積み直します。
 */
class PredictionUpdateRaceLiveJob extends PredictionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** DBだけです */
    public $timeout = 60;
    public $tries = 3;
    public $backoff = [2, 5];

    /** @var int */
    public $snapshotId;
    /** @var array 実状態の変更（['cancellations'=>[…], 'live_close_us'=>int|null]） */
    public $change;

    public function __construct(int $snapshotId, array $change = [])
    {
        $this->snapshotId = $snapshotId;
        $this->change     = $change;

        $this->onConnection(self::CONN);
        $this->onQueue(self::Q_AGG);   // 期限・統合と同じ枠です（AIを待ちません）
    }

    public function handle(): void
    {
        $A = \App\Http\Controllers\Api\AiController::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;

        if (!self::enabled()) { return; }

        $snap = \DB::table('baganriki_snapshots')->where('id', $this->snapshotId)->first();
        if ($snap === null) { return; }
        $raceId = (string) $snap->race_id;

        if ($A::processingPathOf($this->snapshotId) === 'SYNC') {
            $this->note('このレースは旧経路なので触りません', ['race_id' => $raceId]);
            return;
        }

        $before = \DB::table('baganriki_live_states')
                    ->where('snapshot_id', $this->snapshotId)->first();
        $dlBefore = $W::deadlines($snap, $before);

        // ── ① 実状態を既存の版管理へ入れます ──────────────────────
        //   applyLiveChange が無い環境では何もしません（存在を見てから呼びます）。
        $applied = null;
        if (method_exists($A, 'applyLiveChange')) {
            $applied = app($A)->applyLiveChange($this->snapshotId, $this->change);
        } else {
            $this->note('実状態を入れる関数がまだありません（記録だけ残します）', [
                'race_id' => $raceId, 'change' => $this->change,
            ]);
        }

        $after = \DB::table('baganriki_live_states')
                   ->where('snapshot_id', $this->snapshotId)->first();
        $dlAfter = $W::deadlines($snap, $after);

        // ── ② 期限が前倒しになったら、新しい期限で集計を積み直します ──
        $moved = false;
        if ($dlBefore['ok'] && $dlAfter['ok']
            && $dlBefore['reply'] !== null && $dlAfter['reply'] !== null
            && (int) $dlAfter['reply'] < (int) $dlBefore['reply']) {
            $moved = true;
            // @ANCHOR-20261023-NARU-DEADLINE  新しい期限での予約は共通の関数で（同じ期限なら二重に積まない）
            // 【旧】ここで直接2本を積んでいました
        }
        $A::ensureDeadlineAggregation($this->snapshotId);

        // ── ③ 再判定を積みます ───────────────────────────────────
        PredictionAggregateRaceJob::dispatch($this->snapshotId, 'LIVE');

        $this->note('実状態を入れました', [
            'race_id' => $raceId,
            'change'  => $this->change,
            'applied' => $applied,
            '期限が前倒しになったか' => $moved,
            '応答期限（前）' => $dlBefore['reply'] ?? null,
            '応答期限（後）' => $dlAfter['reply'] ?? null,
            '版（前）' => $before === null ? null : (int) $before->state_revision,
            '版（後）' => $after === null ? null : (int) $after->state_revision,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->note('UpdateRaceLiveJob が失敗しました', [
            'snapshot_id' => $this->snapshotId,
            'message' => $e->getMessage(),
            'at' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }
}
