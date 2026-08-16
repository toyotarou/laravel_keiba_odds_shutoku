<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SummaryComputeOddsCorrection
 *
 * 【概要】
 *   過去レースの「6分前単勝オッズ」と「確定単勝オッズ（レース結果）」を突き合わせ、
 *   人気順位別の補正係数（avg_correction_ratio）・補正誤差（std_correction_ratio）・
 *   支持確率（avg_win_probability）を集計して
 *   t_horse_odds_finder_compute_odds_correction に保存する。
 *
 * 【AIコスト】
 *   なし（純粋なSQL集計のみ）
 *
 * 【処理フロー】
 *   1. 多重起動防止（ロックファイル）
 *   2. t_horse_odds_finder_race_result_history × t_horse_odds_finder_odds (6分前) をJOIN
 *   3. 人気順位別に集計（補正係数・誤差・支持確率）
 *   4. t_horse_odds_finder_compute_odds_correction へ UPSERT
 *   5. 完了通知（WebPush）
 *
 * 【使い方】
 *   php artisan keiba:SummaryComputeOddsCorrection
 *
 * 【補正係数の読み方】
 *   avg_correction_ratio > 1.0 → 確定オッズ > 6分前オッズ（直前に買われにくい）
 *   avg_correction_ratio < 1.0 → 確定オッズ < 6分前オッズ（直前にさらに人気集中）
 *   推定確定オッズ = 6分前オッズ × avg_correction_ratio
 */
class SummaryComputeOddsCorrection extends Command
{
    protected $signature   = 'keiba:SummaryComputeOddsCorrection';
    protected $description = '6分前オッズ→確定オッズの人気順位別補正係数を集計する（AIコストなし）';

    public function handle(): void
    {
        // ─── ロックファイルで多重起動防止 ────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_summaryComputeOddsCorrection.lock';
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
        }
        file_put_contents($lockFile, getmypid());

        $startedAt = now();
        $this->info('=== SummaryComputeOddsCorrection 開始 ' . $startedAt->format('Y-m-d H:i:s') . ' ===');

        try {
            // ─── 集計クエリ ────────────────────────────────────────────────
            // t_horse_odds_finder_race_result_history（確定オッズ・人気順位）と
            // t_horse_odds_finder_odds（6分前 minutes_before_start=6）を突き合わせ、
            // 人気順位別に補正係数を集計する。
            //
            // JOIN条件:
            //   history.basho_code  = odds.basho   （どちらも場コード "01","04" 等）
            //   history.date        = odds.date     （型が違うためCAST）
            //   history.kaisuu      = odds.kaisuu   （同上）
            //   history.day         = odds.day      （同上）
            //   history.race        = odds.race
            //   history.num         = odds.num
            //   odds.minutes_before_start = 6
            // ─────────────────────────────────────────────────────────────
            $this->info('集計クエリ実行中...');

            $rows = DB::select("
                SELECT
                    h.popularity_rank,
                    COUNT(*)                                                                  AS sample_count,
                    ROUND(AVG(CAST(o.odds AS DECIMAL(10,2))), 2)                             AS avg_odds_6min,
                    ROUND(AVG(CAST(h.tan  AS DECIMAL(10,2))), 2)                             AS avg_odds_final,
                    ROUND(AVG(CAST(h.tan  AS DECIMAL(10,2)) / CAST(o.odds AS DECIMAL(10,2))), 4) AS avg_correction_ratio,
                    ROUND(STD(CAST(h.tan  AS DECIMAL(10,2)) / CAST(o.odds AS DECIMAL(10,2))), 4) AS std_correction_ratio,
                    ROUND(AVG(1.0 / CAST(h.tan AS DECIMAL(10,2))), 6)                       AS avg_win_probability,
                    MIN(h.date)                                                               AS start_date,
                    MAX(h.date)                                                               AS end_date
                FROM t_horse_odds_finder_race_result_history h
                JOIN t_horse_odds_finder_odds o
                    ON  o.date    = CAST(h.date AS CHAR)
                    AND CAST(o.kaisuu AS UNSIGNED) = h.kaisuu
                    AND o.basho   = h.basho_code
                    AND CAST(o.day AS UNSIGNED) = h.day
                    AND o.race    = h.race
                    AND o.num     = h.num
                    AND o.minutes_before_start = 6
                WHERE h.tan  IS NOT NULL
                  AND h.tan  != ''
                  AND CAST(h.tan  AS DECIMAL(10,2)) > 0
                  AND o.odds IS NOT NULL
                  AND o.odds != ''
                  AND CAST(o.odds AS DECIMAL(10,2)) > 0
                  AND h.popularity_rank IS NOT NULL
                GROUP BY h.popularity_rank
                ORDER BY h.popularity_rank
            ");

            if (empty($rows)) {
                $this->warn('集計対象データが見つかりませんでした。6分前オッズと確定オッズが揃っているレースがない可能性があります。');
                return;
            }

            $this->info('集計完了: ' . count($rows) . '人気分のデータを取得');

            // ─── UPSERT ───────────────────────────────────────────────────
            $now        = now()->format('Y-m-d H:i:s');
            $upsertCount = 0;

            foreach ($rows as $row) {
                DB::table('t_horse_odds_finder_compute_odds_correction')
                    ->upsert(
                        [
                            'popularity_rank'      => $row->popularity_rank,
                            'sample_count'         => $row->sample_count,
                            'avg_odds_6min'        => $row->avg_odds_6min,
                            'avg_odds_final'       => $row->avg_odds_final,
                            'avg_correction_ratio' => $row->avg_correction_ratio,
                            'std_correction_ratio' => $row->std_correction_ratio,
                            'avg_win_probability'  => $row->avg_win_probability,
                            'start_date'           => $row->start_date,
                            'end_date'             => $row->end_date,
                            'computed_at'          => $now,
                        ],
                        ['popularity_rank'], // UNIQUE KEY
                        [
                            'sample_count',
                            'avg_odds_6min',
                            'avg_odds_final',
                            'avg_correction_ratio',
                            'std_correction_ratio',
                            'avg_win_probability',
                            'start_date',
                            'end_date',
                            'computed_at',
                        ]
                    );

                $this->line(sprintf(
                    '  %2d人気: サンプル%4d件  6分前平均%.2f倍→確定平均%.2f倍  補正係数%.4f±%.4f  支持確率%.4f',
                    $row->popularity_rank,
                    $row->sample_count,
                    $row->avg_odds_6min,
                    $row->avg_odds_final,
                    $row->avg_correction_ratio,
                    $row->std_correction_ratio,
                    $row->avg_win_probability
                ));

                $upsertCount++;
            }

            // ─── 完了サマリー ─────────────────────────────────────────────
            $elapsed = now()->diffInSeconds($startedAt);
            $news    = "正常終了\nUPSERT: {$upsertCount}件\n経過: {$elapsed}秒";

            $this->info('=== 完了 ' . now()->format('Y-m-d H:i:s') . " ({$elapsed}秒) ===");
            Log::info('SummaryComputeOddsCorrection 完了', ['upsert_count' => $upsertCount]);

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryComputeOddsCorrection::handle\n{$news}");

        } catch (\Throwable $e) {
            $msg = 'エラー: ' . $e->getMessage();
            $this->error($msg);
            Log::error('SummaryComputeOddsCorrection 失敗', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryComputeOddsCorrection::handle\n{$msg}");
        } finally {
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        }
    }
}
