<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * @ANCHOR-20261021-R122-JOBFREEZE  FreezeRaceJob（010の2-2）
 * ═══════════════════════════════════════════════════════════════════════════
 * なるの指定：
 *   「出馬表・取得監査行から、既存の凍結処理を通して同一レースの入力を
 *     一度だけ確定します。凍結の保存成功後、1stと2ndのRunRoleJobを
 *     独立に投入します。トランザクション内で投入する場合は、未commitの
 *     snapshotをworkerが先に読まないようにします。凍結後に馬データを
 *     取り直して別本文で送ることはしません。」
 *
 * ■ 一度だけ
 *   ShouldBeUnique は使いません。<b>凍結そのものが一度だけ</b>です。
 *   freezePredictionInput は generation=0 の行が既にあれば ALREADY を返して
 *   作り直しません。レースIDを鍵にした一意制約が本物の見張りです。
 *   （ShouldBeUnique はキャッシュの鍵で守るので、cache driver が file の
 *     この環境では、本物の見張りとしては弱いです）
 *
 * ■ 未commit を読ませない
 *   config/queue.php の r122 接続に `'after_commit' => true` を入れます。
 *   これで dispatch はトランザクションの commit 後に実際に積まれます。
 *   さらにこの Job は<b>自分でトランザクションを張りません</b>。
 *   凍結は freezePredictionInput の中で閉じます。
 *
 * ■ 凍結後に取り直さない
 *   RunRoleJob は<b>保存済みの本文</b>（input_json_1st / input_json_2nd）を
 *   そのまま送ります。sendInitialAiRequest が snapshot_hash の一致まで見ます。
 */
