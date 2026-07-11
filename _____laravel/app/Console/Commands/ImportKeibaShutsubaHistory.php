<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportKeibaShutsubaHistory
 *
 * 【概要】
 *   翌日の全開催・全レースの出馬表（前走〜4走前情報を含む）を
 *   keibaOddsGetShutsuba.mjs 経由で取得し、
 *   t_horse_odds_finder_shutsuba_history に保存する。
 *
 *   --date オプションで対象日を明示指定できる。省略時は翌日（tomorrow）を対象とする。
 *   mjs に --date=YYYY-MM-DD を渡すことで、JRA側で該当日の開催のみを絞り込む。
 *   これにより不要な開催のページ遷移が発生しない。
 *
 * 【処理フロー】
 *   【ブロック 1】引数チェック・対象日付の確定
 *   【ブロック 2】多重起動防止（日付別ロックファイル）
 *   【ブロック 3】初期化・開始バナー
 *   【ブロック 4】Step 1: --list-only で対象日の開催一覧を取得
 *   【ブロック 5】インポート済み開催をDBから先読み（PRE-SKIP 判定用）
 *   【ブロック 6】Step 2: 各開催を順番に処理
 *   【ブロック 7】Node.js 実行（リトライ最大3回）
 *   【ブロック 8】DB保存（前走ごとに updateOrInsert）
 *   【ブロック 9】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【cron 設定】
 *   # 毎日22時（翌日の出馬表を取得。開催なし日は自動スキップ）
 *   0 22 * * * flock -n /tmp/keiba_importShutsubaHistory.lock php /var/www/horse_odds_finder/artisan keiba:importShutsubaHistory >> /var/www/horse_odds_finder/storage/logs/importShutsubaHistory.log 2>&1
 *
 * 【使い方】
 *   php artisan keiba:importShutsubaHistory               # 翌日の出馬表を取得
 *   php artisan keiba:importShutsubaHistory --date=2026-07-12  # 日付を明示指定
 */
