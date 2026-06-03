<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteKeibaTableRecords extends Command
{
    protected $signature = 'keiba:deleteKeibaTableRecords';
    protected $description = 'テーブルデータを削除する';

    public function handle()
    {
        $this->info('テーブルデータ削除処理 ── 開始');

        // t_horse_odds_finder_schedules をtruncateする
        DB::statement('TRUNCATE TABLE t_horse_odds_finder_schedules');
        $this->info('t_horse_odds_finder_schedules をtruncateしました。');

        // t_horse_odds_finder_races をtruncateする
        DB::statement('TRUNCATE TABLE t_horse_odds_finder_races');
        $this->info('t_horse_odds_finder_races をtruncateしました。');

        // t_horse_odds_finder_horses をtruncateする
        DB::statement('TRUNCATE TABLE t_horse_odds_finder_horses');
        $this->info('t_horse_odds_finder_horses をtruncateしました。');

        // t_horse_odds_finder_odds をtruncateする
        DB::statement('TRUNCATE TABLE t_horse_odds_finder_odds');
        $this->info('t_horse_odds_finder_odds をtruncateしました。');
        
        // t_horse_odds_finder_netkeiba_races をtruncateする
        DB::statement('TRUNCATE TABLE t_horse_odds_finder_netkeiba_races');
        $this->info('t_horse_odds_finder_netkeiba_races をtruncateしました。');

        // t_horse_odds_finder_netkeiba_odds をtruncateする
        DB::statement('TRUNCATE TABLE t_horse_odds_finder_netkeiba_odds');
        $this->info('t_horse_odds_finder_netkeiba_odds をtruncateしました。');

        // t_horse_odds_finder_odds_get_timing をtruncateする
        DB::statement('TRUNCATE TABLE t_horse_odds_finder_odds_get_timing');
        $this->info('t_horse_odds_finder_odds_get_timing をtruncateしました。');
        
        // t_horse_odds_finder_odds_wide をtruncateする
        DB::statement('TRUNCATE TABLE t_horse_odds_finder_odds_wide');
        $this->info('t_horse_odds_finder_odds_wide をtruncateしました。');
        
        $this->info('テーブルデータ削除処理 ── 完了');



        // ログファイルを削除する
        $logFiles = [
            '/var/www/horse_odds_finder/storage/logs/deleteKeibaTableRecords.log',
            '/var/www/horse_odds_finder/storage/logs/importSchedule.log',
            '/var/www/horse_odds_finder/storage/logs/importRace.log',
            '/var/www/horse_odds_finder/storage/logs/importBaseOdds.log',
            '/var/www/horse_odds_finder/storage/logs/importOdds.log',
            '/var/www/horse_odds_finder/storage/logs/importRaceResult.log',
            '/var/www/horse_odds_finder/storage/logs/importOddsWide.log'
        ];
        foreach ($logFiles as $logFile) {
            if (file_exists($logFile)) {
                unlink($logFile);
                $this->info("{$logFile} を削除しました。");
            }
        }
        
        return 0;
    }
}
