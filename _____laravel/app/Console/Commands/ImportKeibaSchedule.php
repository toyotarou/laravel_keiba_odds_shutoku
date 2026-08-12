<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportKeibaSchedule
 *
 * 【概要】
 *   Node.js スクリプト keibaOddsGetSchedule.mjs 経由で当週の開催スケジュール・
 *   レース・出走馬情報を取得し、DB に保存する。
 *   土曜日は deleteKeibaTableRecords（cron）が事前に全テーブルを TRUNCATE するため
 *   このコマンドは INSERT のみ行う。
 *   土曜日以外（主に日曜日）は当日分のデータを DELETE してから INSERT する。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】開始バナー・DEBUGオプション確認
 *   【ブロック 3】曜日チェック（木/金はスキップ）
 *   【ブロック 4】Node.js 実行（keibaOddsGetSchedule.mjs）
 *   【ブロック 5】JSON パース・schedules/races/horses を展開
 *   【ブロック 6】出走済みチェック（start_time='XXX' の全件カウント）
 *   【ブロック 7】データ絞り込み（土曜日以外は当日分のみ）
 *   【ブロック 8】トランザクション: 対象日付を全テーブルから DELETE
 *   【ブロック 9】schedules INSERT（開催日・回・場所・日目）
 *   【ブロック 10】races INSERT（start_time='XXX' はスキップ）
 *   【ブロック 11】horses INSERT（馬番・馬名・騎手・調教師・URL）
 *   【ブロック 12】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【対象テーブル】
 *   t_horse_odds_finder_schedules … 開催スケジュール
 *   t_horse_odds_finder_races     … レース一覧（発走時刻・頭数など）
 *   t_horse_odds_finder_horses    … 出走馬情報
 *   t_horse_odds_finder_odds      … 当日分をリセット（次回importOddsがゼロから記録）
 *
 * 【土曜/日曜の違い】
 *   土曜: deleteKeibaTableRecords が事前に全テーブルを TRUNCATE → ここでは DELETE スキップ
 *   日曜: 当日分のみ DELETE してから INSERT（土曜データは消さない）
 *
 * 【cron設定】
 *   土日 6:00 に1回だけ実行（importOdds より前に完了させること）
 *
 * 【使い方】
 *   php artisan keiba:importSchedule
 *   php artisan keiba:importSchedule --debug  # 木・金チェックをスキップ
 */
class ImportKeibaSchedule extends Command
{
    protected $signature = 'keiba:importSchedule {--debug : 木曜・金曜チェックをスキップして処理する}';
    protected $description = 'スケジュール・レース・馬情報を取得してDBに保存する';

