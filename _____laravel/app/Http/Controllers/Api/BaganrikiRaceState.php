<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * BaganrikiRaceState  レースごとの状態：役割の完了・処理経路・対象レース・実状態（発走時刻・取消）
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class BaganrikiRaceState
{
    /**
     * その role の「応答の版」を作ります。
     *
     *   初回と訂正の行を attempt_no 順に並べ、状態の分かる項目だけを
     *   連結して SHA-256 にします。行が無ければ 'none' を混ぜます。
     *   （raw_response そのものは混ぜません。response_hash で足ります）
     *
     * @return string 64文字
     */
    public static function roleResponseVersion(int $snapshotId, string $role): string
    {
        $parts = [];
        $rows = \DB::table('baganriki_ai_attempts')
                  ->where('snapshot_id', $snapshotId)
                  ->where('role', $role)
                  ->orderBy('attempt_no')
                  ->get(['attempt_no', 'status', 'response_hash', 'complete']);
        foreach ($rows as $r) {
            $parts[] = implode("\x1f", [
                (string) (int) $r->attempt_no,
                (string) $r->status,
                (string) ($r->response_hash ?? ''),
                (string) (int) $r->complete,
            ]);
        }
        if ($parts === []) { $parts[] = 'none'; }
        return hash('sha256', $snapshotId . "\x1e" . $role . "\x1e" . implode("\x1e", $parts));
    }

    /**
     * 採否が確定した印を付けます（同じ版に二度は付けません）。
     *
     * @param array $info 印に添える内容（resolve の結果など）
     * @return array ['marked','version','already']
     */
    public static function markRoleSettled(int $snapshotId, string $role,
                                               array $info = [],
                                               ?int $nowUs = null): array
    {
        $out = ['marked' => false, 'version' => null, 'already' => false];
        if (!\Schema::hasTable('baganriki_audit_events')) { return $out; }

        $ver = AiController::roleResponseVersion($snapshotId, $role);
        $out['version'] = $ver;

        // すでに同じ版の印があれば足しません
        $cur = AiController::roleSettled($snapshotId, $role);
        if ($cur['settled']) { $out['already'] = true; return $out; }

        $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
        $live = \DB::table('baganriki_live_states')
                  ->where('snapshot_id', $snapshotId)->first();
        $now  = $nowUs !== null ? $nowUs : BaganrikiWorker::nowUs();

        BaganrikiWorker::event(
            (string) ($snap->race_id ?? ''),
            $snapshotId,
            'R122_ROLE_SETTLED',
            null,
            $live !== null ? (int) $live->response_revision : null,
            ['role' => $role, 'version' => $ver] + $info,
            $role,
            $now
        );
        $out['marked'] = true;
        return $out;
    }

    /**
     * その role の採否が確定しているかを見ます。
     *
     *   いまの版と同じ版の印が1つでもあれば確定です。
     *   版が違う印（訂正が後から届いた等）は確定として数えません。
     *
     * @return array ['settled','version','found','stale']
     */
    public static function roleSettled(int $snapshotId, string $role): array
    {
        $out = ['settled' => false, 'version' => null, 'found' => 0, 'stale' => 0,
                'by' => null];
        if (!\Schema::hasTable('baganriki_audit_events')) {
            // 監査表が無い環境では、印で待たせません（従来どおりの数え方）
            $out['settled'] = true;
            return $out;
        }
        $ver = AiController::roleResponseVersion($snapshotId, $role);
        $out['version'] = $ver;

        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-NOTHINGTOADOPT  採るものが無い role
        // ══════════════════════════════════════════════════════════════
        //   初回が FAILED（明確な失敗）か UNKNOWN_RESULT（結果不明）で、
        //   訂正の枠も無い（または終端）なら、その role には
        //   <b>診断も訂正も採否も、やることが残っていません。</b>
        //   印を待っても永久に付きません（印を付けるのは
        //   afterAiResponseReceived で、それは受領に成功したときだけ動きます）。
        //   ここで待たせると、失敗した役のぶんだけ公開が応答期限まで
        //   遅れます。公開期限は応答期限の30秒後しかありません。
        //   なので「採るものが無い」ことを確定として数えます。
        //   RECEIVED のときは<b>数えません</b>。それが010の2-4です。
        $f = \DB::table('baganriki_ai_attempts')
               ->where('snapshot_id', $snapshotId)->where('role', $role)
               ->where('attempt_no', BaganrikiWorker::FIRST)->first();
        if ($f !== null
            && in_array((string) $f->status,
                        [BaganrikiWorker::FAILED, BaganrikiWorker::UNKNOWN], true)) {
            $x = \DB::table('baganriki_ai_attempts')
                   ->where('snapshot_id', $snapshotId)->where('role', $role)
                   ->where('attempt_no', BaganrikiWorker::FIX)->first();
            if ($x === null
                || in_array((string) $x->status, BaganrikiWorker::TERMINAL, true)) {
                $out['settled'] = true;
                $out['by'] = 'NOTHING_TO_ADOPT';
                $out['first_status'] = (string) $f->status;
                return $out;
            }
        }

        $rows = \DB::table('baganriki_audit_events')
                  ->where('snapshot_id', $snapshotId)
                  ->where('kind', 'R122_ROLE_SETTLED')
                  ->where('related_id', $role)
                  ->orderByDesc('id')
                  ->limit(20)
                  ->get(['diagnostics']);
        foreach ($rows as $r) {
            $out['found']++;
            $d = json_decode((string) $r->diagnostics, true);
            if (is_array($d) && (string) ($d['version'] ?? '') === $ver) {
                $out['settled'] = true;
            } else {
                $out['stale']++;
            }
        }
        return $out;
    }

    /**
     * レースの処理開始時に使う経路を固定します（010の2-5）。
     *
     *   「旧経路を残す場合も、レースの処理開始時に使う経路を固定します。
     *     Job失敗を理由に、送信済み・結果不明の同じ枠を旧経路から
     *     再送してはいけません。」
     *
     *   すでに固定されていれば<b>上書きしません。</b>先に決めたほうが勝ちです。
     *
     * @return array ['path','fixed','already']
     */
    public static function fixProcessingPath(int $snapshotId, string $path): array
    {
        $out = ['path' => null, 'fixed' => false, 'already' => false];
        if ($path !== 'JOB' && $path !== 'SYNC') { return $out; }
        if (!\Schema::hasTable('baganriki_audit_events')) {
            $out['path'] = $path;   // 監査表が無ければ記録できません
            return $out;
        }
        $cur = AiController::processingPathOf($snapshotId);
        if ($cur !== null) {
            $out['path'] = $cur;
            $out['already'] = true;
            return $out;
        }
        $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
        BaganrikiWorker::event(
            (string) ($snap->race_id ?? ''), $snapshotId, AiController::PATH_FIXED_AUDIT_KIND,
            null, null, ['path' => $path], $path, BaganrikiWorker::nowUs()
        );
        $out['path'] = $path;
        $out['fixed'] = true;
        return $out;
    }

    /**
     * そのレースに固定された経路を返します。
     *   固定されていなければ null（＝まだどちらでもない）です。
     */
    public static function processingPathOf(int $snapshotId): ?string
    {
        if (!\Schema::hasTable('baganriki_audit_events')) { return null; }
        $r = \DB::table('baganriki_audit_events')
               ->where('snapshot_id', $snapshotId)
               ->where('kind', AiController::PATH_FIXED_AUDIT_KIND)
               ->orderBy('id')->first(['diagnostics', 'related_id']);
        if ($r === null) { return null; }
        $d = json_decode((string) $r->diagnostics, true);
        $p = is_array($d) ? (string) ($d['path'] ?? '') : '';
        if ($p === '') { $p = (string) ($r->related_id ?? ''); }
        return ($p === 'JOB' || $p === 'SYNC') ? $p : null;
    }

    public static function predictionTargets(): array
    {
        $raw = (string) env('BAGANRIKI_PREDICTION_TARGETS', '');
        $out = [];
        foreach (explode(',', $raw) as $t) {
            $t = trim($t);
            if ($t === '') { continue; }
            if (preg_match('/^(\d{8})-(\d{2})-(\d{1,2})$/', $t, $m)) {
                $full = AiController::resolveShortTargetId($m[1], $m[2], (int) $m[3]);
                if ($full === null) { continue; }   // 取り込み前：送らない
                $t = $full;
            }
            $out[$t] = true;
        }
        return array_keys($out);
    }

    /** 「日付-場-R」を本来の race_id へ。見つからなければ null */
    public static function resolveShortTargetId(string $ymd, string $basho, int $race): ?string
    {
        $key = $ymd . '-' . $basho . '-' . $race;
        if (isset(AiController::$shortTargetIdCache[$key])) {
            return AiController::$shortTargetIdCache[$key];
        }
        try {
            $d = substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
            $r = \DB::table('t_horse_odds_finder_races')
                    ->where('date', $d)->where('basho', $basho)
                    ->where('race', $race)->first(['kaisuu', 'day']);
            if ($r === null) { return null; }
            $full = AiController::buildPredictionRaceId($d, (string) $r->kaisuu, $basho,
                                     (string) $r->day, $race);
            // 見つかったものだけ覚えます（取り込み前の空振りは毎回読み直す）
            AiController::$shortTargetIdCache[$key] = $full;
            return $full;
        } catch (\Throwable $e) {
            \Log::error('[PREDICTION-TARGET] 日付-場-R を読み替えられません',
                ['target' => $key, 'message' => $e->getMessage()]);
            return null;
        }
    }

    /** @ANCHOR-20261023-NARU-TARGETMAX  対象レースの上限（BAGANRIKI_PREDICTION_TARGETS_MAX・既定12） */
    public static function predictionTargetMax(): int
    {
        $v = (int) env('BAGANRIKI_PREDICTION_TARGETS_MAX', 12);
        return $v > 0 ? $v : 12;
    }

    public static function checkPredictionTarget(string $raceId): array
    {
        $t = AiController::predictionTargets();
        $out = ['ok' => false, 'code' => null, 'reason' => null,
                'count' => count($t), 'targets' => $t];

        // 段が下りていれば、この見張りは使いません（いまの経路のまま）
        if (!BaganrikiWorker::targetsEnforced()) {
            $out['ok'] = true;
            $out['code'] = 'NOT_ENFORCED';
            $out['reason'] = '対象レースの限定は効かせていません';
            return $out;
        }
        if ($t === []) {
            $out['code'] = 'NO_TARGETS';
            $out['reason'] = 'BAGANRIKI_PREDICTION_TARGETS が空なので1レースも送りません';
            return $out;
        }
        // @ANCHOR-20261023-NARU-TARGETMAX  対象が上限を超える設定は丸ごと拒否します（なる 10/23 重要1）
        //   課金の上限を「12レース」で見積もっているため。超えたら1レースも送りません。
        $max = AiController::predictionTargetMax();
        if (count($t) > $max) {
            $out['code'] = 'TOO_MANY_TARGETS';
            $out['reason'] = 'BAGANRIKI_PREDICTION_TARGETS が ' . count($t) . '件あり、上限 '
                           . $max . '件を超えているので1レースも送りません';
            return $out;
        }
        // @ANCHOR-20261023-PREDICTION-ALL  TARGETS に「ALL」と書くと、実在の全レースを対象にします
        //   （10/23 ふなっしー「全レース予想するんだぜ？」。本番で全レースへ広げるときの書き方です。
        //     試験用のレース（場 89〜99）は ALL に含めません）
        // @ANCHOR-20261102-PREDICTION-ALL-BUDGET  ALL は1件と数えるので、上の12レースの上限が効きません。
        //   費用の歯止めを残すため、ALL は1日の予算（BAGANRIKI_PREDICTION_BUDGET_USD_DAY）を書いたときだけ効かせます。
        if (in_array('ALL', $t, true) && AiController::budgetUsdDay() === null) {
            $out['code'] = 'ALL_NEEDS_BUDGET';
            $out['reason'] = 'TARGETS=ALL は BAGANRIKI_PREDICTION_BUDGET_USD_DAY を書いたときだけ使えます（書いていないので1レースも送りません）';
            return $out;
        }
        if (in_array('ALL', $t, true) && !AiController::isTestRaceId($raceId)
            && (explode('-', $raceId)[2] ?? '') !== '89') {
            $out['ok'] = true;
            $out['code'] = 'TARGET';
            $out['reason'] = $raceId . ' は全レース対象（TARGETS=ALL）に入っています';
            return $out;
        }
        // @ANCHOR-20261023-NARU-FAKEAI  TARGETS に「TEST」と書くと、試験用のレース（場 90〜99）だけを対象にします
        if (in_array('TEST', $t, true) && AiController::isTestRaceId($raceId)) {
            $out['ok'] = true;
            $out['code'] = 'TARGET';
            $out['reason'] = $raceId . ' は試験用のレースです（TARGETS=TEST）';
            return $out;
        }
        if (!in_array($raceId, $t, true)) {
            $out['code'] = 'NOT_TARGET';
            $out['reason'] = $raceId . ' は検証の対象レース（' . count($t)
                           . '件）に入っていません';
            return $out;
        }
        $out['ok'] = true;
        $out['code'] = 'TARGET';
        $out['reason'] = $raceId . ' は検証の対象レースです';
        return $out;
    }

    /**
     * @ANCHOR-20261023-NARU-RESUME  その role にまだ仕事があるか
     *   枠が無い／予約済み・未送信／受領済み・採否未確定 のとき true。
     *   送信済み・結果不明・失敗は送り直さないので false。
     */
    public static function roleNeedsWork(int $snapshotId, string $role): bool
    {
        $f = \DB::table('baganriki_ai_attempts')
               ->where('snapshot_id', $snapshotId)->where('role', $role)
               ->where('attempt_no', BaganrikiWorker::FIRST)->first(['status']);
        if ($f === null) { return true; }
        $st = (string) $f->status;
        if ($st === BaganrikiWorker::RESERVED) { return true; }
        if ($st === BaganrikiWorker::RECEIVED) {
            return !AiController::roleSettled($snapshotId, $role)['settled'];
        }
        return false;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261023-NARU-DEADLINE  応答期限での集計を必ず予約します（なる 10/23 重要3）
     * ═══════════════════════════════════════════════════════════════════════
     *   新規の凍結・既存の凍結・回復・実状態の変更（取消・締切の前倒し）の
     *   すべての経路から、この1つの関数を呼びます。
     *   ・その時点の実効応答期限で、期限ちょうどと3秒後の2本を積みます。
     *   ・同じ期限で積んだ記録（監査 DEADLINE_AGG_SCHEDULED）があれば積みません
     *     （際限なく重複させないため）。
     *   ・期限が前倒しになったら、新しい期限でもう一度積みます。
     *   ・Job の段が下りているときは何もしません（巡回が5秒おきに期限を見ます）。
     *
     * @return array ['scheduled'=>bool,'code'=>string,'reply_us'=>?int]
     */
    public static function ensureDeadlineAggregation(int $snapshotId): array
    {
        $out = ['scheduled' => false, 'code' => null, 'reply_us' => null];
        try {
            if (!BaganrikiWorker::jobs()
                || !class_exists(\App\Jobs\PredictionAggregateRaceJob::class)) {
                $out['code'] = 'JOBS_OFF';
                return $out;
            }
            $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
            if ($snap === null) { $out['code'] = 'NO_SNAPSHOT'; return $out; }
            $live = \DB::table('baganriki_live_states')
                      ->where('snapshot_id', $snapshotId)->first();
            $dl = BaganrikiWorker::deadlines($snap, $live);
            if (!$dl['ok'] || $dl['reply'] === null) {
                $out['code'] = 'NO_DEADLINE';
                return $out;
            }
            $reply = (int) $dl['reply'];
            $out['reply_us'] = $reply;
            $done = \DB::table('baganriki_audit_events')
                      ->where('snapshot_id', $snapshotId)
                      ->where('kind', 'DEADLINE_AGG_SCHEDULED')
                      ->where('related_id', (string) $reply)->exists();
            if ($done) { $out['code'] = 'ALREADY'; return $out; }
            $leftSec = (int) floor(($reply - BaganrikiWorker::nowUs()) / 1000000);
            foreach ([0, 3] as $plus) {
                $d = max(0, $leftSec + $plus);
                \App\Jobs\PredictionAggregateRaceJob::dispatch($snapshotId, 'DEADLINE')
                    ->delay(now()->addSeconds($d));
            }
            BaganrikiWorker::event((string) $snap->race_id, $snapshotId,
                'DEADLINE_AGG_SCHEDULED', null, null,
                ['reply_us' => $reply, 'left_sec' => $leftSec],
                (string) $reply, BaganrikiWorker::nowUs());
            $out['scheduled'] = true;
            $out['code'] = 'SCHEDULED';
            return $out;
        } catch (\Throwable $e) {
            \Log::error('[PREDICTION-JOB] 期限での集計を予約できません',
                ['snapshot_id' => $snapshotId, 'message' => $e->getMessage()]);
            $out['code'] = 'EXCEPTION';
            return $out;
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261023-NARU-STARTTIME  発走時刻の前倒しを実状態へ入れます（オッズ取得から独立）
     * ═══════════════════════════════════════════════════════════════════════
     *   呼ぶところ
     *     ・巡回（sweepPendingPredictions）… 凍結済みの全対象レースを毎回見ます
     *     ・発走時刻の更新処理（keiba:repairStartTime）… 正本の start_time を書き換えた直後
     *     ・オッズ取得（syncLiveFromAcquisition）… これまでどおり
     *   前倒しのときだけ applyLiveChange へ渡します（延長はしません）。Job の段で JOB 経路の
     *   レースは UpdateRaceLiveJob を積みます。積んだ Job が動く前でも、期限の判定
     *   （BaganrikiWorker::deadlines）は正本を直接読むので、古い期限は使いません。
     * @return array ['ran','code','live_close_us']
     */
    public static function syncLiveStartTime(int $snapshotId, string $from): array
    {
        $out = ['ran' => false, 'code' => null, 'live_close_us' => null];
        try {
            $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
            if ($snap === null) { $out['code'] = 'NO_SNAPSHOT'; return $out; }
            $close = BaganrikiWorker::closeFromRaceStartTime($snap);
            if ($close === null) { $out['code'] = 'NO_CHANGE'; return $out; }
            $live = \DB::table('baganriki_live_states')->where('snapshot_id', $snapshotId)->first();
            $cur  = ($live === null) ? null : BaganrikiWorker::dbUs($live->live_close ?? null);
            if ($cur !== null && $close >= $cur) { $out['code'] = 'NO_CHANGE'; return $out; }
            $change = ['cancellations' => [], 'live_close_us' => $close];
            $out['ran'] = true;
            $out['live_close_us'] = $close;
            if (BaganrikiWorker::jobs() && class_exists(\App\Jobs\PredictionUpdateRaceLiveJob::class)
                && AiController::processingPathOf($snapshotId) !== 'SYNC') {
                \App\Jobs\PredictionUpdateRaceLiveJob::dispatch($snapshotId, $change);
                $out['code'] = 'JOB_DISPATCHED';
            } else {
                $r = app(AiController::class)->applyLiveChange($snapshotId, $change);
                $out['code'] = (string) ($r['code'] ?? '');
            }
            \Log::info('[PREDICTION-LIVE] 発走時刻の前倒しを実状態へ入れました', [
                'race_id' => (string) $snap->race_id, 'from' => $from, 'code' => $out['code'],
                'live_close' => BaganrikiTime::toJst($close),
            ]);
            return $out;
        } catch (\Throwable $e) {
            \Log::error('[PREDICTION-LIVE] 発走時刻の前倒しを入れられません', ['message' => $e->getMessage()]);
            $out['code'] = 'EXCEPTION';
            return $out;
        }
    }

    /**
     * @ANCHOR-20261023-NARU-STARTTIME  keiba:repairStartTime から呼びます（date・basho・race で特定）
     *   repairStartTime はこの3列でレースを特定しているので、同じ3列の凍結済みレースを探します。
     * @return array ['checked','ran','codes']
     */
    public static function syncLiveStartTimeForRace(string $date, string $basho, int $race): array
    {
        $out = ['checked' => 0, 'ran' => 0, 'codes' => []];
        try {
            if (!BaganrikiPrompt::enabled() || !\Schema::hasTable('baganriki_snapshots')) { return $out; }
            $d = str_replace('-', '', substr($date, 0, 10));
            foreach (\DB::table('baganriki_snapshots')->where('generation', 0)
                        ->where('race_id', 'like', $d . '-%')->get(['id', 'race_id']) as $sn) {
                $p = explode('-', (string) $sn->race_id);
                if (count($p) !== 5 || $p[2] !== (string) $basho || (int) $p[4] !== $race) { continue; }
                $out['checked']++;
                $r = AiController::syncLiveStartTime((int) $sn->id, 'repairStartTime');
                if ($r['ran']) { $out['ran']++; }
                $out['codes'][] = (string) $sn->race_id . ':' . (string) $r['code'];
            }
        } catch (\Throwable $e) {
            \Log::error('[PREDICTION-LIVE] repairStartTime からの連携で例外', ['message' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261023-NARU-LIVE  正式な取消・発走時刻の前倒しを、取得のたびに実状態へ入れます（なる 10/23 4-B）
     * ═══════════════════════════════════════════════════════════════════════
     *   【旧】applyLiveChange はありましたが、どこからも呼ばれていませんでした。
     *     凍結の後に出た取消・発走時刻の変更が、実状態へ入りませんでした。
     *   【いま】オッズ取得（ImportKeibaOdds）がバッチを採用したあと、ここを呼びます。
     *   ・取消：原ペイロードの tan が「取消」「除外」の馬（凍結のときと同じ読み方）。
     *     凍結集合にいる馬だけを足します。原ペイロードが厳密に読めない・馬番が重複するときは使いません。
     *   ・締切：t_horse_odds_finder_races.start_time から JRA の規則（発走1分前）で出し、
     *     凍結のときの締切より早いときだけ前倒しします。延長はしません。
     *   ・凍結入力（generation=0）が無いレースでは何もしません（AIへの送信もしません）。
     *   ・Job の段なら UpdateRaceLiveJob を積み、そうでなければその場で入れます。
     *   ・失敗してもオッズ取得は止めません。
     *
     * @return array ['ran','code','cancellations','live_close_us']
     */
    public static function syncLiveFromAcquisition($race, string $rawPayload): array
    {
        $out = ['ran' => false, 'code' => null, 'cancellations' => [], 'live_close_us' => null];
        try {
            if (!BaganrikiPrompt::enabled()) { $out['code'] = 'FLAG_OFF'; return $out; }
            if (!\Schema::hasTable('baganriki_snapshots')
                || !\Schema::hasTable('baganriki_live_states')) {
                $out['code'] = 'TABLE_MISSING'; return $out;
            }
            $rid = AiController::buildPredictionRaceId(substr((string) $race->date, 0, 10), $race->kaisuu,
                                               $race->basho, $race->day, (int) $race->race);
            $snap = \DB::table('baganriki_snapshots')->where('race_id', $rid)
                      ->where('generation', 0)->first();
            if ($snap === null) { $out['code'] = 'NO_SNAPSHOT'; return $out; }

            // ── 取消・除外の馬番 ───────────────────────────────────────
            $cancel = [];
            $pr = BaganrikiJson::strict($rawPayload);
            if ($pr['ok'] && is_array($pr['value'])) {
                $seen = []; $dup = false; $tmp = [];
                foreach ($pr['value'] as $h) {
                    if (!is_array($h)) { continue; }
                    $no = $h['num'] ?? null;
                    $d  = (is_int($no) || is_string($no)) ? trim((string) $no) : '';
                    if (!preg_match('/^\d+\z/', $d) || (int) $d <= 0) { continue; }
                    $n = (int) $d;
                    if (isset($seen[$n])) { $dup = true; }
                    $seen[$n] = true;
                    $t = $h['tan'] ?? null;
                    if (is_string($t)) { $t = trim($t); }
                    if ($t === '取消' || $t === '除外') { $tmp[] = $n; }
                }
                if (!$dup) { $cancel = $tmp; }
            }
            $frozen = json_decode((string) ($snap->frozen_nums ?? 'null'), true);
            $frozen = is_array($frozen) ? array_map('intval', $frozen) : [];
            $cancel = array_values(array_intersect(array_unique($cancel), $frozen));

            // ── 発走時刻の前倒し ───────────────────────────────────────
            $closeUs = null;
            $st = trim((string) ($race->start_time ?? ''));
            if (preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $st)) {
                $st = BaganrikiTime::normalizeRaceStartClock($st);
                $anchor   = BaganrikiTime::us(substr((string) $race->date, 0, 10) . 'T' . $st . '+09:00');
                $newClose = ($anchor === null) ? null : BaganrikiTime::salesCloseFromPost($anchor);
                $curClose = BaganrikiWorker::dbUs($snap->sales_close_at ?? null);
                if ($newClose !== null && $curClose !== null && $newClose < $curClose) {
                    $closeUs = $newClose;
                }
            }

            // ── いまの実状態と比べて、新しいものだけ渡します ─────────────
            $live = \DB::table('baganriki_live_states')->where('snapshot_id', (int) $snap->id)->first();
            if (BaganrikiWorker::isExpectedSnapshot($snap)) {
                $saved = BaganrikiWorker::verifiedLiveRoster($snap, $live, true, $issue);
                if ($saved === null) { return array_replace($out, BaganrikiWorker::liveStateFailure($issue)); }
                $curCancel = $saved['withdrawn'];
            } else {
                $curCancel = ($live === null) ? []
                    : array_map('intval', (array) json_decode((string) ($live->cancellations ?? '[]'), true));
            }
            $newCancel = array_values(array_diff($cancel, $curCancel));
            $curLive = ($live === null) ? null : BaganrikiWorker::dbUs($live->live_close ?? null);
            if ($closeUs !== null && $curLive !== null && $closeUs >= $curLive) { $closeUs = null; }
            if ($newCancel === [] && $closeUs === null) { $out['code'] = 'NO_CHANGE'; return $out; }

            $change = ['cancellations' => $newCancel, 'live_close_us' => $closeUs];
            $out['ran'] = true;
            $out['cancellations'] = $newCancel;
            $out['live_close_us'] = $closeUs;
            //   同期の経路（SYNC）で動いているレースは Job 側が触らないので、その場で入れます。
            // E3・E5版の新しい正式取消を、後続の送信・公開が待ち行列より先に読めるようにする。
            $applyNow = BaganrikiWorker::isExpectedSnapshot($snap) && $newCancel !== [];
            if (!$applyNow && BaganrikiWorker::jobs() && class_exists(\App\Jobs\PredictionUpdateRaceLiveJob::class)
                && AiController::processingPathOf((int) $snap->id) !== 'SYNC') {
                \App\Jobs\PredictionUpdateRaceLiveJob::dispatch((int) $snap->id, $change);
                $out['code'] = 'JOB_DISPATCHED';
            } else {
                $r = app(AiController::class)->applyLiveChange((int) $snap->id, $change);
                $out['code'] = (string) ($r['code'] ?? '');
            }
            \Log::info('[PREDICTION-LIVE] 取得から実状態へ入れました', [
                'race_id' => $rid, 'code' => $out['code'],
                'cancellations' => $newCancel,
                'live_close' => ($closeUs === null) ? null : BaganrikiTime::toJst($closeUs),
            ]);
            return $out;
        } catch (\Throwable $e) {
            // @ANCHOR-20261005-AUX-NEVER-BREAKS  オッズ取得は止めません
            \Log::error('[PREDICTION-LIVE] 取得から実状態へ入れられません',
                ['message' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
            $out['code'] = 'EXCEPTION';
            return $out;
        }
    }

    /**
     * @ANCHOR-20261020-PREDICTION-WIRE  AIを1回だけ呼びます（roleで送り先が違います）
     *
     *   1st … Anthropic（AnthropicService）
     *   2nd … DeepSeek（HTTP）
     *   自動再試行は0です（契約10章「SDKの自動再試行は0」）。
     *
     * @return array ['ok','raw','complete','code','reason']
     */
    // ══════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261023-NARU-FAKEAI  試験用のレースだけに効く偽AI
    // ══════════════════════════════════════════════════════════════════════
    //   なるの依頼（8-3・8-4）は「実 worker で、対象外0送信・アクセスなしの定期起動・
    //   既存 snapshot の期限起動・受領後停止からの回復・遅い1st・遅い訂正・近接2レース」
    //   を通した記録です。外部AIを呼ばずに worker と期限の動きを確かめるため、
    //   試験用のレース（場コード 90〜99。JRA の場コードは 01〜10 なので実在しない）
    //   のときだけ、保存済みの応答を返します。
    //
    //   レース番号で振る舞いを決めます（試験用の場コードのときだけ）
    //     2R … 2nd は返ってこない（待ち時間いっぱい待って CONNECTION）
    //     3R … 1st の初回は120秒、訂正は40秒かかる（遅い1st。期限内に受け取れる長さ）
    //     5R … 1st の初回は200秒（必ず待ち時間を超えるので、こちらの上限で切れて結果不明になる）
    //     それ以外 … 3秒で返る
    //   応答の中の race_id と snapshot_hash は、送った本文の値に置き換えます。
    public static function isTestRaceId(string $raceId): bool
    {
        $p = explode('-', $raceId);
        return count($p) === 5 && preg_match('/^9[0-9]$/', $p[2]) === 1;
    }
}
