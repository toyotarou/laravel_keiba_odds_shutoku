<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RepairKeibaStartTime
 *
 * 【概要】
 *   Node.js スクリプト keibaOddsCollectStartTime.mjs 経由で
 *   JRA公式サイトから当日開催の全レースの発走時刻を取得し、
 *   DB（t_horse_odds_finder_races）に登録済みの発走時刻と比較して
 *   差異があれば上書きする。
 *
 *   レース遅延の影響で後続レースの発走時刻がずれる場合があるため、
 *   レース時間帯（9:00〜20:00）の間、毎分 cron で実行することを想定している。
 *   ほぼ空振りになるため、Node.js（Playwright）を起動する前に
 *   安価なチェックで早期終了することで無駄なコストを抑える。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】開始ログ（空振り含め毎回出力）
 *   【ブロック 3】早期終了チェック① 今日のレース登録（0件なら即終了）
 *   【ブロック 4】早期終了チェック② 未発走レース（全レース終了済みなら即終了）
 *   【ブロック 5】開始バナー（Node.js 起動時のみ）
 *   【ブロック 6】Node.js 実行（keibaOddsCollectStartTime.mjs）
 *   【ブロック 7】JSON パース
 *   【ブロック 8】レースごとに DB と比較して差異があれば UPDATE
 *   【ブロック 9】完了サマリー
 *
 * 【対象テーブル】
 *   t_horse_odds_finder_races … 発走時刻（start_time）を更新
 *
 * 【使い方】
 *   php artisan keiba:repairStartTime
 */
class RepairKeibaStartTime extends Command
{
    protected $signature   = 'keiba:repairStartTime {--date= : 対象日付（Y-m-d形式）。省略時は本日}';
    protected $description = 'JRAサイトから発走時刻を取得し、DB と差異があれば上書きする';

    public function handle()
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_repairStartTime.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return 0;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $updatedCount = 0;
        $checkedCount = 0;

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】開始ログ（空振り含め毎回出力）
        //   cron が 9-18 で制御するため時刻チェックはここでは行わない。
        //   空振りのときも何が起きたか追跡できるよう毎回ログに残す。
        //   --date オプション指定時はその日付を対象にする（デバッグ用）。
        // ─────────────────────────────────────────────────────────────────
        $dateOption = $this->option('date');
        $today      = $dateOption ?? date('Y-m-d');
        $isDebug    = (bool) $dateOption;

