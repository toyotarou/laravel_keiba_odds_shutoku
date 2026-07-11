<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use App\Constants\Constants;

/**
 * ImportKeibaOdds
 *
 * 【概要】
 *   cron で毎分（9〜17時）実行されるオッズ取得コマンド。
 *   Constants::ODDS_GET_TIMING = [24, 21, 18, 15, 12, 9, 6, 3, 0]（発走前分数）に
 *   合致するレースのみ keibaOddsGetTanpuku.mjs で単複オッズを取得して DB に保存する。
 *   直前タイミングとのオッズ比較で人気順位の変動を検出し、
 *   2位以上の変動があれば WebPush 通知でユーザーに知らせる。
 *
 * 【処理フロー】
 *   【ブロック 1】初期化（定数・変数・DEBUGオプション）
 *   【ブロック 2】レース一覧取得（通常は当日、DEBUGは直近日付）
 *   【ブロック 3】レースごとのループ・タイミング判定
 *   【ブロック 4】変動検出のための直前タイミングのオッズ先読み
 *   【ブロック 5】Node.js 実行（リトライ最大2回）
 *   【ブロック 6】現タイミングの人気順位を計算
 *   【ブロック 7】DB保存トランザクション（minutes_before_start の値決定ロジック含む）
 *   【ブロック 8】取得タイミング記録（t_horse_odds_finder_odds_get_timing）
 *   【ブロック 9】人気順位変動ログ出力
 *   【ブロック 10】有意変動（2位以上）の WebPush 通知
 *
 * 【cron設定】
 *   * 9-17 * * * php /var/www/horse_odds_finder/artisan keiba:importOdds >> /var/www/horse_odds_finder/storage/logs/cron_odds.log 2>&1
 *
 * 【minutes_before_start の変換ルール】
 *   diff=24 → 999  （importBaseOdds と同じ ベースオッズの扱い）
 *   diff=0  → -999 （発走直前の確定オッズ）
 *   それ以外 → そのまま（21, 18, ... 3）
 */
class ImportKeibaOdds extends Command
{
    protected $signature = 'keiba:importOdds {--debug : タイミングチェックをスキップして全レース処理する}';
    protected $description = 'オッズを取得してDBに保存する';

