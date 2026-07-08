<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportKeibaBaseOdds
 *
 * 【概要】
 *   土曜7時に1回だけ実行するベースオッズ取得コマンド。
 *   土日両日分の全レースの単勝・複勝オッズを keibaOddsGetTanpuku.mjs 経由で取得し、
 *   minutes_before_start=999（24時間前相当のベース値）として DB に保存する。
 *   同一キーのレコードが既に存在する場合は INSERT せず UPDATE（upsert）する。
 *   これにより importOdds（毎分 cron）がレースの24分前タイミングを処理するときの
 *   ベースライン（比較元）オッズが確実に DB に入った状態になる。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】初期化・開始バナー
 *   【ブロック 3】本日以降のレース一覧を取得
 *   【ブロック 4】レースごとのループ
 *   【ブロック 5】Node.js スクリプト実行（リトライ最大3回）
 *   【ブロック 6】取得オッズを DB に upsert
 *   【ブロック 7】完了サマリーログ出力
 *   【ブロック 8】WebPush 通知（finally で必ず送信）
 *
 * 【使い方】
 *   php artisan keiba:importBaseOdds
 *
 * 【cron設定】
 *   0 7 * * 6 php /var/www/horse_odds_finder/artisan keiba:importBaseOdds >> /var/www/horse_odds_finder/storage/logs/importBaseOdds.log 2>&1
 *
 * 【補足: minutes_before_start=999 の意味】
 *   ImportKeibaOdds では ODDS_GET_TIMING=[24,21,...,0] の 24 を DB 値 999 に変換する。
 *   importBaseOdds が事前に 999 で INSERT しておくことで、
 *   importOdds の 24分前タイミング処理が「INSERT ではなく UPDATE」で済む。
 */
class ImportKeibaBaseOdds extends Command
{
    protected $signature = 'keiba:importBaseOdds';
    protected $description = '土曜7時に全レースのベースオッズを取得してDBに保存する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        //   同じコマンドを複数の cron が同時に起動しないよう /tmp にロックファイルを作成する。
        //   ファイルが存在する場合は別プロセスが実行中とみなして即座に終了する。
        //   register_shutdown_function でスクリプト終了時（正常・異常問わず）に自動削除する。
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importBaseOdds.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $totalRaces = 0;
        $totalSaved = 0;

