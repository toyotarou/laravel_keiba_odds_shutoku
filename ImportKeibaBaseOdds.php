<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 土曜7時に1回だけ実行する。
 * 土日両日分の全レースのオッズを取得し、minutes_before_start=999 でDBに保存する。
 * レコードが既に存在する場合はオッズ値を上書き更新する。
 *
 * cron設定:
 *   0 7 * * 6 php /var/www/horse_odds_finder/artisan keiba:importBaseOdds >> /var/www/horse_odds_finder/storage/logs/importBaseOdds.log 2>&1
 */
class ImportKeibaBaseOdds extends Command
{
    protected $signature = 'keiba:importBaseOdds';
    protected $description = '土曜7時に全レースのベースオッズを取得してDBに保存する';

    public function handle(): void
    {
        $now     = microtime(true);
        $script  = base_path('scripts/keibaOddsGetTanpuku.mjs');
        $logFile = base_path('scripts/keibaOddsGetTanpuku.log');
        $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';

        $this->info('');
        $this->info('========== keiba:importBaseOdds 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('スクリプト   : ' . $script);
        $this->info('node パス    : ' . $nodeBin);
        $this->info('ログファイル : ' . $logFile);
        $this->info('');

        // 土日両日分の全レースを取得（日付フィルタなし）
        $races      = DB::table('t_horse_odds_finder_races')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $totalRaces = count($races);
        $this->info("対象レース数: {$totalRaces} 件");

        if ($totalRaces === 0) {
            $this->info('対象レースが0件のため終了します。');
            return;
        }

        $raceIndex    = 0;
        $totalSaved   = 0;
        $failedRaces  = [];

        foreach ($races as $race) {
            $raceIndex++;

            $this->info("──────────────────────────────────────");
            $this->info("[{$raceIndex}/{$totalRaces}] {$race->basho_name} {$race->race}R 「{$race->race_name}」 {$race->date} {$race->start_time}");

            // Node.js でオッズをスクレイピング
            $command = $nodeBin . ' ' . escapeshellarg($script)
                . ' ' . escapeshellarg($race->date)
                . ' ' . escapeshellarg($race->kaisuu)
                . ' ' . escapeshellarg($race->basho)
                . ' ' . escapeshellarg($race->race)
                . ' 2>>' . escapeshellarg($logFile);

            $this->info("  実行コマンド: {$command}");

            $raceStart = microtime(true);
            $odds      = null;
            $output    = '';
            $maxRetry  = 3;
            for ($retry = 1; $retry <= $maxRetry; $retry++) {
                $output = shell_exec($command);
                $odds   = json_decode($output, true);
                if ($odds) break;
                if ($retry < $maxRetry) {
                    $this->warn("  [RETRY {$retry}/{$maxRetry}] オッズ取得失敗。5秒後にリトライします...");
                    sleep(5);
                }
            }
            $elapsed = round((microtime(true) - $raceStart) * 1000);

            if (!$odds) {
                $this->error("  [FAIL] オッズ取得失敗 ({$elapsed}ms)");
                $this->error("  Node.js 出力: " . $output);
                $failedRaces[] = "{$race->basho_name} {$race->race}R ({$race->date})";
                continue;
            }

            $horseCount = count($odds);
            $this->info("  オッズ取得成功 → {$horseCount} 頭分 ({$elapsed}ms)");

            $saved = 0;
            foreach ($odds as $horse) {
                $key = [
                    'date'                 => $race->date,
                    'kaisuu'               => $race->kaisuu,
                    'basho'                => $race->basho,
                    'day'                  => $race->day,
                    'race'                 => $race->race,
                    'num'                  => $horse['num'],
                    'minutes_before_start' => 999,
                ];
                $data = [
                    'odds'     => $horse['tan'],
                    'fuku_min' => $horse['fuku_min'],
                    'fuku_max' => $horse['fuku_max'],
                ];

                $exists = DB::table('t_horse_odds_finder_odds')->where($key)->exists();

                if ($exists) {
                    DB::table('t_horse_odds_finder_odds')->where($key)->update($data);
                } else {
                    DB::table('t_horse_odds_finder_odds')->insert(array_merge($key, $data));
                }
                $saved++;
            }

            $totalSaved += $saved;
            $this->info("  DB保存完了 → {$saved} 頭分");
        }

        $totalElapsed = round(microtime(true) - $now, 1);
        $failedCount  = count($failedRaces);

        $this->info('');
        $this->info('========== keiba:importBaseOdds 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info("処理レース数 : {$totalRaces} 件");
        $this->info("保存頭数合計 : {$totalSaved} 頭");
        $this->info("失敗レース数 : {$failedCount} 件" . ($failedCount > 0 ? ' ← 要確認' : ''));
        foreach ($failedRaces as $f) {
            $this->error("  [FAIL] {$f}");
        }
        $this->info("処理時間     : {$totalElapsed} 秒");
        $this->info('');
    }
}
