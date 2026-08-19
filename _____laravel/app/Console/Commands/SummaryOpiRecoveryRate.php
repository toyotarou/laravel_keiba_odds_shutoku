<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SummaryOpiRecoveryRate
 *
 * 【概要】
 *   t_horse_odds_finder_summary の 6分前オッズと
 *   t_horse_odds_finder_compute_odds_correction の人気順位別過去平均6分前オッズから
 *   OPI（= 過去平均 ÷ 今回6分前オッズ）を算出し、
 *   OPI帯 × 人気帯 別の単勝回収率を集計して
 *   t_horse_odds_finder_opi_recovery に保存する。
 *
 *   集計結果は _getAiAnalysisPrompt で読み込まれ、
 *   「このOPIパターンの過去回収率は X%」としてプロンプトに追加される。
 *
 * 【OPIの意味】
 *   OPI > 1.0 → 今回オッズが過去平均より低い（市場が過大評価・妙味少）
 *   OPI < 1.0 → 今回オッズが過去平均より高い（市場が過小評価・妙味あり）
 *   OPI ≒ 1.0 → 歴史的平均並み
 *
 * 【OPI帯の定義】
 *   0.70未満 / 0.70〜0.85 / 0.85〜1.00 / 1.00〜1.15
 *   1.15〜1.30 / 1.30〜1.50 / 1.50以上
 *
 * 【人気帯の定義】
 *   1〜3人気 / 4〜6人気 / 7〜9人気 / 10人気以上
 *   ※ 人気順位は当該レース内の odds_tan_before_6 昇順ランキングで算出
 *
 * 【AIコスト】
 *   なし（純粋なSQL集計のみ）
 *
 * 【使い方】
 *   php artisan keiba:SummaryOpiRecoveryRate
 *
 * 【cron 登録例】
 *   20 22 * * * flock -n /tmp/keiba_SummaryOpiRecoveryRate.lock \
 *     php /var/www/horse_odds_finder/artisan keiba:SummaryOpiRecoveryRate \
 *     >> /var/www/horse_odds_finder/storage/logs/SummaryOpiRecoveryRate.log 2>&1
 */
class SummaryOpiRecoveryRate extends Command
{
    protected $signature   = 'keiba:SummaryOpiRecoveryRate';
    protected $description = 'OPI帯×人気帯別の単勝回収率を集計する（AIコストなし）';

