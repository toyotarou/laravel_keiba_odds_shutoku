<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SummaryPopularityRankAverage extends Command
{
    protected $signature   = 'keiba:summaryPopularityRankAverage';
    protected $description = 't_horse_odds_finder_popularity_rank_average に人気順別のオッズ平均を集計する';

    public function handle(): void
    {
        // ============================================================
        // 多重起動防止
        // cronで毎日動かすので、前回の処理がまだ終わっていないときに
        // 二重で起動しないようにロックファイルを使う。
        // ロックファイルが存在する = 別プロセスが実行中 なので終了する。
        // register_shutdown_function で処理が終わったら自動的にファイルを消す。
        // ============================================================
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

        // ============================================================
        // STEP1: t_horse_odds_finder_popularity_rank_average の現状を取得する
        //
        // このテーブルは常に popularity_rank 1〜18 の最大18行しかない。
        // keyBy('popularity_rank') で $existingRows[1], $existingRows[2] ...
        // という形でランク番号をキーにして取り出せるようにしておく。
        //
        // isEmpty() で「1行もない = 初回実行」かどうかを判定する。
        // ============================================================
        $existingRows = DB::table('t_horse_odds_finder_popularity_rank_average')
            ->orderBy('popularity_rank')
            ->get()
            ->keyBy('popularity_rank');

        $isFirstRun = $existingRows->isEmpty();
        $this->info($isFirstRun ? '初回実行：全件から計算します' : '2回目以降：増分を足し込みます');
        $this->info('');

        // ============================================================
        // STEP2: ソーステーブル（t_horse_odds_finder_race_result_history）
        //        から集計に使う新しいデータを取得する。
        //
        // ソーステーブルはレコードが膨大になるため古いものは削除する運用。
        // そのため「全件から毎回計算し直す」ことはできない。
        //
        // ■ 初回実行のとき
        //   → まだ end_date がないので全件取得する
        //
        // ■ 2回目以降
        //   → 既存の end_date より「後の日付」のレコードだけ取得する
        //      (すでに集計済みの分は odds_average と count に含まれているので不要)
        //
        // 取得条件：
        //   - popularity_rank が NULL・0 でないもの
        //   - tan（単勝オッズ）が NULL・空・数値以外でないもの
        // ============================================================
        $sourceQuery = DB::table('t_horse_odds_finder_race_result_history')
            ->whereNotNull('popularity_rank')
            ->where('popularity_rank', '!=', 0)
            ->whereNotNull('tan')
            ->where('tan', '!=', '')
            ->whereRaw("tan REGEXP '^[0-9]+(\\.[0-9]+)?$'") // 数値文字列のみ（"取消"などを除外）
            ->select('popularity_rank', 'tan', 'date');

        if (!$isFirstRun) {
            // 既存の end_date を取得（18行あるが全部同じ日付のはずなので max で取る）
            $currentEndDate = $existingRows->max('end_date');
            // end_date より後の日付のレコードだけに絞る
            $sourceQuery->where('date', '>', $currentEndDate);
            $this->info("  現在の end_date : {$currentEndDate}（これより新しいものを対象）");
        }

        $newRecords = $sourceQuery->get();
        $this->info("  取得件数: {$newRecords->count()} 件");
        $this->info('');

        // ============================================================
        // 平日など競馬がない日は新しいレコードが存在しない。
        // その場合は何もしないで終了する（DBへの書き込みゼロ）。
        // cronで毎日回しても、土日レース後にデータが入った日だけ
        // 以降の処理が走る。
        // ============================================================
        if ($newRecords->isEmpty()) {
            $this->warn('追加対象のレコードがありません。処理を終了します。');
            $this->info('========== keiba:summaryPopularityRankAverage 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');
            return;
        }

        // ============================================================
        // STEP3: 取得した新レコードを popularity_rank ごとに配列にまとめる
        //
        // $newGrouped[1] = [2.1, 1.8, 2.5, ...]  ← 1番人気の tan の配列
        // $newGrouped[2] = [4.0, 3.8, ...]        ← 2番人気の tan の配列
        // ...
        //
        // あとで合計（array_sum）と件数（count）を使って平均を出す。
        // ============================================================
        $newGrouped = [];
        foreach ($newRecords as $row) {
            $rank = (int) $row->popularity_rank;
            // 念のため 1〜18 の範囲外は無視する
            if ($rank < 1 || $rank > 18) {
                continue;
            }
            $newGrouped[$rank][] = (float) $row->tan;
        }

        // 新しいレコードの中で一番遅い日付 → これが新しい end_date になる
        $newEndDate   = $newRecords->max('date');
        $updatedCount = 0;

        $this->info('集計・更新中...');

        // ============================================================
        // STEP4: popularity_rank 1〜18 を順番に処理する
        // ============================================================
        for ($rank = 1; $rank <= 18; $rank++) {

            // このランクに新しいデータがあるかどうか
            $hasNewData  = isset($newGrouped[$rank]);
            // このランクがすでにテーブルに存在するかどうか
            $hasExisting = $existingRows->has($rank);

            // ── ケース①：新データなし・既存あり ────────────────────
            // 平均もcountも変わらないが、end_date だけ新しくしておく。
            // （「このデータは ○○ 日時点までを集計したものです」という記録）
            if (!$hasNewData && $hasExisting) {
                DB::table('t_horse_odds_finder_popularity_rank_average')
                    ->where('popularity_rank', $rank)
                    ->update(['end_date' => $newEndDate]);
                continue;
            }

            // ── ケース②：新データなし・既存もなし ──────────────────
            // 何もしない（このランクのレコードは今後来たときに初めて作る）
            if (!$hasNewData) {
                continue;
            }

            // ── ケース③・④：新データあり ────────────────────────────
            // 新しい tan の合計と件数を計算しておく
            $newTanList  = $newGrouped[$rank];           // 今回の tan の配列
            $newSum      = array_sum($newTanList);       // 今回分の合計オッズ
            $newSubCount = count($newTanList);           // 今回分の件数

            if ($hasExisting) {
                // ── ケース③：新データあり・既存あり（通常の更新）────
                //
                // 【加重平均の計算】
                // 古いレコードはソーステーブルから削除されているため、
                // 元の生データを足し直すことはできない。
                // でも「平均 × 件数 = 合計」なので、合計を復元できる。
                //
                // 例）odds_average=4, count=3 なら 合計=4×3=12
                //     新しいデータが 6,8,10 の3件なら 合計=24, count=3
                //     新しい平均 = (12+24) / (3+3) = 6
                //
                $existing    = $existingRows[$rank];
                $existingSum = (float) $existing->odds_average * (int) $existing->count; // 既存の合計を復元
                $totalCount  = (int) $existing->count + $newSubCount;                    // 合計件数
                $totalAverage = round(($existingSum + $newSum) / $totalCount, 1);        // 新しい平均

                DB::table('t_horse_odds_finder_popularity_rank_average')
                    ->where('popularity_rank', $rank)
                    ->update([
                        'odds_average' => (string) $totalAverage,
                        'count'        => $totalCount,
                        // start_date は変えない（最初に決まったら不動）
                        'end_date'     => $newEndDate,
                    ]);

            } else {
                // ── ケース④：新データあり・既存なし（初回INSERT）───
                //
                // このランクがまだテーブルに存在しない。
                // ソーステーブルにある全件（古いものも含む）から計算して INSERT する。
                // start_date はこのタイミングで決まり、以降は変更しない。
                //
                $allForRank = DB::table('t_horse_odds_finder_race_result_history')
                    ->where('popularity_rank', $rank)
                    ->whereNotNull('tan')
                    ->where('tan', '!=', '')
                    ->whereRaw("tan REGEXP '^[0-9]+(\\.[0-9]+)?$'")
                    ->select('tan', 'date')
                    ->get();

                $startDate    = $allForRank->min('date');  // 一番古い日付 → start_date（以降不動）
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
