<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use App\Constants\Constants;
use App\Events\BaganrikiOddsSent;

/**
 * ImportKeibaOdds
 *
 * 【概要】
 *   cron で毎分（9〜17時）実行されるオッズ取得コマンド。
 *   Constants::ODDS_GET_TIMING = [30, 21, 18, 15, 12, 9, 6, 3, 0]（発走前分数）に
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
 *   【ブロック 11】r12.2 取得監査の記録（@ANCHOR-20261020-R122-AUDIT）
 *
 * 【r12.2 取得監査について】
 *   server_contract_r12.2.txt 4章・8章／詳細設計書 4.3 のお願い②（A案）です。
 *   「いつ要求を出し、いつ受け取り、いつ読めるようになったか」の実時刻と、
 *   受け取った原ペイロードそのものを baganriki_acquisition_batches へ残します。
 *   既存の t_horse_odds_finder_odds_get_timing の get_datetime は
 *   「保存が終わった時刻」なので、要求開始・受信完了の代わりには使えません。
 *
 *   <b>既存の列・元値・保存処理には一切触れていません。</b>
 *   新しいテーブルへ1行 INSERT するだけで、失敗しても本番は止めません
 *   （記録できなかったことをログに残し、そのバッチは採用しません）。
 *
 *   止め方: .env に BAGANRIKI_R122_AUDIT=0 を入れると記録しません。
 *   テーブルが無い環境では自動的に何もしません。
 *
 * 【cron設定】
 *   * 9-17 * * * php /var/www/horse_odds_finder/artisan keiba:importOdds >> /var/www/horse_odds_finder/storage/logs/cron_odds.log 2>&1
 *
 * 【minutes_before_start の変換ルール】
 *   diff=30 → 999  （importBaseOdds と同じ ベースオッズの扱い）
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
        //   $timings = ODDS_GET_TIMING: [30, 21, 18, 15, 12, 9, 6, 3, 0]
        //   diff=30 → DB値 999、diff=0 → DB値 -999 に変換する慣例がある。
        // ─────────────────────────────────────────────────────────────────
        $date    = date('Y-m-d');
        $now     = time();
        $script  = base_path('scripts/keibaOddsGetTanpuku.mjs');
        $logFile = base_path('scripts/keibaOddsGetTanpuku.log');
        $nodeBin = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';
        $isDebug = (bool) $this->option('debug');
        $timings = Constants::ODDS_GET_TIMING; // [30, 21, 18, 15, 12, 9, 6, 3, 0]

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
            //   $timings[0]=30 は最初のタイミングなので「前」がない → スキップ。
            //   $timingIndex > 0 の場合、$timings[$timingIndex-1] が直前タイミング。
            //   直前タイミングのオッズを asort して人気順位 $prevRankMap を作る。
            //   diff=30 の場合、DB上のキーは 999 なので $prevMinutesBefore に変換する。
            // ─────────────────────────────────────────────────────────────
            $prevRankMap = [];
            $timingIndex = array_search($diff, $timings);



/////(1)
if ($diff !== $timings[0] && $timingIndex !== false && $timingIndex > 0) {
/////



                $prevDiff          = $timings[$timingIndex - 1];



/////(2)
$prevMinutesBefore = ($prevDiff === $timings[0]) ? Constants::ODDS_DB_FIRST : $prevDiff;
/////



                $prevOddsMap = DB::table('t_horse_odds_finder_odds')
                    ->where('date',                 $race->date)
                    ->where('kaisuu',               $race->kaisuu)
                    ->where('basho',                $race->basho)
                    ->where('day',                  $race->day)
                    ->where('race',                 $race->race)
                    ->where('minutes_before_start', $prevMinutesBefore)
                    ->pluck('odds', 'num')
                    ->all();

                $prevOddsMap = array_filter($prevOddsMap, 'is_numeric'); // 「取消」等は (float) で 0 になり1位扱いになるため除外
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
            // @ANCHOR-20261020-R122-AUDIT  要求を出す直前の実時刻
            $r122ReqStartedAt = self::r122Now();
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
            // @ANCHOR-20261020-R122-AUDIT  受信し終えた実時刻（= observed_at）
            $r122RespReceivedAt = self::r122Now();

            if (!$odds) {
                $this->error("  [単複] オッズ取得失敗。このレースをスキップします。({$fetchSec}秒 / {$fetchMs}ms)");
                $this->error("  Node.js 出力: " . $output);
                // ─────────────────────────────────────────────────────
                // @ANCHOR-20261020-R122-AUDIT  取れなかったことも残します
                //   採用しない監査行（committed=0・REJECTED）として入れます。
                //   「記録が無い」と「取れなかった」を区別するためです。
                // ─────────────────────────────────────────────────────
                $this->r122RecordBatch($race, $diff, [
                    'request_started_at'   => $r122ReqStartedAt,
                    'response_received_at' => $r122RespReceivedAt,
                    'available_at'         => null,
                    'raw_payload'          => (string) $output,
                    'validation_result'    => 'REJECTED',
                    'rejection_reason'     => 'オッズ取得に失敗（Node.jsの出力をJSONとして読めない）',
                    'committed'            => 0,
                ]);
                continue;
            }

            $this->info("  [単複] Node.js 取得完了 → " . count($odds) . " 頭分 ({$fetchSec}秒 / {$fetchMs}ms)");

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 6】現タイミングの人気順位を計算 [num => rank]
            //   取得した $odds を tan（単勝オッズ）の昇順にソートして順位を付ける。
            //   $prevRankMap との差分が $changeRecords になる。
            // ─────────────────────────────────────────────────────────────
            $currRankMap = [];
            $sortedCurr  = array_values(array_filter($odds, fn($h) => is_numeric($h['tan'] ?? null))); // 「取消」等は順位計算から除外
            usort($sortedCurr, fn($a, $b) => (float) $a['tan'] <=> (float) $b['tan']);
            $rank = 1;
            foreach ($sortedCurr as $horse) {
                $currRankMap[$horse['num']] = $rank++;
            }

            $saved         = 0;
            $changeRecords = [];

            DB::transaction(function () use ($odds, $race, $diff, $timings, $prevRankMap, $currRankMap, &$saved, &$changeRecords) {

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
                    //   diff=30: importBaseOdds が999で先行 INSERT している場合があるため upsert
                    //   diff=0 : -999（発走直前の確定オッズ）として INSERT
                    //   その他: diff の値そのままで INSERT（同一 diff の重複は想定しない）
                    // ─────────────────────────────────────────────────────



/////(3)
if ($diff === $timings[0]) {
/////



                        $exists = DB::table('t_horse_odds_finder_odds')
                            ->where($key)->where('minutes_before_start', Constants::ODDS_DB_FIRST)->exists();
                        if ($exists) {
                            DB::table('t_horse_odds_finder_odds')
                                ->where($key)->where('minutes_before_start', Constants::ODDS_DB_FIRST)->update($data);
                        } else {
                            DB::table('t_horse_odds_finder_odds')
                                ->insert(array_merge($key, $data, ['minutes_before_start' => Constants::ODDS_DB_FIRST]));
                        }
                    } else {
                        $minutesBefore = ($diff === 0) ? Constants::ODDS_DB_LAST : $diff;
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



/////(4)
$timing    = ($diff === $timings[0]) ? Constants::ODDS_DB_FIRST : (($diff === 0) ? Constants::ODDS_DB_LAST : $diff);
/////



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
            // 【ブロック 11】r12.2 取得監査の記録
            // @ANCHOR-20261020-R122-AUDIT
            //   available_at は「保存が終わって読めると確かめた実時刻」です。
            //   予定時刻で埋めません。ここは保存トランザクションの<b>後</b>です。
            // ─────────────────────────────────────────────────────────────
            $this->r122RecordBatch($race, $diff, [
                'request_started_at'   => $r122ReqStartedAt,
                'response_received_at' => $r122RespReceivedAt,
                'available_at'         => self::r122Now(),
                'raw_payload'          => (string) $output,
                'validation_result'    => 'ADOPTED',
                'rejection_reason'     => null,
                'committed'            => 1,
            ]);

            // ─────────────────────────────────────────────────────────
            // 【ブロック 10.5】WebSocket broadcast
            //   DB保存完了後、Flutter側にオッズ更新を通知する。
            //   $odds は Node.js から取得した [['num','tan','fuku_min','fuku_max'], ...] の配列。
            // ─────────────────────────────────────────────────────────
            broadcast(new BaganrikiOddsSent(
                $race->date,
                (string) $race->kaisuu,
                $race->basho,
                (string) $race->day,
                $race->race,
                $odds,
            ));

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

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-R122-AUDIT  いまの実時刻（マイクロ秒まで）
     * ═══════════════════════════════════════════════════════════════════════
     * DATETIME(6) へそのまま入れられる形で返します。
     * サーバのタイムゾーン（JST）のままです。
     */
    private static function r122Now(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-R122-AUDIT  取得監査を1行残す（お願い②・A案）
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：server_contract_r12.2.txt 4章・8章／詳細設計書 4.3
     *
     * ■ 残すもの
     *   batch_id              同じ応答バッチの一意ID
     *   slot                  発走までの分（21/18/15/12/9/6。30・3・0も残します）
     *   slot_scheduled_at     発走時刻から slot 分を引いた予定時刻
     *   source_updated_at     取得元の更新時刻。取れないので null のままです
     *   request_started_at    要求を出した実時刻
     *   response_received_at  受信し終えた実時刻（＝ observed_at）
     *   available_at          保存が終わって読めると確かめた実時刻
     *   raw_payload           受け取った原文そのもの（加工しません）
     *   payload_hash          原文の SHA-256
     *   validation_result     ADOPTED / REJECTED
     *   committed             1 のものだけを採用します
     *
     * ■ やらないこと
     *   ・既存の列や元値を書き換えません。新しいテーブルへ INSERT するだけです。
     *   ・時刻を予定時刻で埋めません。取れないものは null のままにします。
     *   ・ここで失敗しても本番を止めません。ログに残してそのまま進みます
     *     （記録できなかったバッチは committed=0 のまま＝採用されません）。
     *
     * ■ 止め方
     *   .env に BAGANRIKI_R122_AUDIT=0 を入れると記録しません。
     *   テーブルが無い環境では何もしません（--r122-install で作ります）。
     */
    private function r122RecordBatch($race, int $diff, array $v): void
    {
        try {
            $sw = (string) env('BAGANRIKI_R122_AUDIT', '1');
            if (in_array($sw, ['0', 'false', 'off', 'no'], true)) {
                return;
            }
            if (!\Illuminate\Support\Facades\Schema::hasTable('baganriki_acquisition_batches')) {
                return;
            }

            $raceId = \App\Http\Controllers\Api\AiController::r122RaceId(
                $race->date, $race->kaisuu, $race->basho, $race->day, $race->race);

            // 予定時刻は「発走時刻 − slot分」。実時刻の代わりには使いません。
            $anchor = strtotime("{$race->date} {$race->start_time}");
            $slotScheduledAt = ($anchor === false) ? null
                : date('Y-m-d H:i:s.000000', $anchor - $diff * 60);

            $raw  = (string) ($v['raw_payload'] ?? '');
            $hash = ($raw === '') ? null : hash('sha256', $raw);

            // batch_id は UNIQUE。同じレース・同じスロットで撮り直したときも
            // 別の行として残るように、実時刻と乱数を混ぜます。
            $batchId = $raceId . '-' . $diff . '-'
                     . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

            \Illuminate\Support\Facades\DB::table('baganriki_acquisition_batches')->insert([
                'race_id'              => $raceId,
                'slot'                 => $diff,
                'batch_id'             => $batchId,
                'slot_scheduled_at'    => $slotScheduledAt,
                'source_updated_at'    => null,   // 取得元の更新時刻は取れません
                'request_started_at'   => $v['request_started_at']   ?? null,
                'response_received_at' => $v['response_received_at'] ?? null,
                'available_at'         => $v['available_at']         ?? null,
                'raw_payload'          => ($raw === '') ? null : $raw,
                'payload_hash'         => $hash,
                'validation_result'    => $v['validation_result'] ?? null,
                'rejection_reason'     => $v['rejection_reason']  ?? null,
                'committed'            => (int) ($v['committed'] ?? 0),
                'created_at'           => self::r122Now(),
            ]);

            $this->info(sprintf('  [r12.2監査] %s slot=%d %s hash=%s',
                $raceId, $diff, (string) ($v['validation_result'] ?? ''),
                ($hash === null) ? '(なし)' : substr($hash, 0, 12)));
        } catch (\Throwable $e) {
            // 記録できなくても本番は止めません。採用もされません。
            $this->warn('  [r12.2監査] 記録できませんでした: ' . $e->getMessage());
            \Log::warning('[R122-AUDIT] 取得監査を記録できませんでした', [
                'date' => $race->date ?? null, 'race' => $race->race ?? null,
                'slot' => $diff, 'err' => $e->getMessage(),
            ]);
        }
    }

}
