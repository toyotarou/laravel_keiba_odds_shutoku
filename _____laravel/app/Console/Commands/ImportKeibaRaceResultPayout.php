<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportKeibaRaceResultPayout
 *
 * 【概要】
 *   指定年月の全開催・全レースの払戻金を keibaOddsGetPayout.mjs 経由で取得し、
 *   t_horse_odds_finder_race_result_payout に保存する。
 *   ImportKeibaRaceResultHistory と同じ2段階処理（--list-only → 個別処理）。
 *   払戻金は券種別に '馬番組合せ|払戻額' 形式にまとめ、'/' で結合した文字列で保存する。
 *
 * 【処理フロー】
 *   【ブロック 1】引数チェック（YYYY-MM 形式の検証）
 *   【ブロック 2】多重起動防止（年月別ロックファイル）
 *   【ブロック 3】初期化・開始バナー
 *   【ブロック 4】Step 1: --list-only で開催一覧を取得
 *   【ブロック 5】インポート済み開催をDBから先読み（PRE-SKIP 判定用）
 *   【ブロック 6】Step 2: 各開催を順番に処理（PRE-SKIP → Node.js → DB保存）
 *   【ブロック 7】Node.js 実行（リトライ最大3回）
 *   【ブロック 8】typeMap による券種名→カラム名変換・バケツへの仕分け
 *   【ブロック 9】DB保存（EXISTS チェック → 未保存のみ INSERT）
 *   【ブロック 10】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【typeMap 変換規則】
 *   '単勝' → 'tan', '複勝' → 'fuku', '枠連' → 'waku', 'ワイド' → 'wide'
 *   '馬連' → 'umaren', '馬単' → 'umatan'
 *   '3連複'/'３連複' → 'trio', '3連単'/'３連単' → 'trifecta'
 *   バケット内の要素は '馬番|金額' 形式。複数は '/' で連結（例: 'tan' = '14|180'）
 *
 * 【使い方】
 *   php artisan keiba:importRaceResultPayout --yearmonth=2023-01
 *   php artisan keiba:importRaceResultPayout  # 当月
 */
class ImportKeibaRaceResultPayout extends Command
{
    protected $signature   = 'keiba:importRaceResultPayout {--yearmonth= : 対象年月 (例: 2023-01)}';
    protected $description = '指定年月の全開催・全レースの払戻金を取得してDBに保存する';

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
        $lockFile = sys_get_temp_dir() . '/keiba_importRaceResultPayout_' . str_replace('-', '', $yearmonth) . '.lock';
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
        $script  = base_path('scripts/keibaOddsGetPayout.mjs');
        $logFile = base_path('scripts/keibaOddsGetPayout.log');
        $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';

        $totalKaisai = 0;
        $totalSaved  = 0;
        $failedList  = [];
        $status      = '不明な理由で終了';