        try {

            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 2】初期化・開始バナー
            //   microtime(true) で処理時間計測のベースタイムを記録する。
            //   Node.js スクリプト・ログファイル・node バイナリのパスを変数化する。
            // ─────────────────────────────────────────────────────────────────
            $now     = microtime(true);
            $script  = base_path('scripts/keibaOddsGetTanpuku.mjs');
            $logFile = base_path('scripts/keibaOddsGetTanpuku.log');
            $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';

            $this->info('');
            $this->info('========== keiba:importBaseOdds 開始 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('スクリプト   : ' . $script);
            $this->info('node パス    : ' . $nodeBin);
            $this->info('ログファイル : ' . $logFile);
            $this->info('');

            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 3】本日以降のレース一覧を取得
            //   土曜実行の場合 → date >= today で土日両日分が全て対象
            //   日曜実行の場合 → 日曜分のみ残っているため日曜分のみが対象
            //   start_time 昇順で取得することで処理ログが発走時刻順になる
            // ─────────────────────────────────────────────────────────────────
            $today      = date('Y-m-d');
            $races      = DB::table('t_horse_odds_finder_races')
                ->where('date', '>=', $today)
                ->orderBy('date')
                ->orderBy('start_time')
                ->get();

            $totalRaces = count($races);
            $this->info("対象レース数: {$totalRaces} 件");

            if ($totalRaces === 0) {
                $this->info('対象レースが0件のため終了します。');
                return;
            }

            $raceIndex    = 0;
            $totalSaved   = 0;
            $failedRaces  = [];

            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 4】レースごとのループ
            //   $race は t_horse_odds_finder_races の1行（stdClass）。
            //   date / kaisuu / basho / race / day を Node.js スクリプトへ渡す。
            // ─────────────────────────────────────────────────────────────────
            foreach ($races as $race) {
                $raceIndex++;

                $this->info("──────────────────────────────────────");
                $this->info("[{$raceIndex}/{$totalRaces}] {$race->basho_name} {$race->race}R 「{$race->race_name}」 {$race->date} {$race->start_time}");

                // ─────────────────────────────────────────────────────────────
                // 【ブロック 5】Node.js スクリプト実行（リトライ最大3回）
                //   stderr はログファイルへリダイレクトし stdout の JSON に混入させない。
                //   timeout 120: Node.js が無応答でも PHP が永久ブロックするのを防ぐ。
                //   リトライ間隔は5秒（JRAサーバの一時的な応答遅延を考慮）。
                //   json_decode が falsy の場合（空文字列・JSON異常）はリトライ対象。
                // ─────────────────────────────────────────────────────────────
                $command = 'timeout 120 ' . $nodeBin . ' ' . escapeshellarg($script)
                    . ' ' . escapeshellarg($race->date)
                    . ' ' . escapeshellarg($race->kaisuu)
                    . ' ' . escapeshellarg($race->basho)
                    . ' ' . escapeshellarg($race->race)
                    . ' ' . escapeshellarg($race->day)
                    . ' 2>>' . escapeshellarg($logFile);

                $this->info("  実行コマンド: {$command}");

                $raceStart = microtime(true);
                $odds      = null;
                $output    = '';
                $maxRetry  = 3;
                for ($retry = 1; $retry <= $maxRetry; $retry++) {
                    $output = shell_exec($command);
                    $odds   = json_decode($output, true);
                    if ($odds) break;
                    if ($retry < $maxRetry) {
                        $this->warn("  [RETRY {$retry}/{$maxRetry}] オッズ取得失敗。5秒後にリトライします...");
                        sleep(5);
                    }
                }
                $elapsed = round((microtime(true) - $raceStart) * 1000);

                // 全リトライで失敗した場合はこのレースを $failedRaces に記録して次へ
                if (!$odds) {
                    $this->error("  [FAIL] オッズ取得失敗 ({$elapsed}ms)");
                    $this->error("  Node.js 出力: " . $output);
                    $failedRaces[] = "{$race->basho_name} {$race->race}R ({$race->date})";
                    continue;
                }

                $horseCount = count($odds);
                $this->info("  オッズ取得成功 → {$horseCount} 頭分 ({$elapsed}ms)");

                // ─────────────────────────────────────────────────────────────
                // 【ブロック 6】取得オッズを DB に upsert（INSERT or UPDATE）
                //   一意キー: date + kaisuu + basho + day + race + num + minutes_before_start(=999)
                //   同一キーが既存の場合は odds / fuku_min / fuku_max を上書き更新する。
                //   EXISTS チェックで INSERT/UPDATE を明示的に分岐する
                //   （DB ドライバに依存しない汎用的な upsert 手法）。
                //   minutes_before_start=999 は「24時間前相当のベースオッズ」を意味する慣例値。
                // ─────────────────────────────────────────────────────────────
                $saved = 0;
                foreach ($odds as $horse) {
                    $key = [
                        'date'                 => $race->date,
                        'kaisuu'               => $race->kaisuu,
                        'basho'                => $race->basho,
                        'day'                  => $race->day,
                        'race'                 => $race->race,
                        'num'                  => $horse['num'],
                        'minutes_before_start' => 999,   // ベースオッズは常に 999 で保存
                    ];
                    $data = [
                        'odds'     => $horse['tan'],      // 単勝オッズ
                        'fuku_min' => $horse['fuku_min'], // 複勝オッズ下限
                        'fuku_max' => $horse['fuku_max'], // 複勝オッズ上限
                    ];

                    $exists = DB::table('t_horse_odds_finder_odds')->where($key)->exists();

                    if ($exists) {
                        DB::table('t_horse_odds_finder_odds')->where($key)->update($data);
                    } else {
                        DB::table('t_horse_odds_finder_odds')->insert(array_merge($key, $data));
                    }
                    $saved++;
                }

                $totalSaved += $saved;
                $this->info("  DB保存完了 → {$saved} 頭分");
            }

            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 7】完了サマリーログ出力
            //   失敗レースがある場合は '[FAIL]' 行として個別に列挙する。
            //   totalElapsed は全レース処理にかかった合計秒数。
            // ─────────────────────────────────────────────────────────────────
            $totalElapsed = round(microtime(true) - $now, 1);
            $failedCount  = count($failedRaces);

            $this->info('');
            $this->info('========== keiba:importBaseOdds 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info("処理レース数 : {$totalRaces} 件");
            $this->info("保存頭数合計 : {$totalSaved} 頭");
            $this->info("失敗レース数 : {$failedCount} 件" . ($failedCount > 0 ? ' ← 要確認' : ''));
            foreach ($failedRaces as $f) {
                $this->error("  [FAIL] {$f}");
            }
            $this->info("処理時間     : {$totalElapsed} 秒");
            $this->info('');

        } finally {
            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 8】WebPush 通知（finally で必ず送信）
            //   try ブロック内で return / 例外が発生しても必ず実行される。
            //   R=処理レース数 / H=保存頭数 を本文に含める。
            // ─────────────────────────────────────────────────────────────────
            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "ImportKeibaBaseOdds::handle\nR:{$totalRaces}、H:{$totalSaved}");
        }
        
    }
}
