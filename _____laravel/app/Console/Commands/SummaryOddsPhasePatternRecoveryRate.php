<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SummaryOddsPhasePatternRecoveryRate
 *
 * 【概要】
 *   オッズの動きを「前半（計測開始→12分前）」と「後半（12分前→6分前）」に分けて
 *   各フェーズの方向（下落／横ばい／上昇）を判定し、
 *   9パターン × 人気帯4区分 の組み合わせで単勝回収率を集計して
 *   t_horse_odds_finder_phase_pattern_recovery に保存する。
 *
 * 【フェーズ方向の定義】
 *   下落: 変化率 <= -5%（資金流入・売れている）
 *   横ばい: -5% < 変化率 < 5%（動きなし）
 *   上昇: 変化率 >= 5%（資金流出・売られている）
 *
 * 【注目パターン】
 *   前半下落・後半上昇（売り戻し）: 早い段階で売れたが直前に手放された
 *   前半上昇・後半下落（直前急落）: 最初は無視されたが直前に急注目
 *
 * 【人気帯の定義】
 *   1〜3人気 / 4〜6人気 / 7〜9人気 / 10人気以上
 *
 * 【AIコスト】
 *   なし（純粋なSQL集計のみ）
 *
 * 【使い方】
 *   php artisan keiba:SummaryOddsPhasePatternRecoveryRate
 *
 * 【cron 登録例】
 *   30 21 * * * flock -n /tmp/keiba_SummaryOddsPhasePatternRecoveryRate.lock \
 *     php /var/www/horse_odds_finder/artisan keiba:SummaryOddsPhasePatternRecoveryRate \
 *     >> /var/www/horse_odds_finder/storage/logs/SummaryOddsPhasePatternRecoveryRate.log 2>&1
 */
class SummaryOddsPhasePatternRecoveryRate extends Command
{
    protected $signature   = 'keiba:SummaryOddsPhasePatternRecoveryRate';
    protected $description = '前半・後半フェーズ方向パターン×人気帯別の単勝回収率を集計する（AIコストなし）';

