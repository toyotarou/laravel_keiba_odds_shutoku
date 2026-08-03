<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportKeibaPayoutInnerOuter
 *
 * 【概要】
 *   払戻金テーブル (t_horse_odds_finder_race_result_payout) の
 *   inner_outer が未設定のレコードを keibaOddsGetRaceInnerOuter.mjs 経由で補完する。
 *
 *   対象競馬場: 新潟(04)・中山(06)・京都(08)・阪神(09)
 *   対象コース: 芝のみ（ダートは inner_outer の概念なし）
 *
 * 【処理フロー】
 *   【ブロック 1】引数チェック（YYYY-MM 形式の検証）
 *   【ブロック 2】多重起動防止（年月別ロックファイル）
 *   【ブロック 3】初期化・開始バナー
 *   ─── ガード節（mjs を使わなくていいなら早期リターン）───
 *   【ガード A】スクリプト・Node バイナリの存在確認
 *   【ガード B】inner_outer IS NULL のレコード件数確認（count のみ・軽量）
 *   ─── ここまで通過したら mjs 実行が確定 ───────────────
 *   【ブロック 4】Node.js 実行（リトライ最大3回）で inner_outer を一括取得
 *   【ブロック 5】mjs データを軸に UPDATE WHERE inner_outer IS NULL
 *   【ブロック 6】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【使い方】
 *   php artisan keiba:importPayoutInnerOuter --yearmonth=2023-01
 *   php artisan keiba:importPayoutInnerOuter  # 当月
 */
class ImportKeibaPayoutInnerOuter extends Command
{
    protected $signature   = 'keiba:importPayoutInnerOuter {--yearmonth= : 対象年月 (例: 2023-01)}';
    protected $description = '払戻金テーブルの inner_outer（外/内コース）を補完する';

    // inner_outer が存在する4競馬場の basho_code
    private const TARGET_BASHO_CODES = ['04', '06', '08', '09']; // 新潟・中山・京都・阪神

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
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importPayoutInnerOuter_' . str_replace('-', '', $yearmonth) . '.lock';
        if (file_exists($lockFile)) {
            $pid = (int) file_get_contents($lockFile);
            if ($pid > 0 && posix_kill($pid, 0)) {
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
        $script       = base_path('scripts/keibaOddsGetRaceInnerOuter.mjs');
        $logFile      = base_path('scripts/keibaOddsGetRaceInnerOuter.log');
        $nodeBin      = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';
        $totalTarget  = 0;
        $totalUpdated = 0;
        $status       = '不明な理由で終了';

        try {
            $this->info('');
            $this->info('========== keiba:importPayoutInnerOuter 開始 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('対象年月     : ' . $yearmonth);
            $this->info('対象競馬場   : 新潟(04)・中山(06)・京都(08)・阪神(09)');
            $this->info('スクリプト   : ' . $script);
            $this->info('ログファイル : ' . $logFile);
            $this->info('');

            // ═════════════════════════════════════════════════════════════
            // ガード節 ── mjs を使わなくていいケースは全部ここで終わらせる
            // ═════════════════════════════════════════════════════════════

            // 【ガード A】スクリプト・Node バイナリの存在確認
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

            // 【ガード B】inner_outer IS NULL のレコード件数確認（count のみ・軽量）
            //   対象: 指定年月 & 対象4場 & 芝 & inner_outer 未設定
            //   除外: 障害レース（race_name に「障害」を含む） → 正しく null のまま
            //   除外: 新潟芝1000m（直線コース）          → 正しく null のまま
            $this->info('[ガードB] inner_outer が未設定のレコードを確認中...');

            $totalTarget = DB::table('t_horse_odds_finder_race_result_payout')
                ->where('date', 'like', $yearmonth . '%')
                ->whereIn('basho_code', self::TARGET_BASHO_CODES)
                ->where('course', '芝')
                ->whereNull('inner_outer')
                ->where('race_name', 'not like', '%障害%')
                ->where(function ($q) {
                    // 新潟(04)芝1000m（直線）は外/内なし → 除外
                    $q->where('basho_code', '!=', '04')
                      ->orWhere('dist', '!=', 1000);
                })
                ->count();

            if ($totalTarget === 0) {
                $this->info('  → 未設定レコードなし。mjs 実行をスキップします。');
                $status = '対象レコードなし（スキップ）';
                return;
            }

            $this->info("  → {$totalTarget} 件が未設定。mjs を実行します。");
            $this->info('');

            // ═════════════════════════════════════════════════════════════
            // ガード節をすべて通過 ── ここから mjs 実行が確定
            // ═════════════════════════════════════════════════════════════

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】Node.js 実行（リトライ最大3回）で inner_outer を一括取得
            // ─────────────────────────────────────────────────────────────
            $this->info('[Step 1] keibaOddsGetRaceInnerOuter.mjs を実行中...');

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
                $this->error('inner_outer データの取得に失敗しました（リトライ上限到達）。');
                $status = 'Node.js 実行失敗';
                return;
            }

            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 5】mjs データを軸に UPDATE WHERE inner_outer IS NULL
            //   mjs は inner_outer が取れたレコードのみ返してくる（null は含まない）。
            //   UPDATE の WHERE に whereNull('inner_outer') を含めることで
            //   既に設定済みのレコードは自動的にスキップされる。
            // ─────────────────────────────────────────────────────────────
            $this->info('[Step 2] inner_outer を更新中...');

            foreach ($mjsJson['data'] as $item) {
                $affected = DB::table('t_horse_odds_finder_race_result_payout')
                    ->where('date',       $item['date'])
                    ->where('kaisuu',     $item['kaisuu'])
                    ->where('basho_code', $item['basho_code'])
                    ->where('day',        $item['day'])
                    ->where('race',       $item['race'])
                    ->whereNull('inner_outer')
                    ->update([
                        'inner_outer' => $item['inner_outer'],
                    ]);

                if ($affected > 0) {
                    $this->info("  [UPDATE] {$item['date']} {$item['kaisuu']}回{$item['basho_name']}{$item['day']}日 {$item['race']}R → inner_outer={$item['inner_outer']}");
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
            $this->info('========== keiba:importPayoutInnerOuter 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            (new WebPushService())->sendPushNotifierDeveloperNews(
                'develop',
                "ImportKeibaPayoutInnerOuter::handle\n{$status}\n対象年月:{$yearmonth}、対象:{$totalTarget}件、更新:{$totalUpdated}件"
            );
        }
    }
}
