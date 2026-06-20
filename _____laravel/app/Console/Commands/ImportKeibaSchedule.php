<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportKeibaSchedule
 *
 * 【役割】
 *   Node.js スクリプト経由で当週の開催スケジュール・レース・馬情報を取得し、
 *   DB に保存する。
 *   土曜日は deleteKeibaTableRecords（cron）が事前に全テーブルを TRUNCATE するため、
 *   このコマンドは INSERT のみ行う。
 *   土曜日以外（主に日曜日）は当日分のデータを DELETE してから INSERT する。
 *
 * 【cron スケジュール】
 *   土日 6:00 に1回だけ実行（importOdds より前に完了させること）
 *   土曜日は事前に deleteKeibaTableRecords（全テーブル TRUNCATE）が実行されること
 *
 * 【対象テーブル】
 *   t_horse_odds_finder_schedules … 開催スケジュール
 *   t_horse_odds_finder_races     … レース一覧（発走時刻・頭数など）
 *   t_horse_odds_finder_horses    … 出走馬情報
 *   t_horse_odds_finder_odds      … オッズ（importOdds が書き込む）
 *                                   ※ スケジュール更新時に一緒にリセットして
 *                                     当日分をゼロから記録できるようにする
 *
 * =====================================================================
 * handle() の流れ
 * =====================================================================
 *
 * 【起動・初期化】
 *   - 開始バナーをログ出力する
 *   - 実行日時・曜日番号をログ出力する
 *
 * 【曜日チェック】
 *   - 木曜日（4）または金曜日（5）の場合は競馬開催がないため処理をスキップして終了する
 *   - それ以外の曜日の場合は処理を続行する
 *
 * 【Node.js スクリプトの実行】
 *   - スクリプトパス・ログファイルパス・node バイナリのパスを変数にセットする
 *   - Node.js スクリプト（keibaOddsGetSchedule.mjs）を実行する
 *   - stderr はログファイルへリダイレクトし、stdout の JSON には混入させない
 *   - 出力文字数をログ出力する
 *
 * 【JSON パース】
 *   - Node.js の出力を JSON としてパースし連想配列に変換する
 *   - パース失敗（Node.js エラー・空レスポンスなど）の場合はエラーログを出して処理を中断する（return 1）
 *
 * 【データの展開】
 *   - JSON から schedules・races・horses の3配列を取り出す
 *   - キーが存在しない場合は空配列にフォールバックする
 *
 * 【出走済みチェック】
 *   - start_time が 'XXX' のレースは既に発走済みであることを示す
 *   - 当日のレース総数と、start_time === 'XXX'（発走済み）のレース数をカウントする
 *   - 当日レースが1件以上あり、かつ全てが発走済みの場合、データ再取得は不要として終了する
 *   - 未発走のレースが1件でも残っている場合は処理を続行する
 *   - 取得件数（絞り込み前）をログ出力する
 *
 * 【データの絞り込み】
 *   - 土曜日以外の場合：schedules・races・horses を当日分のみに絞り込む
 *                       （土曜日のデータには触れないようにするため）
 *   - 土曜日の場合    ：絞り込みをせず、土日両日分の全件をそのまま使う
 *
 * 【トランザクション内でデータを入れ替え】
 *   - トランザクションを開始する
 *   - 土曜日以外の場合：当日分のデータを DELETE してから INSERT する
 *       - t_horse_odds_finder_schedules の当日分を DELETE する
 *       - t_horse_odds_finder_races     の当日分を DELETE する
 *       - t_horse_odds_finder_horses    の当日分を DELETE する
 *       - t_horse_odds_finder_odds      の当日分を DELETE する
 *         （スケジュール更新 = 新しいレース日の始まり なので
 *           前回の odds データをリセットして当日分をゼロから記録できるようにする）
 *   - 土曜日の場合：deleteKeibaTableRecords（cron）が事前に TRUNCATE 済みのため DELETE はスキップする
 *   - schedules を INSERT する
 *       - 開催日・開催回・場所・場所名・何日目かを記録する
 *       - 進捗を10件ごとにログ出力する
 *       - INSERT 完了件数をログ出力する
 *   - races を INSERT する
 *       - 発走時刻・頭数など importOdds が参照する情報を記録する
 *       - 発走済みレース（start_time === 'XXX'）は INSERT をスキップする
 *       - 1件ごとに場所名・レース番号・レース名・発走時刻をログ出力する
 *       - INSERT 完了件数をログ出力する
 *   - horses を INSERT する
 *       - 馬番・枠番・馬名・騎手・調教師・馬 URL などを記録する
 *       - 進捗を10件ごとにログ出力する
 *       - INSERT 完了件数をログ出力する
 *   - トランザクションをコミットする（INSERT 途中で失敗した場合は自動でロールバックされる）
 *
 * 【終了処理】
 *   - 処理結果サマリー（schedules・races・horses の件数・完了日時）をログ出力する
 *   - 正常終了を示すメッセージをログ出力する
 *   - return 0 を返す
 */