    public function handle(): void
    {
        // // ── 多重起動防止 ─────────────────────────────────────────────
        // $lockFile = sys_get_temp_dir() . '/keiba_importOdds.lock';
        // if (file_exists($lockFile)) {
        //     $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
        //     return;
        // }
        // file_put_contents($lockFile, getmypid());
        // register_shutdown_function(fn() => @unlink($lockFile));





        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】初期化（定数・変数・DEBUGオプション）
        //   $timings = ODDS_GET_TIMING: [24, 21, 18, 15, 12, 9, 6, 3, 0]
        //   diff=24 → DB値 999、diff=0 → DB値 -999 に変換する慣例がある。
        // ─────────────────────────────────────────────────────────────────
        $date    = date('Y-m-d');
        $now     = time();
        $script  = base_path('scripts/keibaOddsGetTanpuku.mjs');
        $logFile = base_path('scripts/keibaOddsGetTanpuku.log');
        $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';
        $isDebug = (bool) $this->option('debug');
        $timings = Constants::ODDS_GET_TIMING; // [24, 21, 18, 15, 12, 9, 6, 3, 0]

        $this->info('');
        $this->info('========== keiba:importOdds 開始 ' . date('Y-m-d H:i:s', $now) . ' ==========');
        if ($isDebug) {
            $this->warn('【DEBUGモード】タイミングチェックをスキップします。');
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】レース一覧取得（通常は当日、DEBUGは直近日付）
        //   DEBUGモードは min('date') で「今日以降で最も近い開催日」を取得する。
        //   レースのない日に動作確認するための配慮。
        // ─────────────────────────────────────────────────────────────────
        $query = DB::table('t_horse_odds_finder_races');
        if ($isDebug) {
            $nearestDate = DB::table('t_horse_odds_finder_races')
                ->where('date', '>=', $date)
                ->min('date');
            $query->where('date', $nearestDate ?? $date);
        } else {
            $query->where('date', $date);
        }

        $races = $query->orderBy('start_time')->get();
        $this->info("対象レース数: {$races->count()} 件");

        if ($races->isEmpty()) {
            $this->info('対象レースが0件のため終了します。');
            $this->info('');
            $this->info('========== keiba:importOdds 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');
            return;
        }

        $totalInserted = 0;

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】レースごとのループ・タイミング判定
        //   $diff = (発走時刻 - 現在時刻) / 60（残り分数、発走後は負）
        //   発走済み（$diff < 0）はスキップ。
        //   $timings に含まれない分数もスキップ（DEBUGモード除く）。
        // ─────────────────────────────────────────────────────────────────
        foreach ($races as $race) {

            $diff = (int) round((strtotime("{$race->date} {$race->start_time}") - $now) / 60);

            if ($diff < 0) {
                continue;  // 発走済みはスキップ（$diff=0 が最後のタイミング）
            }

            $this->info('--------------------------------------------------');
            $this->info("[{$race->basho_name}] {$race->race}R 「{$race->race_name}」 発走: {$race->start_time}  (残り {$diff} 分)");

            if (!$isDebug && !in_array($diff, $timings)) {
                $this->info("  → スキップ (残り{$diff}分は取得タイミング外)");
                continue;
            }

            $this->info("  → 取得タイミング合致 (残り{$diff}分) : オッズ取得を開始します。");

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】変動検出のための直前タイミングのオッズ先読み
            //   $timings[0]=24 は最初のタイミングなので「前」がない → スキップ。
            //   $timingIndex > 0 の場合、$timings[$timingIndex-1] が直前タイミング。
            //   直前タイミングのオッズを asort して人気順位 $prevRankMap を作る。
            //   diff=24 の場合、DB上のキーは 999 なので $prevMinutesBefore に変換する。
            // ─────────────────────────────────────────────────────────────
            $prevRankMap = [];
            $timingIndex = array_search($diff, $timings);
            if ($diff !== 24 && $timingIndex !== false && $timingIndex > 0) {
                $prevDiff          = $timings[$timingIndex - 1];
                $prevMinutesBefore = ($prevDiff === 24) ? 999 : $prevDiff;
                $prevOddsMap = DB::table('t_horse_odds_finder_odds')
                    ->where('date',                 $race->date)
                    ->where('kaisuu',               $race->kaisuu)
                    ->where('basho',                $race->basho)
                    ->where('day',                  $race->day)
                    ->where('race',                 $race->race)
                    ->where('minutes_before_start', $prevMinutesBefore)
                    ->pluck('odds', 'num')
                    ->all();

                uasort($prevOddsMap, fn($a, $b) => (float) $a <=> (float) $b); // 数値昇順ソート → 人気順位に相当
                $rank = 1;
                foreach ($prevOddsMap as $num => $_) {
                    $prevRankMap[$num] = $rank++;
                }
            }

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 5】Node.js 実行（リトライ最大2回）
            //   stderr はログファイルへリダイレクトして stdout の JSON に混入させない。
            //   timeout 120: Node.js が無応答でも PHP が永久ブロックするのを防ぐ。
            //   リトライ間隔3秒（JRAサーバの一時的な応答遅延を考慮）。
            // ─────────────────────────────────────────────────────────────
            $raceStart = microtime(true);
            $command   = 'timeout 120 ' . $nodeBin . ' ' . escapeshellarg($script)
                . ' ' . escapeshellarg($race->date)
                . ' ' . escapeshellarg($race->kaisuu)
                . ' ' . escapeshellarg($race->basho)
                . ' ' . escapeshellarg($race->race)
                . ' ' . escapeshellarg($race->day)
                . ' 2>>' . escapeshellarg($logFile);

            $this->info("  実行コマンド: {$command}");

            $odds     = null;
            $output   = '';
            $maxRetry = 2;
            for ($retry = 1; $retry <= $maxRetry; $retry++) {
                $output = shell_exec($command);
                $odds   = json_decode($output, true);
                if ($odds) break;
                if ($retry < $maxRetry) {
                    $this->warn("  [RETRY {$retry}/{$maxRetry}] オッズ取得失敗。3秒後にリトライします...");
                    sleep(3);
                }
            }

            $fetchMs  = round((microtime(true) - $raceStart) * 1000);
            $fetchSec = number_format($fetchMs / 1000, 2);

            if (!$odds) {
                $this->error("  [単複] オッズ取得失敗。このレースをスキップします。({$fetchSec}秒 / {$fetchMs}ms)");
                $this->error("  Node.js 出力: " . $output);
                continue;
            }

            $this->info("  [単複] Node.js 取得完了 → " . count($odds) . " 頭分 ({$fetchSec}秒 / {$fetchMs}ms)");

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 6】現タイミングの人気順位を計算 [num => rank]
            //   取得した $odds を tan（単勝オッズ）の昇順にソートして順位を付ける。
            //   $prevRankMap との差分が $changeRecords になる。
            // ─────────────────────────────────────────────────────────────
            $currRankMap = [];
            $sortedCurr  = $odds;
            usort($sortedCurr, fn($a, $b) => (float) $a['tan'] <=> (float) $b['tan']);
            $rank = 1;
            foreach ($sortedCurr as $horse) {
                $currRankMap[$horse['num']] = $rank++;
            }

            $saved         = 0;
            $changeRecords = [];

            DB::transaction(function () use ($odds, $race, $diff, $prevRankMap, $currRankMap, &$saved, &$changeRecords) {

                foreach ($odds as $horse) {
                    $key = [
                        'date'   => $race->date,
                        'kaisuu' => $race->kaisuu,
                        'basho'  => $race->basho,
                        'day'    => $race->day,
                        'race'   => $race->race,
                        'num'    => $horse['num'],
                    ];
                    $data = [
                        'odds'     => $horse['tan'],
                        'fuku_min' => $horse['fuku_min'],
                        'fuku_max' => $horse['fuku_max'],
                    ];

                    // ─────────────────────────────────────────────────────
                    // 【ブロック 7】minutes_before_start の値決定・DB保存
                    //   diff=24: importBaseOdds が999で先行 INSERT している場合があるため upsert
                    //   diff=0 : -999（発走直前の確定オッズ）として INSERT
                    //   その他: diff の値そのままで INSERT（同一 diff の重複は想定しない）
                    // ─────────────────────────────────────────────────────
                    if ($diff === 24) {
                        $exists = DB::table('t_horse_odds_finder_odds')
                            ->where($key)->where('minutes_before_start', 999)->exists();
                        if ($exists) {
                            DB::table('t_horse_odds_finder_odds')
                                ->where($key)->where('minutes_before_start', 999)->update($data);
                        } else {
                            DB::table('t_horse_odds_finder_odds')
                                ->insert(array_merge($key, $data, ['minutes_before_start' => 999]));
                        }
                    } else {
                        $minutesBefore = ($diff === 0) ? -999 : $diff;
                        DB::table('t_horse_odds_finder_odds')
                            ->insert(array_merge($key, $data, ['minutes_before_start' => $minutesBefore]));
                    }
                    $saved++;

                    // 前タイミングから人気順位が変わった馬を記録
                    $prevRank = $prevRankMap[$horse['num']] ?? null;
                    $currRank = $currRankMap[$horse['num']] ?? null;
                    if ($prevRank !== null && $currRank !== null && $prevRank !== $currRank) {
                        $changeRecords[] = [
                            'num'       => $horse['num'],
                            'prev_rank' => $prevRank,
                            'curr_rank' => $currRank,
                        ];
                    }
                }

                // ─────────────────────────────────────────────────────────
                // 【ブロック 8】取得タイミング記録（t_horse_odds_finder_odds_get_timing）
                //   「いつ・どのタイミング・どのソースから」オッズを取得したかを記録する。
                //   同一キーが既存の場合は get_datetime と odds_from を上書き更新する。
                // ─────────────────────────────────────────────────────────
                $timing    = ($diff === 24) ? 999 : (($diff === 0) ? -999 : $diff);
                $timingKey = [
                    'date'   => $race->date,
                    'kaisuu' => $race->kaisuu,
                    'basho'  => $race->basho,
                    'day'    => $race->day,
                    'race'   => $race->race,
                    'timing' => $timing,
                ];
                $timingValues = [
                    'get_datetime' => date('Y-m-d H:i:s'),
                    'odds_from'    => 'JRA',
                ];
                $exists = DB::table('t_horse_odds_finder_odds_get_timing')->where($timingKey)->exists();
                if ($exists) {
                    DB::table('t_horse_odds_finder_odds_get_timing')->where($timingKey)->update($timingValues);
                } else {
                    DB::table('t_horse_odds_finder_odds_get_timing')->insert(array_merge($timingKey, $timingValues));
                }
            });

            $totalMs  = round((microtime(true) - $raceStart) * 1000);
            $totalSec = number_format($totalMs / 1000, 2);
            $this->info("  [単複] DB保存完了 → {$saved} 頭分 (合計 {$totalSec}秒 / {$totalMs}ms)");
            $totalInserted += $saved;

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 9】人気順位変動ログ出力
            //   前タイミングと現タイミングで順位が変わった馬を1頭ずつログに出す。
            // ─────────────────────────────────────────────────────────────
            foreach ($changeRecords as $change) {
                $this->info("  [人気順位変動] {$change['num']}番: {$change['prev_rank']}位 → {$change['curr_rank']}位");
            }

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 10】有意変動（2位以上）の WebPush 通知
            //   abs(prev - curr) >= 2 の変動をユーザーへ通知する。
            //   ユーザーが注目馬の急変を見逃さないようにするための仕組み。
            // ─────────────────────────────────────────────────────────────
            $significantChanges = array_filter($changeRecords, fn($c) => abs($c['prev_rank'] - $c['curr_rank']) >= 2);
            if (in_array($diff, $timings) && !empty($significantChanges)) {
                $deepLinkUrl = 'https://baganriki.com/horse_odds_finder/?' . http_build_query([
                    'date'    => $race->date,
                    'kbd'     => "{$race->kaisuu}_{$race->basho}_{$race->day}",
                    'name'    => "{$race->kaisuu}回{$race->basho_name}{$race->day}日",
                    'race'    => $race->race,
                    'ranking' => '1',
                    'zoomed'  => '0',
                ]);

                (new WebPushService())->sendPushNotifierOddsNews(
                    '人気順位の変更がありました（2以上の変化）',
                    "{$race->date} {$race->kaisuu}回{$race->basho_name}{$race->day}日\nR{$race->race} {$race->race_name}",
                    $deepLinkUrl,
                );
            }
        }

        $this->info('');
        $this->info('========== keiba:importOdds 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');
    }

}
