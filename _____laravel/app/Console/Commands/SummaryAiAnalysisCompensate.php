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
 *   本来 Flutter 側で計算する補足ヒント用パラメータ。
 *   サーバー側で再現する必要はなく、空文字を渡せば該当セクションが
 *   プロンプトからスキップされるだけで動作に支障はない。
 *
 * 【使い方】
 *   php artisan keiba:ai-analysis-compensate
 */
class SummaryAiAnalysisCompensate extends Command
{
    protected $signature   = 'keiba:ai-analysis-compensate';
    protected $description = '未実行のAI予想を補完実行する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（日付別ロックファイル）
        //   同日に複数のプロセスが同時実行されるのを防ぐ。
        // ─────────────────────────────────────────────────────────────────
        $date     = date('Y-m-d');
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
            $this->info('');
            $this->info('========== keiba:ai-analysis-compensate 開始 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('対象日付 : ' . $date);
            $this->info('');

            // ═════════════════════════════════════════════════════════════
            // 【ガード A】当日レースが存在しなければ早期リターン
            // ═════════════════════════════════════════════════════════════
            $races = DB::table('t_horse_odds_finder_races')
                ->where('date', $date)
                ->orderBy('kaisuu')
                ->orderBy('basho')
                ->orderBy('day')
                ->orderBy('race')
                ->get();

            if ($races->isEmpty()) {
                $this->info('[ガードA] 当日のレースが存在しません。');
                $status = 'SKIP';
                return;
            }

            $this->info("対象レース数 : {$races->count()} 件");
            $this->info('');

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
                        $this->info("[スキップ] {$label} : オッズ未取得 (base={$hasBase}, 6min={$has6min})");
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
                            'gapHorseNums'         => '',   // Flutter側計算値なし → 補足ヒントセクションをスキップ
                            'upsetPickupHorseNums' => '',   // 同上
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

                // ── 2nd AI チェック ───────────────────────────────────────
                $exists2nd = DB::table('t_horse_odds_finder_ai_analysis2')
                    ->where('date',       $raceRow->date)
                    ->where('kaisuu',     $raceRow->kaisuu)
                    ->where('basho_code', $raceRow->basho)
                    ->where('day',        $raceRow->day)
                    ->where('race',       $raceRow->race)
                    ->exists();

                if (!$exists2nd) {
                    $this->info("[2nd AI] {$label} : 実行開始");

                    try {
                        $req2nd = new Request();
                        $req2nd->query->add([
                            'date'   => $raceRow->date,
                            'kaisuu' => $raceRow->kaisuu,
                            'basho'  => $raceRow->basho,
                            'day'    => $raceRow->day,
                            'race'   => (string) $raceRow->race,
                        ]);

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
                $this->info('');
                $this->info('[ガードB] 補完対象なし（全レースのAI予想は実行済み）。');
                $status = 'SKIP';
                return;
            }

            $status = '正常終了';

        } finally {
            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】完了サマリー・WebPush 通知（finally で必ず実行）
            // ─────────────────────────────────────────────────────────────
            $totalElapsed = round(microtime(true) - $now, 1);

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
