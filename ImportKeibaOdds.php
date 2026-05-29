<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * cronで毎分実行される。
 * $ary に合致する発走前分数のレースのみオッズを取得してDBに保存する。
 *
 * cron設定:
 *   * 9-17 * * * php /var/www/horse_odds_finder/artisan keiba:importOdds >> /var/www/horse_odds_finder/storage/logs/cron_odds.log 2>&1
 */
class ImportKeibaOdds extends Command
{
    protected $signature = 'keiba:importOdds';
    protected $description = 'オッズを取得してDBに保存する';

    public function handle(): void
    {
        $date    = date('Y-m-d');
        $now     = time();

        $script  = base_path('scripts/keibaOddsGetTanpuku.mjs');
        $logFile = base_path('scripts/keibaOddsGetTanpuku.log');
        $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';

        $this->info('');
        $this->info('========== keiba:importOdds 開始 ' . date('Y-m-d H:i:s', $now) . ' ==========');

        // ── レース一覧を取得（当日分のみ） ───────────────────────────
        $query = DB::table('t_horse_odds_finder_races')
            ->where('date', $date);

        $races      = $query->orderBy('start_time')->get();
        $totalRaces = count($races);

        $this->info("対象レース数: {$totalRaces} 件");

        if ($totalRaces === 0) {
            $this->info('対象レースが0件のため終了します。');
            return;
        }

        // オッズを取得するタイミング（発走X分前）の定義。
        // cronが毎分動くため、$diff がこの配列に含まれる分のみ処理する。
        $ary = [24, 21, 18, 15, 12, 9, 6, 3, 0];

        // ── レースごとの処理 ──────────────────────────────────────────
        foreach ($races as $race) {

            $targetTime  = strtotime("{$race->date} {$race->start_time}");
            $diffSeconds = $targetTime - $now;

            // 発走までの残り分数（切り捨て）
            $diff = (int) floor($diffSeconds / 60);

            // 発走済みは処理しない（$diff=0 が最後のタイミング）
            if ($diff < 0) {
                continue;
            }

            $this->info("--------------------------------------------------");
            $this->info("[{$race->basho_name}] {$race->race}R 「{$race->race_name}」 発走: {$race->start_time}  (残り {$diff} 分)");

            // $ary に合致しない分数はスキップ
            if (!in_array($diff, $ary)) {
                $this->info("  → スキップ (残り{$diff}分は取得タイミング外)");
                continue;
            }

            $this->info("  → 取得タイミング合致 (残り{$diff}分) : オッズ取得を開始します。");

            // ── Node.js でオッズをスクレイピング ─────────────────────
            // stderrはlogFileへリダイレクトし、stdoutのJSONに混入させない。
            $command = $nodeBin . ' ' . escapeshellarg($script)
                . ' ' . escapeshellarg($race->date)
                . ' ' . escapeshellarg($race->kaisuu)
                . ' ' . escapeshellarg($race->basho)
                . ' ' . escapeshellarg($race->race)
                . ' 2>>' . escapeshellarg($logFile);

            $this->info("  実行コマンド: {$command}");

            $odds    = null;
            $output  = '';
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

            if (!$odds) {
                $this->error("  オッズ取得失敗。このレースをスキップします。");
                $this->error("  Node.js 出力: " . $output);
                continue;
            }

            $horseCount = count($odds);
            $this->info("  オッズ取得成功 → {$horseCount} 頭分");

            // ── 各馬のオッズをDBに保存 ───────────────────────────────
            $saved = 0;
            DB::transaction(function () use ($odds, $race, $diff, &$saved) {
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
                }

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
                    DB::table('t_horse_odds_finder_odds_get_timing')
                        ->where($timingKey)
                        ->update($timingValues);
                } else {
                    DB::table('t_horse_odds_finder_odds_get_timing')
                        ->insert(array_merge($timingKey, $timingValues));
                }
            });

            $this->info("  DB保存完了 → {$saved} 頭分");
        }

        $this->info('');
        $this->info('========== keiba:importOdds 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');
    }
}
