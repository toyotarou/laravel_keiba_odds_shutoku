<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use App\Constants\Constants;

use App\Services\LineService;

/**
 * cronで毎分実行される。
 * $ary に合致する発走前分数のレースのみ結果・オッズを取得してDBに保存する。
 *
 * cron設定:
 *   * 9-17 * * * php /var/www/horse_odds_finder/artisan keiba:importRaceResult >> /var/www/horse_odds_finder/storage/logs/importRaceResult.log 2>&1
 */
class ImportKeibaRaceResult extends Command
{
    protected $signature = 'keiba:importRaceResult {--debug : タイミングチェックをスキップして全レース処理する}';
    protected $description = 'ネットケイバからレースの結果・オッズを取得する';

    public function handle(): void
    {
        // ── 多重起動防止 ─────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importRaceResult.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $now  = time();
        $date = date('Y-m-d');

        $isDebug = (bool) $this->option('debug');

        $this->info('');
        $this->info('========== keiba:importRaceResult 開始 ' . date('Y-m-d H:i:s', $now) . ' ==========');
        $this->info('対象日付: ' . $date);
        if ($isDebug) {
            $this->warn('【DEBUGモード】タイミングチェックをスキップします。');
        }

        // オッズを取得するタイミング（発走X分前）の定義。
        // cronが毎分動くため、$diff がこの配列に含まれる分のみ処理する。
        $ary = Constants::ODDS_GET_TIMING;

        if ($isDebug) {
            // テーブルに存在する最も近い未来（または当日）の日付を対象にする
            $nearestDate = DB::table('t_horse_odds_finder_netkeiba_races')
                ->where('date', '>=', $date)
                ->min('date');
            $targetDate = $nearestDate ?? $date;
        } else {
            $targetDate = $date;
        }

        $races = DB::table('t_horse_odds_finder_netkeiba_races')
            ->where('date', $targetDate)
            ->orderBy('start_time')
            ->get();

        $totalRaces = count($races);
        $this->info("対象レース数: {$totalRaces} 件");

        if ($totalRaces === 0) {
            $this->info('対象レースが0件のため終了します。');
            return;
        }

        $totalSaved = 0;
        $raceIndex  = 0;
        foreach ($races as $race) {
            $raceIndex++;

            $targetTime  = strtotime("{$race->date} {$race->start_time}");
            $diffSeconds = $targetTime - $now;

            // 発走までの残り分数（四捨五入）importOddsと合わせてround()を使う
            $diff = (int) round($diffSeconds / 60);

            // 発走済みは処理しない（$diff=0 が最後のタイミング）
            if ($diff < 0) {
                continue;
            }

            $this->info("--------------------------------------------------");
            $this->info("[{$raceIndex}/{$totalRaces}] race_id={$race->race_id} 発走: {$race->start_time}  (残り {$diff} 分)");

            // $ary に合致しない分数はスキップ（DEBUGモード時はスキップしない）
            if (!$isDebug && !in_array($diff, $ary)) {
                $this->info("  → スキップ (残り{$diff}分は取得タイミング外)");
                continue;
            }

            $this->info("  → 取得タイミング合致 (残り{$diff}分) : オッズ取得を開始します。");

            // minutes_before_start の値を決定
            if ($diff === 24) {
                $diffMinutes = 999;
            } elseif ($diff === 0) {
                $diffMinutes = -999;
            } else {
                $diffMinutes = $diff;
            }

            // 発走済みで確定オッズが保存済みならスクレイピング不要
            if ($diffMinutes === -999) {
                $alreadySaved = DB::table('t_horse_odds_finder_netkeiba_odds')
                    ->where('date',   $race->date)
                    ->where('kaisuu', $race->kaisuu)
                    ->where('basho',  $race->basho)
                    ->where('race',   $race->race)
                    ->where('minutes_before_start', -999)
                    ->exists();

                if ($alreadySaved) {
                    $this->info("  確定オッズ保存済み → スキップ");
                    continue;
                }
            }

            // Node.js でオッズをスクレイピング
            $raceStart = microtime(true);

            $json = $this->fetchRaceDetail($race->race_id);
            if (!$json) {
                $fetchMs = round((microtime(true) - $raceStart) * 1000);
                $this->warn("  → 取得失敗: {$race->race_id} ({$fetchMs}ms)");
                continue;
            }

            $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE || empty($data['data'])) {
                $fetchMs = round((microtime(true) - $raceStart) * 1000);
                $this->warn("  → JSONパース失敗: {$race->race_id} ({$fetchMs}ms)");
                continue;
            }

            $fetchMs    = round((microtime(true) - $raceStart) * 1000);
            $horseCount = count($data['data']);
            $this->info("  Node.js 取得完了 → {$horseCount} 頭分 ({$fetchMs}ms)");

            $saved = 0;
            DB::transaction(function () use ($data, $race, $diffMinutes, &$saved) {
                foreach ($data['data'] as $horse) {
                    $this->saveOdds(
                        $race,
                        $horse['horse_num'],
                        $horse['odds'],
                        $horse['fuku_odds_min'],
                        $horse['fuku_odds_max'],
                        $diffMinutes
                    );
                    $saved++;
                }

                $timingKey = [
                    'date'   => $race->date,
                    'kaisuu' => $race->kaisuu,
                    'basho'  => $race->basho,
                    'day'    => $race->day,
                    'race'   => $race->race,
                    'timing' => $diffMinutes,
                ];
                $timingValues = [
                    'get_datetime' => date('Y-m-d H:i:s'),
                    'odds_from'    => 'netkeiba',
                ];
                $exists = DB::table('t_horse_odds_finder_odds_get_timing')->where($timingKey)->exists();
                if ($exists) {
                    DB::table('t_horse_odds_finder_odds_get_timing')
                        ->where($timingKey)
                        ->update($timingValues);
                } else {
                    DB::table('t_horse_odds_finder_odds_get_timing')
                        ->insert(array_merge($timingKey, $timingValues));
                }
            });
            $totalMs = round((microtime(true) - $raceStart) * 1000);
            $this->info("  DB保存完了 → {$saved} 頭分  (合計 {$totalMs}ms)");
            $totalSaved += $saved;
            
        }
        


