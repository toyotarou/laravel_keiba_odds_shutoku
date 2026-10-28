<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SummaryKeibaInfo
 *
 * 【概要】
 *   t_horse_odds_finder_odds のデータをもとに
 *   t_horse_odds_finder_summary に馬ごとのサマリーを生成・保存する。
 *   また t_horse_odds_finder_race_result_history へのベースオッズ upsert も行う。
 *   同一キー（date/kaisuu/basho/day/race/num）が既に存在する場合はINSERTしない。
 *
 * 【処理フロー】
 *   【ブロック 1】クエリログ無効化・多重起動防止
 *   【ブロック 2】馬情報を取得（waku, name） → $horses マップ
 *   【ブロック 3】レース情報を取得（basho_name, race_name） → $races マップ
 *   【ブロック 4】既存サマリーキーを取得（重複INSERT防止用ハッシュセット）
 *   【ブロック 5】JRAオッズを取得 → $oddsMap に集約
 *                （20261027: 6分前の複勝下限・上限 → $fuku6、6分前の取得日時 → $getTime6 も集約）
 *   【ブロック 6】サマリーを INSERT（既存キーはスキップ）
 *   【ブロック 7】レース結果履歴をUPSERT（popularity_rank は設定済み行を保護）
 *   【ブロック 8】完了ログ・WebPush 通知
 *
 * 【$oddsMap のデータ構造】
 *   $oddsMap[horse_key]['odds_tan_before_XX'] = value
 *   minutes_before_start の変換:
 *     999  → 'odds_tan_before_24'  （ベースオッズ）
 *     -999 → 'odds_tan_before_0'   （発走直前確定）
 *     N    → 'odds_tan_before_N'   （残り N 分前）
 *
 * 【メモリ削減の工夫】
 *   DB::disableQueryLog() でクエリログ蓄積を停止。
 *   $result（7104行超）は $oddsMap 構築後に unset() して解放する。
 *   history テーブルは全件取得せず対象日付のみに絞り込む。
 *
 * 【cron設定】
 *   0 18 * * * php /var/www/horse_odds_finder/artisan keiba:summary >> /var/www/horse_odds_finder/storage/logs/summaryKeibaInfo.log 2>&1
 */
class SummaryKeibaInfo extends Command
{
    protected $signature = 'keiba:summary';
    protected $description = '競馬情報のサマリーを作成する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】クエリログ無効化・多重起動防止
        //   DB::disableQueryLog(): 長時間バッチでクエリログがメモリを肥大化させないよう無効化。
        // ─────────────────────────────────────────────────────────────────
        DB::disableQueryLog();

