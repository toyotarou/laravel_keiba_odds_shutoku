<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SummaryPopularityRankAverage
 *
 * 【概要】
 *   t_horse_odds_finder_race_result_history から人気順位別の単勝オッズ平均を計算し
 *   t_horse_odds_finder_popularity_rank_average テーブルに増分で反映する。
 *   ソーステーブルは古いレコードを削除する運用のため「全件から毎回再計算」はできない。
 *   代わりに「平均 × count = 合計」を利用した加重平均の増分更新で実現する。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】STEP1: 既存の平均テーブルを取得（初回かどうかを判定）
 *   【ブロック 3】STEP2: ソーステーブルから新レコードを取得
 *                （2回目以降は end_date より新しいものだけ）
 *   【ブロック 4】新レコードなし → 早期終了
 *   【ブロック 5】STEP3: 新レコードを popularity_rank 別にグループ化
 *   【ブロック 6】STEP4: ランク 1〜18 を順番に処理（4ケース）
 *   【ブロック 7】完了ログ・WebPush 通知
 *
 * 【4ケースの処理】
 *   ①新データなし・既存あり  → end_date のみ更新（データは変わらない）
 *   ②新データなし・既存なし  → スキップ（このランクのデータは今後入ったとき INSERT）
 *   ③新データあり・既存あり  → 加重平均で更新
 *       new_avg = (existingAvg × existingCount + newSum) / (existingCount + newCount)
 *   ④新データあり・既存なし  → 全件取得して初回 INSERT（start_date を確定）
 *
 * 【加重平均の考え方】
 *   ソーステーブルから古いデータは削除されるため生データは復元できない。
 *   しかし「平均 × 件数 = 合計」は復元できる。
 *   例: 既存 avg=4, count=3 → 既存合計=12; 新着合計=24(6+8+10, count=3)
 *       新平均 = (12+24)/(3+3) = 6
 *
 * 【使い方】
 *   php artisan keiba:summaryPopularityRankAverage
 */
