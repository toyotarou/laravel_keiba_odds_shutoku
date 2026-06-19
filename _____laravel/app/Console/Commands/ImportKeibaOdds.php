<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use App\Constants\Constants;
use App\Services\LineService;

/**
 * cronで毎分実行される。
 * ODDS_GET_TIMING に合致する発走前分数のレースのみオッズを取得してDBに保存する。
 *
 * cron設定:
 *   * 9-17 * * * php /var/www/horse_odds_finder/artisan keiba:importOdds >> /var/www/horse_odds_finder/storage/logs/cron_odds.log 2>&1
 */
class ImportKeibaOdds extends Command
{
    protected $signature = 'keiba:importOdds {--debug : タイミングチェックをスキップして全レース処理する}';
    protected $description = 'オッズを取得してDBに保存する';

    public function handle(): void
    {
        // ── 多重起動防止 ─────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importOdds.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

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

        // ── レース一覧を取得（通常は当日分のみ、DEBUGモードは直近日付分） ──
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
            return;
        }

        $totalInserted = 0;

        // ── レースごとの処理 ──────────────────────────────────────────
        foreach ($races as $race) {

            $diff = (int) round((strtotime("{$race->date} {$race->start_time}") - $now) / 60);

            // 発走済みはスキップ（$diff=0 が最後のタイミング）
            if ($diff < 0) {
                continue;
            }

            $this->info('--------------------------------------------------');
            $this->info("[{$race->basho_name}] {$race->race}R 「{$race->race_name}」 発走: {$race->start_time}  (残り {$diff} 分)");

            // 取得タイミング外はスキップ（DEBUGモード時はスキップしない）
            if (!$isDebug && !in_array($diff, $timings)) {
                $this->info("  → スキップ (残り{$diff}分は取得タイミング外)");
                continue;
            }

            $this->info("  → 取得タイミング合致 (残り{$diff}分) : オッズ取得を開始します。");

            // ── 変動検出・LINE通知のための事前データ取得 ─────────────────
            $timingIndex = array_search($diff, $timings); // 24→0, 21→1, ..., 0→8

            // 前タイミングのオッズ: [馬番 => 単勝オッズ]（変動検出用）
            // 最初のタイミング(index=0)は比較対象がないため空のまま
            $prevOddsMap = [];
            if ($timingIndex > 0) {
                $prevOddsMap = DB::table('t_horse_odds_finder_odds')
                    ->where('date',                 $race->date)
                    ->where('kaisuu',               $race->kaisuu)
                    ->where('basho',                $race->basho)
                    ->where('day',                  $race->day)
                    ->where('race',                 $race->race)
                    ->where('minutes_before_start', $timings[$timingIndex - 1])
                    ->pluck('odds', 'num')
                    ->all();
            }

            // 馬名マップ: [馬番 => 馬名]（LINE通知メッセージ用）
            $horseNames = DB::table('t_horse_odds_finder_horses')
                ->where('date',   $race->date)
                ->where('kaisuu', $race->kaisuu)
                ->where('basho',  $race->basho)
                ->where('day',    $race->day)
                ->where('race',   $race->race)
                ->pluck('name', 'num')
                ->all();

            // ── 単複オッズのスクレイピング ──────────────────────────────
            // stderrはlogFileへリダイレクトし、stdoutのJSONに混入させない。
            // timeout 120: Node.js が無応答でもPHPプロセスが永久ブロックしないようにする
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

            // ── 単複オッズのDB保存 + 変動検出 ────────────────────────────
            $saved         = 0;
            $changeRecords = [];

            DB::transaction(function () use ($odds, $race, $diff, $prevOddsMap, &$saved, &$changeRecords) {

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

                    if ($diff === 24) {
                        // importBaseOdds が既に 999 を INSERT している可能性があるため upsert
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

                    // 前タイミングからオッズが変わった馬を記録
                    $prevOdds = $prevOddsMap[$horse['num']] ?? null;
                    if ($prevOdds !== null && (float) $prevOdds !== (float) $horse['tan']) {
                        $changeRecords[] = [
                            'num'       => $horse['num'],
                            'prev_odds' => $prevOdds,
                            'curr_odds' => $horse['tan'],
                        ];
                    }
                }

                // 取得タイミングを記録
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

            // ── オッズ変動のログ出力・LINE通知 ────────────────────────────
            foreach ($changeRecords as $change) {
                $this->info("  [オッズ変動] {$change['num']}番: {$change['prev_odds']} → {$change['curr_odds']}");
            }

            if ($changeRecords) {
                try {
                    app(LineService::class)->sendLineOddsNews(
                        $this->buildOddsChangeMessage($race, $changeRecords, $horseNames, $diff)
                    );
                    $this->info('  [LINE] オッズ変動通知を送信しました。(' . count($changeRecords) . '頭)');
                } catch (\Exception $e) {
                    \Log::warning('LINE送信失敗: ' . $e->getMessage());
                    $this->warn('  [LINE] 送信失敗: ' . $e->getMessage());
                }
            }

            // ── ワイドオッズのスクレイピング・DB保存 ──────────────────────
            $wideStart = microtime(true);
            $wideOdds  = $this->getWideOdds($race);

            $this->info("  [Wide] {$race->race}R 取得組数: " . count($wideOdds));
            if ($wideOdds) {
                $wideSaved     = 0;
                $minutesBefore = ($diff === 24) ? 999 : (($diff === 0) ? -999 : $diff);
                DB::transaction(function () use ($wideOdds, $race, $minutesBefore, &$wideSaved) {
                    foreach ($wideOdds as $w) {
                        $key = [
                            'date'                 => $race->date,
                            'kaisuu'               => $race->kaisuu,
                            'basho'                => $race->basho,
                            'day'                  => $race->day,
                            'race'                 => $race->race,
                            'uma1'                 => $w['uma1'],
                            'uma2'                 => $w['uma2'],
                            'minutes_before_start' => $minutesBefore,
                        ];
                        $data = [
                            'odds_min' => $w['odds_min'],
                            'odds_max' => $w['odds_max'],
                        ];
                        $exists = DB::table('t_horse_odds_finder_odds_wide')->where($key)->exists();
                        if ($exists) {
                            DB::table('t_horse_odds_finder_odds_wide')->where($key)->update($data);
                        } else {
                            DB::table('t_horse_odds_finder_odds_wide')->insert(array_merge($key, $data));
                        }
                        $wideSaved++;
                    }
                });
                $wideTotalMs  = round((microtime(true) - $wideStart) * 1000);
                $wideTotalSec = number_format($wideTotalMs / 1000, 2);
                $this->info("  [Wide] DB保存完了 → {$wideSaved} 組 (合計 {$wideTotalSec}秒 / {$wideTotalMs}ms)");
            }
        }
        


        if ($totalInserted > 0) {
            try {
                app(LineService::class)->sendLineDevelopperNews(
                    "ImportKeibaOdds::handle\n" .
                    "インサート: {$totalInserted} 件\n" .
                    "完了日時: " . date('Y-m-d H:i:s')
                );
            } catch (\Exception $e) {
                \Log::warning('LINE送信失敗: ' . $e->getMessage());
            }
        }
        

        
        $this->info('');
        $this->info('========== keiba:importOdds 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');
    }

    /**
     * ワイドオッズを Node.js でスクレイピングして返す。
     * DB保存は呼び出し元で行う。
     *
     * @return array{uma1:string, uma2:string, odds_min:string, odds_max:string}[]
     */
    private function getWideOdds(object $race): array
    {
        $script  = base_path('scripts/keibaOddsGetWide.mjs');
        $logFile = '/var/www/horse_odds_finder/storage/logs/importOddsWide.log';
        $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';

        $command = 'timeout 120 ' . $nodeBin . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($race->date)
            . ' ' . escapeshellarg($race->kaisuu)
            . ' ' . escapeshellarg($race->basho)
            . ' ' . escapeshellarg($race->race)
            . ' ' . escapeshellarg($race->day)
            . ' 2>>' . escapeshellarg($logFile);

        $this->info("  [Wide] 実行コマンド: {$command}");

        $odds      = null;
        $output    = '';
        $maxRetry  = 2;
        $nodeStart = microtime(true);
        for ($retry = 1; $retry <= $maxRetry; $retry++) {
            $output = shell_exec($command);
            $odds   = json_decode($output, true);
            if ($odds) break;
            if ($retry < $maxRetry) {
                $this->warn("  [Wide][RETRY {$retry}/{$maxRetry}] オッズ取得失敗。3秒後にリトライします...");
                sleep(3);
            }
        }

        $nodeMs  = round((microtime(true) - $nodeStart) * 1000);
        $nodeSec = number_format($nodeMs / 1000, 2);

        if (!$odds) {
            $this->error("  [Wide] オッズ取得失敗。({$nodeSec}秒 / {$nodeMs}ms)");
            $this->error("  [Wide] Node.js 出力: " . $output);
            return [];
        }

        $this->info("  [Wide] Node.js 取得完了 → " . count($odds) . " 組分 ({$nodeSec}秒 / {$nodeMs}ms)");
        return $odds;
    }

    /**
     * LINE通知用のオッズ変動メッセージを組み立てる。
     */
    private function buildOddsChangeMessage(object $race, array $changeRecords, array $horseNames, int $diffMinutes): string
    {
        $lines   = [];
        $lines[] = '馬眼力OddsFinder News';
        $lines[] = '';
        $lines[] = 'オッズ変更がありました。';
        $lines[] = "{$race->date}　{$race->kaisuu}回{$race->basho_name}{$race->day}日";
        $lines[] = "R{$race->race}　{$race->race_name}";
        $lines[] = "出走まであと {$diffMinutes} 分";

        foreach ($changeRecords as $change) {
            $name    = $horseNames[$change['num']] ?? '不明';
            $lines[] = '';
            $lines[] = "{$change['num']}番　{$name}";
            $lines[] = "{$change['prev_odds']} → {$change['curr_odds']}";
        }

        return implode("\n", $lines);
    }
}
