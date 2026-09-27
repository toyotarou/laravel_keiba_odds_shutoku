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
 *   2. t_horse_odds_finder_summary の 6分前オッズ・発走直前オッズを取得（20260924変更）
 *   3. 6分前オッズ順の人気順位別に集計（補正係数・誤差・支持確率）
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
            // 【20260924 変更】集計元を t_horse_odds_finder_odds → t_horse_odds_finder_summary に変更
            //   旧: t_horse_odds_finder_odds（毎週土曜 5:50 に TRUNCATE）と JOIN していたため、
            //       サンプルが「直近1週間分（1人気あたり70件前後）」しかなく、週ごとに係数がぶれていた。
            //   新: t_horse_odds_finder_summary（TRUNCATEされず蓄積される）の
            //       odds_tan_before_6（6分前）と odds_tan_before_0（発走直前＝確定相当）を使う。
            //       ※ race_result_history.tan も同じ発走直前オッズ（SummaryKeibaInfo が投入）なので値の定義は同じ。
            //
            // 【20260924 変更】人気順位を「確定オッズ順」→「6分前オッズ順」に変更
            //   AiController は 6分前オッズ昇順の人気順位（$h['popularity']）で本テーブルを引くため、
            //   集計側も 6分前の人気順位で揃える（確定順位で集計すると、直前に売れなかった馬ほど
            //   下位人気に移るため、下位人気の補正係数が過大になる）。
            //
            //   popularity_rank = レース内の odds_tan_before_6 昇順ランク（RANK()）
            //   補正係数 = AVG(発走直前オッズ ÷ 6分前オッズ)
            // ─────────────────────────────────────────────────────────────
            $this->info('集計クエリ実行中...');

            $rows = DB::select("
                WITH base AS (
                    SELECT
                        s.date,
                        CAST(s.odds_tan_before_6 AS DECIMAL(10,2)) AS odds_6,
                        CAST(s.odds_tan_before_0 AS DECIMAL(10,2)) AS odds_final,
                        RANK() OVER (
                            PARTITION BY s.date, s.kaisuu, s.basho, s.day, s.race
                            ORDER BY CAST(s.odds_tan_before_6 AS DECIMAL(10,2)) ASC
                        ) AS popularity_rank
                    FROM t_horse_odds_finder_summary s
                    WHERE s.odds_tan_before_6 REGEXP '^[0-9]+(\\\\.[0-9]+)?$'
                      AND CAST(s.odds_tan_before_6 AS DECIMAL(10,2)) > 0
                      AND s.odds_tan_before_0 REGEXP '^[0-9]+(\\\\.[0-9]+)?$'
                      AND CAST(s.odds_tan_before_0 AS DECIMAL(10,2)) > 0
                )
                SELECT
                    popularity_rank,
                    COUNT(*)                                 AS sample_count,
                    ROUND(AVG(odds_6), 2)                    AS avg_odds_6min,
                    ROUND(AVG(odds_final), 2)                AS avg_odds_final,
                    ROUND(AVG(odds_final / odds_6), 4)       AS avg_correction_ratio,
                    ROUND(STD(odds_final / odds_6), 4)       AS std_correction_ratio,
                    ROUND(AVG(1.0 / odds_final), 6)          AS avg_win_probability,
                    MIN(date)                                AS start_date,
                    MAX(date)                                AS end_date
                FROM base
                GROUP BY popularity_rank
                ORDER BY popularity_rank
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
            $elapsed = (int) $startedAt->diffInSeconds(now()); // Laravel11(Carbon3)では now()->diffInSeconds(過去) が負数になるため向きを修正
            $news    = "正常終了\nUPSERT: {$upsertCount}件\n経過: {$elapsed}秒";

            $this->info('=== 完了 ' . now()->format('Y-m-d H:i:s') . " ({$elapsed}秒) ===");
//             Log::info('SummaryComputeOddsCorrection 完了', ['upsert_count' => $upsertCount]);

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
