<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SummaryFukuPopularityRankAverage
 *
 * 【概要】
 *   t_horse_odds_finder_race_result_history から人気順位別の複勝最小オッズ平均を計算し
 *   t_horse_odds_finder_fuku_popularity_rank_average テーブルに増分で反映する。
 *   単勝版 (SummaryPopularityRankAverage) の複勝版。
 *
 * 【メモリ対策】
 *   生レコードを全件PHPに読み込まず、DB側で GROUP BY 集計（SUM/COUNT/MIN/MAX）してから
 *   取得する。PHPに渡るのは最大18行のみ。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】STEP1: 既存の平均テーブルを取得（初回かどうかを判定）
 *   【ブロック 3】STEP2: ソーステーブルをDB側で集計（popularity_rank 別 SUM/COUNT）
 *                （2回目以降は end_date より新しいものだけ）
 *   【ブロック 4】新レコードなし → 早期終了
 *   【ブロック 5】STEP3: ランク 1〜18 を順番に処理（4ケース）
 *   【ブロック 6】完了ログ・WebPush 通知
 *
 * 【4ケースの処理】
 *   ①新データなし・既存あり  → end_date のみ更新
 *   ②新データなし・既存なし  → スキップ
 *   ③新データあり・既存あり  → 加重平均で更新
 *       new_avg = (existingAvg × existingCount + newSum) / (existingCount + newCount)
 *   ④新データあり・既存なし  → そのままINSERT（GROUP BYの集計値をそのまま使う）
 *
 * 【使い方】
 *   php artisan keiba:summaryFukuPopularityRankAverage
 */
class SummaryFukuPopularityRankAverage extends Command
{
    protected $signature   = 'keiba:summaryFukuPopularityRankAverage';
    protected $description = 't_horse_odds_finder_fuku_popularity_rank_average に人気順別の複勝オッズ平均を集計する';

