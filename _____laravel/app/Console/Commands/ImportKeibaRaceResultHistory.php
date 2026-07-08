<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportKeibaRaceResultHistory
 *
 * 【概要】
 *   指定年月の全開催・全レースの最終単勝・複勝オッズを
 *   keibaOddsGetRaceResultHistory.mjs 経由で取得し、
 *   t_horse_odds_finder_race_result_history に保存する。
 *   2段階処理: まず --list-only で開催一覧を取得し、
 *   次に各開催を順番に処理してオッズを取得・保存する。
 *   インポート済みの開催は Node.js 実行前にスキップして時間を節約する。
 *
 * 【処理フロー】
 *   【ブロック 1】引数チェック（YYYY-MM 形式の検証）
 *   【ブロック 2】多重起動防止（年月別ロックファイル）
 *   【ブロック 3】初期化・開始バナー
 *   【ブロック 4】Step 1: --list-only で開催一覧を取得
 *   【ブロック 5】インポート済み開催をDBから先読み（PRE-SKIP 判定用）
 *   【ブロック 6】Step 2: 各開催を順番に処理
 *   【ブロック 7】Node.js 実行（リトライ最大3回）
 *   【ブロック 8】DB保存（馬ごとに updateOrInsert）
 *   【ブロック 9】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【使い方】
 *   php artisan keiba:importRaceResultHistory --yearmonth=2021-01
 *   php artisan keiba:importRaceResultHistory  # 当月
 */