class PredictionFreezeRaceJob extends PredictionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** 入力凍結はDBの作業だけです。外部への通信はありません */
    public $timeout = 120;

    /** 凍結は何度やっても一度しか作らないので、やり直してかまいません */
    public $tries = 3;

    /** やり直しの間隔（秒）。3回で合計約21秒です */
    public $backoff = [3, 8, 10];

    /** @var string 日付 'YYYY-MM-DD' */
    public $date;
    /** @var string 回次 */
    public $kaisuu;
    /** @var string 場コード */
    public $basho;
    /** @var string 日 */
    public $day;
    /** @var int レース番号 */
    public $race;

    public function __construct(string $date, string $kaisuu, string $basho,
                                string $day, int $race)
    {
        $this->date   = $date;
        $this->kaisuu = $kaisuu;
        $this->basho  = $basho;
        $this->day    = $day;
        $this->race   = $race;

        $this->onConnection(self::CONN);
        $this->onQueue(self::Q_FREEZE);
    }

    /**
     * 応答期限を過ぎてからやり直しても意味がないので、そこで打ち切ります。
     * （Laravel 8 の retryUntil。失敗の記録だけ残ります）
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addSeconds(120);
    }

    public function handle(): void
    {
        $A = \App\Http\Controllers\Api\AiController::class;
        $raceId = $A::buildPredictionRaceId($this->date, $this->kaisuu, $this->basho,
                                 $this->day, $this->race);

        if (!self::enabled()) {
            $this->note('Jobの段が下りているので何もしません', ['race_id' => $raceId]);
            return;
        }

        $ctrl = app($A);

        // ── ① すでに凍結済みなら作り直しません ──────────────────────
        $snap = \DB::table('baganriki_snapshots')
                  ->where('race_id', $raceId)->where('generation', 0)->first();

        if ($snap === null) {
            // ── ② 既存の凍結処理をそのまま通します ──────────────────
            //   出馬表と取得監査行を読むのは freezePredictionInput の中です。
            //   ここで馬データを別に取り直したりはしません。
            $fz = $ctrl->freezePredictionInput($this->date, $this->kaisuu, $this->basho,
                                    $this->day, $this->race, [], 'PRODUCTION', false);
            $this->note('凍結しました', [
                'race_id' => $raceId,
                'ok'      => $fz['ok'] ?? false,
                'code'    => $fz['code'] ?? null,
                'reason'  => $fz['reason'] ?? null,
            ]);
            if (!($fz['ok'] ?? false)) { return; }
            $snap = \DB::table('baganriki_snapshots')
                      ->where('race_id', $raceId)->where('generation', 0)->first();
            if ($snap === null) { return; }
        }

        $sid = (int) $snap->id;

        // ── ③ このレースで使う経路を固定します（010の2-5）────────────
        //   「レースの処理開始時に使う経路を固定します。Job失敗を理由に、
        //     送信済み・結果不明の同じ枠を旧経路から再送してはいけません。」
        $A::fixProcessingPath($sid, 'JOB');

        // ── ④' 先に期限での集計を確保します（なる 10/23 重要3）──────────
        //   @ANCHOR-20261023-NARU-DEADLINE  role を積んだあと・期限を予約する前に
        //   止まると予約が欠けるので、順番を入れ替えました。下の⑤は呼び出しを残し、
        //   同じ期限なら二重に積まない共通の関数（ensureDeadlineAggregation）に替えました。
        $A::ensureDeadlineAggregation($sid);

        // ── ④ 1st と 2nd を独立に積みます ───────────────────────────
        //   片方の失敗がもう片方を止めません。
        //   枠（baganriki_ai_attempts）は一意なので、画面側と同時に
        //   動いても二重には送りません。
        foreach (['1st', '2nd'] as $role) {
            $has = \DB::table('baganriki_ai_attempts')
                     ->where('snapshot_id', $sid)->where('role', $role)
                     ->where('attempt_no',
                         \App\Http\Controllers\Api\BaganrikiWorker::FIRST)->exists();
            if ($has) {
                $this->note('その role の初回の枠はすでにあります（積みません）',
                    ['race_id' => $raceId, 'role' => $role]);
                continue;
            }
            PredictionRunRoleJob::dispatch($sid, $role);
        }

        // ── ⑤ 期限での集計を、受領とは独立に予約します（010の2-3）──
        //   「凍結完了時：応答期限に連動する集計Jobを独立に予約します。
        //     受領時の起動と、期限時の起動の両方を持たせます。」
        //   受領時の起動は RunRoleJob の末尾で積みます。こちらは期限の側です。
        // @ANCHOR-20261023-NARU-DEADLINE  ④' で確保済み。ここは同じ期限なら何もしません
        $ed = $A::ensureDeadlineAggregation($sid);
        $live = \DB::table('baganriki_live_states')
                  ->where('snapshot_id', $sid)->first();
        $dl = \App\Http\Controllers\Api\BaganrikiWorker::deadlines($snap, $live);
        // 【旧】if ($dl['ok'] && $dl['reply'] !== null) { … 2本を積む … }
        if (false && $dl['ok'] && $dl['reply'] !== null) {
            $leftSec = (int) floor(($dl['reply']
                        - \App\Http\Controllers\Api\BaganrikiWorker::nowUs()) / 1000000);
            // 期限ぴったりと、そのすぐ後の2回を積みます。
            // 1回だけだと、同じ時刻に来た回が待ちで終わる場合があります
            // （010の2-3 末尾のご指摘。判定自体は >= に直してあります）
            foreach ([0, 3] as $plus) {
                $d = $leftSec + $plus;
                if ($d < 0) { $d = 0; }
                PredictionAggregateRaceJob::dispatch($sid, 'DEADLINE')
                    ->delay(now()->addSeconds($d));
            }
            $this->note('期限での集計を予約しました', [
                'race_id' => $raceId, '応答期限まで秒' => $leftSec,
            ]);
        }
        $this->note('期限での集計', ['race_id' => $raceId, 'code' => $ed['code'] ?? null]);
    }

    /** 失敗しても商品は止めません。記録だけ残します */
    public function failed(\Throwable $e): void
    {
        $this->note('FreezeRaceJob が失敗しました', [
            'date' => $this->date, 'race' => $this->race,
            'message' => $e->getMessage(),
            'at' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }
}