class ImportKeibaSchedule extends Command
{
    protected $signature = 'keiba:importSchedule {--debug : 木曜・金曜チェックをスキップして処理する}';
    protected $description = 'スケジュール・レース・馬情報を取得してDBに保存する';

    public function handle()
    {
        // ── 多重起動防止 ─────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importSchedule.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return 0;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $this->info('');
        $this->info('╔══════════════════════════════════════════════════╗');
        $this->info('║     競馬スケジュール取得処理 ── 開始              ║');
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->info('実行日時: ' . date('Y-m-d H:i:s') . '  曜日番号: ' . date('w') . ' （0=日, 6=土）');
        $this->info('');

        $isDebug = (bool) $this->option('debug');
        if ($isDebug) {
            $this->warn('【DEBUGモード】木曜・金曜チェックをスキップします。');
            $this->info('');
        }

        // ─────────────────────────────────────────────────────────────
        // 木曜日（4）・金曜日（5）はレース開催がないためスキップする
        // ─────────────────────────────────────────────────────────────
        if (!$isDebug && (date('w') === '4' || date('w') === '5')) {
            $this->warn('本日は木曜日または金曜日のため処理をスキップします。');
            $this->info('終了。');
            return 0;
        }

        $this->info('曜日チェック OK ── 本日は処理対象の曜日です。');
        $this->info('');

        // ─────────────────────────────────────────────────────────────
        // Node.js スクリプトを実行してスケジュール JSON を取得
        // stderr はログファイルへリダイレクトし、stdout の JSON には混入させない
        // ─────────────────────────────────────────────────────────────
        $script  = base_path('scripts/keibaOddsGetSchedule.mjs');
        $logFile = base_path('scripts/keibaOddsGetSchedule.log');
        $this->info('Node.js スクリプトのパス: ' . $script);
        $this->info('Node.js ログファイル    : ' . $logFile);
        $this->info('Node.js を実行してスクレイピング開始...');
        $this->info('  （ネットワーク状況によっては数秒〜数十秒かかることがあります）');

        $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';
        $this->info('node パス: ' . $nodeBin);
        // timeout 300: 2開催×12R×4秒 + ページ遷移 = 最大約250秒かかりうるため180では不足
        $output = shell_exec('timeout 300 ' . $nodeBin . ' ' . escapeshellarg($script) . ' 2>>' . escapeshellarg($logFile));

        $this->info('Node.js スクリプト完了。出力を受け取りました。');
        $this->info('出力文字数: ' . mb_strlen($output ?? '') . ' 文字');
        $this->info('');

        // ─────────────────────────────────────────────────────────────
        // Node.js の出力を連想配列にパース
        // ─────────────────────────────────────────────────────────────
        $this->info('JSON をパース中...');
        $data = json_decode($output, true);

        // パース失敗（Node.js エラー・空レスポンスなど）は処理を中断
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->error('JSON パース失敗。Node.js の出力内容:');
            $this->error($output);
            $this->error('処理を中断します。');
            return 1;
        }