    public function handle(): void
    {
        // Laravelフレームワーク起動時のオーバーヘッドを考慮してメモリ上限を引き上げる
        ini_set('memory_limit', '512M');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_summaryFukuPopularityRankAverage.lock';
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

        $now = microtime(true);
        $this->info('');
        $this->info('========== keiba:summaryFukuPopularityRankAverage 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】STEP1: 既存の平均テーブルを取得（初回かどうかを判定）
        // ─────────────────────────────────────────────────────────────────
        $existingRows = DB::table('t_horse_odds_finder_fuku_popularity_rank_average')
            ->orderBy('popularity_rank')
            ->get()
            ->keyBy('popularity_rank');

        $isFirstRun = $existingRows->isEmpty();
        $this->info($isFirstRun ? '初回実行：全件から計算します' : '2回目以降：増分を足し込みます');
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】STEP2: ソーステーブルをDB側で集計
        //   生レコードを全件PHPに読み込まず、GROUP BY で集計してからPHPに渡す。
        //   PHPに渡るのは最大 popularity_rank 1〜18 の18行のみ。
        //
        //   取得カラム:
        //     popularity_rank … グループキー
        //     fuku_sum        … SUM(fuku_min)  加重平均の計算に使う
        //     fuku_count      … COUNT(*)
        //     min_date        … 集計期間の最古日（ケース④のstart_date用）
        //     max_date        … 集計期間の最新日（end_date更新用）
        // ─────────────────────────────────────────────────────────────────
        $sourceQuery = DB::table('t_horse_odds_finder_race_result_history')
            ->whereNotNull('popularity_rank')
            ->where('popularity_rank', '!=', 0)
            ->whereNotNull('fuku_min')
            ->where('fuku_min', '!=', '')
            ->whereRaw("fuku_min REGEXP '^[0-9]+(\\.[0-9]+)?$'") // 数値文字列のみ（"取消"などを除外）
            ->select(
                'popularity_rank',
                DB::raw('SUM(fuku_min) as fuku_sum'),
                DB::raw('COUNT(*)     as fuku_count'),
                DB::raw('MIN(date)    as min_date'),
                DB::raw('MAX(date)    as max_date')
            )
            ->groupBy('popularity_rank');

        if (!$isFirstRun) {
            $currentEndDate = $existingRows->max('end_date');
            $sourceQuery->where('date', '>', $currentEndDate);
            $this->info("  現在の end_date : {$currentEndDate}（これより新しいものを対象）");
        }

        // PHPに渡るのは最大18行
        $newSummary = $sourceQuery->get()->keyBy('popularity_rank');
        $this->info("  集計ランク数: {$newSummary->count()} ランク");
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 4】新レコードなし → 早期終了
        // ─────────────────────────────────────────────────────────────────
        if ($newSummary->isEmpty()) {
            $this->warn('追加対象のレコードがありません。処理を終了します。');
            $this->info('========== keiba:summaryFukuPopularityRankAverage 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');
            (new WebPushService())->sendPushNotifierDeveloperNews(
                'develop',
                'SummaryFukuPopularityRankAverage::handle' . "\n" . 'SKIP'
            );
            return;
        }

        $newEndDate   = $newSummary->max('max_date');
        $updatedCount = 0;

        $this->info('集計・更新中...');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 5】STEP3: ランク 1〜18 を順番に処理（4ケース）
        // ─────────────────────────────────────────────────────────────────
        for ($rank = 1; $rank <= 18; $rank++) {

            $newData     = $newSummary->get($rank);
            $hasNewData  = $newData !== null && (int) $newData->fuku_count > 0;
            $hasExisting = $existingRows->has($rank);

            // ケース①: 新データなし・既存あり → end_date のみ更新
            if (!$hasNewData && $hasExisting) {
                DB::table('t_horse_odds_finder_fuku_popularity_rank_average')
                    ->where('popularity_rank', $rank)
                    ->update(['end_date' => $newEndDate]);
                continue;
            }

            // ケース②: 新データなし・既存なし → スキップ
            if (!$hasNewData) {
                continue;
            }

            $newSum      = (float) $newData->fuku_sum;
            $newSubCount = (int)   $newData->fuku_count;

            if ($hasExisting) {
                // ケース③: 新データあり・既存あり → 加重平均で UPDATE
                //   既存合計 = odds_average × count で復元できる
                $existing     = $existingRows[$rank];
                $existingSum  = (float) $existing->odds_average * (int) $existing->count;
                $totalCount   = (int) $existing->count + $newSubCount;
                $totalAverage = round(($existingSum + $newSum) / $totalCount, 1);

                DB::table('t_horse_odds_finder_fuku_popularity_rank_average')
                    ->where('popularity_rank', $rank)
                    ->update([
                        'odds_average' => (string) $totalAverage,
                        'count'        => $totalCount,
                        'end_date'     => $newEndDate,
                    ]);

            } else {
                // ケース④: 新データあり・既存なし → 初回 INSERT
                //   GROUP BY 集計済みの値をそのまま使う（全件再取得不要）
                //   start_date は集計期間の最古日
                $totalCount   = $newSubCount;
                $totalAverage = round($newSum / $totalCount, 1);

                DB::table('t_horse_odds_finder_fuku_popularity_rank_average')->insert([
                    'popularity_rank' => $rank,
                    'odds_average'    => (string) $totalAverage,
                    'count'           => $totalCount,
                    'start_date'      => $newData->min_date,
                    'end_date'        => $newEndDate,
                ]);
            }

            $updatedCount++;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 6】完了ログ・WebPush 通知
        // ─────────────────────────────────────────────────────────────────
        $elapsed = round(microtime(true) - $now, 1);
        $this->info('');
        $this->info('========== keiba:summaryFukuPopularityRankAverage 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('new end_date : ' . $newEndDate);
        $this->info('更新ランク数 : ' . $updatedCount . ' 件');
        $this->info('処理時間     : ' . $elapsed . ' 秒');
        $this->info('');

        (new WebPushService())->sendPushNotifierDeveloperNews(
            'develop',
            "SummaryFukuPopularityRankAverage::handle\n最終日:{$newEndDate}、time:{$elapsed}"
        );
    }
}