        $this->info('---------- keiba:repairStartTime 起動 ' . date('Y-m-d H:i:s') . ' ----------');
        if ($isDebug) {
            $this->warn('【DEBUGモード】対象日付: ' . $today);
            (new WebPushService())->sendPushNotifierDeveloperNews(
                'develop',
                'repairStartTime DEBUG 開始: ' . $today
            );
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】早期終了チェック① 今日のレース登録（0件なら即終了）
        //   importSchedule がまだ動いていない場合は修正対象がないため終了する。
        // ─────────────────────────────────────────────────────────────────
        $todayRaceCount = DB::table('t_horse_odds_finder_races')
            ->where('date', $today)
            ->count();

        if ($todayRaceCount === 0) {
            $this->info('SKIP: 本日のレースが DB に未登録のため終了。');
            return 0;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 4】早期終了チェック② 未発走レースの有無
        //   現在時刻より後の start_time を持つレースが 0 件 = 全レース終了済み。
        //   終了済みなら時刻が変わることはないため Playwright を起動しない。
        //   ※ start_time が 'XXX'（発走済みフラグ）のレースは除外して判定する。
        //   ※ --date 指定のデバッグ時は過去日付でも通過させる。
        // ─────────────────────────────────────────────────────────────────
        $nowTime = date('H:i:s');
        $remainingCount = DB::table('t_horse_odds_finder_races')
            ->where('date', $today)
            ->where('start_time', '!=', 'XXX')
            ->where('start_time', '>', $nowTime)
            ->count();

        if (!$isDebug && $remainingCount === 0) {
            $this->info('SKIP: 未発走レースが 0 件（全レース終了済み）のため終了。');
            return 0;
        }

        try {

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 5】開始バナー（Node.js 起動時のみ表示）
            // ─────────────────────────────────────────────────────────────
            $this->info('');
            $this->info('╔══════════════════════════════════════════════════╗');
            $this->info('║     発走時刻修正処理 ── 開始                      ║');
            $this->info('╚══════════════════════════════════════════════════╝');
            $this->info('実行日時    : ' . date('Y-m-d H:i:s'));
            $this->info('未発走レース: ' . $remainingCount . ' 件');
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 6】Node.js 実行（keibaOddsCollectStartTime.mjs）
            //   馬情報の取得は行わないためスケジュール取得より大幅に短い。
            //   timeout 120 で余裕を持たせる。
            // ─────────────────────────────────────────────────────────────
            $script  = base_path('scripts/keibaOddsCollectStartTime.mjs');
            $logFile = base_path('scripts/keibaOddsCollectStartTime.log');
            $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';

            $this->info('Node.js スクリプトのパス: ' . $script);
            $this->info('Node.js を実行して発走時刻を取得中...');

            $output = shell_exec('timeout 120 ' . $nodeBin . ' ' . escapeshellarg($script) . ' 2>>' . escapeshellarg($logFile));

            $this->info('Node.js スクリプト完了。出力文字数: ' . mb_strlen($output ?? '') . ' 文字');
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 7】JSON パース
            // ─────────────────────────────────────────────────────────────
            $data = json_decode($output, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                $this->error('JSON パース失敗。Node.js の出力内容:');
                $this->error($output);
                $this->error('処理を中断します。');
                return 1;
            }

            $races = $data['races'] ?? [];
            $this->info('取得レース数: ' . count($races) . ' 件');
            $this->info('');

            if (empty($races)) {
                $this->warn('取得レースが 0 件のため終了します。');
                return 0;
            }

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 8】レースごとに DB と比較して差異があれば UPDATE
            //   キー: date + basho + race（この3列でレースを一意に特定）
            //   start_time が null のレースは時刻未確定のためスキップ。
            //   DB に該当レコードが存在しない場合もスキップ（scheduleが未取得）。
            // ─────────────────────────────────────────────────────────────
            $this->info('発走時刻を照合・更新中...');
            $this->info('──────────────────────────────────────');

            foreach ($races as $race) {
                // start_time が null のレースはスキップ
                if ($race['start_time'] === null) {
                    continue;
                }

                $checkedCount++;

                $dbRace = DB::table('t_horse_odds_finder_races')
                    ->where('date',  $race['date'])
                    ->where('basho', $race['basho'])
                    ->where('race',  $race['race'])
                    ->first();

                // DB に存在しない場合はスキップ
                if (!$dbRace) {
                    $this->line("  SKIP  [{$race['date']} {$race['basho']} {$race['race']}R] DBに未登録");
                    continue;
                }

                // 発走時刻が一致している場合はスキップ
                if ($dbRace->start_time === $race['start_time']) {
                    continue;
                }

                // 差異あり → UPDATE
                DB::table('t_horse_odds_finder_races')
                    ->where('date',  $race['date'])
                    ->where('basho', $race['basho'])
                    ->where('race',  $race['race'])
                    ->update(['start_time' => $race['start_time']]);

                $updatedCount++;

                $this->info(sprintf(
                    '  UPDATE [%s %s %2dR] %s → %s  (%s)',
                    $race['date'],
                    $race['basho_name'],
                    $race['race'],
                    $dbRace->start_time,
                    $race['start_time'],
                    $race['race_name']
                ));

                (new WebPushService())->sendPushNotifierDeveloperNews(
                    'develop',
                    sprintf(
                        "時刻修正\n%s %dR %s\n%s → %s",
                        $race['basho_name'],
                        $race['race'],
                        $race['race_name'],
                        $dbRace->start_time,
                        $race['start_time']
                    )
                );
            }

            $this->info('──────────────────────────────────────');
            $this->info('');

        } finally {

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 9】完了サマリー
            //   WebPush は UPDATE 検知の都度、foreach 内で 1 件ずつ送信済み。
            // ─────────────────────────────────────────────────────────────
            $this->info('╔══════════════════════════════════════════════════╗');
            $this->info('║     処理結果サマリー                              ║');
            $this->info('╚══════════════════════════════════════════════════╝');
            $this->info('照合レース数: ' . $checkedCount . ' 件');
            $this->info('更新レース数: ' . $updatedCount . ' 件');
            $this->info('完了日時    : ' . date('Y-m-d H:i:s'));
            $this->info('');
            $this->info('========== keiba:repairStartTime 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            if ($isDebug) {
                (new WebPushService())->sendPushNotifierDeveloperNews(
                    'develop',
                    sprintf(
                        "repairStartTime DEBUG 完了\n照合: %d件 / 更新: %d件\n%s",
                        $checkedCount,
                        $updatedCount,
                        date('H:i:s')
                    )
                );
            }

        }

        return 0;
    }
}