        $this->info('JSON パース成功。');
        $this->info('');

        // ─────────────────────────────────────────────────────────────
        // JSON の各キーを取り出す（キーが存在しない場合は空配列にフォールバック）
        // ─────────────────────────────────────────────────────────────
        $this->info('データを展開中...');
        $schedules = $data['schedules'] ?? [];
        $races     = $data['races']     ?? [];
        $horses    = $data['horses']    ?? [];

        // ─────────────────────────────────────────────────────────────
        // 出走済みチェック
        // start_time が 'XXX' のレースは既に発走済みであることを示す。
        // 当日の全レースが発走済みの場合、データ再取得は不要として終了する。
        // ─────────────────────────────────────────────────────────────
        $today = date('Y-m-d');
        $todayRaceCount = 0;
        $finishedCount  = 0;
        foreach ($races as $race) {
            if ($race['date'] === $today) {
                $todayRaceCount++;
                if ($race['start_time'] === 'XXX') {
                    $finishedCount++;
                }
            }
        }

        if ($todayRaceCount > 0 && $finishedCount === $todayRaceCount) {
            $this->warn('当日の全レースが発走済みのため、終了します。');
            $this->info('終了。');
            return 0;
        }

        // 取得件数を表示（土曜日以外はこの後当日分に絞り込む）
        $this->info('  schedules : ' . count($schedules) . ' 件');
        $this->info('  races     : ' . count($races)     . ' 件');
        $this->info('  horses    : ' . count($horses)    . ' 件');
        $this->info('');

        // ─────────────────────────────────────────────────────────────
        // トランザクション内でデータを入れ替え（DELETE → INSERT）
        //
        // 土曜日:
        //   deleteKeibaTableRecords（cron）が事前に全テーブルを TRUNCATE 済みのため
        //   ここでは DELETE をスキップして INSERT だけ行う。
        //
        // 土曜日以外（主に日曜日）:
        //   当日分のデータのみ DELETE してから INSERT する。
        //   トランザクションで囲むことで、INSERT 途中に失敗しても
        //   DELETE 前の状態にロールバックされる。
        //
        // odds テーブルも削除する理由:
        //   スケジュールを入れ替える = 新しいレース日の始まり なので、
        //   前回の odds データをリセットして当日分をゼロから記録できるようにする。
        // ─────────────────────────────────────────────────────────────
        $this->info('──────────────────────────────────────');
        $this->info('トランザクション開始 ── データを入れ替えます...');

        // 土曜日以外は当日分のみ INSERT（土曜日のデータには触れない）
        // DEBUGモード時は絞り込みをスキップして取得した全データを INSERT する
        if (!$isDebug && date('w') !== '6') {
            $schedules = array_values(array_filter($schedules, fn($r) => $r['date'] === $today));
            $races      = array_values(array_filter($races,     fn($r) => $r['date'] === $today));
            $horses     = array_values(array_filter($horses,    fn($r) => $r['date'] === $today));
        }

        // INSERT する対象日付を収集（重複防止のため INSERT 前に必ず DELETE する）
        $datesToDelete = array_unique(array_merge(
            array_column($schedules, 'date'),
            array_column($races,     'date'),
            array_column($horses,    'date'),
        ));

