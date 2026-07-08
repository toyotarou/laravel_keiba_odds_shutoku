<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportKeibaJraRaceOneResult
 *
 * 【概要】
 *   発走時刻から 10 / 15 / 20 / 25 / 30 分後のいずれかに当たる cron 実行時に
 *   JRAサイトから最新レース結果（着順・馬名）を取得し、
 *   t_horse_odds_finder_race_results に INSERT する。
 *   結果確定が遅れるケースに備えて Node.js を最大2回実行し（1回目不一致 → 2回目リトライ）、
 *   INSERT 完了レースは WebPush 通知でユーザーに知らせる。
 *
 * 【処理フロー】
 *   【ブロック 1】DEBUGオプション解析・開始バナー
 *   【ブロック 2】当日レース一覧を取得（通常は当日、DEBUG時は最新日付）
 *   【ブロック 3】発走後タイミング（+10〜+30分）にヒットしたレースを収集
 *   【ブロック 4】登録済みキーセットを一括先読み（重複 INSERT 防止）
 *   【ブロック 5】通知済みレースキーセットを一括先読み（二重通知防止）
 *   【ブロック 6】1回目 Node.js 実行 → 全ヒットレースを照合
 *   【ブロック 7】2回目 Node.js 実行（1回目でマッチしなかったレースのみ再試行）
 *   【ブロック 8】WebPush 通知送信 & notified_at 更新
 *   ── プライベートメソッド ──
 *   【ブロック 9】matchAndInsert(): レース照合・INSERT・通知キュー登録
 *   【ブロック 10】fetchResults(): Node.js 実行して結果配列を返す
 *
 * 【使い方】
 *   php artisan keiba:importJraRaceOneResult
 *   php artisan keiba:importJraRaceOneResult --debug   （タイミングチェック無効）
 *
 * 【多重起動防止について】
 *   現在はロックファイルコードがコメントアウトされている。
 *   同一 cron が複数並走しても matchAndInsert 内の $existingKeys チェックで
 *   二重 INSERT は防がれるため、実害はない。
 */
class ImportKeibaJraRaceOneResult extends Command
{
    protected $signature = 'keiba:importJraRaceOneResult {--debug : タイミングチェックをスキップして全レース処理する}';
    protected $description = 'JRAからひとつのレース結果（着順）を取得する';