        if ($totalSaved > 0) {
            try {
                app(LineService::class)->sendLineDevelopperNews(
                    "ImportKeibaRaceResult::handle\n" .
                    "DB保存完了 → {$totalSaved} 頭分"
                );
            } catch (\Exception $e) {
                \Log::warning('LINE送信失敗: ' . $e->getMessage());
            }
        }
        


        $this->info('');
        $this->info('========== keiba:importRaceResult 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');
    }

    /**
     * 1頭分のオッズを t_horse_odds_finder_netkeiba_odds に保存する。
     * minutes_before_start の値によって INSERT/UPDATE の挙動を切り替える。
     */
    private function saveOdds(object $race, mixed $num, mixed $oddsValue, mixed $fukuMin, mixed $fukuMax, int $minutesBefore): void
    {
        $key = [
            'date'   => $race->date,
            'kaisuu' => $race->kaisuu,
            'basho'  => $race->basho,
            'day'    => $race->day,
            'race'   => $race->race,
            'num'    => $num,
        ];

        $insert = array_merge($key, [
            'odds'                 => $oddsValue,
            'fuku_min'             => $fukuMin,
            'fuku_max'             => $fukuMax,
            'minutes_before_start' => $minutesBefore,
        ]);

        $update = [
            'odds'     => $oddsValue,
            'fuku_min' => $fukuMin,
            'fuku_max' => $fukuMax,
        ];

        if ($minutesBefore === 999) {
            $exists = DB::table('t_horse_odds_finder_netkeiba_odds')
                ->where($key)
                ->where('minutes_before_start', 999)
                ->exists();

            if ($exists) {
                DB::table('t_horse_odds_finder_netkeiba_odds')
                    ->where($key)
                    ->where('minutes_before_start', 999)
                    ->update($update);
            } else {
                DB::table('t_horse_odds_finder_netkeiba_odds')->insert($insert);
            }
            return;
        }

        if ($minutesBefore === -999) {
            $alreadySaved = DB::table('t_horse_odds_finder_netkeiba_odds')
                ->where($key)
                ->where('minutes_before_start', -999)
                ->exists();

            if (!$alreadySaved) {
                DB::table('t_horse_odds_finder_netkeiba_odds')->insert($insert);
            }
            return;
        }

        DB::table('t_horse_odds_finder_netkeiba_odds')->insert($insert);
    }

    private function fetchRaceDetail(string $race_id): ?string
    {
        $nodeBin    = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';
        $scriptPath = base_path('scripts/keibaOddsGetRaceResult.mjs');
        // timeout 120: Node.js が無応答でもPHPプロセスが永久ブロックしないようにする
        $command    = 'timeout 120 ' . $nodeBin . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($race_id) . ' 2>/dev/null';
        $output     = shell_exec($command);

        if (!$output) {
            return null;
        }

        json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return trim($output);
        }

        return null;
    }
}






