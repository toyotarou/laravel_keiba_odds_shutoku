<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 指定年月の t_horse_odds_finder_race_result_history の finishing_position を埋める。
 *
 * finishing_position の値の意味:
 *   NULL / 0  : 未取得（cron の再処理対象）
 *   1〜       : 着順
 *   -1        : 中止・除外・取消・失格等（再処理対象外）
 *
 * 使い方:
 *   php artisan keiba:summaryHistoryFinishingPosition --yearmonth=2023-01
 *   php artisan keiba:summaryHistoryFinishingPosition          # 当月を対象
 */
class SummaryHistoryFinishingPosition extends Command
{
    protected $signature   = 'keiba:summaryHistoryFinishingPosition {--yearmonth= : 対象年月 (例: 2023-01、省略時は当月)}';
    protected $description = '指定年月の全開催・全レースの着順をDBに更新する';

    public function handle(): void
    {
        // ── 引数チェック ──────────────────────────────────────────────
        $yearmonth = $this->option('yearmonth') ?: date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $yearmonth)) {
            $this->error('--yearmonth=YYYY-MM の形式で指定してください。例: --yearmonth=2023-01');
            return;
        }

        // ── 多重起動防止 ─────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_summaryHistoryFinishingPosition_' . str_replace('-', '', $yearmonth) . '.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $now     = microtime(true);
        $script  = base_path('scripts/keibaOddsGetFinishingPosition.mjs');
        $logFile = base_path('scripts/keibaOddsGetFinishingPosition.log');
        $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';

        $this->info('');
        $this->info('========== keiba:summaryHistoryFinishingPosition 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('対象年月     : ' . $yearmonth);
        $this->info('スクリプト   : ' . $script);
        $this->info('');

        // ── Step 1: finishing_position が未取得の開催を取得 ──────────
        // NULL = インポート前 / 0 = インポート済みだが着順未取得
        // どちらも再処理対象。-1（非完走）や 1〜（着順）は対象外。
        $this->info('[Step 1] 未取得の開催を DB から取得中...');

        $kaisaiRows = DB::table('t_horse_odds_finder_race_result_history')
            ->where('date', 'LIKE', $yearmonth . '%')
            ->where(fn($q) => $q->whereNull('finishing_position')->orWhere('finishing_position', 0))
            ->select('date', 'kaisuu', 'basho', 'basho_code', 'day')
            ->distinct()
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('day')
            ->get();

        if ($kaisaiRows->isEmpty()) {
            $this->info('  対象開催なし（全て取得済み、またはデータなし）');
            $this->info('');
            return;
        }

        $totalKaisai = $kaisaiRows->count();
        $this->info("  → {$totalKaisai} 開催を検出");
        $this->info('');

        // ── Step 2: 各開催を順番に処理 ───────────────────────────────
        $kaisaiIndex  = 0;
        $totalUpdated = 0;
        $failedList   = [];

        foreach ($kaisaiRows as $row) {
            $kaisaiIndex++;

            $kaisai = "{$row->kaisuu}回{$row->basho}{$row->day}日";

            $this->info('══════════════════════════════════════════════════════');
            $this->info("[{$kaisaiIndex}/{$totalKaisai}] {$kaisai} (date={$row->date}) 処理開始");
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
                    $this->warn('  5秒後にリトライします...');
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

            // ── DB UPDATE（1開催 = 1クエリ CASE WHEN）───────────────
            // CASE WHEN race = 1 AND num = 14 THEN 1
            //      WHEN race = 1 AND num =  5 THEN 2
            //      ...
            //      WHEN race = 12 AND num = 1 THEN 1
            // END
            // 中止・除外等（数値以外）は -1 → 次回 cron の再処理対象外になる
            $caseWhen    = 'CASE';
            $raceGroups  = [];
            $totalHorses = 0;

            foreach ($result['races'] as $raceData) {
                $raceNo = (int) $raceData['race'];
                foreach ($raceData['horses'] as $horse) {
                    $fp  = is_int($horse['chakujun']) ? $horse['chakujun'] : -1;
                    $num = (int) $horse['num'];
                    $caseWhen .= " WHEN race = {$raceNo} AND num = {$num} THEN {$fp}";
                    $raceGroups[$raceNo][] = $num;
                    $totalHorses++;
                }
            }
            $caseWhen .= ' END';

            $this->info("  取得完了: {$raceCount}レース / {$totalHorses}頭 ({$elapsed}秒)");
            $this->info("  1クエリで一括更新中...");

            $affected = DB::table('t_horse_odds_finder_race_result_history')
                ->where('date',       $row->date)
                ->where('kaisuu',     $row->kaisuu)
                ->where('basho_code', $row->basho_code)
                ->where('day',        $row->day)
                ->where(function ($q) use ($raceGroups) {
                    foreach ($raceGroups as $raceNo => $nums) {
                        $q->orWhere(fn($q2) => $q2->where('race', $raceNo)->whereIn('num', $nums));
                    }
                })
                ->update(['finishing_position' => DB::raw($caseWhen)]);

            $totalUpdated += $affected;
            $this->info("  [{$kaisaiIndex}/{$totalKaisai}] {$kaisai} 完了 → {$affected} 頭更新");
            $this->info('');
        }

        // ── 完了サマリー ─────────────────────────────────────────────
        $totalElapsed = round(microtime(true) - $now, 1);
        $failedCount  = count($failedList);

        $this->info('');
        $this->info('========== keiba:summaryHistoryFinishingPosition 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info("対象年月     : {$yearmonth}");
        $this->info("処理開催数   : {$totalKaisai} 開催");
        $this->info("更新頭数合計 : {$totalUpdated} 頭");
        $this->info("失敗開催数   : {$failedCount} 件" . ($failedCount > 0 ? ' ← 要確認' : ''));
        foreach ($failedList as $f) {
            $this->error("  [FAIL] {$f}");
        }
        $this->info("処理時間     : {$totalElapsed} 秒");
        $this->info('');

        (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryHistoryFinishingPosition::handle\n対象年月:{$yearmonth}、開催:{$totalKaisai}、頭数:{$totalUpdated}");
    }
}
