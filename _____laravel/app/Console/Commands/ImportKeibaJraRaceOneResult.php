<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 発走時刻から一定時間後（10〜30分）に1レース分の結果を取得し、
 * t_horse_odds_finder_race_results にINSERTする。
 *
 * Usage:
 *   php artisan keiba:importJraRaceOneResult
 *   php /var/www/horse_odds_finder/artisan keiba:importJraRaceOneResult >> /var/www/horse_odds_finder/storage/logs/importJraRaceOneResult.log
 *
 * Debug:
 *   php artisan keiba:importJraRaceOneResult --debug
 */
class ImportKeibaJraRaceOneResult extends Command
{
    protected $signature = 'keiba:importJraRaceOneResult {--debug : タイミングチェックをスキップして全レース処理する}';
    protected $description = 'JRAからひとつのレース結果（着順）を取得する';

    public function handle(): void
    {
        // ── 多重起動防止 ─────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importJraRaceOneResult.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $isDebug = (bool) $this->option('debug');

        $this->info('');
        $this->info('========== keiba:importJraRaceOneResult 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        if ($isDebug) {
            $this->warn('【DEBUGモード】タイミングチェックをスキップします。');
        }

        $date = date("Y-m-d");
        $now  = time();

        // 当日のレース一覧を取得（DEBUGモードはテーブル内の最新日付を対象にする）
        $query = DB::table('t_horse_odds_finder_races');
        if ($isDebug) {
            $nearestDate = DB::table('t_horse_odds_finder_races')->max('date');
            $query->where('date', $nearestDate ?? $date);
        } else {
            $query->where('date', $date);
        }

        $resultRaces = $query
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->orderBy('start_time')
            ->get();

        $this->info("当日レース取得 → {$resultRaces->count()} 件");

        // ── タイミングにヒットしたレースを収集 ──────────────────────────
        $hitRaces = [];
        foreach ($resultRaces as $race) {
            $targetTime  = strtotime("{$race->date} {$race->start_time}");
            $diffSeconds = $now - $targetTime;
            $diff        = (int) round($diffSeconds / 60);

            if (!$isDebug && !in_array($diff, [10, 15, 20, 25, 30])) {
                continue;
            }

            $race->_diff = $diff;
            $hitRaces[]  = $race;

            if (in_array($diff, [10, 15, 20, 25, 30])) {
                $this->info("  ヒット: kaisuu={$race->kaisuu} {$race->basho_name} {$race->day}日目 R{$race->race} (発走+{$diff}分)");
            } else {
                $this->info("  [DEBUG] kaisuu={$race->kaisuu} {$race->basho_name} {$race->day}日目 R{$race->race} (発走+{$diff}分)");
            }
        }

        if (empty($hitRaces)) {
            $this->info('ヒットするレースなし。終了します。');
            $this->info('========== keiba:importJraRaceOneResult 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');
            return;
        }

        // 登録済みキーセットをループ前に一括取得する。
        // キー形式: "{kaisuu}-{basho}-{day}-{race}-{num}"
        // INSERT後はメモリ上に追記して同一実行内の重複も防ぐ。
        $existingKeys = DB::table('t_horse_odds_finder_race_results')
            ->where('date', $date)
            ->whereNotNull('result')
            ->get(['kaisuu', 'basho', 'day', 'race', 'num'])
            ->keyBy(fn($r) => "{$r->kaisuu}-{$r->basho}-{$r->day}-{$r->race}-{$r->num}")
            ->keys()
            ->flip()
            ->all();

        $this->info("登録済み結果取得 → " . count($existingKeys) . " 件");

        $inserted = 0;
        $skipped  = 0;

        // ── 1回目: Node.js を1回実行して全ヒットレースを照合 ─────────────
        $this->info('');
        $this->info('  [1回目] Node.js 実行中...');
        $start   = microtime(true);
        $results = $this->fetchResults();
        $fetchMs = round((microtime(true) - $start) * 1000);

        if ($results === null) {
            $this->error('Node.js スクリプトの実行失敗（出力なし）');
            return;
        }

        $this->info("  [1回目] 取得完了 → {$fetchMs}ms / " . count($results) . " 件");

        // 1回目の照合。マッチしなかったレースを $unmatchedRaces に積む。
        $unmatchedRaces = [];
        foreach ($hitRaces as $race) {
            $found = $this->matchAndInsert($race, $results, $existingKeys, $inserted, $skipped);
            if (!$found) {
                $unmatchedRaces[] = $race;
                $this->warn("  [1回目] マッチなし: {$race->basho_name} R{$race->race} → 2回目で再試行します");
            }
        }

        // ── 2回目: マッチしなかったレースがあれば Node.js を再実行 ────────
        if (!empty($unmatchedRaces)) {
            $this->info('');
            $this->info('  [2回目] Node.js 再実行中...');
            $start    = microtime(true);
            $results2 = $this->fetchResults();
            $fetchMs  = round((microtime(true) - $start) * 1000);

            if ($results2 === null) {
                $this->warn('  [2回目] Node.js 実行失敗。スキップします。');
            } else {
                $this->info("  [2回目] 取得完了 → {$fetchMs}ms / " . count($results2) . " 件");
                foreach ($unmatchedRaces as $race) {
                    $found = $this->matchAndInsert($race, $results2, $existingKeys, $inserted, $skipped);
                    if (!$found) {
                        $this->warn("  [2回目] マッチなし: {$race->basho_name} R{$race->race} → 次回cron実行時に再試行されます");
                    }
                }
            }
        }

        $this->info('');
        $this->info("INSERT完了 → 登録: {$inserted} 件 / スキップ: {$skipped} 件");
        $this->info('========== keiba:importJraRaceOneResult 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');
        


        (new WebPushService())->sendPushNotifierDeveloperNews('develop', 'ImportKeibaJraRaceOneResult::handle' . "\n" . date('Y-m-d H:i:s') . '　登録:' . $inserted . '、飛:' . $skipped);
        

        
    }