        try {
            $this->info('');
            $this->info('========== keiba:importRaceResultPayout 開始 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('対象年月     : ' . $yearmonth);
            $this->info('スクリプト   : ' . $script);
            $this->info('ログファイル : ' . $logFile);
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】Step 1: --list-only で開催一覧を取得
            //   ImportKeibaRaceResultHistory と同じ2段階処理パターン。
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
            //   $existingKaisaiKeys (date付きキー) は Node.js 実行後の最終スキップに使う。
            //   $existingKaisaiShortKeys (date無しキー) は Node.js 実行前の早期スキップに使う。
            // ─────────────────────────────────────────────────────────────
            $this->info('[事前確認] インポート済みkaisaiを確認中...');
            [$year, $month] = explode('-', $yearmonth);
            $from = "{$year}-{$month}-01";
            $to   = date('Y-m-t', strtotime($from));
            $existingRows = DB::table('t_horse_odds_finder_race_result_payout')
                ->whereBetween('date', [$from, $to])
                ->select('date', 'kaisuu', 'basho_code', 'day')
                ->distinct()
                ->get();
            $existingKaisaiKeys      = $existingRows
                ->mapWithKeys(fn($r) => ["{$r->date}_{$r->kaisuu}_{$r->basho_code}_{$r->day}" => true])
                ->toArray();
            $existingKaisaiShortKeys = $existingRows
                ->mapWithKeys(fn($r) => ["{$r->kaisuu}_{$r->basho_code}_{$r->day}" => true])
                ->toArray();
            $this->info('  インポート済み開催: ' . count($existingKaisaiKeys) . ' 件');
            $this->info('');

            $bashoMap = [
                '札幌' => '01', '函館' => '02', '福島' => '03', '新潟' => '04',
                '東京' => '05', '中山' => '06', '中京' => '07', '京都' => '08',
                '阪神' => '09', '小倉' => '10',
            ];

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 6】Step 2: 各開催を順番に処理（PRE-SKIP → Node.js → DB保存）
            //   kaisai テキスト "3回東京1日" を正規表現で解析し、
            //   $existingKaisaiShortKeys に一致したら Node.js 実行そのものをスキップ。
            // ─────────────────────────────────────────────────────────────
            $kaisaiIndex = 0;

            foreach ($kaisaiList as $kaisai) {
                $kaisaiIndex++;

                $this->info('══════════════════════════════════════════════════════');
                $this->info("[{$kaisaiIndex}/{$totalKaisai}] {$kaisai} 処理開始");
                $this->info('══════════════════════════════════════════════════════');

                if (preg_match('/^(\d+)回(.+?)(\d+)日$/', $kaisai, $m)) {
                    $shortKey = (int)$m[1] . '_' . ($bashoMap[$m[2]] ?? '') . '_' . (int)$m[3];
                    if (!empty($bashoMap[$m[2]]) && isset($existingKaisaiShortKeys[$shortKey])) {
                        $this->info("  [SKIP] インポート済み（Node.js実行省略）: {$kaisai}");
                        $this->info('');
                        continue;
                    }
                }

                $command = 'timeout 300 ' . $nodeBin . ' ' . escapeshellarg($script)
                    . ' --yearmonth=' . escapeshellarg($yearmonth)
                    . ' --kaisai="' . $kaisai . '"'
                    . ' 2>>' . escapeshellarg($logFile);

                $this->info('  実行: ' . $command);
                $this->info('');

                // ─────────────────────────────────────────────────────────
                // 【ブロック 7】Node.js 実行（リトライ最大3回）
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
                // 【ブロック 8】typeMap による券種名→カラム名変換・バケツへの仕分け
                //   $payout['type'] は JRA払戻ページから取得した券種名（漢字・全角含む）。
                //   $typeMap で正規化して $buckets[カラム名][] に 'combo|amount' 形式で格納。
                //   例: 単勝14番→180円 → $buckets['tan'][] = '14|180'
                //   例: 複勝が3着同着 → $buckets['fuku'] = ['14|110', '5|150', '1|480']
                //       → implode('/', ...) で 'tan' = '14|180', 'fuku' = '14|110/5|150/1|480'
                // ─────────────────────────────────────────────────────────
                $saved = 0;
                foreach ($result['races'] as $raceData) {
                    $this->info("    {$raceData['race']}R 「{$raceData['race_name']}」 保存中...");

                    $typeMap = [
                        '単勝'  => 'tan',
                        '複勝'  => 'fuku',
                        '枠連'  => 'waku',
                        'ワイド' => 'wide',
                        '馬連'  => 'umaren',
                        '馬単'  => 'umatan',
                        '3連複' => 'trio',
                        '３連複' => 'trio',
                        '3連単' => 'trifecta',
                        '３連単' => 'trifecta',
                    ];

                    $buckets = [
                        'tan' => [], 'fuku' => [], 'waku' => [], 'wide' => [],
                        'umaren' => [], 'umatan' => [], 'trio' => [], 'trifecta' => [],
                    ];

                    foreach ($raceData['payouts'] as $payout) {
                        $col = $typeMap[$payout['type']] ?? null;
                        if ($col === null) continue;
                        $buckets[$col][] = $payout['combo'] . '|' . $payout['amount'];
                    }

                    // ─────────────────────────────────────────────────────
                    // 【ブロック 9】DB保存（EXISTS チェック → 未保存のみ INSERT）
                    //   同一 $key が既に存在する場合はスキップ（冪等性の確保）。
                    //   bucket を '/' で連結した文字列でカラムに格納する。
                    // ─────────────────────────────────────────────────────
                    $key = [
                        'date'       => $result['date'],
                        'kaisuu'     => $result['kaisuu'],
                        'basho_code' => $result['basho_code'],
                        'day'        => $result['day'],
                        'race'       => $raceData['race'],
                    ];
                    $data = [
                        'basho'     => $result['basho'],
                        'race_name' => $raceData['race_name'],
                        'tan'       => implode('/', $buckets['tan'])      ?: null,
                        'fuku'      => implode('/', $buckets['fuku'])     ?: null,
                        'waku'      => implode('/', $buckets['waku'])     ?: null,
                        'wide'      => implode('/', $buckets['wide'])     ?: null,
                        'umaren'    => implode('/', $buckets['umaren'])   ?: null,
                        'umatan'    => implode('/', $buckets['umatan'])   ?: null,
                        'trio'      => implode('/', $buckets['trio'])     ?: null,
                        'trifecta'  => implode('/', $buckets['trifecta']) ?: null,
                    ];

                    if (DB::table('t_horse_odds_finder_race_result_payout')->where($key)->exists()) {
                        $this->info("    {$raceData['race']}R → スキップ（既存）");
                        continue;
                    }

                    DB::table('t_horse_odds_finder_race_result_payout')
                        ->insert(array_merge($key, $data));
                    $saved++;
                    $this->info("    {$raceData['race']}R → 保存完了");
                }

                $totalSaved += $saved;
                $this->info('');
                $this->info("  [{$kaisaiIndex}/{$totalKaisai}] {$kaisai} 完了 → {$saved} レース保存");
                $this->info('');
            }

            $status = (count($failedList) > 0) ? '正常終了（一部失敗あり）' : '正常終了';

        } finally {
            // ─────────────────────────────────────────────────────────────
            // 【ブロック 10】完了サマリー・WebPush 通知（finally で必ず実行）
            // ─────────────────────────────────────────────────────────────
            $totalElapsed = round(microtime(true) - $now, 1);
            $failedCount  = count($failedList);

            $this->info('');
            $this->info("終了理由     : {$status}");
            $this->info("対象年月     : {$yearmonth}");
            $this->info("処理開催数   : {$totalKaisai} 開催");
            $this->info("保存レース数 : {$totalSaved} レース");
            $this->info("失敗開催数   : {$failedCount} 件" . ($failedCount > 0 ? ' ← 要確認' : ''));
            foreach ($failedList as $f) {
                $this->error("  [FAIL] {$f}");
            }
            $this->info("処理時間     : {$totalElapsed} 秒");
            $this->info('');
            $this->info('========== keiba:importRaceResultPayout 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "ImportKeibaRaceResultPayout::handle\n{$status}\n対象年月:{$yearmonth}、開催:{$totalKaisai}、レース:{$totalSaved}");
        }
    }
}