        DB::transaction(function () use ($schedules, $races, $horses, $datesToDelete) {

            // 挿入対象の日付ぶんを事前に全削除（何度実行しても重複しない）
            foreach ($datesToDelete as $date) {
                DB::table('t_horse_odds_finder_schedules')->where('date', $date)->delete();
                DB::table('t_horse_odds_finder_races')    ->where('date', $date)->delete();
                DB::table('t_horse_odds_finder_horses')   ->where('date', $date)->delete();
                DB::table('t_horse_odds_finder_odds')     ->where('date', $date)->delete();
                $this->info("  {$date} 分を全テーブルから削除完了。");
            }

            $this->info('');

            // ── スケジュールを INSERT ──
            // 開催日・開催回・場所・場所名・何日目かを記録する
            $this->info('スケジュールを INSERT 中...');
            $count = 0;
            foreach ($schedules as $row) {
                DB::table('t_horse_odds_finder_schedules')->insert([
                    'date'       => $row['date'],
                    'kaisuu'     => $row['kaisuu'],
                    'basho'      => $row['basho'],
                    'basho_name' => $row['basho_name'],
                    'day'        => $row['day'],
                ]);
                $count++;
                // 進捗を10件ごとに表示
                if ($count % 10 === 0) {
                    $this->line("  schedules: {$count} 件挿入済み...");
                }
            }
            $this->info("  schedules INSERT 完了 ── 合計 {$count} 件。");
            $this->info('');

            // ── レース一覧を INSERT ──
            // 発走時刻・頭数など importOdds が参照する情報を記録する
            $this->info('レース一覧を INSERT 中...');
            $count = 0;
            foreach ($races as $row) {

                // 発走済みレース（start_time === 'XXX'）は INSERT をスキップ
                if ($row['start_time'] === 'XXX') {
                    continue;
                }

                DB::table('t_horse_odds_finder_races')->insert([
                    'date'       => $row['date'],
                    'kaisuu'     => $row['kaisuu'],
                    'basho'      => $row['basho'],
                    'basho_name' => $row['basho_name'],
                    'day'        => $row['day'],
                    'race'       => $row['race'],
                    'race_name'  => $row['race_name'],
                    'start_time' => $row['start_time'],
                    'num_horses' => $row['num_horses'],
                ]);
                $count++;
                $this->line("  races: [{$row['basho_name']}] R{$row['race']} {$row['race_name']} ({$row['start_time']}) 挿入...");
            }
            $this->info("  races INSERT 完了 ── 合計 {$count} 件。");
            $this->info('');

            // ── 出走馬情報を INSERT ──
            // 馬番・馬名・騎手・調教師・馬 URL などを記録する
            $this->info('出走馬情報を INSERT 中...');
            $count = 0;
            foreach ($horses as $row) {
                DB::table('t_horse_odds_finder_horses')->insert([
                    'date'       => $row['date'],
                    'kaisuu'     => $row['kaisuu'],
                    'basho'      => $row['basho'],
                    'race'       => $row['race'],
                    'num'        => $row['num'],
                    'basho_name' => $row['basho_name'],
                    'day'        => $row['day'],
                    'waku'       => $row['waku'],
                    'name'       => $row['name'],
                    'horse_url'  => $row['horse_url'],
                    'jockey'     => $row['jockey'],
                    'trainer'    => $row['trainer'],
                ]);
                $count++;
                // 進捗を10件ごとに表示
                if ($count % 10 === 0) {
                    $this->line("  horses: {$count} 件挿入済み...");
                }
            }
            $this->info("  horses INSERT 完了 ── 合計 {$count} 件。");
        });

        $this->info('トランザクション コミット成功。');
        $this->info('');

        // ── 最終サマリー ──
        $this->info('╔══════════════════════════════════════════════════╗');
        $this->info('║     処理結果サマリー                              ║');
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->info('スケジュール: ' . count($schedules) . ' 件');
        $this->info('レース      : ' . count($races)     . ' 件');
        $this->info('馬情報      : ' . count($horses)    . ' 件');
        $this->info('完了日時    : ' . date('Y-m-d H:i:s'));
        $this->info('');
        $this->info('=== 競馬スケジュール取得処理 ── 正常終了 ===');
        $this->info('');
        
        return 0;
    }
}
