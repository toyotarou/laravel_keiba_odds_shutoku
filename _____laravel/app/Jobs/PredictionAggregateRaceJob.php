<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261021-R122-JOBAGG  AggregateRaceJob（010の2-2）
 * ═══════════════════════════════════════════════════════════════════════════
 * なるの指定：
 *   「AIのHTTP応答を待ちません。両roleの処理が確定したとき、または
 *     応答期限に達して待機を終えるときに、保存済みの採用可能な結果で
 *     統合・公開判定を進めます。公開commit直前の時刻・版の検査を
 *     維持します。」
 *
 * ■ AIを待ちません
 *   このJobは外部へ1回も通信しません。DBを読んで公開判定をするだけです。
 *   だから待ち行列を AI の2本と分けます（r122-agg）。
 *   1st が145秒かかっていても、こちらは止まりません。
 *
 * ■ 2回の起動を持ちます（010の2-3）
 *   受領時   … RunRoleJob の末尾から（reason='RECEIVED'）
 *   期限時   … FreezeRaceJob が応答期限に合わせて予約（reason='DEADLINE'）
 *   期限のほうは<b>期限ぴったりと3秒後の2回</b>積みます。
 *   1回だけだと、同じ時刻に来た回が待ちで終わる場合があるためです。
 *
 * ■ 早すぎる公開をしません（010の2-4）
 *   公開判定は publishWhenReady です。
 *   そこで「HTTP受領の終端」ではなく「採否確定の印」を見ます
 *   （@ANCHOR-20261021-R122-SETTLE）。
 *   このJobは判定を書き写しません。同じ関数を呼びます。
 *
 * ■ commit直前の検査は、そのままです
 *   publishPrediction の中にある intent・revision・時刻の検査は触っていません。
 */
class PredictionAggregateRaceJob extends PredictionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** 外部への通信はありません。DBだけです */
    public $timeout = 60;

    /**
     * やり直してかまいません。
     * 同じ intent は作り直さない契約なので、二重に公開しません。
     */
    public $tries = 3;

    /** @var int[] やり直しの間隔（秒） */
    public $backoff = [2, 5];

    /** @var int */
    public $snapshotId;
    /** @var string なぜ動いたか（RECEIVED／DEADLINE／ENTRY／SWEEP／ROLE_FAILED／LIVE） */
    public $reason;

    public function __construct(int $snapshotId, string $reason = 'RECEIVED')
    {
        $this->snapshotId = $snapshotId;
        $this->reason     = $reason;

        $this->onConnection(self::CONN);
        $this->onQueue(self::Q_AGG);
    }

    public function handle(): void
    {
        $A = \App\Http\Controllers\Api\AiController::class;
        $W = \App\Http\Controllers\Api\BaganrikiWorker::class;

        if (!self::enabled()) { return; }

        $snap = \DB::table('baganriki_snapshots')->where('id', $this->snapshotId)->first();
        if ($snap === null) {
            $this->note('凍結入力がありません', ['snapshot_id' => $this->snapshotId]);
            return;
        }
        $raceId = (string) $snap->race_id;

        // 旧経路で始まったレースには触りません（010の2-5）
        if ($A::processingPathOf($this->snapshotId) === 'SYNC') {
            $this->note('このレースは旧経路なので触りません',
                ['race_id' => $raceId, 'reason' => $this->reason]);
            return;
        }

        $ctrl = app($A);

        // ── ① いまの採否の状況を記録します（なるが追えるように）────────
        $state = [];
        foreach (['1st', '2nd'] as $role) {
            $f = \DB::table('baganriki_ai_attempts')
                   ->where('snapshot_id', $this->snapshotId)->where('role', $role)
                   ->where('attempt_no', $W::FIRST)->first();
            $x = \DB::table('baganriki_ai_attempts')
                   ->where('snapshot_id', $this->snapshotId)->where('role', $role)
                   ->where('attempt_no', $W::FIX)->first();
            $sv = $A::roleSettled($this->snapshotId, $role);
            $state[$role] = [
                '初回'   => $f === null ? '枠なし' : (string) $f->status,
                '訂正'   => $x === null ? '枠なし' : (string) $x->status,
                '採否'   => $sv['settled'] ? '確定' : '未確定',
                '確定の根拠' => $sv['by'],
            ];
        }

        // ── ② 公開判定（判定そのものは既存の関数です）──────────────
        $pb = $ctrl->publishWhenReady($this->snapshotId);

        $this->note('集計しました', [
            'race_id' => $raceId, 'なぜ動いたか' => $this->reason,
            'code' => $pb['code'] ?? null,
            'publication_id' => $pb['publication_id'] ?? null,
            '公開候補' => count((array) ($pb['candidates'] ?? [])),
            'reason' => $pb['reason'] ?? null,
            'roleの状況' => $state,
        ]);

        // ── ③ まだ待ちなら、期限の側でもう一度動けるようにします ────
        //   受領で動いたのに待ちだった場合（もう片方がまだ）です。
        //   期限の起動は FreezeRaceJob が予約しているので、ここでは
        //   <b>積み増しません。</b>積み増すと待ち行列が膨らみます。
        //   期限より前に両roleが確定したときは、その role の RunRoleJob が
        //   自分で集計を積みます。
        if ((string) ($pb['code'] ?? '') === 'WAITING'
            && $this->reason === 'DEADLINE') {
            // 期限で動いたのに待ちになるのは、期限が出せないときだけです
            $this->note('期限で動いたのに待ちになりました（期限が出せていません）',
                ['race_id' => $raceId, 'reason' => $pb['reason'] ?? null]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->note('AggregateRaceJob が失敗しました', [
            'snapshot_id' => $this->snapshotId, 'なぜ動いたか' => $this->reason,
            'message' => $e->getMessage(),
            'at' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }
}
