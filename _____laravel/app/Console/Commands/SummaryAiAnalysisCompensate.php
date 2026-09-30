<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\AiController;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SummaryAiAnalysisCompensate
 *
 * 【概要】
 *   当日のレースのうち、AI予想が未実行のものを補完実行する。
 *   1st AI (t_horse_odds_finder_ai_analysis) と
 *   2nd AI (t_horse_odds_finder_ai_analysis2) をそれぞれ独立して確認・補完する。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（日付別ロックファイル）
 *   【ブロック 2】初期化・開始バナー
 *   ─── ガード A（当日レースなし → 早期リターン）─────────────────
 *   【ブロック 3】全レースループ
 *     - 1st AI: ai_analysis に未登録 かつ オッズ（base/6min）が揃っていれば補完
 *     - 2nd AI: ai_analysis2 に未登録であれば補完（オッズ確認不要）
 *   ─── ガード B（補完ゼロ → SKIP 扱い）──────────────────────────
 *   【ブロック 4】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【テーブル対応】
 *   t_horse_odds_finder_races.basho     = AI テーブルの basho_code
 *   t_horse_odds_finder_odds.minutes_before_start:
 *     999 = ODDS_DB_FIRST（基準オッズ）
 *       6 = 6分前オッズ（1st AI 実行の必要条件）
 *
 * 【gapHorseNums / upsetPickupHorseNums について】
 *   本来 Flutter 側で計算する注目馬番①②（仕様「注目馬番（3種類）」）。
 *   【20260924】空文字を渡すと AiController 側でアプリと同じ計算をして埋める
 *   （以前はプロンプトから抜け落ちていた）。
 *
 * 【使い方】
 *   php artisan keiba:ai-analysis-compensate                  … 当日を処理
 *   php artisan keiba:ai-analysis-compensate --date=2026-09-26 … 指定日を処理
 *   php artisan keiba:ai-analysis-compensate --quiet-skip
 *       … 補完対象が無かった回は開始バナー・完了サマリー・WebPush通知を出さない。
 *         発走6分前オッズが入った直後にAI予想を作っておく「先回り実行」用。
 *         毎分動かしても、空振りの回はログにも通知にも残らない。
 *         ※このオプションを付けないと、毎分の実行で1日480通の通知が飛ぶ。
 *   php artisan keiba:ai-analysis-compensate --date=2026-09-26 --remerge
 *       … 保存済みのAI回答を使い、マージ＋フィルターだけやり直す（AI呼び出し0回）
 *         フィルターの仕様を変更したあと、過去レースへ新ルールを適用する用途。
 *         テーブルを削除する必要はない。
 *
 * 【--date について（20260926 追加）】
 *   フィルターの閾値を変更したあと、過去日のAI予想をやり直すために追加した。
 *   省略時は従来どおり当日（date('Y-m-d')）を対象にする。
 *   ロックファイルも対象日付ごとに分かれるため、
 *   別日を指定すれば当日ぶんの実行と同時に走らせても衝突しない。
 *
 *   やり直し手順（閾値だけ変えた場合）:
 *     1) 修正版を配置
 *     2) DELETE FROM t_horse_odds_finder_ai_analysis2 WHERE date='YYYY-MM-DD';
 *     3) php artisan keiba:ai-analysis-compensate --date=YYYY-MM-DD
 *     4) t_horse_odds_finder_ai_merge_result の頭数を確認
 */
