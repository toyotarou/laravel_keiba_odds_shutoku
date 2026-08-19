<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportKeibaPayoutGrade
 *
 * 【概要】
 *   払戻金テーブル (t_horse_odds_finder_race_result_payout) の
 *   grade が未設定のレコードを keibaOddsGetRaceGrade.mjs 経由で補完する。
 *
 * 【処理フロー】
 *   【ブロック 1】引数チェック（YYYY-MM 形式の検証）
 *   【ブロック 2】多重起動防止（年月別ロックファイル）
 *   【ブロック 3】初期化・開始バナー
 *   ─── ガード節（mjs を使わなくていいなら早期リターン）───
 *   【ガード A】スクリプト・Node バイナリの存在確認
 *   ─── ここから mjs 実行 ───────────────────────────────
 *   【ブロック 4】Node.js 実行（リトライ最大3回）で grade を一括取得
 *   ─── ガード節（更新対象がなければ早期リターン）──────────
 *   【ガード B】mjs が返したレースの中で grade IS NULL のレコードを件数確認
 *              date + kaisuu + basho_code + day + race の5キーで突合するため
 *              通常の条件戦（永遠に grade IS NULL）には反応しない。
 *   ─── ここまで通過したら UPDATE が確定 ────────────────
 *   【ブロック 5】mjs データを軸に UPDATE WHERE grade IS NULL
 *   【ブロック 6】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【クエリ設計】
 *   ガードB: mjs が返したグレードレースの (date,kaisuu,basho_code,day,race) に
 *            絞って grade IS NULL を count() → 不要な更新を完全に排除。
 *   UPDATE : WHERE に whereNull('grade') を含めることで二重更新を防止。
 *            update() の戻り値（実際に更新された行数）で totalUpdated を集計する。
 *
 * 【使い方】
 *   php artisan keiba:importPayoutGrade --yearmonth=2023-01
 *   php artisan keiba:importPayoutGrade  # 当月
 */
