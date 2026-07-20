<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SummaryPopularityRankMedian
 *
 * 【概要】
 *   t_horse_odds_finder_races のレースごとに、
 *   popularity_ratio_table_ids に紐づく類似レース群から
 *   t_horse_odds_finder_race_result_history の tan を参照して
 *   人気順位別のオッズ中央値を計算し
 *   t_horse_odds_finder_popularity_rank_median テーブルに INSERT する。
 *
 * 【前提】
 *   t_horse_odds_finder_popularity_rank_median は呼び出し前に TRUNCATE 済みのため
 *   増分管理は不要。全件を毎回 INSERT する。
 *   ただし多重起動や再実行に備え、INSERT 前に重複チェックを行う。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】STEP1: popularity_ratio_table_ids が設定済みの全レースを取得
 *   【ブロック 3】レースなし → 早期終了
 *   【ブロック 4】STEP2: レースごとに中央値を計算して INSERT
 *       ① 重複チェック（INSERT済みならスキップ）
 *       ② popularity_ratio_table_ids をパイプ分割して類似レース ID 一覧を得る
 *       ③ t_horse_odds_finder_races_popularity_ratio から類似レースの識別子を一括取得
 *       ④ t_horse_odds_finder_race_result_history から popularity_rank 別の tan を収集
 *       ⑤ ランクごとにソートして中央値を計算
 *       ⑥ INSERT データを組み立てて INSERT
 *   【ブロック 5】完了ログ・WebPush 通知
 *
 * 【中央値の計算方法】
 *   類似レース群の tan をランク別に収集し、昇順ソート後に中央値を算出する。
 *   要素数が奇数なら中央要素、偶数なら中央2値の平均（小数点1桁丸め）。
 *
 * 【使い方】
 *   php artisan keiba:summaryPopularityRankMedian
 */
