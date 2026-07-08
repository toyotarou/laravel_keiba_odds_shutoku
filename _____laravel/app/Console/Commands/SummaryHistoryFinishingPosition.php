<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SummaryHistoryFinishingPosition
 *
 * 【概要】
 *   指定年月の t_horse_odds_finder_race_result_history テーブルの
 *   finishing_position（着順）が未取得（NULL/0）の開催を
 *   keibaOddsGetFinishingPosition.mjs 経由で取得して一括更新する。
 *   中止・除外等の非完走馬は -1 に設定して以後の再処理対象外とする。
 *
 * 【処理フロー】
 *   【ブロック 1】引数チェック（YYYY-MM 形式の検証）
 *   【ブロック 2】多重起動防止（年月別ロックファイル）
 *   【ブロック 3】初期化・開始バナー
 *   【ブロック 4】Step 1: finishing_position が未取得の開催を取得
 *   【ブロック 5】Step 2: 各開催を順番に処理
 *   【ブロック 6】Node.js 実行（リトライ最大3回）
 *   【ブロック 7】CASE WHEN 一括 UPDATE（1開催 = 1クエリ）
 *   【ブロック 8】完了サマリー・WebPush 通知
 *
 * 【finishing_position の値の意味】
 *   NULL / 0  : 未取得（cron の再処理対象）
 *   1〜       : 着順
 *   -1        : 中止・除外・取消・失格等（再処理対象外）
 *
 * 【CASE WHEN 一括 UPDATE の構造】
 *   CASE
 *     WHEN race = 1 AND num = 14 THEN 1
 *     WHEN race = 1 AND num =  5 THEN 2
 *     ...
 *     WHEN race = 12 AND num = 1 THEN 1
 *   END
 *   1開催分の全着順を1クエリで書き込むことで DB ラウンドトリップを最小化する。
 *
 * 【使い方】
 *   php artisan keiba:summaryHistoryFinishingPosition --yearmonth=2023-01
 *   php artisan keiba:summaryHistoryFinishingPosition  # 当月
 */
class SummaryHistoryFinishingPosition extends Command
{
    protected $signature   = 'keiba:summaryHistoryFinishingPosition {--yearmonth= : 対象年月 (例: 2023-01、省略時は当月)}';
    protected $description = '指定年月の全開催・全レースの着順をDBに更新する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】引数チェック（YYYY-MM 形式の検証）
        // ─────────────────────────────────────────────────────────────────
        $yearmonth = $this->option('yearmonth') ?: date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $yearmonth)) {
            $this->error('--yearmonth=YYYY-MM の形式で指定してください。例: --yearmonth=2023-01');
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】多重起動防止（年月別ロックファイル）
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_summaryHistoryFinishingPosition_' . str_replace('-', '', $yearmonth) . '.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】初期化・開始バナー
        // ─────────────────────────────────────────────────────────────────
        $now     = microtime(true);
        $script  = base_path('scripts/keibaOddsGetFinishingPosition.mjs');
        $logFile = base_path('scripts/keibaOddsGetFinishingPosition.log');
        $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';

        $this->info('');
        $this->info('========== keiba:summaryHistoryFinishingPosition 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('対象年月     : ' . $yearmonth);
        $this->info('スクリプト   : ' . $script);
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 4】Step 1: finishing_position が未取得の開催を取得
        //   NULL = インポート前 / 0 = インポート済みだが着順未取得 → 再処理対象
        //   -1（非完走）や 1〜（着順確定）は対象外。
        //   date LIKE 'YYYY-MM%' で年月単位に絞り込む。
        // ─────────────────────────────────────────────────────────────────
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

        $totalKaisai  = $kaisaiRows->count();
        $kaisaiIndex  = 0;
        $totalUpdated = 0;
        $failedList   = [];

        if ($kaisaiRows->isEmpty()) {
            $this->info('  対象開催なし（全て取得済み、またはデータなし）');
        } else {
            $this->info("  → {$totalKaisai} 開催を検出");
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 5】Step 2: 各開催を順番に処理
            // ─────────────────────────────────────────────────────────────
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

                // ─────────────────────────────────────────────────────────
                // 【ブロック 6】Node.js 実行（リトライ最大3回）
                // ─────────────────────────────────────────────────────────
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

                // ─────────────────────────────────────────────────────────
                // 【ブロック 7】CASE WHEN 一括 UPDATE（1開催 = 1クエリ）
                //   CASE WHEN race = 1 AND num = 14 THEN 1
                //        WHEN race = 1 AND num =  5 THEN 2
                //        ...
                //        WHEN race = 12 AND num = 1 THEN 1
                //   END
                //   中止・除外等（chakujun が整数でない）は -1 → 以後の再処理対象外になる。
                //   1開催の全着順を1クエリで書き込むことで DB ラウンドトリップを最小化する。
                // ─────────────────────────────────────────────────────────
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
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 8】完了サマリー・WebPush 通知
        // ─────────────────────────────────────────────────────────────────
        $totalElapsed = round(microtime(true) - $now, 1);
        $failedCount  = count($failedList);

        $this->info('');
        $this->info("対象年月     : {$yearmonth}");
        $this->info("処理開催数   : {$totalKaisai} 開催");
        $this->info("更新頭数合計 : {$totalUpdated} 頭");
        $this->info("失敗開催数   : {$failedCount} 件" . ($failedCount > 0 ? ' ← 要確認' : ''));
        foreach ($failedList as $f) {
            $this->error("  [FAIL] {$f}");
        }
        $this->info("処理時間     : {$totalElapsed} 秒");
        $this->info('');
        $this->info('========== keiba:summaryHistoryFinishingPosition 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');

        (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryHistoryFinishingPosition::handle\n対象年月:{$yearmonth}、開催:{$totalKaisai}、頭数:{$totalUpdated}");
    }
}