    public function handle(): void
    {
        // // ── 多重起動防止 ─────────────────────────────────────────────
        // $lockFile = sys_get_temp_dir() . '/keiba_importJraRaceOneResult.lock';
        // if (file_exists($lockFile)) {
        //     $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
        //     return;
        // }
        // file_put_contents($lockFile, getmypid());
        // register_shutdown_function(fn() => @unlink($lockFile));

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】DEBUGオプション解析・開始バナー
        //   --debug オプションがある場合はタイミングチェックをスキップし、
        //   当日または DB 内最新日付の全レースを処理対象にする。
        // ─────────────────────────────────────────────────────────────────
        $isDebug = (bool) $this->option('debug');

        $this->info('');
        $this->info('========== keiba:importJraRaceOneResult 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        if ($isDebug) {
            $this->warn('【DEBUGモード】タイミングチェックをスキップします。');
        }

        $date = date("Y-m-d");
        $now  = time();

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】当日レース一覧を取得
        //   通常モード: 当日（$date）のレースのみ対象
        //   DEBUGモード: テーブル内の最大日付（最新レース日）を対象にする
        //               （レースのない日に手動で動作確認するため）
        // ─────────────────────────────────────────────────────────────────
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

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】発走後タイミング（+10〜+30分）にヒットしたレースを収集
        //   $diff = (現在時刻 - 発走時刻) / 60（分）
        //   [10, 15, 20, 25, 30] のいずれかに一致するレースのみ処理する。
        //   JRA公式の結果掲載が遅れる場合を考慮して30分後まで複数タイミングを設定。
        //   DEBUGモード時はタイミング不問で全レースを $hitRaces に追加する。
        // ─────────────────────────────────────────────────────────────────
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

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 4】登録済みキーセットを一括先読み（重複 INSERT 防止）
        //   キー形式: "{kaisuu}-{basho}-{day}-{race}-{num}"
        //   ->keys()->flip()->all() でキーを値にした連想配列を生成し、
        //   isset($existingKeys[$key]) で O(1) 検索できるようにする。
        //   INSERT 後はメモリ上に追記して同一実行内の重複も防ぐ。
        // ─────────────────────────────────────────────────────────────────
        $existingKeys = DB::table('t_horse_odds_finder_race_results')
            ->where('date', $date)
            ->whereNotNull('result')
            ->get(['kaisuu', 'basho', 'day', 'race', 'num'])
            ->keyBy(fn($r) => "{$r->kaisuu}-{$r->basho}-{$r->day}-{$r->race}-{$r->num}")
            ->keys()
            ->flip()
            ->all();

        $this->info("登録済み結果取得 → " . count($existingKeys) . " 件");

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 5】通知済みレースキーセットを一括先読み（二重通知防止）
        //   キー形式: "{kaisuu}-{basho}-{day}-{race}"（馬番は含まない）
        //   notified_at が設定済みのレースは WebPush 通知をスキップする。
        //   ->unique()->flip()->all() でハッシュセットとして利用する。
        // ─────────────────────────────────────────────────────────────────
        $notifiedRaceKeys = DB::table('t_horse_odds_finder_race_results')
            ->where('date', $date)
            ->whereNotNull('notified_at')
            ->get(['kaisuu', 'basho', 'day', 'race'])
            ->map(fn($r) => "{$r->kaisuu}-{$r->basho}-{$r->day}-{$r->race}")
            ->unique()
            ->flip()
            ->all();

        $this->info("通知済みレース取得 → " . count($notifiedRaceKeys) . " 件");

        $inserted     = 0;
        $skipped      = 0;
        $notifyRaces  = [];

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 6】1回目 Node.js 実行 → 全ヒットレースを照合
        //   fetchResults() で JRA サイトから最新の結果一覧を取得する。
        //   取得した結果を全ヒットレースに対して matchAndInsert() で照合する。
        //   照合失敗（結果未掲載）のレースは $unmatchedRaces に積む。
        // ─────────────────────────────────────────────────────────────────
        $this->info('');
        $this->info('  [1回目] Node.js 実行中...');
        $start   = microtime(true);
        $results = $this->fetchResults();
        $fetchMs = round((microtime(true) - $start) * 1000);

        if ($results === null) {
            $this->error('Node.js スクリプトの実行失敗（出力なし）');
            $this->info('========== keiba:importJraRaceOneResult 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');
            return;
        }

        $this->info("  [1回目] 取得完了 → {$fetchMs}ms / " . count($results) . " 件");

        $unmatchedRaces = [];
        foreach ($hitRaces as $race) {
            $found = $this->matchAndInsert($race, $results, $existingKeys, $notifiedRaceKeys, $inserted, $skipped, $notifyRaces);
            if (!$found) {
                $unmatchedRaces[] = $race;
                $this->warn("  [1回目] マッチなし: {$race->basho_name} R{$race->race} → 2回目で再試行します");
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 7】2回目 Node.js 実行（1回目でマッチしなかったレースのみ再試行）
        //   JRA公式の結果掲載タイムラグにより1回目で取れないケースを救済する。
        //   2回目でもマッチしない場合は次回 cron 実行時（5分後）に自動リトライされる。
        // ─────────────────────────────────────────────────────────────────
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
                    $found = $this->matchAndInsert($race, $results2, $existingKeys, $notifiedRaceKeys, $inserted, $skipped, $notifyRaces);
                    if (!$found) {
                        $this->warn("  [2回目] マッチなし: {$race->basho_name} R{$race->race} → 次回cron実行時に再試行されます");
                    }
                }
            }
        }