class SummaryPopularityRankAverage extends Command
{
    protected $signature   = 'keiba:summaryPopularityRankAverage';
    protected $description = 't_horse_odds_finder_popularity_rank_average に人気順別のオッズ平均を集計する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        //   cron で毎日動かすので前回の処理がまだ終わっていないときに
        //   二重で起動しないようロックファイルを使う。
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_summaryPopularityRankAverage.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $now = microtime(true);
        $this->info('');
        $this->info('========== keiba:summaryPopularityRankAverage 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】STEP1: 既存の平均テーブルを取得（初回かどうかを判定）
        //   このテーブルは常に popularity_rank 1〜18 の最大18行しかない。
        //   keyBy('popularity_rank') でランク番号をキーにして O(1) アクセス。
        //   isEmpty() で「1行もない = 初回実行」かどうかを判定する。
        // ─────────────────────────────────────────────────────────────────
        $existingRows = DB::table('t_horse_odds_finder_popularity_rank_average')
            ->orderBy('popularity_rank')
            ->get()
            ->keyBy('popularity_rank');

        $isFirstRun = $existingRows->isEmpty();
        $this->info($isFirstRun ? '初回実行：全件から計算します' : '2回目以降：増分を足し込みます');
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】STEP2: ソーステーブルから新レコードを取得
        //   ソーステーブルは古いレコードを削除する運用のため全件再計算はできない。
        //   ■ 初回実行: end_date がないので全件取得する。
        //   ■ 2回目以降: 既存の end_date より後の日付のレコードだけ取得する。
        //     （集計済み分は odds_average と count に含まれているので不要）
        //   取得条件: popularity_rank が NULL/0 でなく tan が数値文字列のもの。
        // ─────────────────────────────────────────────────────────────────
        $sourceQuery = DB::table('t_horse_odds_finder_race_result_history')
            ->whereNotNull('popularity_rank')
            ->where('popularity_rank', '!=', 0)
            ->whereNotNull('tan')
            ->where('tan', '!=', '')
            ->whereRaw("tan REGEXP '^[0-9]+(\\.[0-9]+)?$'") // 数値文字列のみ（"取消"などを除外）
            ->select('popularity_rank', 'tan', 'date');

        if (!$isFirstRun) {
            $currentEndDate = $existingRows->max('end_date');
            $sourceQuery->where('date', '>', $currentEndDate);
            $this->info("  現在の end_date : {$currentEndDate}（これより新しいものを対象）");
        }

        $newRecords = $sourceQuery->get();
        $this->info("  取得件数: {$newRecords->count()} 件");
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 4】新レコードなし → 早期終了
        //   平日など競馬がない日は新しいレコードが存在しない。
        //   DB への書き込みゼロで終了する（cron で毎日回しても安全）。
        // ─────────────────────────────────────────────────────────────────
        if ($newRecords->isEmpty()) {
            $this->warn('追加対象のレコードがありません。処理を終了します。');
            $this->info('========== keiba:summaryPopularityRankAverage 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 5】STEP3: 新レコードを popularity_rank ごとにグループ化
        //   $newGrouped[1] = [2.1, 1.8, 2.5, ...]  ← 1番人気の tan の配列
        //   $newGrouped[2] = [4.0, 3.8, ...]        ← 2番人気の tan の配列
        //   1〜18 の範囲外は念のために無視する。
        // ─────────────────────────────────────────────────────────────────
        $newGrouped = [];
        foreach ($newRecords as $row) {
            $rank = (int) $row->popularity_rank;
            if ($rank < 1 || $rank > 18) {
                continue;
            }
            $newGrouped[$rank][] = (float) $row->tan;
        }

        $newEndDate   = $newRecords->max('date');
        $updatedCount = 0;

        $this->info('集計・更新中...');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 6】STEP4: ランク 1〜18 を順番に処理（4ケース）
        //   ①新データなし・既存あり  → end_date のみ更新
        //   ②新データなし・既存なし  → スキップ
        //   ③新データあり・既存あり  → 加重平均で更新
        //      new_avg = (existingAvg × existingCount + newSum) / (existingCount + newCount)
        //   ④新データあり・既存なし  → 全件取得して初回 INSERT
        // ─────────────────────────────────────────────────────────────────
        for ($rank = 1; $rank <= 18; $rank++) {

            $hasNewData  = isset($newGrouped[$rank]);
            $hasExisting = $existingRows->has($rank);

            // ケース①: 新データなし・既存あり → end_date のみ更新
            if (!$hasNewData && $hasExisting) {
                DB::table('t_horse_odds_finder_popularity_rank_average')
                    ->where('popularity_rank', $rank)
                    ->update(['end_date' => $newEndDate]);
                continue;
            }

            // ケース②: 新データなし・既存なし → スキップ
            if (!$hasNewData) {
                continue;
            }

            $newTanList  = $newGrouped[$rank];
            $newSum      = array_sum($newTanList);
            $newSubCount = count($newTanList);

            if ($hasExisting) {
                // ケース③: 新データあり・既存あり → 加重平均で UPDATE
                //   既存の合計 = odds_average × count で復元できる。
                //   start_date は変えない（最初に決まったら不動）。
                $existing     = $existingRows[$rank];
                $existingSum  = (float) $existing->odds_average * (int) $existing->count;
                $totalCount   = (int) $existing->count + $newSubCount;
                $totalAverage = round(($existingSum + $newSum) / $totalCount, 1);

                DB::table('t_horse_odds_finder_popularity_rank_average')
                    ->where('popularity_rank', $rank)
                    ->update([
                        'odds_average' => (string) $totalAverage,
                        'count'        => $totalCount,
                        'end_date'     => $newEndDate,
                    ]);

            } else {
                // ケース④: 新データあり・既存なし → 全件取得して初回 INSERT
                //   start_date はこのタイミングで確定し、以降は変更しない。
                $allForRank = DB::table('t_horse_odds_finder_race_result_history')
                    ->where('popularity_rank', $rank)
                    ->whereNotNull('tan')
                    ->where('tan', '!=', '')
                    ->whereRaw("tan REGEXP '^[0-9]+(\\.[0-9]+)?$'")
                    ->select('tan', 'date')
                    ->get();

                $startDate    = $allForRank->min('date');
                $totalCount   = $allForRank->count();
                $totalAverage = round($allForRank->sum(fn($r) => (float) $r->tan) / $totalCount, 1);

                DB::table('t_horse_odds_finder_popularity_rank_average')->insert([
                    'popularity_rank' => $rank,
                    'odds_average'    => (string) $totalAverage,
                    'count'           => $totalCount,
                    'start_date'      => $startDate,
                    'end_date'        => $newEndDate,
                ]);
            }

            $updatedCount++;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 7】完了ログ・WebPush 通知
        // ─────────────────────────────────────────────────────────────────
        $elapsed = round(microtime(true) - $now, 1);
        $this->info('');
        $this->info('========== keiba:summaryPopularityRankAverage 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('new end_date : ' . $newEndDate);
        $this->info('更新ランク数 : ' . $updatedCount . ' 件');
        $this->info('処理時間     : ' . $elapsed . ' 秒');
        $this->info('');

        (new WebPushService())->sendPushNotifierDeveloperNews(
            'develop',
            "SummaryPopularityRankAverage::handle\n最終日:{$newEndDate}、time:{$elapsed}"
        );
    }
}