class SummaryAiAnalysisCompensate extends Command
{
    protected $signature   = 'keiba:ai-analysis-compensate'
                           . ' {--date= : 対象日付 YYYY-MM-DD（省略時は当日）}'
                           . ' {--remerge : 保存済みのAI回答でマージ・フィルターだけやり直す}'
                           . ' {--quiet-skip : 補完対象が無かった回は通知もログ要約も出さない（毎分の先回り実行用）}';
    protected $description = '未実行のAI予想を補完実行する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（日付別ロックファイル）
        //   同日に複数のプロセスが同時実行されるのを防ぐ。
        // ─────────────────────────────────────────────────────────────────
        // ── 20260926: --date= で対象日を指定できるようにした ──────────────
        //   省略時は従来どおり当日。過去日のやり直しに使う。
        //   不正な日付でDBを引かないよう、書式と実在日をここで弾く。
        $date = (string) ($this->option('date') ?: date('Y-m-d'));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dm)
            || !checkdate((int) $dm[2], (int) $dm[3], (int) $dm[1])) {
            $this->error("--date の書式が不正です（YYYY-MM-DD で指定してください）: {$date}");
            return;
        }

        // ── 20260926 追加: 再マージモード ─────────────────────────────────
        //   フィルターの仕様を変えたあと、過去レースへ新ルールを適用するためのモード。
        //   保存済みの1st/2nd AI回答をそのまま使い、DeepSeekを呼ばずに
        //   マージ＋フィルターだけやり直して ai_merge_result を上書きする。
        //   外部AI呼び出しは0回。入力が同じなので結果は決定的。
        $remerge = (bool) $this->option('remerge');

        // ── 20260928 追加: 先回り実行（毎分cron）用の静音モード ─────────────
        //   補完対象が1件も無かった回は、開始バナー・完了サマリー・WebPush通知を
        //   すべて省く。これを付けずに毎分動かすと、空振りの通知でdeveloper宛の
        //   WebPushが1日480通になり、ログも同じだけ膨らむ。
        //   補完を実際に行った回は、従来どおり全部出力する。
        $quietSkip = (bool) $this->option('quiet-skip');

        $lockFile = sys_get_temp_dir() . '/keiba_aiAnalysisCompensate_' . str_replace('-', '', $date) . '.lock';

        if (file_exists($lockFile)) {
            $pid       = (int) file_get_contents($lockFile);
            $isRunning = $pid > 0 && (
                (function_exists('posix_kill') && posix_kill($pid, 0))
                || file_exists("/proc/{$pid}")
            );

            if ($isRunning) {
                $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
                return;
            }
            $this->warn("ロックファイルの残骸を削除して続行します (PID: {$pid})");
            unlink($lockFile);
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】初期化・開始バナー
        // ─────────────────────────────────────────────────────────────────
        $now            = microtime(true);
        $compensated1st = 0;
        $compensated2nd = 0;
        $skipped        = 0;
        $status         = '不明な理由で終了';

        try {
            if (!$quietSkip) {
                $this->info('');
                $this->info('========== keiba:ai-analysis-compensate 開始 ' . date('Y-m-d H:i:s') . ' ==========');
                $this->info('対象日付 : ' . $date);
                if ($remerge) {
                    $this->info('モード   : 再マージ（DeepSeekを呼ばず、保存済み回答でフィルターを再適用）');
                }
                $this->info('');
            }

            // ═════════════════════════════════════════════════════════════
            // 【ガード A】対象日のレースが存在しなければ早期リターン
            // ═════════════════════════════════════════════════════════════
            $races = DB::table('t_horse_odds_finder_races')
                ->where('date', $date)
                ->orderBy('kaisuu')
                ->orderBy('basho')
                ->orderBy('day')
                ->orderBy('race')
                ->get();

            if ($races->isEmpty()) {
                // 静音モードでは何も出さない（開催のない日に毎分1行ずつ積み上がるのを防ぐ）
                if (!$quietSkip) {
                    $this->info("[ガードA] {$date} のレースが存在しません。");
                }
                $status = 'SKIP';
                return;
            }

            if (!$quietSkip) {
                $this->info("対象レース数 : {$races->count()} 件");
                $this->info('');
            }

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 3】全レースループ
            //   1st AI → オッズ確認 → 補完
            //   2nd AI → 補完（オッズ確認不要）
            // ─────────────────────────────────────────────────────────────
            /** @var AiController $controller */
            $controller = app(AiController::class);

            foreach ($races as $raceRow) {
                $label = "{$raceRow->kaisuu}回{$raceRow->basho_name}{$raceRow->day}日 {$raceRow->race}R";

                // ── 1st AI チェック ───────────────────────────────────────
                // races.basho = ai_analysis.basho_code
                $exists1st = DB::table('t_horse_odds_finder_ai_analysis')
                    ->where('date',       $raceRow->date)
                    ->where('kaisuu',     $raceRow->kaisuu)
                    ->where('basho_code', $raceRow->basho)
                    ->where('day',        $raceRow->day)
                    ->where('race',       $raceRow->race)
                    ->exists();

                if (!$exists1st) {
                    // オッズが揃っているか確認（base=999 と 6分前 の両方が必要）
                    $hasBase = DB::table('t_horse_odds_finder_odds')
                        ->where('date',                $raceRow->date)
                        ->where('kaisuu',              $raceRow->kaisuu)
                        ->where('basho',               $raceRow->basho)
                        ->where('day',                 $raceRow->day)
                        ->where('race',                $raceRow->race)
                        ->where('minutes_before_start', 999)
                        ->exists();

                    $has6min = DB::table('t_horse_odds_finder_odds')
                        ->where('date',                $raceRow->date)
                        ->where('kaisuu',              $raceRow->kaisuu)
                        ->where('basho',               $raceRow->basho)
                        ->where('day',                 $raceRow->day)
                        ->where('race',                $raceRow->race)
                        ->where('minutes_before_start', 6)
                        ->exists();

                    if (!$hasBase || !$has6min) {
                        // 静音モードでは出さない。発走6分前まではこれが全レース分出るため、
                        // 毎分実行では1日あたり数千行になってログが読めなくなる。
                        if (!$quietSkip) {
                            $this->info("[スキップ] {$label} : オッズ未取得 (base={$hasBase}, 6min={$has6min})");
                        }
                        $skipped++;
                        continue;
                    }

                    $this->info("[1st AI] {$label} : 実行開始");

                    try {
                        $req = new Request();
                        $req->query->add([
                            'date'                 => $raceRow->date,
                            'kaisuu'               => $raceRow->kaisuu,
                            'basho'                => $raceRow->basho,
                            'day'                  => $raceRow->day,
                            'race'                 => (string) $raceRow->race,
                            'gapHorseNums'         => '',   // 空 → AiController がアプリと同じ計算で①を埋める（20260924）
                            'upsetPickupHorseNums' => '',   // 空 → 同上で②を埋める
                        ]);

                        $response1st = $controller->getHorseOddsFinderAiAnalysis($req);
                        $status1st   = $response1st->getStatusCode();

                        if ($status1st === 200) {
                            $this->info("[1st AI] {$label} : 成功");
                            $compensated1st++;
                        } else {
                            $this->error("[1st AI] {$label} : 失敗 status={$status1st} body=" . $response1st->getContent());
                        }
                    } catch (\Throwable $e) {
                        $this->error("[1st AI] {$label} : 例外発生 " . $e->getMessage());
                    }
                }

                // ── 1st AI 未登録なら 2nd AI は呼ばない（20260924 追加） ─────────
                //   最終確定版：1st AI が失敗したレースは通常予測を公開しない。
                //   1st AI 結果がないまま DeepSeek を呼んでも公開されず、API 呼び出しが無駄になるためスキップする。
                $has1stNow = DB::table('t_horse_odds_finder_ai_analysis')
                    ->where('date',       $raceRow->date)
                    ->where('kaisuu',     $raceRow->kaisuu)
                    ->where('basho_code', $raceRow->basho)
                    ->where('day',        $raceRow->day)
                    ->where('race',       $raceRow->race)
                    ->exists();
                if (!$has1stNow) {
                    $this->warn("[2nd AI] {$label} : 1st AI 結果がないためスキップ");
                    $skipped++;
                    continue;
                }

                // ── 2nd AI チェック ───────────────────────────────────────
                $exists2nd = DB::table('t_horse_odds_finder_ai_analysis2')
                    ->where('date',       $raceRow->date)
                    ->where('kaisuu',     $raceRow->kaisuu)
                    ->where('basho_code', $raceRow->basho)
                    ->where('day',        $raceRow->day)
                    ->where('race',       $raceRow->race)
                    ->exists();

                // 再マージモードでは、2nd AI の結果が既にあっても処理する。
                // （保存済み回答を使ってマージ・フィルターだけやり直すため）
                if (!$exists2nd || $remerge) {
                    $this->info($remerge && $exists2nd
                        ? "[再マージ] {$label} : 開始"
                        : "[2nd AI] {$label} : 実行開始");

                    try {
                        $req2ndParams = [
                            'date'   => $raceRow->date,
                            'kaisuu' => $raceRow->kaisuu,
                            'basho'  => $raceRow->basho,
                            'day'    => $raceRow->day,
                            'race'   => (string) $raceRow->race,
                        ];
                        if ($remerge) {
                            $req2ndParams['remerge'] = '1';
                        }
                        $req2nd = new Request();
                        $req2nd->query->add($req2ndParams);

                        $response2nd = $controller->getHorseOddsFinderSecondAiOpinion($req2nd);
                        $status2nd   = $response2nd->getStatusCode();

                        if ($status2nd === 200) {
                            $this->info("[2nd AI] {$label} : 成功");
                            $compensated2nd++;
                        } else {
                            $this->error("[2nd AI] {$label} : 失敗 status={$status2nd} body=" . $response2nd->getContent());
                        }
                    } catch (\Throwable $e) {
                        $this->error("[2nd AI] {$label} : 例外発生 " . $e->getMessage());
                    }
                }
            }

            // ═════════════════════════════════════════════════════════════
            // 【ガード B】補完ゼロ → 空振り（SKIP 扱い）
            // ═════════════════════════════════════════════════════════════
            if ($compensated1st === 0 && $compensated2nd === 0) {
                // 静音モードでは何も出さない（補完対象が無い回が1日の大半を占めるため）
                if (!$quietSkip) {
                    $this->info('');
                    $this->info('[ガードB] 補完対象なし（全レースのAI予想は実行済み）。');
                }
                $status = 'SKIP';
                return;
            }

            $status = '正常終了';

        } finally {
            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】完了サマリー・WebPush 通知（finally で必ず実行）
            // ─────────────────────────────────────────────────────────────
            $totalElapsed = round(microtime(true) - $now, 1);

            // ── 20260928: 静音モード（--quiet-skip）で空振りだった回は何も出さない ──
            //   「補完0件」＝ SKIP。毎分の先回り実行では、これが1日の大半を占める。
            //   ※ finally の中で return すると、try で発生した例外を握り潰してしまう。
            //     必ず if で囲うこと（return を書いてはいけない）。
            $quietSilent = ($quietSkip && $compensated1st === 0 && $compensated2nd === 0);

            if (!$quietSilent) {
                $this->info('');
                $this->info("終了理由       : {$status}");
                $this->info("対象日付       : {$date}");
                $this->info("1st AI 補完    : {$compensated1st} 件");
                $this->info("2nd AI 補完    : {$compensated2nd} 件");
                $this->info("オッズ未取得   : {$skipped} 件（スキップ）");
                $this->info("処理時間       : {$totalElapsed} 秒");
                $this->info('');
                $this->info('========== keiba:ai-analysis-compensate 終了 ' . date('Y-m-d H:i:s') . ' ==========');
                $this->info('');

                $newsValue   = [];
                $newsValue[] = $status;
                if ($status !== 'SKIP') {
                    $newsValue[] = "対象日:{$date}、";
                    $newsValue[] = "1st AI:{$compensated1st}件、";
                    $newsValue[] = "2nd AI:{$compensated2nd}件、";
                    $newsValue[] = "スキップ:{$skipped}件";
                }
                $news = implode('', $newsValue);

                (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryAiAnalysisCompensate::handle\n{$news}");
            }
        }
    }
}