        $this->info('');
        $this->info("INSERT完了 → 登録: {$inserted} 件 / スキップ: {$skipped} 件");

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 8】WebPush 通知送信 & notified_at 更新
        //   $notifyRaces は matchAndInsert() が通知対象として積んだレースの連想配列。
        //   キー = "{kaisuu}-{basho}-{day}-{race}" で重複通知を防ぐ。
        //   送信後に t_horse_odds_finder_race_results の全馬行へ notified_at を一括更新する。
        // ─────────────────────────────────────────────────────────────────
        $notifyCount = count($notifyRaces);
        $this->info("通知対象レース → {$notifyCount} 件");

        foreach ($notifyRaces as $raceKey => $race) {
            $deepLinkUrl = 'https://baganriki.com/horse_odds_finder/?' . http_build_query([
                'date'    => $race->date,
                'kbd'     => "{$race->kaisuu}_{$race->basho}_{$race->day}",
                'name'    => "{$race->kaisuu}回{$race->basho_name}{$race->day}日",
                'race'    => $race->race,
                'ranking' => '1',
                'zoomed'  => '0',
            ]);

            (new WebPushService())->sendPushNotifierOddsNews(
                'レース結果が確定しました',
                "{$race->date} {$race->kaisuu}回{$race->basho_name}{$race->day}日\nR{$race->race} {$race->race_name}",
                $deepLinkUrl,
            );

            // 同レースの全馬行をまとめて notified_at 更新（whereNull で二重更新を防ぐ）
            DB::table('t_horse_odds_finder_race_results')
                ->where('date',    $race->date)
                ->where('kaisuu',  $race->kaisuu)
                ->where('basho',   $race->basho)
                ->where('day',     $race->day)
                ->where('race',    $race->race)
                ->whereNull('notified_at')
                ->update(['notified_at' => date('Y-m-d H:i:s')]);

            $this->info("  通知送信済み & notified_at 更新: {$race->basho_name} R{$race->race}");
        }

        $this->info('========== keiba:importJraRaceOneResult 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');
    }

    /**
     * 【ブロック 9】matchAndInsert: レース照合・INSERT・通知キュー登録
     *
     * JRA結果配列 ($results) の中に $race と一致するエントリを探す。
     * 一致判定: kaisuu / basho_name / day / race が全て trim 一致
     * 一致した馬を $existingKeys でチェックし、未登録のものだけ INSERT する。
     * INSERT した馬の所属レースが通知未済の場合は $notifyRaces に追加する。
     *
     * 戻り値: 1件でもヒットすれば true、1件もなければ false（次回リトライ対象）
     *
     * @param array $notifiedRaceKeys 通知済みレースキーのセット（読み取り専用）
     */
    private function matchAndInsert(
        object $race,
        array  $results,
        array  &$existingKeys,
        array  $notifiedRaceKeys,
        int    &$inserted,
        int    &$skipped,
        array  &$notifyRaces
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
                        'date'        => $race->date,
                        'kaisuu'      => $race->kaisuu,
                        'basho'       => $race->basho,
                        'basho_name'  => $race->basho_name,
                        'day'         => $race->day,
                        'race'        => $race->race,
                        'race_name'   => $race->race_name,
                        'num'         => $v['horse_num'],
                        'horse_name'  => $v['horse_name'],
                        'result'      => $v['rank'],
                        'notified_at' => null,
                    ]);

                    $existingKeys[$key] = true;

                    // 通知済みでないレースのみ通知対象に追加
                    $raceKey = "{$race->kaisuu}-{$race->basho}-{$race->day}-{$race->race}";
                    if (!isset($notifiedRaceKeys[$raceKey])) {
                        $notifyRaces[$raceKey] = $race;
                    }

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
     * 【ブロック 10】fetchResults: Node.js 実行して結果配列を返す
     *
     * keibaOddsGetJraRaceResult.mjs を実行し、JRAサイトの最新レース結果を取得する。
     * timeout 300: 6開催分のページ遷移があるため余裕を持たせる。
     * 出力 JSON の構造: { "results": [ { kaisuu, basho, day, race, horse_num, horse_name, rank }, ... ] }
     *
     * 取得失敗（出力なし / JSON パース不可 / results キー欠落）の場合は null を返す。
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
