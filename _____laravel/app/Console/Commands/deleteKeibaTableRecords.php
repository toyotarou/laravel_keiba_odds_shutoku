<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DeleteKeibaTableRecords
 *
 * 【概要】
 *   開発・デバッグ用のリセットコマンド。
 *   当シーズンの競馬データをすべてクリアして初期状態に戻す。
 *   TRUNCATE で高速削除し、関連ログファイルも合わせて削除する。
 *   ★本番環境での誤実行に注意。実行後は ImportKeibaSchedule から再投入が必要。
 *
 * 【処理フロー】
 *   【ブロック 1】テーブルを TRUNCATE（8テーブル）
 *   【ブロック 2】ログファイルを削除（15ファイル）
 *   【ブロック 3】WebPush 通知
 *
 * 【TRUNCATE 対象テーブル】
 *   - t_horse_odds_finder_horses       （馬情報）
 *   - t_horse_odds_finder_odds         （JRA 単勝・複勝オッズ）
 *   - t_horse_odds_finder_odds_get_timing（オッズ取得タイミング記録）
 *   - t_horse_odds_finder_odds_wide    （ワイドオッズ）
 *   - t_horse_odds_finder_race_results （レース結果）
 *   - t_horse_odds_finder_races        （レース情報）
 *   - t_horse_odds_finder_schedules    （開催スケジュール）
 *   ※ netkeiba 系テーブルはコメントアウト（廃止済み）
 *
 * 【使い方】
 *   php artisan keiba:deleteKeibaTableRecords
 */
class DeleteKeibaTableRecords extends Command
{
    protected $signature = 'keiba:deleteKeibaTableRecords';
    protected $description = 'テーブルデータを削除する';

    public function handle()
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】テーブルを TRUNCATE（8テーブル）
        //   TRUNCATE は DELETE より高速で AUTO_INCREMENT もリセットされる。
        //   netkeiba 系テーブルはスクレイピング廃止につきコメントアウト済み。
        // ─────────────────────────────────────────────────────────────────
        $this->info('テーブルデータ削除処理 ── 開始');

        DB::statement('TRUNCATE TABLE t_horse_odds_finder_horses');
        $this->info('t_horse_odds_finder_horses をtruncateしました。');

        // DB::statement('TRUNCATE TABLE t_horse_odds_finder_netkeiba_odds');   // 廃止済み
        // DB::statement('TRUNCATE TABLE t_horse_odds_finder_netkeiba_races');  // 廃止済み

        DB::statement('TRUNCATE TABLE t_horse_odds_finder_odds');
        $this->info('t_horse_odds_finder_odds をtruncateしました。');

        DB::statement('TRUNCATE TABLE t_horse_odds_finder_odds_get_timing');
        $this->info('t_horse_odds_finder_odds_get_timing をtruncateしました。');

        DB::statement('TRUNCATE TABLE t_horse_odds_finder_odds_wide');
        $this->info('t_horse_odds_finder_odds_wide をtruncateしました。');

        DB::statement('TRUNCATE TABLE t_horse_odds_finder_race_results');
        $this->info('t_horse_odds_finder_race_results をtruncateしました。');

        DB::statement('TRUNCATE TABLE t_horse_odds_finder_races');
        $this->info('t_horse_odds_finder_races をtruncateしました。');

        DB::statement('TRUNCATE TABLE t_horse_odds_finder_schedules');
        $this->info('t_horse_odds_finder_schedules をtruncateしました。');

        $this->info('テーブルデータ削除処理 ── 完了');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】ログファイルを削除（15ファイル）
        //   file_exists() で存在チェックしてから unlink() する。
        //   存在しないファイルはスキップ（エラーにしない）。
        // ─────────────────────────────────────────────────────────────────
        $logFiles = [
            '/var/www/horse_odds_finder/scripts/keibaOddsGetJraRaceResult.log',
            '/var/www/horse_odds_finder/scripts/keibaOddsGetSchedule.log',
            '/var/www/horse_odds_finder/storage/logs/importSchedule.log',
            '/var/www/horse_odds_finder/storage/logs/importRace.log',
            '/var/www/horse_odds_finder/storage/logs/importBaseOdds.log',
            '/var/www/horse_odds_finder/storage/logs/importOdds.log',
            '/var/www/horse_odds_finder/storage/logs/importRaceResult.log',
            '/var/www/horse_odds_finder/storage/logs/importOddsWide.log',
            '/var/www/horse_odds_finder/storage/logs/summaryKeibaInfo.log',
            '/var/www/horse_odds_finder/storage/logs/importJraRaceResult.log',
            '/var/www/horse_odds_finder/storage/logs/importJraRaceOneResult.log',
            '/var/www/horse_odds_finder/storage/logs/importRaceResultHistory.log',
            '/var/www/horse_odds_finder/storage/logs/SummaryHistoryPopularityRank.log',
            '/var/www/horse_odds_finder/storage/logs/summaryHistoryFinishingPosition.log',
            '/var/www/horse_odds_finder/storage/logs/importRaceResultPayout.log',
            '/var/www/horse_odds_finder/storage/logs/importShutsubaHistory.log'
        ];
        foreach ($logFiles as $logFile) {
            if (file_exists($logFile)) {
                unlink($logFile);
                $this->info("{$logFile} を削除しました。");
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】WebPush 通知
        // ─────────────────────────────────────────────────────────────────
        (new WebPushService())->sendPushNotifierDeveloperNews('develop', "DeleteKeibaTableRecords::handle");

        return 0;
    }
}
