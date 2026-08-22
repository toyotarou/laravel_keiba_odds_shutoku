<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SummarySimilarRaceStats
 *
 * 【概要】
 *   t_horse_odds_finder_race_result_history の過去データから、
 *   人気順・頭数帯ごとの3着以内率・5着以内率・平均着順等を集計して
 *   t_horse_odds_finder_similar_race_stats に保存する。
 *   AIへのプロンプトで「過去の類似レースの傾向」として使用する。
 *
 * 【テーブル DDL（初回のみ手動実行）】
 *   CREATE TABLE t_horse_odds_finder_similar_race_stats (
 *     id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *     popularity_rank        TINYINT UNSIGNED NOT NULL COMMENT '人気順（6分前単勝オッズ昇順）',
 *     horse_count_band       ENUM('small','medium','large') NOT NULL COMMENT '頭数帯 (small=8以下, medium=9-13, large=14以上)',
 *     top3_rate              DECIMAL(6,4) NOT NULL DEFAULT 0 COMMENT '3着以内率（0.0〜1.0）',
 *     top5_rate              DECIMAL(6,4) NOT NULL DEFAULT 0 COMMENT '5着以内率（0.0〜1.0）',
 *     avg_finishing_position DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT '平均着順',
 *     outside_rate           DECIMAL(6,4) NOT NULL DEFAULT 0 COMMENT '着外率（3着より外・0.0〜1.0）',
 *     sample_count           INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '母数（サンプル数）',
 *     reliability            ENUM('normal','low','reference','insufficient') NOT NULL DEFAULT 'insufficient' COMMENT '信頼度',
 *     updated_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *     UNIQUE KEY uq_pop_band (popularity_rank, horse_count_band)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 *
 * 【信頼度ルール】
 *   30件以上  : normal（通常）
 *   20〜29件  : low（信頼度低下）
 *   10〜19件  : reference（参考程度）
 *   10件未満  : insufficient（統計不足・AIへ渡さない）
 *
 * 【メモリ対策】
 *   生レコードを全件PHPに読み込まず、DB側のサブクエリJOIN＋GROUP BYで集計してから取得する。
 *   PHPに渡るのは最大 popularity_rank 1〜18 × 頭数帯 3種 = 54行のみ。
 *
 * 【集計対象】
 *   finishing_position > 0（着順確定済み）
 *   popularity_rank    > 0（人気順確定済み）
 *   finishing_position = -1（中止・除外等）は自然に除外される。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】初期化・開始バナー
 *   【ブロック 3】DB側でGROUP BY集計（最大54行をPHPに渡す）
 *   【ブロック 4】t_horse_odds_finder_similar_race_stats に upsert
 *   【ブロック 5】完了サマリー・WebPush 通知
 *
 * 【使い方】
 *   php artisan keiba:summarySimilarRaceStats
 */
class SummarySimilarRaceStats extends Command
{
    protected $signature   = 'keiba:summarySimilarRaceStats';
    protected $description = '過去レースの人気順・頭数帯別の着順統計を集計して保存する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_summarySimilarRaceStats.lock';
        if (file_exists($lockFile)) {
            $pid = (int) file_get_contents($lockFile);
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
        $now = microtime(true);
        $this->info('');
        $this->info('========== keiba:summarySimilarRaceStats 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】DB側でGROUP BY集計（最大54行をPHPに渡す）
        //
        //   生レコードを全件PHPに読み込まず、サブクエリJOIN＋GROUP BYで集計する。
        //   PHPに渡るのは最大 popularity_rank(1〜18) × 頭数帯(3) = 54行のみ。
        //
        //   サブクエリ rb: レースごとの馬数を COUNT して頭数帯を判定する。
        //     CASE WHEN COUNT(*) <= 8  THEN 'small'
        //          WHEN COUNT(*) <= 13 THEN 'medium'
        //          ELSE 'large'
        //
        //   メインクエリ: rb に h を JOIN し、popularity_rank × horse_count_band で集計。
        //     sample_count … 対象馬数（母数）
        //     top3_count   … 着順 1〜3 の馬数
        //     top5_count   … 着順 1〜5 の馬数
        //     pos_sum      … 着順の合計（平均着順算出用）
        // ─────────────────────────────────────────────────────────────────
        $this->info('[ブロック 3] DB側でGROUP BY集計中...');

        $rows = DB::table('t_horse_odds_finder_race_result_history as h')
            ->join(DB::raw('(
                SELECT date, kaisuu, basho_code, day, race,
                       CASE WHEN COUNT(*) <= 8  THEN \'small\'
                            WHEN COUNT(*) <= 13 THEN \'medium\'
                            ELSE \'large\'
                       END AS horse_count_band
                FROM t_horse_odds_finder_race_result_history
                WHERE finishing_position > 0 AND popularity_rank > 0
                GROUP BY date, kaisuu, basho_code, day, race
            ) rb'), function ($join) {
                $join->on('h.date',       '=', 'rb.date')
                     ->on('h.kaisuu',     '=', 'rb.kaisuu')
                     ->on('h.basho_code', '=', 'rb.basho_code')
                     ->on('h.day',        '=', 'rb.day')
                     ->on('h.race',       '=', 'rb.race');
            })
            ->where('h.finishing_position', '>', 0)
            ->where('h.popularity_rank',    '>', 0)
            ->select(
                'h.popularity_rank',
                'rb.horse_count_band',
                DB::raw('COUNT(*)                                                    as sample_count'),
                DB::raw('SUM(CASE WHEN h.finishing_position <= 3 THEN 1 ELSE 0 END) as top3_count'),
                DB::raw('SUM(CASE WHEN h.finishing_position <= 5 THEN 1 ELSE 0 END) as top5_count'),
                DB::raw('SUM(h.finishing_position)                                  as pos_sum')
            )
            ->groupBy('h.popularity_rank', 'rb.horse_count_band')
            ->get();

        $this->info("  → {$rows->count()} 組の集計結果を取得（最大54組）");
        $this->info('');

        if ($rows->isEmpty()) {
            $this->warn('集計対象データがありません（finishing_position / popularity_rank が未設定の可能性）。');
            $this->info('========== keiba:summarySimilarRaceStats 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');
            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummarySimilarRaceStats::handle\nSKIP");
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 4】t_horse_odds_finder_similar_race_stats に upsert
        //   各種率を PHP 側で算出して updateOrInsert する。
        //   既存行は全項目を最新集計値で上書き。
        //
        //   信頼度の判定（30以上:通常 / 20〜29:低下 / 10〜19:参考 / 10未満:不使用）
        // ─────────────────────────────────────────────────────────────────
        $this->info('[ブロック 4] t_horse_odds_finder_similar_race_stats に保存中...');

        $upsertCount = 0;

        foreach ($rows as $row) {
            $sampleCount = (int) $row->sample_count;
            if ($sampleCount === 0) {
                continue;
            }

            $top3Rate    = round($row->top3_count / $sampleCount, 4);
            $top5Rate    = round($row->top5_count / $sampleCount, 4);
            $avgPos      = round($row->pos_sum    / $sampleCount, 2);
            $outsideRate = round(1 - $top3Rate, 4);

            $reliability = match (true) {
                $sampleCount >= 30 => 'normal',
                $sampleCount >= 20 => 'low',
                $sampleCount >= 10 => 'reference',
                default            => 'insufficient',
            };

            DB::table('t_horse_odds_finder_similar_race_stats')
                ->updateOrInsert(
                    [
                        'popularity_rank'  => (int) $row->popularity_rank,
                        'horse_count_band' => $row->horse_count_band,
                    ],
                    [
                        'top3_rate'              => $top3Rate,
                        'top5_rate'              => $top5Rate,
                        'avg_finishing_position' => $avgPos,
                        'outside_rate'           => $outsideRate,
                        'sample_count'           => $sampleCount,
                        'reliability'            => $reliability,
                        'updated_at'             => now(),
                    ]
                );

            $upsertCount++;
        }

        $this->info("  → {$upsertCount} 件を保存（upsert）");
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 5】完了サマリー・WebPush 通知
        // ─────────────────────────────────────────────────────────────────
        $elapsed = round(microtime(true) - $now, 1);

        $this->info('========== keiba:summarySimilarRaceStats 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info("保存件数 : {$upsertCount} 件");
        $this->info("処理時間 : {$elapsed} 秒");
        $this->info('');

        (new WebPushService())->sendPushNotifierDeveloperNews(
            'develop',
            "SummarySimilarRaceStats::handle\n保存:{$upsertCount}件 / {$elapsed}秒"
        );
    }
}
