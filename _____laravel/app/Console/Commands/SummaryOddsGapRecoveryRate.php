<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SummaryOddsGapRecoveryRate
 *
 * 【概要】
 *   t_horse_odds_finder_summary の「計測開始（odds_tan_before_24）→ 6分前（odds_tan_before_6）」
 *   の変化率を算出し、変化率帯 × 人気帯 別の単勝回収率を集計して
 *   t_horse_odds_finder_odds_gap_recovery に保存する。
 *
 *   集計結果は _getAiAnalysisPrompt で読み込まれ、
 *   「この変化率パターンの過去回収率は X%」としてプロンプトに追加される。
 *
 * 【変化率帯の定義】
 *   30%以上下落 / 20〜30%下落 / 10〜20%下落 / 5〜10%下落
 *   ±5%以内 / 5〜10%上昇 / 10〜20%上昇 / 20〜30%上昇 / 30%以上上昇
 *
 * 【人気帯の定義】
 *   1〜3人気 / 4〜6人気 / 7〜9人気 / 10人気以上
 *   ※ 人気順位は当該レース内の odds_tan_before_6 昇順ランキングで算出
 *
 * 【AIコスト】
 *   なし（純粋なSQL集計のみ）
 *
 * 【使い方】
 *   php artisan keiba:SummaryOddsGapRecoveryRate
 *
 * 【cron 登録例】
 *   15 21 * * * flock -n /tmp/keiba_SummaryOddsGapRecoveryRate.lock \
 *     php /var/www/horse_odds_finder/artisan keiba:SummaryOddsGapRecoveryRate \
 *     >> /var/www/horse_odds_finder/storage/logs/SummaryOddsGapRecoveryRate.log 2>&1
 */
class SummaryOddsGapRecoveryRate extends Command
{
    protected $signature   = 'keiba:SummaryOddsGapRecoveryRate';
    protected $description = '計測開始→6分前の変化率帯×人気帯別の単勝回収率を集計する（AIコストなし）';

    public function handle(): void
    {
        // ─── ロックファイルで多重起動防止 ────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_SummaryOddsGapRecoveryRate.lock';
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
        $this->info('=== SummaryOddsGapRecoveryRate 開始 ' . $startedAt->format('Y-m-d H:i:s') . ' ===');

        try {
            // ─── 集計クエリ ────────────────────────────────────────────────
            //
            // ① t_horse_odds_finder_summary から必要な列を取得
            // ② レース内で odds_tan_before_6 の昇順ランクを付けて人気帯を決定
            // ③ 変化率 = (odds_tan_before_6 - odds_tan_before_24) / odds_tan_before_24 * 100
            // ④ 変化率帯 × 人気帯 ごとに勝率・回収率を集計
            //    回収率: 1着時 → odds_tan_before_0 × 100、それ以外 → 0
            //            全サンプル数で割る（＝ 100円ずつ全馬に単勝投資した場合の回収率）
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
                      AND s.odds_tan_before_6  IS NOT NULL
                      AND s.odds_tan_before_6  != ''
                      AND CAST(s.odds_tan_before_6  AS DECIMAL(10,2)) > 0
                      AND s.odds_tan_before_0  IS NOT NULL
                      AND s.odds_tan_before_0  != ''
                      AND CAST(s.odds_tan_before_0  AS DECIMAL(10,2)) > 0
                      AND s.result IS NOT NULL
                ),
                with_bands AS (
                    SELECT
                        *,
                        ROUND((odds_6 - odds_start) / odds_start * 100, 1) AS change_rate,
                        CASE
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <= -30 THEN '30%以上下落'
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <= -20 THEN '20〜30%下落'
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <= -10 THEN '10〜20%下落'
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <= -5  THEN '5〜10%下落'
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <  5   THEN '±5%以内'
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <  10  THEN '5〜10%上昇'
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <  20  THEN '10〜20%上昇'
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <  30  THEN '20〜30%上昇'
                            ELSE '30%以上上昇'
                        END AS gap_band,
                        CASE
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <= -30 THEN -999.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <= -20 THEN -30.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <= -10 THEN -20.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <= -5  THEN -10.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <  5   THEN  -5.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <  10  THEN   5.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <  20  THEN  10.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <  30  THEN  20.0
                            ELSE 30.0
                        END AS gap_min,
                        CASE
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <= -30 THEN -20.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <= -20 THEN -20.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <= -10 THEN -10.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <= -5  THEN  -5.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <  5   THEN   5.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <  10  THEN  10.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <  20  THEN  20.0
                            WHEN ROUND((odds_6 - odds_start) / odds_start * 100, 1) <  30  THEN  30.0
                            ELSE 999.0
                        END AS gap_max,
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
                    FROM base
                )
                SELECT
                    gap_band,
                    gap_min,
                    gap_max,
                    popularity_band,
                    popularity_min,
                    popularity_max,
                    COUNT(*)                                                                       AS sample_count,
                    SUM(CASE WHEN result = 1 THEN 1 ELSE 0 END)                                   AS win_count,
                    ROUND(SUM(CASE WHEN result = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100, 2)        AS win_rate,
                    ROUND(SUM(CASE WHEN result = 1 THEN odds_final * 100 ELSE 0 END) / COUNT(*), 2) AS recovery_rate,
                    MIN(date)                                                                      AS start_date,
                    MAX(date)                                                                      AS end_date
                FROM with_bands
                GROUP BY gap_band, gap_min, gap_max, popularity_band, popularity_min, popularity_max
                ORDER BY gap_min ASC, popularity_min ASC
            ");

            if (empty($rows)) {
                $this->warn('集計対象データが見つかりませんでした。t_horse_odds_finder_summary にデータがない可能性があります。');
                return;
            }

            $this->info('集計完了: ' . count($rows) . '行のデータを取得');

            // ─── UPSERT ───────────────────────────────────────────────────
            $now         = now()->format('Y-m-d H:i:s');
            $upsertCount = 0;

            foreach ($rows as $row) {
                DB::table('t_horse_odds_finder_odds_gap_recovery')
                    ->upsert(
                        [
                            'gap_band'       => $row->gap_band,
                            'gap_min'        => $row->gap_min,
                            'gap_max'        => $row->gap_max,
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
                        ['gap_band', 'popularity_band'],
                        [
                            'gap_min', 'gap_max',
                            'popularity_min', 'popularity_max',
                            'sample_count', 'win_count',
                            'win_rate', 'recovery_rate',
                            'start_date', 'end_date',
                            'computed_at',
                        ]
                    );

                $this->line(sprintf(
                    '  [%s × %s]  サンプル%4d件  勝率%5.1f%%  回収率%6.1f%%',
                    $row->gap_band,
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
            Log::info('SummaryOddsGapRecoveryRate 完了', ['upsert_count' => $upsertCount]);

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryOddsGapRecoveryRate::handle\n{$news}");

        } catch (\Throwable $e) {
            $msg = 'エラー: ' . $e->getMessage();
            $this->error($msg);
            Log::error('SummaryOddsGapRecoveryRate 失敗', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryOddsGapRecoveryRate::handle\n{$msg}");
        } finally {
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        }
    }
}
