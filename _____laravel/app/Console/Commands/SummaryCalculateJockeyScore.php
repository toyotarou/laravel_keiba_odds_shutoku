<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SummaryCalculateJockeyScore
 *
 * 【概要】
 *   t_horse_odds_finder_shutsuba_history から騎乗20回以上の騎手を抽出し、
 *   各騎手の逆順位率スコアを計算して t_horse_odds_finder_jockey_scores に保存する。
 *   実行前にスコアテーブルを TRUNCATE するため、常に最新状態で全再計算される。
 *   騎手名は先頭の ▲★☆△◇ 等の記号を除去してから集計・保存する。
 *
 * 【スコア算出式】
 *   逆順位率 = 1 - (着順 - 1) / (頭数 - 1)
 *   ・1着 → 1.0（最高）、最下位 → 0.0（最低）
 *   ・全レースの平均を 100 倍して score に保存する（int型）
 *
 * 【処理フロー】
 *   【ブロック 1】スコアテーブルを TRUNCATE（毎回全再計算）
 *   【ブロック 2】騎乗20回以上の騎手を一覧取得
 *   【ブロック 3】各騎手のスコアを計算してDB保存
 *   【ブロック 4】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【使い方】
 *   php artisan keiba:summaryCalculateJockeyScore
 */
class SummaryCalculateJockeyScore extends Command
{
    protected $signature   = 'keiba:summaryCalculateJockeyScore';
    protected $description = '騎乗20回以上の騎手の逆順位率スコアを計算してDBに保存する';

    public function handle(): void
    {
        $now        = microtime(true);
        $savedCount = 0;
        $status     = '不明な理由で終了';

        try {
            $this->info('');
            $this->info('========== keiba:summaryCalculateJockeyScore 開始 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 1】スコアテーブルを TRUNCATE（毎回全再計算）
            // ─────────────────────────────────────────────────────────────
            $this->info('[ブロック 1] t_horse_odds_finder_jockey_scores を初期化中...');
            DB::statement('TRUNCATE TABLE t_horse_odds_finder_jockey_scores');
            $this->info('  → TRUNCATE 完了');
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 2】騎乗20回以上の騎手を一覧取得
            //   jockey フィールド先頭の ▲★☆△◇ 等を REGEXP_REPLACE で除去して集計する。
            // ─────────────────────────────────────────────────────────────
            $this->info('[ブロック 2] 騎乗20回以上の騎手を集計中...');
            $jockeys = DB::select("
                SELECT
                    TRIM(REGEXP_REPLACE(jockey, '^[▲★☆△◇]+', '')) AS name,
                    COUNT(id) AS count
                FROM t_horse_odds_finder_shutsuba_history
                WHERE TRIM(REGEXP_REPLACE(jockey, '^[▲★☆△◇]+', '')) != ''
                GROUP BY TRIM(REGEXP_REPLACE(jockey, '^[▲★☆△◇]+', ''))
                HAVING count >= 20
                ORDER BY count DESC
            ");
            $totalJockeys = count($jockeys);
            $this->info("  → {$totalJockeys} 名を検出");
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 3】各騎手のスコアを計算してDB保存
            //   逆順位率 = 1 - (着順 - 1) / (頭数 - 1)
            //   全レース平均 × 100 を score（int）として保存する。
            // ─────────────────────────────────────────────────────────────
            $this->info('[ブロック 3] スコア計算・保存中...');

            foreach ($jockeys as $jockey) {
                $races = DB::table('t_horse_odds_finder_shutsuba_history')
                    ->whereRaw("TRIM(REGEXP_REPLACE(jockey, '^[▲★☆△◇]+', '')) = ?", [$jockey->name])
                    ->orderBy('date')
                    ->get();

                $scores = $races->map(fn($r) => 1 - ($r->finishing_position - 1) / ($r->num_horses - 1))->toArray();
                $score  = (int) round(array_sum($scores) / count($scores) * 100);

                DB::table('t_horse_odds_finder_jockey_scores')->insert([
                    'name'  => $jockey->name,
                    'count' => $jockey->count,
                    'score' => $score,
                ]);

                $savedCount++;
                $this->info("  [{$savedCount}/{$totalJockeys}] {$jockey->name} → score: {$score} ({$jockey->count}騎乗)");
            }

            $this->info('');
            $status = '正常終了';

        } finally {
            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】完了サマリー・WebPush 通知（finally で必ず実行）
            // ─────────────────────────────────────────────────────────────
            $totalElapsed = round(microtime(true) - $now, 1);

            $this->info("終了理由   : {$status}");
            $this->info("保存騎手数 : {$savedCount} 名");
            $this->info("処理時間   : {$totalElapsed} 秒");
            $this->info('');
            $this->info('========== keiba:summaryCalculateJockeyScore 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryCalculateJockeyScore::handle\n{$status}\n保存騎手数:{$savedCount}名");
        }
    }
}
