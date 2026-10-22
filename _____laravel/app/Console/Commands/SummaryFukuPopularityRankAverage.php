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
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-R122-REALTIME  算出時刻を記録します
        // ══════════════════════════════════════════════════════════════
        //   r12.2 は「無い時刻は作らない」方針です（時刻A案）。
        //   この表に算出時刻が無いと、情報上限より前に利用できた証拠が
        //   無いことになり、OPI を予想の材料に使えません。
        //   ここで<b>実際に集計した時刻</b>を書きます。後付けはしません。
        $computedAt = now()->format('Y-m-d H:i:s.u');

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
            $this->warn('追加対象のレコードがありません。');
            // ═════════════════════════════════════════════════════════════
            // @ANCHOR-20261020-R122-COMPUTEDAT  算出時刻だけは残します
            // ═════════════════════════════════════════════════════════════
            //   理由は SummaryPopularityRankAverage と同じです。
            //   新しいレース結果が無い日はDBに1行も書かずに終わる作りだったので、
            //   <b>算出時刻（computed_at）が永久に入りませんでした。</b>
            // ═════════════════════════════════════════════════════════
            // @ANCHOR-20261021-R122-COMMENTFIX  10月21日010の5章①
            // ═════════════════════════════════════════════════════════
            //   ご指摘：
            //     「古い説明コメント『NULLならverified_atと同時刻を入れる』
            //       が残っているため、現在の仕様と区別して整理すると
            //       誤読を防げます。」
            //   そのとおりです。下の2行は10月20日の作りの説明で、
            //   いまの作りと食い違っていました。コメントアウトして残します。
            //
            // ──【10月20日の説明。いまは当てはまりません】───────────
            //   値は1つも変えず、算出時刻だけを入れ直します。
            //   集計期間の終わり（end_date）は触りません。
            // ────────────────────────────────────────────────────────
            //
            //   【いまの作り】このブロックは何も算出していません。
            //   「end_date より後の新規データが無い」ことを確かめただけです。
            //   ですので<b>computed_at は1行も触りません。</b>
            //     computed_at  実際に値を算出した時刻。ここでは書きません
            //     verified_at  その値がこの時刻に存在し、使えたことの確認
            //     end_date     集計期間の終わり。ここでは触りません
            //   computed_at が NULL の行は、<b>件数だけ数えて報告します。</b>
            if (!$isFirstRun) {
                // ═════════════════════════════════════════════════════
                // @ANCHOR-20261021-R122-VERIFIEDAT  10月21日008のご指摘8
                // ═════════════════════════════════════════════════════
                //   【説明が間違っていました】
                //   ここは「古い結果を再集計して一致を比べる」処理では
                //   ありません。end_date より後の新規データが無いことを
                //   確かめているだけです。
                //   ですので「もう一度集計して同じ値を確認した」は誤りです。
                //
                //   【もう1つ、こちらのほうが重い】
                //   新規結果が無いだけで computed_at を毎回上書きすると、
                //   <b>いつ実際に算出したかという証拠が消えます。</b>
                //   r12.2 は「情報上限より前に利用可能だった証拠」を見るので、
                //   証拠そのものを毎晩書き換えてはいけません。
                //
                //   【いま】意味の違う3つを分けます。
                //     computed_at  実際に値を算出した時刻。<b>ここでは触りません</b>
                //     verified_at  その値がこの時刻に存在し、使えたことの確認
                //     end_date     集計期間の終わり。ここでは触りません
                // ═════════════════════════════════════════════════════
                // @ANCHOR-20261021-R122-NOFILL  10月21日009・010のご指摘
                // ═════════════════════════════════════════════════════
                //   computed_at が NULL の行を、いまの時刻で埋めていました。
                //   いつ算出したかの証拠を<b>こちらで作ってしまいます。</b>
                //   埋めるのをやめ、<b>件数だけ数えて報告します。</b>
                //   埋まっていない行は、次に実際に集計したときに入ります。
                $tbl = 't_horse_odds_finder_fuku_popularity_rank_average';
                // ──【旧】NULL の行を今の時刻で埋めていました ───────────
                // $firstTime = DB::table($tbl)->whereNull('computed_at')
                //                ->update(['computed_at' => $computedAt]);
                // ────────────────────────────────────────────────────────
                $firstTime = (int) DB::table($tbl)->whereNull('computed_at')->count();
                $verified  = DB::table($tbl)
                               ->update(['verified_at' => $computedAt]);
                $this->info("  新規データはありません。値は1つも変えていません。");
                if ($firstTime > 0) {
                    $this->info("  算出時刻（computed_at）が入っていない行が {$firstTime} 行"
                              . " あります。<b>ここでは埋めません。</b>"
                              . "次に実際に集計したときに入ります");
                } else {
                    $this->info("  算出時刻（computed_at）が入っていない行はありません");
                }
                $this->info("  {$verified} 行の verified_at を更新しました"
                          . "（この時刻に、この値が使えることを確かめた、の意味）");
                $this->info('  ※ computed_at（実際に算出した時刻）と'
                          . ' end_date（集計期間の終わり）は1行も変えていません');
            }
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
                    ->update(['end_date' => $newEndDate,
                              'computed_at' => $computedAt]);
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
                        'computed_at'  => $computedAt,
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
                    'computed_at'     => $computedAt,
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
