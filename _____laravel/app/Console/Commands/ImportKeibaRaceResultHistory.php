<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 指定年月の全開催・全レースの最終単勝・複勝オッズを取得し
 * t_horse_odds_finder_race_result_history に保存する。
 *
 * 使い方:
 *   php artisan keiba:importRaceResultHistory --yearmonth=2021-01
 */
class ImportKeibaRaceResultHistory extends Command
{
    protected $signature   = 'keiba:importRaceResultHistory {--yearmonth= : 対象年月 (例: 2021-01)}';
    protected $description = '指定年月の全開催・全レースの最終オッズを取得してDBに保存する';

    public function handle(): void
    {
        // ── 引数チェック ──────────────────────────────────────────────
        $yearmonth = $this->option('yearmonth');
        if (!$yearmonth || !preg_match('/^\d{4}-\d{2}$/', $yearmonth)) {
            $this->error('--yearmonth=YYYY-MM の形式で指定してください。例: --yearmonth=2021-01');
            return;
        }

        // ── 多重起動防止 ─────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importRaceResultHistory_' . str_replace('-', '', $yearmonth) . '.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $now     = microtime(true);
        $script  = base_path('scripts/keibaOddsGetRaceResultHistory.mjs');
        $logFile = base_path('scripts/keibaOddsGetRaceResultHistory.log');
        $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';

        $this->info('');
        $this->info('========== keiba:importRaceResultHistory 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('対象年月     : ' . $yearmonth);
        $this->info('スクリプト   : ' . $script);
        $this->info('ログファイル : ' . $logFile);
        $this->info('');

        // ── Step 1: --list-only で開催一覧を取得 ─────────────────────
        $this->info('[Step 1] 開催一覧を取得中...');

        $listCommand = 'timeout 120 ' . $nodeBin . ' ' . escapeshellarg($script)
            . ' --yearmonth=' . escapeshellarg($yearmonth)
            . ' --list-only'
            . ' 2>>' . escapeshellarg($logFile);

        $this->info('  実行: ' . $listCommand);

        $listOutput = shell_exec($listCommand);
        $listJson   = json_decode($listOutput, true);

        if (!$listJson || empty($listJson['kaisaiList'])) {
            $this->error('開催一覧の取得に失敗しました。');
            $this->error('Node.js 出力: ' . $listOutput);
            return;
        }

        $kaisaiList  = $listJson['kaisaiList'];
        $totalKaisai = count($kaisaiList);

        $this->info("  → {$totalKaisai} 開催を検出: " . implode(', ', $kaisaiList));
        $this->info('');

        // ── Step 2: 各開催を順番に処理 ───────────────────────────────
        $kaisaiIndex = 0;
        $totalSaved  = 0;
        $failedList  = [];

        foreach ($kaisaiList as $kaisai) {
            $kaisaiIndex++;

            $this->info('══════════════════════════════════════════════════════');
            $this->info("[{$kaisaiIndex}/{$totalKaisai}] {$kaisai} 処理開始");
            $this->info('══════════════════════════════════════════════════════');

            $command = 'timeout 300 ' . $nodeBin . ' ' . escapeshellarg($script)
                . ' --yearmonth=' . escapeshellarg($yearmonth)
                . ' --kaisai="' . $kaisai . '"'
                . ' 2>>' . escapeshellarg($logFile);

            $this->info('  実行: ' . $command);
            $this->info('');

            $kaisaiStart = microtime(true);
            $result      = null;
            $output      = '';
            $maxRetry    = 3;

            for ($retry = 1; $retry <= $maxRetry; $retry++) {
                $this->info("  [試行 {$retry}/{$maxRetry}] Node.js 実行中...");
                $output = shell_exec($command);
                $result = json_decode($output, true);

                if ($result && !empty($result['races'])) {
                    $this->info("  [試行 {$retry}/{$maxRetry}] 取得成功！");
                    break;
                }

                $this->warn("  [試行 {$retry}/{$maxRetry}] 取得失敗。");
                $this->warn('  Node.js 出力: ' . substr($output ?? '', 0, 500));

                if ($retry < $maxRetry) {
                    $this->warn("  5秒後にリトライします...");
                    sleep(5);
                }
            }

            $elapsed = round(microtime(true) - $kaisaiStart, 1);

            if (!$result || empty($result['races'])) {
                $this->error("  [FAIL] {$kaisai} → 取得失敗 ({$elapsed}秒)");
                $failedList[] = $kaisai;
                $this->info('');
                continue;
            }

            $raceCount = count($result['races']);
            $this->info("  取得完了: {$raceCount} レース / 日付: {$result['date']} ({$elapsed}秒)");
            $this->info('');

            // ── DB保存 ───────────────────────────────────────────────
            $saved = 0;
            foreach ($result['races'] as $raceData) {
                $horseCount = count($raceData['horses']);
                $this->info("    {$raceData['race']}R 「{$raceData['race_name']}」 → {$horseCount}頭 保存中...");

                foreach ($raceData['horses'] as $horse) {
                    $key = [
                        'date'       => $result['date'],
                        'kaisuu'     => $result['kaisuu'],
                        'basho_code' => $result['basho_code'],
                        'day'        => $result['day'],
                        'race'       => $raceData['race'],
                        'num'        => $horse['num'],
                    ];
                    $data = [
                        'basho'     => $result['basho'],
                        'race_name' => $raceData['race_name'],
                        'name'      => $horse['name'],
                        'tan'       => $horse['tan'],
                        'fuku_min'  => $horse['fuku_min'],
                        'fuku_max'  => $horse['fuku_max'],
                        'finish_rank'      => 0,
                    ];

                    DB::table('t_horse_odds_finder_race_result_history')
                        ->updateOrInsert($key, $data);

                    $saved++;
                }

                $this->info("    {$raceData['race']}R → 保存完了");
            }

            $totalSaved += $saved;
            $this->info('');
            $this->info("  [{$kaisaiIndex}/{$totalKaisai}] {$kaisai} 完了 → {$saved} 頭保存");
            $this->info('');
        }

        // ── 完了サマリー ─────────────────────────────────────────────
        $totalElapsed = round(microtime(true) - $now, 1);
        $failedCount  = count($failedList);

        $this->info('');
        $this->info('========== keiba:importRaceResultHistory 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info("対象年月     : {$yearmonth}");
        $this->info("処理開催数   : {$totalKaisai} 開催");
        $this->info("保存頭数合計 : {$totalSaved} 頭");
        $this->info("失敗開催数   : {$failedCount} 件" . ($failedCount > 0 ? ' ← 要確認' : ''));
        foreach ($failedList as $f) {
            $this->error("  [FAIL] {$f}");
        }
        $this->info("処理時間     : {$totalElapsed} 秒");
        $this->info('');
    }
}
