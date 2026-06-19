<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use App\Services\LineService;

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
            $nearestDate = DB::table('t_horse_odds_finder_races')
                ->max('date');
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

        // fetchJraRaceResult() は全レース分をまとめて返すため、
        // 複数レースが時間条件にヒットしても1回だけ実行する
        $json         = null;
        $results      = null;
        $existingKeys = null;

        $inserted = 0;
        $skipped  = 0;

        foreach ($resultRaces as $race) {

            // 発走時刻との差分（分）を計算
            $targetTime  = strtotime("{$race->date} {$race->start_time}");
            $diffSeconds = $now - $targetTime;
            $diff        = (int) round($diffSeconds / 60);

            // 結果確定まで最低10分かかるため、10〜30分後のみ処理する（DEBUGモード時はスキップ）
            if (!$isDebug && !in_array($diff, [10, 15, 20, 25, 30])) {
                continue;
            }

            if (in_array($diff, [10, 15, 20, 25, 30])) {
                $this->info("  ヒット: kaisuu={$race->kaisuu} {$race->basho_name} {$race->day}日目 R{$race->race} (発走+{$diff}分)");
            } else {
                $this->info("  [DEBUG] kaisuu={$race->kaisuu} {$race->basho_name} {$race->day}日目 R{$race->race} (発走+{$diff}分)");
            }

            //============================================================//
            // Node.js スクリプトはまだ実行していない場合のみ実行する
            if ($results === null) {
                $start = microtime(true);
                $json  = $this->fetchJraRaceResult();
                $fetchMs = round((microtime(true) - $start) * 1000);

                if (!$json) {
                    $this->error('Node.js スクリプトの実行失敗（出力なし）');
                    return;
                }

                $data = json_decode($json, true);
                if (json_last_error() !== JSON_ERROR_NONE || !isset($data['results'])) {
                    $this->error('JSONパース失敗: ' . json_last_error_msg());
                    return;
                }

                $results = $data['results'];
                $this->info("  Node.js 取得完了 → {$fetchMs}ms / " . count($results) . " 件");

                // 登録済みチェックをループ内DBクエリではなく配列参照で行うため、
                // 当日の登録済み結果を一括取得してキーセットを構築する
                // キー形式: "{kaisuu}-{basho}-{day}-{race}-{num}"
                $existingKeys = DB::table('t_horse_odds_finder_race_results')
                    ->where('date', $date)
                    ->whereNotNull('result')
                    ->get(['kaisuu', 'basho', 'day', 'race', 'num'])
                    ->keyBy(fn($r) => "{$r->kaisuu}-{$r->basho}-{$r->day}-{$r->race}-{$r->num}")
                    ->keys()
                    ->flip()
                    ->all();

                $this->info("  登録済み結果取得 → " . count($existingKeys) . " 件");
            }
            //============================================================//

            // JRAのレース結果と照合し、合致した馬をINSERT
            // basho_name（例: 東京）と JRA側の basho（例: 東京）で照合する
            $raceInsertedHorses = [];
            foreach ($results as $v) {
                if (
                    trim($race->kaisuu)     == trim($v['kaisuu']) and
                    trim($race->basho_name) == trim($v['basho'])  and
                    trim($race->day)        == trim($v['day'])     and
                    trim($race->race)       == trim($v['race'])
                ) {
                    // 同じ馬の結果がすでに登録済みであればスキップ（配列参照）
                    $key = "{$race->kaisuu}-{$race->basho}-{$race->day}-{$race->race}-{$v['horse_num']}";
                    $exists = isset($existingKeys[$key]);

                    if (!$exists) {
                        $insert = [];

                        $insert['date']       = $race->date;

                        $insert['kaisuu']     = $race->kaisuu;
                        $insert['basho']      = $race->basho;
                        $insert['basho_name'] = $race->basho_name;
                        $insert['day']        = $race->day;

                        $insert['race']       = $race->race;
                        $insert['race_name']  = $race->race_name;

                        $insert['num']        = $v['horse_num'];
                        $insert['horse_name'] = $v['horse_name'];

                        $insert['result']     = $v['rank'];

                        DB::table('t_horse_odds_finder_race_results')->insert($insert);

                        // INSERT後にキーセットへ追加（同一実行内での重複防止）
                        $existingKeys[$key] = true;

                        $this->info("    INSERT: {$v['horse_name']} ({$v['horse_num']}番) → {$v['rank']}着");
                        $inserted++;

                        $raceInsertedHorses[] = [
                            'rank' => $v['rank'],
                            'num'  => $v['horse_num'],
                            'name' => $v['horse_name'],
                        ];
                    } else {
                        $this->warn("    SKIP (登録済み): {$v['horse_name']} ({$v['horse_num']}番)");
                        $skipped++;
                    }
                }
            }
            


            if (!empty($raceInsertedHorses)) {
                try {
                    usort($raceInsertedHorses, fn($a, $b) => (int)$a['rank'] <=> (int)$b['rank']);

                    $lines   = [];
                    $lines[] = '===========================';
                    $lines[] = '馬眼力OddsFinder News';
                    $lines[] = '';
                    $lines[] = 'レース結果が確定しました。';
                    $lines[] = "{$race->date}　{$race->kaisuu}回{$race->basho_name}{$race->day}日";
                    $lines[] = "R{$race->race}　{$race->race_name}";
                    $lines[] = '';
                    foreach ($raceInsertedHorses as $h) {
                        $lines[] = $h['rank'] . '着　' . $h['num'] . '　' . $h['name'];
                    }
                    $lines[] = '===========================';

                    app(LineService::class)->sendLineOddsNews(implode("\n", $lines));

                } catch (\Exception $e) {
                    \Log::warning('LINE送信失敗: ' . $e->getMessage());
                }
            }

        }

        if ($inserted > 0) {
            try {
                app(LineService::class)->sendLineDevelopperNews(
                    "ImportKeibaJraRaceOneResult::handle\n" .
                    "登録: {$inserted} 件\n" .
                    "スキップ: {$skipped} 件\n" .
                    "完了日時: " . date('Y-m-d H:i:s')
                );
            } catch (\Exception $e) {
                \Log::warning('LINE送信失敗: ' . $e->getMessage());
            }
        }
        

        
        $this->info("INSERT完了 → 登録: {$inserted} 件 / スキップ: {$skipped} 件");
        $this->info('========== keiba:importJraRaceOneResult 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');
    }

    /**
     * Node.js スクリプトを実行してJRAのレース結果JSONを取得する
     */
    private function fetchJraRaceResult(): ?string
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

        json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return trim($output);
        }

        return null;
    }
}
