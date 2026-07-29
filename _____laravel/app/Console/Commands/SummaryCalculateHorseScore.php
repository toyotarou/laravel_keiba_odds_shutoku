<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SummaryCalculateHorseScore
 *
 * 【概要】
 *   t_horse_odds_finder_shutsuba_history から出走4回以上の馬を抽出し、
 *   各馬の逆順位率スコアを計算して t_horse_odds_finder_horse_scores に保存する。
 *   実行前にスコアテーブルを TRUNCATE するため、常に最新状態で全再計算される。
 *
 * 【スコア算出式】
 *   逆順位率 = 1 - (着順 - 1) / (頭数 - 1)
 *   ・1着 → 1.0（最高）、最下位 → 0.0（最低）
 *   ・全レースの平均を 100 倍して score に保存する
 *
 * 【処理フロー】
 *   【ブロック 1】スコアテーブルを TRUNCATE（毎回全再計算）
 *   【ブロック 2】出走4回以上の馬を一覧取得
 *   【ブロック 3】各馬のスコアを計算してDB保存
 *   【ブロック 4】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【使い方】
 *   php artisan keiba:summaryCalculateHorseScore
 */
class SummaryCalculateHorseScore extends Command
{
    protected $signature   = 'keiba:summaryCalculateHorseScore';
    protected $description = '出走4回以上の馬の逆順位率スコアを計算してDBに保存する';

    public function handle(): void
    {
        $now        = microtime(true);
        $savedCount = 0;
        $status     = '不明な理由で終了';

        try {
            $this->info('');
            $this->info('========== keiba:summaryCalculateHorseScore 開始 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 1】スコアテーブルを TRUNCATE（毎回全再計算）
            // ─────────────────────────────────────────────────────────────
            $this->info('[ブロック 1] t_horse_odds_finder_horse_scores を初期化中...');
            DB::statement('TRUNCATE TABLE t_horse_odds_finder_horse_scores');
            $this->info('  → TRUNCATE 完了');
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 2】出走4回以上の馬を一覧取得
            // ─────────────────────────────────────────────────────────────
            $this->info('[ブロック 2] 出走4回以上の馬を集計中...');
            $horses      = DB::select('SELECT name, COUNT(id) AS count FROM t_horse_odds_finder_shutsuba_history GROUP BY name HAVING count >= 4');
            $totalHorses = count($horses);
            $this->info("  → {$totalHorses} 頭を検出");
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 3】各馬のスコアを計算してDB保存
            //   逆順位率 = 1 - (着順 - 1) / (頭数 - 1)
            //   全レース平均 × 100 を score として保存する。
            // ─────────────────────────────────────────────────────────────
            $this->info('[ブロック 3] スコア計算・保存中...');

            foreach ($horses as $horse) {
                $races = DB::table('t_horse_odds_finder_shutsuba_history')
                    ->where('name', $horse->name)
                    ->orderBy('date')
                    ->get();

                $scores = $races->map(fn($r) => 1 - ($r->finishing_position - 1) / ($r->num_horses - 1))->toArray();
                $score  = (int) round(array_sum($scores) / count($scores) * 100);

                DB::table('t_horse_odds_finder_horse_scores')->insert([
                    'name'  => $horse->name,
                    'count' => $horse->count,
                    'score' => $score,
                ]);

                $savedCount++;
                $this->info("  [{$savedCount}/{$totalHorses}] {$horse->name} → score: {$score} ({$horse->count}走)");
            }

            $this->info('');
            $status = '正常終了';

        } finally {
            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】完了サマリー・WebPush 通知（finally で必ず実行）
            // ─────────────────────────────────────────────────────────────
            $totalElapsed = round(microtime(true) - $now, 1);

            $this->info("終了理由   : {$status}");
            $this->info("保存馬数   : {$savedCount} 頭");
            $this->info("処理時間   : {$totalElapsed} 秒");
            $this->info('');
            $this->info('========== keiba:summaryCalculateHorseScore 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryCalculateHorseScore::handle\n{$status}\n保存馬数:{$savedCount}頭");
        }
    }
}