class ImportKeibaPayoutGrade extends Command
{
    protected $signature   = 'keiba:importPayoutGrade {--yearmonth= : 対象年月 (例: 2023-01)}';
    protected $description = '払戻金テーブルの grade を補完する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】引数チェック（YYYY-MM 形式の検証）
        //   省略時は当月（date('Y-m')）を対象にする。
        // ─────────────────────────────────────────────────────────────────
        $yearmonth = $this->option('yearmonth') ?: date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $yearmonth)) {
            $this->error('--yearmonth=YYYY-MM の形式で指定してください。例: --yearmonth=2023-01');
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】多重起動防止（年月別ロックファイル）
        //   年月をロックファイル名に含めることで異なる年月の同時実行は許可する。
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importPayoutGrade_' . str_replace('-', '', $yearmonth) . '.lock';
        if (file_exists($lockFile)) {
            $pid = (int) file_get_contents($lockFile);
            $isRunning = $pid > 0 && (
                (function_exists('posix_kill') && posix_kill($pid, 0))
                || file_exists("/proc/{$pid}")
            );

            if ($isRunning) {
                $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
                return;
            }
            $this->warn("ロックファイルの残骸を削除して続行します (PID: {$pid})");
            unlink($lockFile);
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】初期化・開始バナー
        // ─────────────────────────────────────────────────────────────────
        $now          = microtime(true);
        $script       = base_path('scripts/keibaOddsGetRaceGrade.mjs');
        $logFile      = base_path('scripts/keibaOddsGetRaceGrade.log');
        $nodeBin      = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';
        $totalTarget  = 0;
        $totalUpdated = 0;
        $status       = '不明な理由で終了';

        try {
            $this->info('');
            $this->info('========== keiba:importPayoutGrade 開始 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('対象年月     : ' . $yearmonth);
            $this->info('スクリプト   : ' . $script);
            $this->info('ログファイル : ' . $logFile);
            $this->info('');

            // ═════════════════════════════════════════════════════════════
            // 【ガード A】スクリプト・Node バイナリの存在確認
            //   ファイルがなければ mjs 実行は絶対に失敗するため、事前に弾く。
            // ═════════════════════════════════════════════════════════════
            if (!file_exists($nodeBin)) {
                $this->error("[ガードA] Node バイナリが見つかりません: {$nodeBin}");
                $status = 'Node バイナリ不在';
                return;
            }
            if (!file_exists($script)) {
                $this->error("[ガードA] スクリプトが見つかりません: {$script}");
                $status = 'スクリプト不在';
                return;
            }

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】Node.js 実行（リトライ最大3回）で grade を一括取得
            //   timeout 600: 月全体を1リクエストで取得するため長めに確保する。
            //   ※ ガードBは mjs 結果と DB を突合するため、mjs を先に実行する。
            // ─────────────────────────────────────────────────────────────
            $this->info('[Step 1] keibaOddsGetRaceGrade.mjs を実行中...');

            $command = 'timeout 600 ' . $nodeBin . ' ' . escapeshellarg($script)
                . ' --yearmonth=' . escapeshellarg($yearmonth)
                . ' 2>>' . escapeshellarg($logFile);

            $this->info('  実行: ' . $command);
            $this->info('');

            $mjsJson  = null;
            $maxRetry = 3;

            for ($retry = 1; $retry <= $maxRetry; $retry++) {
                $this->info("  [試行 {$retry}/{$maxRetry}] Node.js 実行中...");
                $output  = shell_exec($command);
                $mjsJson = json_decode($output, true);

                if ($mjsJson && !empty($mjsJson['data'])) {
                    $this->info("  [試行 {$retry}/{$maxRetry}] 取得成功！ " . count($mjsJson['data']) . ' レース');
                    break;
                }

                $this->warn("  [試行 {$retry}/{$maxRetry}] 取得失敗。");
                $this->warn('  Node.js 出力: ' . substr($output ?? '', 0, 500));

                if ($retry < $maxRetry) {
                    $this->warn('  5秒後にリトライします...');
                    sleep(5);
                }
            }

            if (!$mjsJson || empty($mjsJson['data'])) {
                $this->error('grade データの取得に失敗しました（リトライ上限到達）。');
                $status = 'Node.js 実行失敗';
                return;
            }

            $this->info('');

            // ═════════════════════════════════════════════════════════════
            // 【ガード B】mjs が返したレースの中で grade IS NULL のものを確認
            //
            //   通常の条件戦は mjs に含まれない（グレードありのみ返す）ため、
            //   ここで false なら「全グレードレースが既に登録済み」を意味する。
            //
            //   突合キー: date + kaisuu + basho_code + day + race の5カラム。
            //   1件でも見つかれば UPDATE へ進むので exists() で十分（count() 不要）。
            // ═════════════════════════════════════════════════════════════
            $this->info('[ガードB] mjs 取得レースと DB を突合して grade IS NULL を確認中...');

            $hasTarget = DB::table('t_horse_odds_finder_race_result_payout')
                ->whereNull('grade')
                ->where(function ($query) use ($mjsJson) {
                    foreach ($mjsJson['data'] as $item) {
                        $query->orWhere(function ($q) use ($item) {
                            $q->where('date',       $item['date'])
                              ->where('kaisuu',     $item['kaisuu'])
                              ->where('basho_code', $item['basho_code'])
                              ->where('day',        $item['day'])
                              ->where('race',       $item['race']);
                        });
                    }
                })
                ->exists();

            if (!$hasTarget) {
                $this->info('  → 更新対象なし（全グレードレースが登録済み）。スキップします。');
                $status = 'SKIP';
                return;
            }

            // mjs が取得したグレードレース数をサマリー用に記録
            $totalTarget = count($mjsJson['data']);
            $this->info("  → 更新対象あり。mjs 取得グレードレース数: {$totalTarget} 件。UPDATE を実行します。");
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 5】mjs データを軸に UPDATE WHERE grade IS NULL
            //   whereNull('grade') を含めることで、既に設定済みのレコードは
            //   自動的にスキップされる（0 件更新 = no-op）。
            //   update() の戻り値が実際の更新行数なので、それで totalUpdated を集計する。
            // ─────────────────────────────────────────────────────────────
            $this->info('[Step 2] grade を更新中...');

            foreach ($mjsJson['data'] as $item) {
                $affected = DB::table('t_horse_odds_finder_race_result_payout')
                    ->where('date',       $item['date'])
                    ->where('kaisuu',     $item['kaisuu'])
                    ->where('basho_code', $item['basho_code'])
                    ->where('day',        $item['day'])
                    ->where('race',       $item['race'])
                    ->whereNull('grade')
                    ->update([
                        'grade' => $item['grade'],
                    ]);

                if ($affected > 0) {
                    $this->info("  [UPDATE] {$item['date']} {$item['kaisuu']}回{$item['basho_name']}{$item['day']}日 {$item['race']}R → grade={$item['grade']}");
                    $totalUpdated += $affected;
                }
            }

            $status = '正常終了';

        } finally {
            // ─────────────────────────────────────────────────────────────
            // 【ブロック 6】完了サマリー・WebPush 通知（finally で必ず実行）
            // ─────────────────────────────────────────────────────────────
            $totalElapsed = round(microtime(true) - $now, 1);

            $this->info('');
            $this->info("終了理由     : {$status}");
            $this->info("対象年月     : {$yearmonth}");
            $this->info("対象レコード : {$totalTarget} 件");
            $this->info("更新レコード : {$totalUpdated} 件");
            $this->info("処理時間     : {$totalElapsed} 秒");
            $this->info('');
            $this->info('========== keiba:importPayoutGrade 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            $newsValue = [];
            $newsValue[] = $status;
            if ($status != 'SKIP') {
                $newsValue[] = "対象年月:{$yearmonth}、";
                $newsValue[] = "対象:{$totalTarget}件、";
                $newsValue[] = "更新:{$totalUpdated}件";
            }
            $news = implode("", $newsValue);

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "ImportKeibaPayoutGrade::handle\n{$news}");
        }
    }
}