class ImportKeibaShutsubaHistory extends Command
{
    protected $signature   = 'keiba:importShutsubaHistory {--date= : 対象日付 (例: 2026-07-12)。省略時は翌日}';
    protected $description = '翌日（または指定日）の全開催・全レースの出馬表（前走〜4走前）を取得してDBに保存する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】引数チェック・対象日付の確定
        //   --date 省略時は翌日（date('Y-m-d', strtotime('+1 day'))）を対象にする。
        //   cron から日付を渡す必要はなく、Laravel 側で翌日を自動計算して mjs に渡す。
        //   手動再実行や特定日の取得には --date=YYYY-MM-DD で明示指定する。
        // ─────────────────────────────────────────────────────────────────
        $dateOption = $this->option('date');
        if ($dateOption) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOption)) {
                $this->error('--date=YYYY-MM-DD の形式で指定してください。例: --date=2026-07-12');
                return;
            }
            $targetDate = $dateOption;
        } else {
            $targetDate = date('Y-m-d', strtotime('+1 day'));
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】多重起動防止（日付別ロックファイル）
        //   日付をロックキーに含めることで、異なる日付の同時実行は許可しつつ
        //   同一日付の重複起動のみを防ぐ。
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importShutsubaHistory_' . str_replace('-', '', $targetDate) . '.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】初期化・開始バナー
        //   $status は finally で WebPush 通知の本文に使う。
        //   microtime(true) を $now に記録して処理時間を計測する。
        // ─────────────────────────────────────────────────────────────────
        $now     = microtime(true);
        $script  = base_path('scripts/keibaOddsGetShutsuba.mjs');
        $logFile = base_path('scripts/keibaOddsGetShutsuba.log');
        $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';

        $totalKaisai = 0;
        $totalSaved  = 0;
        $failedList  = [];
        $status      = '不明な理由で終了';

        // kaisai テキスト ("2回福島5日") から basho_code を導出するマップ
        $bashoMap = [
            '札幌' => '01', '函館' => '02', '福島' => '03', '新潟' => '04',
            '東京' => '05', '中山' => '06', '中京' => '07', '京都' => '08',
            '阪神' => '09', '小倉' => '10',
        ];

        try {
            $this->info('');
            $this->info('========== keiba:importShutsubaHistory 開始 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('対象日付     : ' . $targetDate . ($dateOption ? ' (指定)' : ' (翌日・自動計算)'));
            $this->info('スクリプト   : ' . $script);
            $this->info('ログファイル : ' . $logFile);
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】Step 1: --list-only で対象日の開催一覧を取得
            //   mjs に --date と --list-only を渡すことで、
            //   JRA側で該当日付の開催のみに絞り込んだ一覧を JSON で受け取る。
            //   出力形式: { kaisaiList: [{ kaisai: "2回福島5日", date: "2026-07-12" }, ...] }
            //   kaisaiList が空 → 出馬表未確定 or 開催なし → 正常終了扱い。
            //   kaisaiList キー自体がない → Node.js 側の異常 → エラー終了。
            // ─────────────────────────────────────────────────────────────
            $this->info('[Step 1] ' . $targetDate . ' の開催一覧を取得中...');

            $listCommand = 'timeout 120 ' . $nodeBin . ' ' . escapeshellarg($script)
                . ' --date=' . escapeshellarg($targetDate)
                . ' --list-only'
                . ' 2>>' . escapeshellarg($logFile);

            $this->info('  実行: ' . $listCommand);

            $listOutput = shell_exec($listCommand);
            $listJson   = json_decode($listOutput, true);

            if (!is_array($listJson) || !array_key_exists('kaisaiList', $listJson)) {
                $this->error('開催一覧の取得に失敗しました（出力が不正です）。');
                $this->error('Node.js 出力: ' . $listOutput);
                $status = '開催一覧の取得失敗（出力不正）';
                return;
            }

            $kaisaiList  = $listJson['kaisaiList'];  // [{ kaisai: "...", date: "..." }, ...]
            $totalKaisai = count($kaisaiList);

            if (empty($kaisaiList)) {
                $this->warn('対象開催なし（' . $targetDate . ' の出馬表がまだ確定していないか、開催がありません）。');
                $status = '対象開催なし';
                return;
            }

            $this->info("  → {$totalKaisai} 開催を検出: " . implode(', ', array_column($kaisaiList, 'kaisai')));
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 5】インポート済み開催をDBから先読み（PRE-SKIP 判定用）
            //   t_horse_odds_finder_shutsuba_history には kaisuu / day カラムがないため、
            //   basho_code + 当日の created_at で「今日取得済みの場所」を判定する。
            //   $preSkipSet: basho_code をキーにしたハッシュセット。
            //   Node.js 実行前に kaisai テキストから basho_code を解析して
            //   $preSkipSet に存在すれば Node.js 実行をスキップし起動コストを節約する。
            // ─────────────────────────────────────────────────────────────
            $this->info('[事前確認] インポート済み開催を確認中...');

            $existingRaw = DB::table('t_horse_odds_finder_shutsuba_history')
                ->whereNotNull('basho_code')
                ->whereRaw('DATE(created_at) = ?', [date('Y-m-d')])
                ->select('basho_code')
                ->distinct()
                ->get();

            $preSkipSet = $existingRaw
                ->mapWithKeys(fn($r) => [$r->basho_code => true])
                ->toArray();

            $this->info('  インポート済み開催: ' . count($preSkipSet) . ' 件');
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 6】Step 2: 各開催を順番に処理
            //   Node.js 実行前に kaisai テキストから basho_code を解析して
            //   $preSkipSet に存在する場合は Node.js 実行そのものをスキップする。
            //   kaisai テキスト例: "2回福島5日" → basho_code=03
            // ─────────────────────────────────────────────────────────────
            $kaisaiIndex = 0;

            foreach ($kaisaiList as $kaisaiItem) {
                $kaisaiIndex++;
                $kaisai     = $kaisaiItem['kaisai'];  // 例: "2回福島5日"
                $kaisaiDate = $kaisaiItem['date'];    // 例: "2026-07-12"

                // PRE-SKIP: Node.js 実行前に開催名から basho_code を解析して判定
                if (preg_match('/^(\d+)回(.+?)(\d+)日$/u', $kaisai, $m)) {
                    $preKey = $bashoMap[$m[2]] ?? null;
                    if ($preKey && isset($preSkipSet[$preKey])) {
                        $this->info("[{$kaisaiIndex}/{$totalKaisai}] {$kaisai} → [PRE-SKIP] インポート済み（Node.js省略）");
                        continue;
                    }
                }

                $this->info('══════════════════════════════════════════════════════');
                $this->info("[{$kaisaiIndex}/{$totalKaisai}] {$kaisai} ({$kaisaiDate}) 処理開始");
                $this->info('══════════════════════════════════════════════════════');

                // mjs に --kaisai を渡して1開催分だけ取得する
                // --date は不要（kaisai 指定時は mjs 側で kaisai で絞り込む）
                $command = 'timeout 300 ' . $nodeBin . ' ' . escapeshellarg($script)
                    . ' --kaisai="' . $kaisai . '"'
                    . ' 2>>' . escapeshellarg($logFile);

                $this->info('  実行: ' . $command);
                $this->info('');

                // ─────────────────────────────────────────────────────────
                // 【ブロック 7】Node.js 実行（リトライ最大3回）
                //   成功条件: $result['races'] が空でない配列。
                //   timeout 300: 1開催12レース × ページ遷移に余裕を持たせる。
                //   失敗時は5秒待ってリトライ。3回失敗で $failedList に追加してスキップ。
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
                $this->info("  取得完了: {$raceCount} レース / 日付: {$result['date']} ({$elapsed}秒)");
                $this->info('');

                // ─────────────────────────────────────────────────────────
                // 【ブロック 8】DB保存（前走ごとに updateOrInsert）
                //   テーブル: t_horse_odds_finder_shutsuba_history
                //   UNIQUE KEY: name + date + place + race_name
                //     → 同じ馬の同じレース履歴は重複INSERTされない。
                //     → 別の出馬表取得時に同じ前走が出てきても安全に UPDATE される。
                //   kaisuu / basho_code / day / race は出馬表のレース情報として保存する。
                //   前走データが null（出走歴なし）の場合はその走数をスキップする。
                // ─────────────────────────────────────────────────────────
                $saved = 0;

                foreach ($result['races'] as $raceData) {
                    $horseCount = count($raceData['horses']);
                    $this->info("    {$raceData['race']}R 「{$raceData['race_name']}」 → {$horseCount}頭 保存中...");

                    foreach ($raceData['horses'] as $horse) {
                        foreach ($horse['pasts'] as $past) {
                            // null または date が空は出走歴なしのためスキップ
                            if (is_null($past) || empty($past['date'])) {
                                continue;
                            }

                            // mjs側で "2026-04-11" 形式に変換済み
                            // place（漢字）→ basho_code（2桁コード）に変換
                            $pastBashoCode = $bashoMap[$past['place']] ?? null;

                            // UNIQUE KEY: name + race_date + basho_code + race
                            // → 同じ馬の同じレースは7/10取得でも8/10取得でも1レコード
                            $key = [
                                'name'       => $horse['name'],
                                'date'       => $past['date'],
                                'basho_code' => $pastBashoCode,
                                'race'       => $raceData['race'],
                            ];

                            $data = [
                                'basho'     => $past['place'],
                                'race_name' => $past['race_name'],
                                'grade'         => $past['grade'],
                                'finishing_position' => $past['place_num'],
                                'num_horses'    => $past['head_count'],
                                'gate'          => $past['gate'],
                                'popularity'    => $past['popularity'],
                                'jockey'        => $past['jockey'],
                                'burden_weight' => $past['burden_weight'],
                                'dist'          => $past['dist'],
                                'time'          => $past['time'],
                                'condition'     => $past['condition'],
                                'horse_weight'  => $past['horse_weight'],
                                'corner_1'      => $past['corners'][0] ?? null,
                                'corner_2'      => $past['corners'][1] ?? null,
                                'corner_3'      => $past['corners'][2] ?? null,
                                'corner_4'      => $past['corners'][3] ?? null,
                                'last_3f'       => $past['last_3f'],
                                'fin_horse'     => $past['fin_horse'],
                                'fin_time_diff' => $past['fin_time_diff'],
                            ];

                            DB::table('t_horse_odds_finder_shutsuba_history')
                                ->updateOrInsert($key, $data);

                            $saved++;
                        }
                    }

                    $this->info("    {$raceData['race']}R → 保存完了");
                }

                $totalSaved += $saved;
                $this->info('');
                $this->info("  [{$kaisaiIndex}/{$totalKaisai}] {$kaisai} 完了 → {$saved} 件保存");
                $this->info('');
            }

            $status = (count($failedList) > 0) ? '正常終了（一部失敗あり）' : '正常終了';

        } finally {
            // ─────────────────────────────────────────────────────────────
            // 【ブロック 9】完了サマリー・WebPush 通知（finally で必ず実行）
            //   どの経路（対象なし・PRE-SKIP・失敗・正常終了）でも必ず実行する。
            //   $status を含む通知を開発者向けに送信する。
            //   失敗開催は個別に error ログで列挙する。
            // ─────────────────────────────────────────────────────────────
            $totalElapsed = round(microtime(true) - $now, 1);
            $failedCount  = count($failedList);

            $this->info('');
            $this->info("終了理由     : {$status}");
            $this->info("対象日付     : {$targetDate}");
            $this->info("処理開催数   : {$totalKaisai} 開催");
            $this->info("保存件数合計 : {$totalSaved} 件");
            $this->info("失敗開催数   : {$failedCount} 件" . ($failedCount > 0 ? ' ← 要確認' : ''));
            foreach ($failedList as $f) {
                $this->error("  [FAIL] {$f}");
            }
            $this->info("処理時間     : {$totalElapsed} 秒");
            $this->info('');
            $this->info('========== keiba:importShutsubaHistory 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            (new WebPushService())->sendPushNotifierDeveloperNews(
                'develop',
                "ImportKeibaShutsubaHistory::handle\n{$status}\n対象日:{$targetDate}、開催:{$totalKaisai}、保存:{$totalSaved}件" .
                ($failedCount > 0 ? "\n失敗: " . implode(', ', $failedList) : '')
            );
        }
    }
}