class SummaryPopularityRankMedian extends Command
{
    protected $signature   = 'keiba:summaryPopularityRankMedian';
    protected $description = 't_horse_odds_finder_popularity_rank_median に人気順別のオッズ中央値を集計する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        //   cron で毎日動かすので前回の処理がまだ終わっていないときに
        //   二重で起動しないようロックファイルを使う。
        //   register_shutdown_function で正常終了・異常終了を問わずロックを解放する。
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_summaryPopularityRankMedian.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $now = microtime(true);
        $this->info('');
        $this->info('========== keiba:summaryPopularityRankMedian 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】STEP1: 全レースを t_horse_odds_finder_races から取得
        //   popularity_ratio_table_ids が NULL のレースは類似レース群が未設定のため除外する。
        //   date → kaisuu → basho → day → race の順で昇順ソートする。
        // ─────────────────────────────────────────────────────────────────
        $races = DB::table('t_horse_odds_finder_races')
            ->whereNotNull('popularity_ratio_table_ids')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->get();

        $this->info("  取得レース数: {$races->count()} 件");
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】レースなし → 早期終了
        //   popularity_ratio_table_ids が設定済みのレースが1件もない場合は処理不要。
        //   DB への書き込みゼロで終了する（cron で毎日回しても安全）。
        // ─────────────────────────────────────────────────────────────────
        if ($races->isEmpty()) {
            $this->warn('処理対象のレースがありません。処理を終了します。');
            $this->info('========== keiba:summaryPopularityRankMedian 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');
            (new WebPushService())->sendPushNotifierDeveloperNews(
                'develop',
                'SummaryPopularityRankMedian::handle' . "\n" . '処理対象なし（空振り）'
            );
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 4】STEP2: レースごとに中央値を計算して INSERT
        //   ① 重複チェック（INSERT 済みならスキップ）
        //      → 重い処理の前に弾くことで、無駄なクエリを発生させない
        //   ② popularity_ratio_table_ids をパイプ分割して類似レース ID 一覧を得る
        //   ③ t_horse_odds_finder_races_popularity_ratio から類似レースの識別子を一括取得
        //   ④ t_horse_odds_finder_race_result_history から popularity_rank 別の tan を収集
        //      → 数値以外（"取消"など）は REGEXP で除外する
        //      → 1〜18 の範囲外の popularity_rank は念のため無視する
        //   ⑤ ランクごとに tan を昇順ソートして中央値を計算
        //   ⑥ INSERT データを組み立てて INSERT
        //      → median_01〜median_18 は取得できたランク分だけセットし、残りは NULL
        // ─────────────────────────────────────────────────────────────────
        $this->info('集計・挿入中...');
        $insertedCount = 0;

        foreach ($races as $race) {

            // ① 重複チェック
            //   date / kaisuu / basho / day / race の組み合わせで既存レコードを確認する。
            //   存在する場合は ②以降の重いクエリをすべてスキップして次のレースへ進む。
            $exists = DB::table('t_horse_odds_finder_popularity_rank_median')
                ->where('date',   $race->date)
                ->where('kaisuu', $race->kaisuu)
                ->where('basho',  $race->basho)
                ->where('day',    $race->day)
                ->where('race',   $race->race)
                ->exists();

            if ($exists) {
                continue;
            }

            // ② popularity_ratio_table_ids をパイプで分割（空文字は除外）
            //   例: "123|456|789" → [123, 456, 789]
            $prtIds = array_filter(explode('|', $race->popularity_ratio_table_ids), fn($v) => $v !== '');

            // ③ 類似レース群の識別子を t_horse_odds_finder_races_popularity_ratio から一括取得
            //   各レコードには date / kaisuu / basho / day / race が格納されており、
            //   これを使って ④ で race_result_history を引く。
            $ratioRows = DB::table('t_horse_odds_finder_races_popularity_ratio')
                ->whereIn('id', $prtIds)
                ->get();

            // ④ 類似レース群の race_result_history から popularity_rank 別に tan を収集
            //   basho_code には t_horse_odds_finder_races_popularity_ratio の basho を使う。
            //   tan が数値文字列でないもの（"取消"など）は REGEXP で除外する。
            $tanByRank = [];
            foreach ($ratioRows as $ratio) {
                $results = DB::table('t_horse_odds_finder_race_result_history')
                    ->where('date',       $ratio->date)
                    ->where('kaisuu',     $ratio->kaisuu)
                    ->where('basho_code', $ratio->basho)
                    ->where('day',        $ratio->day)
                    ->where('race',       $ratio->race)
                    ->whereNotNull('popularity_rank')
                    ->where('popularity_rank', '!=', 0)
                    ->whereNotNull('tan')
                    ->where('tan', '!=', '')
                    ->whereRaw("tan REGEXP '^[0-9]+(\\.[0-9]+)?$'")
                    ->select('popularity_rank', 'tan')
                    ->get();

                foreach ($results as $result) {
                    $rank = (int) $result->popularity_rank;
                    if ($rank < 1 || $rank > 18) {
                        continue;
                    }
                    $tanByRank[$rank][] = (float) $result->tan;
                }
            }

            // ⑤ ランクごとに tan を昇順ソートして中央値を計算
            //   sort() で破壊的ソートをしてから calcMedian() に渡す。
            $medianByRank = [];
            foreach ($tanByRank as $rank => $odds) {
                sort($odds);
                $medianByRank[$rank] = $this->calcMedian($odds);
            }

            // ⑥ INSERT データを組み立てて INSERT
            //   レース基本情報 + median_01〜median_18（取得できたランク分のみセット）
            $insert = [
                'date'       => $race->date,
                'kaisuu'     => $race->kaisuu,
                'basho'      => $race->basho,
                'basho_name' => $race->basho_name,
                'day'        => $race->day,
                'race'       => $race->race,
                'race_name'  => $race->race_name,
            ];
            foreach ($medianByRank as $rank => $median) {
                $insert[sprintf('median_%02d', $rank)] = $median;
            }

            DB::table('t_horse_odds_finder_popularity_rank_median')->insert($insert);
            $insertedCount++;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 5】完了ログ・WebPush 通知
        // ─────────────────────────────────────────────────────────────────
        $elapsed = round(microtime(true) - $now, 1);
        $this->info('');
        $this->info('========== keiba:summaryPopularityRankMedian 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('挿入レース数 : ' . $insertedCount . ' 件');
        $this->info('処理時間     : ' . $elapsed . ' 秒');
        $this->info('');

        (new WebPushService())->sendPushNotifierDeveloperNews(
            'develop',
            "SummaryPopularityRankMedian::handle\n挿入:{$insertedCount}件、time:{$elapsed}"
        );
    }

    /**
     * ソート済み配列から中央値を返す。
     *   奇数個: 中央要素をそのまま返す
     *   偶数個: 中央2値の平均を小数点1桁で丸めて返す
     *   空配列: 0.0 を返す
     */
    private function calcMedian(array $sorted): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (float) $sorted[$mid];
        }
        return round(((float) $sorted[$mid - 1] + (float) $sorted[$mid]) / 2, 1);
    }
}