    public function handle(): void
    {
        // ─── ロックファイルで多重起動防止 ────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_SummaryOddsPhasePatternRecoveryRate.lock';
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
        $this->info('=== SummaryOddsPhasePatternRecoveryRate 開始 ' . $startedAt->format('Y-m-d H:i:s') . ' ===');

        try {
            // ─── 集計クエリ ────────────────────────────────────────────────
            //
            // ① t_horse_odds_finder_summary から計測開始・12分前・6分前・確定オッズを取得
            // ② 前半変化率 = (odds_12 - odds_start) / odds_start * 100
            //    後半変化率 = (odds_6  - odds_12)   / odds_12   * 100
            // ③ 各フェーズを 下落(<=-5%) / 横ばい(-5%〜5%) / 上昇(>=5%) に分類
            // ④ "前半XX・後半YY" の9パターン × 人気帯4区分 で集計
            //
            $this->info('集計クエリ実行中...');

            $rows = DB::select("
                WITH base AS (
                    SELECT
                        s.date,
                        s.kaisuu,
                        s.basho,
                        s.day,
                        s.race,
                        s.num,
                        s.result,
                        CAST(s.odds_tan_before_24 AS DECIMAL(10,2)) AS odds_start,
                        CAST(s.odds_tan_before_12 AS DECIMAL(10,2)) AS odds_12,
                        CAST(s.odds_tan_before_6  AS DECIMAL(10,2)) AS odds_6,
                        CAST(s.odds_tan_before_0  AS DECIMAL(10,2)) AS odds_final,
                        RANK() OVER (
                            PARTITION BY s.date, s.kaisuu, s.basho, s.day, s.race
                            ORDER BY CAST(s.odds_tan_before_6 AS DECIMAL(10,2)) ASC
                        ) AS popularity_rank
                    FROM t_horse_odds_finder_summary s
                    WHERE s.odds_tan_before_24 IS NOT NULL
                      AND s.odds_tan_before_24 != ''
                      AND CAST(s.odds_tan_before_24 AS DECIMAL(10,2)) > 0
                      AND s.odds_tan_before_12 IS NOT NULL
                      AND s.odds_tan_before_12 != ''
                      AND CAST(s.odds_tan_before_12 AS DECIMAL(10,2)) > 0
                      AND s.odds_tan_before_6  IS NOT NULL
                      AND s.odds_tan_before_6  != ''
                      AND CAST(s.odds_tan_before_6  AS DECIMAL(10,2)) > 0
                      AND s.odds_tan_before_0  IS NOT NULL
                      AND s.odds_tan_before_0  != ''
                      AND CAST(s.odds_tan_before_0  AS DECIMAL(10,2)) > 0
                      AND s.result IS NOT NULL
                ),
                with_rates AS (
                    SELECT
                        *,
                        ROUND((odds_12  - odds_start) / odds_start * 100, 1) AS half1_rate,
                        ROUND((odds_6   - odds_12)   / odds_12   * 100, 1) AS half2_rate
                    FROM base
                ),
                with_bands AS (
                    SELECT
                        *,
                        CASE
                            WHEN half1_rate <= -5 THEN '前半下落'
                            WHEN half1_rate <   5 THEN '前半横ばい'
                            ELSE '前半上昇'
                        END AS half1_label,
                        CASE
                            WHEN half2_rate <= -5 THEN '後半下落'
                            WHEN half2_rate <   5 THEN '後半横ばい'
                            ELSE '後半上昇'
                        END AS half2_label,
                        -- ソート用の数値
                        CASE
                            WHEN half1_rate <= -5 THEN 1
                            WHEN half1_rate <   5 THEN 2
                            ELSE 3
                        END AS half1_order,
                        CASE
                            WHEN half2_rate <= -5 THEN 1
                            WHEN half2_rate <   5 THEN 2
                            ELSE 3
                        END AS half2_order,
                        CASE
                            WHEN popularity_rank <= 3 THEN '1〜3人気'
                            WHEN popularity_rank <= 6 THEN '4〜6人気'
                            WHEN popularity_rank <= 9 THEN '7〜9人気'
                            ELSE '10人気以上'
                        END AS popularity_band,
                        CASE WHEN popularity_rank <= 3 THEN 1
                             WHEN popularity_rank <= 6 THEN 4
                             WHEN popularity_rank <= 9 THEN 7
                             ELSE 10 END AS popularity_min,
                        CASE WHEN popularity_rank <= 3 THEN 3
                             WHEN popularity_rank <= 6 THEN 6
                             WHEN popularity_rank <= 9 THEN 9
                             ELSE 18 END AS popularity_max
                    FROM with_rates
                )
                SELECT
                    CONCAT(half1_label, '・', half2_label)  AS phase_pattern,
                    half1_label,
                    half2_label,
                    half1_order,
                    half2_order,
                    popularity_band,
                    popularity_min,
                    popularity_max,
                    COUNT(*)                                                                          AS sample_count,
                    SUM(CASE WHEN result = 1 THEN 1 ELSE 0 END)                                      AS win_count,
                    ROUND(SUM(CASE WHEN result = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100, 2)           AS win_rate,
                    ROUND(SUM(CASE WHEN result = 1 THEN odds_final * 100 ELSE 0 END) / COUNT(*), 2)  AS recovery_rate,
                    MIN(date)                                                                         AS start_date,
                    MAX(date)                                                                         AS end_date
                FROM with_bands
                GROUP BY phase_pattern, half1_label, half2_label, half1_order, half2_order,
                         popularity_band, popularity_min, popularity_max
                ORDER BY half1_order ASC, half2_order ASC, popularity_min ASC
            ");

            if (empty($rows)) {
                $this->warn('集計対象データが見つかりませんでした。odds_tan_before_12 にデータがない可能性があります。');
                return;
            }

            $this->info('集計完了: ' . count($rows) . '行のデータを取得');

            // ─── UPSERT ───────────────────────────────────────────────────
            $now         = now()->format('Y-m-d H:i:s');
            $upsertCount = 0;

            foreach ($rows as $row) {
                DB::table('t_horse_odds_finder_phase_pattern_recovery')
                    ->upsert(
                        [
                            'phase_pattern'  => $row->phase_pattern,
                            'half1_label'    => $row->half1_label,
                            'half2_label'    => $row->half2_label,
                            'half1_order'    => $row->half1_order,
                            'half2_order'    => $row->half2_order,
                            'popularity_band'=> $row->popularity_band,
                            'popularity_min' => $row->popularity_min,
                            'popularity_max' => $row->popularity_max,
                            'sample_count'   => $row->sample_count,
                            'win_count'      => $row->win_count,
                            'win_rate'       => $row->win_rate,
                            'recovery_rate'  => $row->recovery_rate,
                            'start_date'     => $row->start_date,
                            'end_date'       => $row->end_date,
                            'computed_at'    => $now,
                        ],
                        ['phase_pattern', 'popularity_band'],
                        [
                            'half1_label', 'half2_label',
                            'half1_order', 'half2_order',
                            'popularity_min', 'popularity_max',
                            'sample_count', 'win_count',
                            'win_rate', 'recovery_rate',
                            'start_date', 'end_date',
                            'computed_at',
                        ]
                    );

                $this->line(sprintf(
                    '  [%s × %s]  サンプル%4d件  勝率%5.1f%%  回収率%6.1f%%',
                    $row->phase_pattern,
                    $row->popularity_band,
                    $row->sample_count,
                    $row->win_rate,
                    $row->recovery_rate
                ));

                $upsertCount++;
            }

            // ─── 完了サマリー ─────────────────────────────────────────────
            $elapsed = now()->diffInSeconds($startedAt);
            $news    = "正常終了\nUPSERT: {$upsertCount}件\n経過: {$elapsed}秒";

            $this->info('=== 完了 ' . now()->format('Y-m-d H:i:s') . " ({$elapsed}秒) ===");
            Log::info('SummaryOddsPhasePatternRecoveryRate 完了', ['upsert_count' => $upsertCount]);

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryOddsPhasePatternRecoveryRate::handle\n{$news}");

        } catch (\Throwable $e) {
            $msg = 'エラー: ' . $e->getMessage();
            $this->error($msg);
            Log::error('SummaryOddsPhasePatternRecoveryRate 失敗', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryOddsPhasePatternRecoveryRate::handle\n{$msg}");
        } finally {
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        }
    }
}
