<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use App\Constants\Constants;

/**
 * ImportKeibaRaceResult
 *
 * 【概要】
 *   ★DEPRECATED★
 *   Node.js スクリプト（keibaOddsGetRaceResult.mjs）経由でレースのオッズ・結果を取得して
 *   t_horse_odds_finder_netkeiba_odds に保存するコマンド。
 *   現在は DB 取得元（t_horse_odds_finder_netkeiba_races）の参照および
 *   saveOdds() の全 INSERT/UPDATE がコメントアウトされており実質的に無効化されている。
 *   代わりに ImportKeibaOdds（JRAサイト経由）を使用する。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】初期化・開始バナー・debug モード確認
 *   【ブロック 3】レース一覧取得（★現在は $races = collect() で常に空）
 *   【ブロック 4】レースごとのループ
 *                  → 発走タイミング差 $diff を計算
 *                  → Constants::ODDS_GET_TIMING に合致しない場合はスキップ
 *                  → minutes_before_start: diff=30→999, diff=0→-999 に変換
 *                  → Node.js 実行（fetchRaceDetail）
 *                  → saveOdds() + timing 記録（いずれも現在コメントアウト中）
 *   【ブロック 5】完了ログ
 *
 * 【minutes_before_start の変換規則】
 *   30 → 999  （30分前のベースオッズ）
 *    0 → -999 （発走直前の確定オッズ）
 *   その他 → そのままの分数
 *
 * 【cron 設定（旧）】
 *   * 9-17 * * * php /var/www/horse_odds_finder/artisan keiba:importRaceResult >> ... 2>&1
 *
 * 【使い方】
 *   php artisan keiba:importRaceResult
 *   php artisan keiba:importRaceResult --debug  # タイミングチェックをスキップ
 *   ※現在は無効化されているため実行しても何も保存されない。
 */
class ImportKeibaRaceResult extends Command
{
    protected $signature = 'keiba:importRaceResult {--debug : タイミングチェックをスキップして全レース処理する}';
    protected $description = 'ネットケイバからレースの結果・オッズを取得する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importRaceResult.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】初期化・開始バナー・debug モード確認
        //   --debug: タイミングチェック（Constants::ODDS_GET_TIMING との照合）をスキップして
        //            全レースを強制処理する。テスト用途のみ。
        // ─────────────────────────────────────────────────────────────────
        $now  = time();
        $date = date('Y-m-d');

        $isDebug = (bool) $this->option('debug');

        $this->info('');
        $this->info('========== keiba:importRaceResult 開始 ' . date('Y-m-d H:i:s', $now) . ' ==========');
        $this->info('対象日付: ' . $date);
        if ($isDebug) {
            $this->warn('【DEBUGモード】タイミングチェックをスキップします。');
        }

        $ary        = Constants::ODDS_GET_TIMING;
        $targetDate = $date;

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】レース一覧取得（★現在は $races = collect() で常に空）
        //   本来は t_horse_odds_finder_netkeiba_races から当日のレースを取得する。
        //   スクレイピング廃止に伴い常に空コレクションを返す。
        // ─────────────────────────────────────────────────────────────────
        // $races = DB::table('t_horse_odds_finder_netkeiba_races')
        //     ->where('date', $targetDate)
        //     ->orderBy('start_time')
        //     ->get();
        $races = collect();  // ★廃止済み: 常に空

        $totalRaces = count($races);
        $this->info("対象レース数: {$totalRaces} 件");

        if ($totalRaces === 0) {
            $this->info('対象レースが0件のため終了します。');
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 4】レースごとのループ
        //   ① 発走までの残り分数 $diff を算出（round() で四捨五入 = ImportKeibaOdds と同じ）。
        //   ② $diff < 0 は発走済み → スキップ。
        //   ③ Constants::ODDS_GET_TIMING に含まれない分数は取得タイミング外 → スキップ。
        //   ④ $diff → $diffMinutes: 30→999, 0→-999, それ以外→そのまま。
        //   ⑤ Node.js でオッズをスクレイピング（fetchRaceDetail）。
        //   ⑥ DB::transaction で saveOdds() + timing 記録（現在はいずれもコメントアウト中）。
        // ─────────────────────────────────────────────────────────────────
        $totalSaved = 0;
        $raceIndex  = 0;
        foreach ($races as $race) {
            $raceIndex++;

            $targetTime  = strtotime("{$race->date} {$race->start_time}");
            $diffSeconds = $targetTime - $now;
            $diff        = (int) round($diffSeconds / 60);

            if ($diff < 0) {
                continue;
            }

            $this->info("--------------------------------------------------");
            $this->info("[{$raceIndex}/{$totalRaces}] race_id={$race->race_id} 発走: {$race->start_time}  (残り {$diff} 分)");

            if (!$isDebug && !in_array($diff, $ary)) {
                $this->info("  → スキップ (残り{$diff}分は取得タイミング外)");
                continue;
            }

            $this->info("  → 取得タイミング合致 (残り{$diff}分) : オッズ取得を開始します。");



/////(1)
if ($diff === $ary[0]) {
/////



                $diffMinutes = Constants::ODDS_DB_FIRST;
            } elseif ($diff === 0) {
                $diffMinutes = Constants::ODDS_DB_LAST;
            } else {
                $diffMinutes = $diff;
            }

            // 発走済みで確定オッズが保存済みならスクレイピング不要（廃止済み）
            // if ($diffMinutes === -999) { ... }

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

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 5】完了ログ
        // ─────────────────────────────────────────────────────────────────
        $this->info('');
        $this->info('========== keiba:importRaceResult 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');
    }

    /**
     * 1頭分のオッズを t_horse_odds_finder_netkeiba_odds に保存する。
     * ★全 INSERT/UPDATE がコメントアウト中（廃止済み）。
     * minutes_before_start の挙動:
     *   999 → 既存があれば UPDATE、なければ INSERT
     *  -999 → 未保存の場合のみ INSERT（確定オッズの重複防止）
     *   その他 → 常に INSERT
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

        // if ($minutesBefore === 999) {
        //     $exists = DB::table('t_horse_odds_finder_netkeiba_odds')
        //         ->where($key)->where('minutes_before_start', 999)->exists();
        //     if ($exists) {
        //         DB::table('t_horse_odds_finder_netkeiba_odds')
        //             ->where($key)->where('minutes_before_start', 999)->update($update);
        //     } else {
        //         DB::table('t_horse_odds_finder_netkeiba_odds')->insert($insert);
        //     }
        //     return;
        // }
        //
        // if ($minutesBefore === -999) {
        //     $alreadySaved = DB::table('t_horse_odds_finder_netkeiba_odds')
        //         ->where($key)->where('minutes_before_start', -999)->exists();
        //     if (!$alreadySaved) {
        //         DB::table('t_horse_odds_finder_netkeiba_odds')->insert($insert);
        //     }
        //     return;
        // }
        //
        // DB::table('t_horse_odds_finder_netkeiba_odds')->insert($insert);
    }

    /**
     * Node.js スクリプトで1レース分のオッズ詳細を取得して JSON 文字列で返す。
     * 取得失敗または JSON でない場合は null を返す（timeout 120 で無応答を防ぐ）。
     */
    private function fetchRaceDetail(string $race_id): ?string
    {
        $nodeBin    = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';
        $scriptPath = base_path('scripts/keibaOddsGetRaceResult.mjs');
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