class ImportKeibaRaceResultHistory extends Command
{
    protected $signature   = 'keiba:importRaceResultHistory {--yearmonth= : 対象年月 (例: 2021-01)}';
    protected $description = '指定年月の全開催・全レースの最終オッズを取得してDBに保存する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】引数チェック（YYYY-MM 形式の検証）
        //   省略時は当月（date('Y-m')）を対象にする。
        //   正規表現 /^\d{4}-\d{2}$/ で厳密な形式チェックを行う。
        // ─────────────────────────────────────────────────────────────────
        $yearmonth = $this->option('yearmonth') ?: date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $yearmonth)) {
            $this->error('--yearmonth=YYYY-MM の形式で指定してください。例: --yearmonth=2021-01');
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】多重起動防止（年月別ロックファイル）
        //   年月をロックファイル名に含めることで、
        //   異なる年月の同時実行は許可しつつ、同一年月の重複起動のみを防ぐ。
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importRaceResultHistory_' . str_replace('-', '', $yearmonth) . '.lock';
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
        $script  = base_path('scripts/keibaOddsGetRaceResultHistory.mjs');
        $logFile = base_path('scripts/keibaOddsGetRaceResultHistory.log');
        $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';

        $totalKaisai = 0;
        $totalSaved  = 0;
        $failedList  = [];
        $status      = '不明な理由で終了';

        try {
            $this->info('');
            $this->info('========== keiba:importRaceResultHistory 開始 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('対象年月     : ' . $yearmonth);
            $this->info('スクリプト   : ' . $script);
            $this->info('ログファイル : ' . $logFile);
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】Step 1: --list-only で開催一覧を取得
            //   --list-only オプションで keibaOddsGetRaceResultHistory.mjs を実行し、
            //   指定年月の全開催名リスト（kaisaiList）だけを JSON で受け取る。
            //   kaisaiList が空（当月の開催がまだない）は正常終了扱い。
            //   kaisaiList キー自体がない場合は取得失敗とみなす。
            // ─────────────────────────────────────────────────────────────
            $this->info('[Step 1] 開催一覧を取得中...');

            $listCommand = 'timeout 120 ' . $nodeBin . ' ' . escapeshellarg($script)
                . ' --yearmonth=' . escapeshellarg($yearmonth)
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

            $kaisaiList  = $listJson['kaisaiList'];
            $totalKaisai = count($kaisaiList);

            if (empty($kaisaiList)) {
                $this->warn('対象開催なし（当月の結果確定済み開催がまだありません）。');
                $status = '対象開催なし';
                return;
            }

            $this->info("  → {$totalKaisai} 開催を検出: " . implode(', ', $kaisaiList));
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 5】インポート済み開催をDBから先読み（PRE-SKIP 判定用）
            //   $existingKaisaiKeys: date_kaisuu_basho_code_day をキーにしたハッシュセット。
            //     Node.js 実行後に kaisai キーが一致したらDBスキップ。
            //   $preSkipSet: kaisuu_basho_code_day のみのキー。
            //     Node.js 実行前に開催名から解析して一致したらNode.js自体をスキップ。
            //   両者を使い分けることで: Node.js起動コストを最小化しつつ
            //   mjs の日付解析が頼りにならない場合も安全に保護する。
            // ─────────────────────────────────────────────────────────────
            $this->info('[事前確認] インポート済みkaisaiを確認中...');
            [$year, $month] = explode('-', $yearmonth);
            $from = "{$year}-{$month}-01";
            $to   = date('Y-m-t', strtotime($from));
            $existingKaisaiRaw = DB::table('t_horse_odds_finder_race_result_history')
                ->whereBetween('date', [$from, $to])
                ->select('date', 'kaisuu', 'basho_code', 'day')
                ->distinct()
                ->get();
            $existingKaisaiKeys = $existingKaisaiRaw
                ->mapWithKeys(fn($r) => ["{$r->date}_{$r->kaisuu}_{$r->basho_code}_{$r->day}" => true])
                ->toArray();
            $preSkipSet = $existingKaisaiRaw
                ->mapWithKeys(fn($r) => ["{$r->kaisuu}_{$r->basho_code}_{$r->day}" => true])
                ->toArray();
            $this->info('  インポート済み開催: ' . count($existingKaisaiKeys) . ' 件');
            $this->info('');

            // kaisaiテキスト ("3回東京1日") から basho_code を導出するマップ
            $bashoMap = [
                '札幌' => '01', '函館' => '02', '福島' => '03', '新潟' => '04',
                '東京' => '05', '中山' => '06', '中京' => '07', '京都' => '08',
                '阪神' => '09', '小倉' => '10',
            ];

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 6】Step 2: 各開催を順番に処理
            //   Node.js 実行前に preg_match で kaisai テキストを解析して
            //   $preSkipSet に存在する場合は Node.js 実行そのものをスキップする。
            //   kaisai テキスト例: "3回東京1日" → kaisuu=3, basho_code=05, day=1
            // ─────────────────────────────────────────────────────────────
            $kaisaiIndex = 0;

            foreach ($kaisaiList as $kaisai) {
                $kaisaiIndex++;

                if (preg_match('/^(\d+)回(.+?)(\d+)日$/u', $kaisai, $m)) {
                    $preKey = "{$m[1]}_" . ($bashoMap[$m[2]] ?? '??') . "_{$m[3]}";
                    if (isset($preSkipSet[$preKey])) {
                        $this->info("[{$kaisaiIndex}/{$totalKaisai}] {$kaisai} → [PRE-SKIP] インポート済み（Node.js省略）");
                        continue;
                    }
                }

                $this->info('══════════════════════════════════════════════════════');
                $this->info("[{$kaisaiIndex}/{$totalKaisai}] {$kaisai} 処理開始");
                $this->info('══════════════════════════════════════════════════════');

                $command = 'timeout 300 ' . $nodeBin . ' ' . escapeshellarg($script)
                    . ' --yearmonth=' . escapeshellarg($yearmonth)
                    . ' --kaisai="' . $kaisai . '"'
                    . ' 2>>' . escapeshellarg($logFile);

                $this->info('  実行: ' . $command);
                $this->info('');

                // ─────────────────────────────────────────────────────────
                // 【ブロック 7】Node.js 実行（リトライ最大3回）
                //   成功条件: $result['races'] が空でない配列。
                //   timeout 300: 1開催 × 複数レース分のページ遷移に余裕を持たせる。
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

                $kaisaiKey = "{$result['date']}_{$result['kaisuu']}_{$result['basho_code']}_{$result['day']}";
                if (isset($existingKaisaiKeys[$kaisaiKey])) {
                    $this->info("  [SKIP] インポート済み: {$kaisaiKey} ({$elapsed}秒)");
                    $this->info('');
                    continue;
                }

                $raceCount = count($result['races']);
                $this->info("  取得完了: {$raceCount} レース / 日付: {$result['date']} ({$elapsed}秒)");
                $this->info('');

                // ─────────────────────────────────────────────────────────
                // 【ブロック 8】DB保存（馬ごとに updateOrInsert）
                //   updateOrInsert($key, $data): $key の行が存在すれば UPDATE、なければ INSERT。
                //   popularity_rank / finishing_position は初期値0で保存する。
                //   後続の SummaryHistoryPopularityRank / SummaryHistoryFinishingPosition が
                //   これらを正しい値で埋める。
                // ─────────────────────────────────────────────────────────
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
                            'popularity_rank'      => 0,
                            'finishing_position'   => 0,
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

            $status = (count($failedList) > 0) ? '正常終了（一部失敗あり）' : '正常終了';

        } finally {
            // ─────────────────────────────────────────────────────────────
            // 【ブロック 9】完了サマリー・WebPush 通知（finally で必ず実行）
            //   $status を含む通知を開発者向けに送信する。
            //   失敗開催は個別に error ログで列挙する。
            // ─────────────────────────────────────────────────────────────
            $totalElapsed = round(microtime(true) - $now, 1);
            $failedCount  = count($failedList);

            $this->info('');
            $this->info("終了理由     : {$status}");
            $this->info("対象年月     : {$yearmonth}");
            $this->info("処理開催数   : {$totalKaisai} 開催");
            $this->info("保存頭数合計 : {$totalSaved} 頭");
            $this->info("失敗開催数   : {$failedCount} 件" . ($failedCount > 0 ? ' ← 要確認' : ''));
            foreach ($failedList as $f) {
                $this->error("  [FAIL] {$f}");
            }
            $this->info("処理時間     : {$totalElapsed} 秒");
            $this->info('');
            $this->info('========== keiba:importRaceResultHistory 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "ImportKeibaRaceResultHistory::handle\n{$status}\n対象年月:{$yearmonth}、開催:{$totalKaisai}、頭数:{$totalSaved}");
        }
    }
}