    public function handle(): void
    {
        // ─── ロックファイルで多重起動防止 ────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_SummaryOpiRecoveryRate.lock';
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
        $this->info('=== SummaryOpiRecoveryRate 開始 ' . $startedAt->format('Y-m-d H:i:s') . ' ===');

        try {
            // ─── 集計クエリ ────────────────────────────────────────────────
            //
            // ① t_horse_odds_finder_summary から6分前・確定オッズ・着順を取得
            // ② レース内で odds_tan_before_6 の昇順ランクを付けて人気帯を決定
            // ③ t_horse_odds_finder_compute_odds_correction と JOIN して
            //    人気順位別の過去平均6分前オッズ（avg_odds_6min）を取得
            // ④ OPI = avg_odds_6min / odds_6（今回6分前オッズ）
            // ⑤ OPI帯 × 人気帯 ごとに勝率・回収率を集計
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
                        CAST(s.odds_tan_before_6 AS DECIMAL(10,2)) AS odds_6,
                        CAST(s.odds_tan_before_0 AS DECIMAL(10,2)) AS odds_final,
                        RANK() OVER (
                            PARTITION BY s.date, s.kaisuu, s.basho, s.day, s.race
                            ORDER BY CAST(s.odds_tan_before_6 AS DECIMAL(10,2)) ASC
                        ) AS popularity_rank
                    FROM t_horse_odds_finder_summary s
                    WHERE s.odds_tan_before_6 IS NOT NULL
                      AND s.odds_tan_before_6 != ''
                      AND CAST(s.odds_tan_before_6 AS DECIMAL(10,2)) > 0
                      AND s.odds_tan_before_0 IS NOT NULL
                      AND s.odds_tan_before_0 != ''
                      AND CAST(s.odds_tan_before_0 AS DECIMAL(10,2)) > 0
                      AND s.result IS NOT NULL
                ),
                with_opi AS (
                    SELECT
                        b.*,
                        c.avg_odds_6min,
                        ROUND(c.avg_odds_6min / b.odds_6, 3) AS opi
                    FROM base b
                    INNER JOIN t_horse_odds_finder_compute_odds_correction c
                        ON c.popularity_rank = b.popularity_rank
                    WHERE c.avg_odds_6min IS NOT NULL
                      AND c.avg_odds_6min > 0
                ),
                with_bands AS (
                    SELECT
                        *,
                        CASE
                            WHEN opi <  0.70 THEN '0.70未満'
                            WHEN opi <  0.85 THEN '0.70〜0.85'
                            WHEN opi <  1.00 THEN '0.85〜1.00'
                            WHEN opi <  1.15 THEN '1.00〜1.15'
                            WHEN opi <  1.30 THEN '1.15〜1.30'
                            WHEN opi <  1.50 THEN '1.30〜1.50'
                            ELSE '1.50以上'
                        END AS opi_band,
                        CASE
                            WHEN opi <  0.70 THEN -999.0
                            WHEN opi <  0.85 THEN  0.70
                            WHEN opi <  1.00 THEN  0.85
                            WHEN opi <  1.15 THEN  1.00
                            WHEN opi <  1.30 THEN  1.15
                            WHEN opi <  1.50 THEN  1.30
                            ELSE 1.50
                        END AS opi_min,
                        CASE
                            WHEN opi <  0.70 THEN  0.70
                            WHEN opi <  0.85 THEN  0.85
                            WHEN opi <  1.00 THEN  1.00
                            WHEN opi <  1.15 THEN  1.15
                            WHEN opi <  1.30 THEN  1.30
                            WHEN opi <  1.50 THEN  1.50
                            ELSE 999.0
                        END AS opi_max,
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
                    FROM with_opi
                )
                SELECT
                    opi_band,
                    opi_min,
                    opi_max,
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
                GROUP BY opi_band, opi_min, opi_max, popularity_band, popularity_min, popularity_max
                ORDER BY opi_min ASC, popularity_min ASC
            ");

            if (empty($rows)) {
                $this->warn('集計対象データが見つかりませんでした。t_horse_odds_finder_compute_odds_correction にデータがない可能性があります。');
                return;
            }

            $this->info('集計完了: ' . count($rows) . '行のデータを取得');

            // ─── UPSERT ───────────────────────────────────────────────────
            $now         = now()->format('Y-m-d H:i:s');
            $upsertCount = 0;

            foreach ($rows as $row) {
                DB::table('t_horse_odds_finder_opi_recovery')
                    ->upsert(
                        [
                            'opi_band'       => $row->opi_band,
                            'opi_min'        => $row->opi_min,
                            'opi_max'        => $row->opi_max,
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
                        ['opi_band', 'popularity_band'],
                        [
                            'opi_min', 'opi_max',
                            'popularity_min', 'popularity_max',
                            'sample_count', 'win_count',
                            'win_rate', 'recovery_rate',
                            'start_date', 'end_date',
                            'computed_at',
                        ]
                    );

                $this->line(sprintf(
                    '  [OPI %s × %s]  サンプル%4d件  勝率%5.1f%%  回収率%6.1f%%',
                    $row->opi_band,
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
            Log::info('SummaryOpiRecoveryRate 完了', ['upsert_count' => $upsertCount]);

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryOpiRecoveryRate::handle\n{$news}");

        } catch (\Throwable $e) {
            $msg = 'エラー: ' . $e->getMessage();
            $this->error($msg);
            Log::error('SummaryOpiRecoveryRate 失敗', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryOpiRecoveryRate::handle\n{$msg}");
        } finally {
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        }
    }
}