    public function handle()
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        //   cron が60秒間隔で起動しても、前の実行が終わるまで重複しないようにする。
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importSchedule.lock';
        if (file_exists($lockFile)) {
            $pid = (int) file_get_contents($lockFile);
            $isRunning = $pid > 0 && (

                (function_exists('posix_kill') && posix_kill($pid, 0))

                || file_exists("/proc/{$pid}")

            );

            if ($isRunning) {
                $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
                return 0;
            }
            $this->warn("ロックファイルの残骸を削除して続行します (PID: {$pid})");
            unlink($lockFile);
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $schedules = [];
        $races     = [];
        $horses    = [];
        $status    = '不明な理由で終了';

        try {

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 2】開始バナー・DEBUGオプション確認
            //   date('w') の値: 0=日, 1=月, ..., 5=金, 6=土
            // ─────────────────────────────────────────────────────────────
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
            // 【ブロック 3】曜日チェック（木/金はスキップ）
            //   木曜日（4）・金曜日（5）はレース開催がないためスキップする。
            //   --debug オプション時はこのチェックを迂回する。
            // ─────────────────────────────────────────────────────────────
            if (!$isDebug && (date('w') === '4' || date('w') === '5')) {
                $this->warn('本日は木曜日または金曜日のため処理をスキップします。');
                $status = '木曜・金曜のためスキップ';
                return 0;
            }

            $this->info('曜日チェック OK ── 本日は処理対象の曜日です。');
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】Node.js 実行（keibaOddsGetSchedule.mjs）
            //   timeout 300: 2開催×12R×ページ遷移 = 最大約250秒かかりうるため余裕を持たせる。
            //   stderr はログファイルへリダイレクトし stdout の JSON に混入させない。
            // ─────────────────────────────────────────────────────────────
            $script  = base_path('scripts/keibaOddsGetSchedule.mjs');
            $logFile = base_path('scripts/keibaOddsGetSchedule.log');
            $this->info('Node.js スクリプトのパス: ' . $script);
            $this->info('Node.js ログファイル    : ' . $logFile);
            $this->info('Node.js を実行してスクレイピング開始...');
            $this->info('  （ネットワーク状況によっては数秒〜数十秒かかることがあります）');

            $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';
            $this->info('node パス: ' . $nodeBin);
            $output = shell_exec('timeout 300 ' . $nodeBin . ' ' . escapeshellarg($script) . ' 2>>' . escapeshellarg($logFile));

            $this->info('Node.js スクリプト完了。出力を受け取りました。');
            $this->info('出力文字数: ' . mb_strlen($output ?? '') . ' 文字');
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 5】JSON パース・schedules/races/horses を展開
            //   パース失敗（Node.js エラー・空レスポンスなど）は処理を中断する。
            //   各キーが存在しない場合は空配列にフォールバックする。
            // ─────────────────────────────────────────────────────────────
            $this->info('JSON をパース中...');
            $data = json_decode($output, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                $this->error('JSON パース失敗。Node.js の出力内容:');
                $this->error($output);
                $this->error('処理を中断します。');
                $status = 'JSONパース失敗のため中断';
                return 1;
            }

            $this->info('JSON パース成功。');
            $this->info('');

            $this->info('データを展開中...');
            $schedules = $data['schedules'] ?? [];
            $races     = $data['races']     ?? [];
            $horses    = $data['horses']    ?? [];

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 6】出走済みチェック（start_time='XXX' の全件カウント）
            //   start_time='XXX' のレースは既に発走済みであることを示す慣例値。
            //   当日の全レースが発走済みの場合はデータ再取得は不要として早期終了する。
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
                $status = '全レース発走済みのため終了';
                return 0;
            }

            $this->info('  schedules : ' . count($schedules) . ' 件');
            $this->info('  races     : ' . count($races)     . ' 件');
            $this->info('  horses    : ' . count($horses)    . ' 件');
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 7】データ絞り込み（土曜日以外は当日分のみ）
            //   土曜日: deleteKeibaTableRecords が事前に全テーブルを TRUNCATE 済みのため
            //           絞り込みなし→土日両日分を全件 INSERT する。
            //   日曜日: 当日分のみ INSERT（土曜のデータには触れない）。
            //   DEBUGモード: 絞り込みをスキップして取得した全データを INSERT する。
            //
            //   INSERT 前に必ず DELETE → $datesToDelete で収集した日付を全テーブルで削除。
            //   これにより何度実行しても重複せずに冪等性が保たれる。
            // ─────────────────────────────────────────────────────────────
            $this->info('──────────────────────────────────────');
            $this->info('トランザクション開始 ── データを入れ替えます...');

            if (!$isDebug && date('w') !== '6') {
                $schedules = array_values(array_filter($schedules, fn($r) => $r['date'] === $today));
                $races      = array_values(array_filter($races,     fn($r) => $r['date'] === $today));
                $horses     = array_values(array_filter($horses,    fn($r) => $r['date'] === $today));
            }

            $datesToDelete = array_unique(array_merge(
                array_column($schedules, 'date'),
                array_column($races,     'date'),
                array_column($horses,    'date'),
            ));

            // start_time='XXX'（発走済み）を除外してバルク INSERT 用データを構築
            $schedulesRows = array_map(fn($r) => [
                'date'       => $r['date'],
                'kaisuu'     => $r['kaisuu'],
                'basho'      => $r['basho'],
                'basho_name' => $r['basho_name'],
                'day'        => $r['day'],
            ], $schedules);

            $racesFiltered = array_values(array_filter($races, fn($r) => $r['start_time'] !== 'XXX'));
            $racesRows     = array_map(fn($r) => [
                'date'        => $r['date'],
                'kaisuu'      => $r['kaisuu'],
                'basho'       => $r['basho'],
                'basho_name'  => $r['basho_name'],
                'day'         => $r['day'],
                'race'        => $r['race'],
                'race_name'   => $r['race_name'],
                'start_time'  => $r['start_time'],
                'num_horses'  => $r['num_horses'],
                'course'      => $r['course']      ?? '',
                'dist'        => $r['dist']        ?? 0,
                'inner_outer' => $r['inner_outer'] ?? null,
            ], $racesFiltered);

            $horsesRows = array_map(fn($r) => [
                'date'       => $r['date'],
                'kaisuu'     => $r['kaisuu'],
                'basho'      => $r['basho'],
                'race'       => $r['race'],
                'num'        => $r['num'],
                'basho_name' => $r['basho_name'],
                'day'        => $r['day'],
                'waku'       => $r['waku'],
                'name'       => $r['name'],
                'horse_url'  => $r['horse_url'],
                'jockey'     => $r['jockey'],
                'trainer'    => $r['trainer'],
            ], $horses);

            DB::transaction(function () use ($schedulesRows, $racesRows, $horsesRows, $datesToDelete) {

                // ─────────────────────────────────────────────────────────
                // 【ブロック 8】トランザクション: 対象日付を全テーブルから DELETE
                //   odds テーブルも削除する理由:
                //     スケジュール更新 = 新しいレース日の始まり なので
                //     前回 odds データをリセットして当日分をゼロから記録できるようにする。
                // ─────────────────────────────────────────────────────────
                foreach ($datesToDelete as $date) {
                    DB::table('t_horse_odds_finder_schedules')->where('date', $date)->delete();
                    DB::table('t_horse_odds_finder_races')    ->where('date', $date)->delete();
                    DB::table('t_horse_odds_finder_horses')   ->where('date', $date)->delete();
                    DB::table('t_horse_odds_finder_odds')     ->where('date', $date)->delete();
                    $this->info("  {$date} 分を全テーブルから削除完了。");
                }

                $this->info('');

                // ─────────────────────────────────────────────────────────
                // 【ブロック 9】schedules バルク INSERT
                // ─────────────────────────────────────────────────────────
                $this->info('スケジュールを INSERT 中...');
                if (!empty($schedulesRows)) {
                    DB::table('t_horse_odds_finder_schedules')->insert($schedulesRows);
                }
                $this->info("  schedules INSERT 完了 ── 合計 " . count($schedulesRows) . " 件。");
                $this->info('');

                // ─────────────────────────────────────────────────────────
                // 【ブロック 10】races バルク INSERT（start_time='XXX' 除外済み）
                // ─────────────────────────────────────────────────────────
                $this->info('レース一覧を INSERT 中...');
                if (!empty($racesRows)) {
                    DB::table('t_horse_odds_finder_races')->insert($racesRows);
                }
                $this->info("  races INSERT 完了 ── 合計 " . count($racesRows) . " 件。");
                $this->info('');

                // ─────────────────────────────────────────────────────────
                // 【ブロック 11】horses バルク INSERT
                // ─────────────────────────────────────────────────────────
                $this->info('出走馬情報を INSERT 中...');
                if (!empty($horsesRows)) {
                    DB::table('t_horse_odds_finder_horses')->insert($horsesRows);
                }
                $this->info("  horses INSERT 完了 ── 合計 " . count($horsesRows) . " 件。");
            });

            $this->info('トランザクション コミット成功。');
            $this->info('');

            $status = '正常終了';

        } finally {

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 12】完了サマリー・WebPush 通知（finally で必ず実行）
            //   どの経路（スキップ・全発走済み・パース失敗・正常終了）でも必ず実行。
            //   S=schedules、R=races、H=horses の件数を通知本文に含める。
            // ─────────────────────────────────────────────────────────────
            $cnt_schedule = count($schedules);
            $cnt_race     = count($races);
            $cnt_horse    = count($horses);

            $this->info('');
            $this->info('╔══════════════════════════════════════════════════╗');
            $this->info('║     処理結果サマリー                              ║');
            $this->info('╚══════════════════════════════════════════════════╝');
            $this->info('終了理由    : ' . $status);
            $this->info('スケジュール: ' . $cnt_schedule . ' 件');
            $this->info('レース      : ' . $cnt_race     . ' 件');
            $this->info('馬情報      : ' . $cnt_horse    . ' 件');
            $this->info('完了日時    : ' . date('Y-m-d H:i:s'));
            $this->info('=== 競馬スケジュール取得処理 ── ' . $status . ' ===');
            $this->info('');
            $this->info('========== keiba:importSchedule 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "ImportKeibaSchedule::handle\n{$status}\nS:{$cnt_schedule}、R:{$cnt_race}、H:{$cnt_horse}");
        }

        return 0;
    }
}