    /**
     * レースの照合・INSERT を行う。
     * JRA結果の中に該当レースのエントリが1件でもあれば true を返す。
     * 1件もなければ false を返す（未掲載・照合ミス）。
     */
    private function matchAndInsert(
        object $race,
        array  $results,
        array  &$existingKeys,
        int    &$inserted,
        int    &$skipped
    ): bool {
        $found = false;

        foreach ($results as $v) {
            if (
                trim($race->kaisuu)     == trim($v['kaisuu']) &&
                trim($race->basho_name) == trim($v['basho'])  &&
                trim($race->day)        == trim($v['day'])     &&
                trim($race->race)       == trim($v['race'])
            ) {
                $found = true;

                $key    = "{$race->kaisuu}-{$race->basho}-{$race->day}-{$race->race}-{$v['horse_num']}";
                $exists = isset($existingKeys[$key]);

                if (!$exists) {
                    DB::table('t_horse_odds_finder_race_results')->insert([
                        'date'       => $race->date,
                        'kaisuu'     => $race->kaisuu,
                        'basho'      => $race->basho,
                        'basho_name' => $race->basho_name,
                        'day'        => $race->day,
                        'race'       => $race->race,
                        'race_name'  => $race->race_name,
                        'num'        => $v['horse_num'],
                        'horse_name' => $v['horse_name'],
                        'result'     => $v['rank'],
                    ]);

                    $existingKeys[$key] = true;

                    $this->info("    INSERT: {$v['horse_name']} ({$v['horse_num']}番) → {$v['rank']}着");
                    $inserted++;
                } else {
                    $this->warn("    SKIP (登録済み): {$v['horse_name']} ({$v['horse_num']}番)");
                    $skipped++;
                }
            }
        }

        return $found;
    }

    /**
     * Node.js スクリプトを実行してJRAのレース結果を配列で返す。
     * 取得失敗時は null を返す。
     */
    private function fetchResults(): ?array
    {
        $nodeBin    = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';
        $scriptPath = base_path('scripts/keibaOddsGetJraRaceResult.mjs');

        if (!file_exists($scriptPath)) {
            $this->error("スクリプトが見つかりません: {$scriptPath}");
            return null;
        }

        // timeout 300: 6開催×ページ遷移があるため余裕を持たせる
        $command = 'timeout 300 ' . $nodeBin . ' ' . escapeshellarg($scriptPath) . ' 2>/dev/null';
        $output  = shell_exec($command);

        if (!$output) {
            return null;
        }

        $data = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['results'])) {
            return null;
        }

        return $data['results'];
    }
}