        $lockFile = sys_get_temp_dir() . '/keiba_summary.lock';
        if (file_exists($lockFile)) {
            $pid = (int) file_get_contents($lockFile);
            $isRunning = $pid > 0 && (

                (function_exists('posix_kill') && posix_kill($pid, 0))

                || file_exists("/proc/{$pid}")

            );

            if ($isRunning) {
                $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
                return;
            }
            $this->warn("ロックファイルの残骸を削除して続行します (PID: {$pid})");
            unlink($lockFile);
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $now = time();
        $this->info('');
        $this->info('========== keiba:summary 開始 ' . date('Y-m-d H:i:s', $now) . ' ==========');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】馬情報を取得（waku, name） → $horses マップ
        //   キー: "date_kaisuu_basho_day_race_num"
        //   summary INSERT 時に waku・horse_name の参照に使う。
        // ─────────────────────────────────────────────────────────────────
        $this->info('馬情報を取得中...');
        $horses = [];

        $result = DB::table('t_horse_odds_finder_horses')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->orderBy('waku')
            ->orderBy('num')
            ->get();

        foreach ($result as $v) {
            $horses["{$v->date}_{$v->kaisuu}_{$v->basho}_{$v->day}_{$v->race}_{$v->num}"]['waku'] = $v->waku;
            $horses["{$v->date}_{$v->kaisuu}_{$v->basho}_{$v->day}_{$v->race}_{$v->num}"]['name'] = $v->name;
        }
        $this->info('  馬情報: ' . count($horses) . ' 頭');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】レース情報を取得（basho_name, race_name） → $races マップ
        //   キー: "date_kaisuu_basho_day_race"
        // ─────────────────────────────────────────────────────────────────
        $this->info('レース情報を取得中...');
        $races = [];

        $result = DB::table('t_horse_odds_finder_races')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->get();

        foreach ($result as $v) {
            $races["{$v->date}_{$v->kaisuu}_{$v->basho}_{$v->day}_{$v->race}"]['basho_name'] = $v->basho_name;
            $races["{$v->date}_{$v->kaisuu}_{$v->basho}_{$v->day}_{$v->race}"]['race_name']  = $v->race_name;
        }
        $this->info('  レース情報: ' . count($races) . ' レース');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 4】既存サマリーキーを取得（重複INSERT防止用ハッシュセット）
        //   毎日実行されるため、既にINSERT済みのキーは処理をスキップする。
        //   O(1) ルックアップのために配列値を true で保持する（isset() で判定）。
        // ─────────────────────────────────────────────────────────────────
        $this->info('既存サマリーキーを確認中...');
        $existingSummaryKeys = [];

        $result = DB::table('t_horse_odds_finder_summary')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->orderBy('num')
            ->get();

        foreach ($result as $v) {
            $key                      = "{$v->date}_{$v->kaisuu}_{$v->basho}_{$v->day}_{$v->race}_{$v->num}";
            $existingSummaryKeys[$key] = true;
        }
        $this->info('  既存サマリー: ' . count($existingSummaryKeys) . ' 件');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 5】JRAオッズを取得 → $oddsMap に集約
        //   1馬につき minutes_before_start の数だけ行が存在するため、
        //   horse_key でまとめて 'odds_tan_before_XX' カラム名に変換する。
        //   minutes_before_start の変換: 999→'before_24', -999→'before_0', N→'before_N'
        //   $lastFukuOdds: -999（発走直前）の複勝オッズ（下限・上限）を別途保持。
        //   $targetDates: history テーブルの絞り込みに使う対象日付セット。
        //   $result は map 構築後に unset() してメモリを解放する。
        // ─────────────────────────────────────────────────────────────────
        $this->info('JRAオッズを取得中...');
        // 20261026: 翌日以降のオッズはサマリーにしない。
        //   G1 の週は、土曜の時点で日曜の G1（前日発売）が「1R」として races / horses / odds に入り、
        //   土曜18時のこの処理で日曜「1R」のサマリーが G1 の馬・ベースオッズだけで作られていた。
        //   日曜に正しいデータへ入れ替わっても、既存キーはスキップするため直らなかった
        //   （09-27 スプリンターズS、10-18 秋華賞、10-25 菊花賞で発生）。
        //   当日以前に絞れば、日曜分は日曜18時に正しいデータで作られる。
        $result = DB::table('t_horse_odds_finder_odds')
            ->where('date', '<=', date('Y-m-d'))   // 20261026: 追加
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->orderBy('num')
            ->orderBy('minutes_before_start')
            ->get();

        $oddsMap      = [];
        $lastFukuOdds = [];
        $fuku6        = [];   // 20261027: 6分前の複勝下限・上限（期待値数の検証用に保存）
        $targetDates  = [];

        foreach ($result as $v) {
            $key = "{$v->date}_{$v->kaisuu}_{$v->basho}_{$v->day}_{$v->race}_{$v->num}";
            $targetDates[$v->date] = true;

            switch ($v->minutes_before_start) {
                case (string) Constants::ODDS_DB_FIRST:
                    $oddsMap[$key]['odds_tan_before_24'] = $v->odds;
                    break;
                case (string) Constants::ODDS_DB_LAST:
                    $oddsMap[$key]['odds_tan_before_0'] = $v->odds;
                    $lastFukuOdds[$key]['min'] = $v->fuku_min;
                    $lastFukuOdds[$key]['max'] = $v->fuku_max;
                    break;
                default:
                    $oddsMap[$key]["odds_tan_before_{$v->minutes_before_start}"] = $v->odds;
                    // 20261027: 6分前は複勝の下限・上限も保存する
                    if ((int) $v->minutes_before_start === 6) {
                        $fuku6[$key]['min'] = $v->fuku_min;
                        $fuku6[$key]['max'] = $v->fuku_max;
                    }
                    break;
            }
        }

        // 20261027: 6分前オッズの取得日時（レース単位）を取得する。
        //   t_horse_odds_finder_odds_get_timing は毎週消えるため、サマリーに写して残す。
        //   同じレースで複数行ある場合は最も遅い取得日時を使う。
        $getTime6 = [];
        if (!empty($targetDates)) {
            DB::table('t_horse_odds_finder_odds_get_timing')
                ->whereIn('date', array_keys($targetDates))
                ->where('timing', 6)
                ->groupBy('date', 'kaisuu', 'basho', 'day', 'race')
                ->get([
                    'date', 'kaisuu', 'basho', 'day', 'race',
                    DB::raw('MAX(get_datetime) AS get_datetime'),
                ])
                ->each(function ($r) use (&$getTime6) {
                    $getTime6["{$r->date}_{$r->kaisuu}_{$r->basho}_{$r->day}_{$r->race}"] = $r->get_datetime;
                });
        }
        $this->info('  JRAオッズ: ' . count($oddsMap) . ' 頭分');

        unset($result);  // $oddsMap 構築後はもう不要なので解放

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 6】サマリーを INSERT（既存キーはスキップ）
        //   $oddsMap のキーから date/kaisuu/basho/day/race/num を explode で復元。
        //   レース情報・馬情報のどちらかがない場合はエラーとしてスキップ。
        // ─────────────────────────────────────────────────────────────────
        $this->info('サマリーを生成中...');
        $inserted     = 0;
        $skipped      = 0;
        $errors       = 0;
        $insertedKeys = [];

        foreach ($oddsMap as $key => $odds) {
            if (isset($existingSummaryKeys[$key])) {
                $skipped++;
                continue;
            }

            [$date, $kaisuu, $basho, $day, $race, $num] = explode('_', $key);
            $raceKey = "{$date}_{$kaisuu}_{$basho}_{$day}_{$race}";

            if (!isset($races[$raceKey])) {
                $this->warn("  [SKIP] レース情報なし: {$raceKey}");
                $errors++;
                continue;
            }
            if (!isset($horses[$key])) {
                $this->warn("  [SKIP] 馬情報なし: {$key}");
                $errors++;
                continue;
            }

            $insert = [
                'date'   => $date,
                'kaisuu' => $kaisuu,
                'basho'  => $basho,
                'day'    => $day,
                'race'   => $race,
                'num'    => $num,

                'basho_name' => $races[$raceKey]['basho_name'],
                'race_name'  => $races[$raceKey]['race_name'],

                'waku'       => $horses[$key]['waku'],
                'horse_name' => $horses[$key]['name'],

                'odds_tan_before_24' => $odds['odds_tan_before_24'] ?? null,
                'odds_tan_before_21' => $odds['odds_tan_before_21'] ?? null,
                'odds_tan_before_18' => $odds['odds_tan_before_18'] ?? null,
                'odds_tan_before_15' => $odds['odds_tan_before_15'] ?? null,
                'odds_tan_before_12' => $odds['odds_tan_before_12'] ?? null,
                'odds_tan_before_9'  => $odds['odds_tan_before_9']  ?? null,
                'odds_tan_before_6'  => $odds['odds_tan_before_6']  ?? null,
                'odds_fuku_min_before_6'     => $fuku6[$key]['min'] ?? null,     // 20261027: 追加
                'odds_fuku_max_before_6'     => $fuku6[$key]['max'] ?? null,     // 20261027: 追加
                'odds_get_datetime_before_6' => $getTime6[$raceKey] ?? null,     // 20261027: 追加
                'odds_tan_before_3'  => $odds['odds_tan_before_3']  ?? null,
                'odds_tan_before_0'  => $odds['odds_tan_before_0']  ?? null,
            ];

            DB::table('t_horse_odds_finder_summary')->insert($insert);
            $insertedKeys[$key] = true;
            $inserted++;
        }

        $this->info("  INSERT完了: {$inserted} 件 / スキップ（既存）: {$skipped} 件 / エラー（データ不備）: {$errors} 件");

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 7】レース結果履歴をUPSERT（popularity_rank は設定済み行を保護）
        //   history テーブルは全件取得せず $targetDateList で絞り込む
        //   （旧実装の全件取得は16万件超でメモリを圧迫していた）。
        //   $existingPopularityRanks: 馬キー → 既存の popularity_rank マップ。
        //   $rankedRaceGroups: レースキー → 設定済みかどうかのフラグ。
        //   popularity_rank が設定済みのレースはスキップ（SummaryHistoryPopularityRank を保護）。
        // ─────────────────────────────────────────────────────────────────
        $this->info('レース結果履歴をUPSERT中...');

        $targetDateList          = array_keys($targetDates);
        $existingPopularityRanks = [];
        $rankedRaceGroups        = [];

        if (!empty($targetDateList)) {
            DB::table('t_horse_odds_finder_race_result_history')
                ->select('date', 'kaisuu', 'basho_code', 'day', 'race', 'num', 'popularity_rank')
                ->whereIn('date', $targetDateList)
                ->get()
                ->each(function ($r) use (&$existingPopularityRanks, &$rankedRaceGroups) {
                    $horseKey = "{$r->date}_{$r->kaisuu}_{$r->basho_code}_{$r->day}_{$r->race}_{$r->num}";
                    $raceKey  = "{$r->date}_{$r->kaisuu}_{$r->basho_code}_{$r->day}_{$r->race}";
                    $existingPopularityRanks[$horseKey] = $r->popularity_rank;
                    if (!isset($rankedRaceGroups[$raceKey]) && $r->popularity_rank > 0) {
                        $rankedRaceGroups[$raceKey] = true;
                    }
                });
        }

        $historyRecords = [];
        foreach ($oddsMap as $key => $odds) {
            [$date, $kaisuu, $basho, $day, $race, $num] = explode('_', $key);
            $raceKey = "{$date}_{$kaisuu}_{$basho}_{$day}_{$race}";

            if (!isset($races[$raceKey]) || !isset($horses[$key])) {
                continue;
            }

            $historyRecords[$key] = [
                'date'            => $date,
                'kaisuu'          => $kaisuu,
                'basho'           => $races[$raceKey]['basho_name'],
                'basho_code'      => $basho,
                'day'             => $day,
                'race'            => $race,
                'race_name'       => $races[$raceKey]['race_name'],
                'num'             => $num,
                'name'            => $horses[$key]['name'],
                'tan'             => $odds['odds_tan_before_0'] ?? null,
                'fuku_min'        => $lastFukuOdds[$key]['min'] ?? null,
                'fuku_max'        => $lastFukuOdds[$key]['max'] ?? null,
                'popularity_rank' => $existingPopularityRanks[$key] ?? null,
            ];
        }

        // レースごとに人気順を計算（rankedRaceGroups に含まれるレースはスキップ）
        $raceGroups = [];
        foreach ($historyRecords as $key => $record) {
            $raceKey = "{$record['date']}_{$record['kaisuu']}_{$record['basho_code']}_{$record['day']}_{$record['race']}";
            $raceGroups[$raceKey][] = $key;
        }

        foreach ($raceGroups as $raceKey => $keys) {
            if (isset($rankedRaceGroups[$raceKey])) {
                continue;
            }
            // 「取消」等の非数値は (float) で 0 になり 1番人気扱いになるため、数値のみ採番する
            $withOdds = array_filter($keys, fn($k) => is_numeric($historyRecords[$k]['tan']));
            usort($withOdds, fn($a, $b) => (float)$historyRecords[$a]['tan'] <=> (float)$historyRecords[$b]['tan']);
            $rank = 1;
            foreach ($withOdds as $k) {
                $historyRecords[$k]['popularity_rank'] = $rank++;
            }
        }

        if (!empty($historyRecords)) {
            DB::table('t_horse_odds_finder_race_result_history')->upsert(
                array_values($historyRecords),
                ['date', 'kaisuu', 'basho_code', 'day', 'race', 'num'],
                ['basho', 'race_name', 'name', 'tan', 'fuku_min', 'fuku_max', 'popularity_rank']
            );
        }

        $historyCount = count($historyRecords);
        $this->info("  UPSERT完了: {$historyCount} 件");

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 8】完了ログ・WebPush 通知
        // ─────────────────────────────────────────────────────────────────
        $this->info('');
        $this->info('========== keiba:summary 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');

        $newsValue = [];
        if($inserted == 0){
            $newsValue[] = "SKIP";
        }else{
            $newsValue[] = "投入:{$inserted}、";
            $newsValue[] = "飛:{$skipped}、";
            $newsValue[] = "履歴:{$historyCount}";
        }
        $news = implode("", $newsValue);
        
        (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryKeibaInfo::handle\n{$news}");
    }
}
