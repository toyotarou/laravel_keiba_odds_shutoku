<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * cronで実行される。
 * t_horse_odds_finder_odds のデータをもとに
 * t_horse_odds_finder_summary に馬ごとのサマリーを生成・保存する。
 * 同一キー（date/kaisuu/basho/day/race/num）が既に存在する場合はINSERTしない。
 *
 * cron設定:
 *   0 18 * * * php /var/www/horse_odds_finder/artisan keiba:summary >> /var/www/horse_odds_finder/storage/logs/summaryKeibaInfo.log 2>&1
 */
class SummaryKeibaInfo extends Command
{
    protected $signature = 'keiba:summary';
    protected $description = '競馬情報のサマリーを作成する';

    public function handle(): void
    {
        // ★変更: クエリログ蓄積を停止（長時間バッチのメモリ肥大を防ぐ）
        DB::disableQueryLog();

        // ── 多重起動防止 ─────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_summary.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $now = time();
        $this->info('');
        $this->info('========== keiba:summary 開始 ' . date('Y-m-d H:i:s', $now) . ' ==========');

        // ── 馬情報を取得（waku, name） ─────────────────────────────────
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

        // ── レース情報を取得（basho_name, race_name） ──────────────────
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

        // ── 既存サマリーキーを取得（同一キーの重複INSERT防止） ─────────
        // 毎日実行されるため、既にINSERT済みのキーは処理をスキップする
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

        // ── JRAオッズを取得 ──────────────────────────────────────────
        // 1馬につき minutes_before_start の数だけ行が存在する
        $this->info('JRAオッズを取得中...');
        $result = DB::table('t_horse_odds_finder_odds')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->orderBy('num')
            ->orderBy('minutes_before_start')
            ->get();

        // $oddsMap: [ horse_key => [ 'odds_tan_before_XX' => value, ... ] ]
        // minutes_before_start: 999=24分前相当, -999=発走直前(0分), それ以外=残り分数
        $oddsMap = [];

        $lastFukuOdds = [];

        // ★変更: history絞り込み用の対象日付セットを収集する
        $targetDates = [];

        foreach ($result as $v) {
            $key = "{$v->date}_{$v->kaisuu}_{$v->basho}_{$v->day}_{$v->race}_{$v->num}";

            // ★変更: この馬の日付を対象日付として記録
            $targetDates[$v->date] = true;

            switch ($v->minutes_before_start) {
                case '999':
                    $oddsMap[$key]['odds_tan_before_24'] = $v->odds;
                    break;
                case '-999':
                    $oddsMap[$key]['odds_tan_before_0'] = $v->odds;

                    $lastFukuOdds[$key]['min'] = $v->fuku_min;
                    $lastFukuOdds[$key]['max'] = $v->fuku_max;

                    break;
                default:
                    $oddsMap[$key]["odds_tan_before_{$v->minutes_before_start}"] = $v->odds;
                    break;
            }
        }
        $this->info('  JRAオッズ: ' . count($oddsMap) . ' 頭分');

        // ★変更: odds全件($result)はmap構築後に不要。7104行を解放してメモリを空ける
        unset($result);

        // ── サマリーをINSERT ──────────────────────────────────────────
        // ★変更: 旧実装は $result(7104行) を再ループしていたが、
        //        $oddsMap のキーだけでINSERTに必要な値は全て復元できるため、
        //        $oddsMap を基準にループする（$result 保持が不要になりメモリ削減）。
        $this->info('サマリーを生成中...');
        $inserted     = 0;
        $skipped      = 0;
        $errors       = 0;
        $insertedKeys = [];

        foreach ($oddsMap as $key => $odds) {
            // ★変更: 同馬の複数タイミング行は $oddsMap で既に1キーに集約済みのため、
            //        $insertedKeys による今回実行内の重複チェックは不要。

            // 既存サマリーに存在するキーはスキップ（毎日実行のための重複防止）
            if (isset($existingSummaryKeys[$key])) {
                $skipped++;
                continue;
            }

            // ★変更: キーからカラム値を復元（history側と同じ explode パターン）
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
                'odds_tan_before_3'  => $odds['odds_tan_before_3']  ?? null,
                'odds_tan_before_0'  => $odds['odds_tan_before_0']  ?? null,
            ];

            DB::table('t_horse_odds_finder_summary')->insert($insert);
            $insertedKeys[$key] = true;
            $inserted++;
        }

        $this->info("  INSERT完了: {$inserted} 件 / スキップ（既存）: {$skipped} 件 / エラー（データ不備）: {$errors} 件");

        // ── レース結果履歴をUPSERT ─────────────────────────────────────────

        $this->info('レース結果履歴をUPSERT中...');

        // ★変更: 対象日付のリストを作成（この配列で history をスコープする）
        $targetDateList = array_keys($targetDates);

        // SummaryHistoryPopularityRank で設定済みの popularity_rank を保護するため
        // 既存レコードを先読みする
        // ★変更: 旧実装は t_horse_odds_finder_race_result_history を全件 ->get() していたが、
        //        16万件超（毎日増加）でメモリを圧迫するため、
        //        今回処理対象の date に含まれる行のみに絞り込む。
        //        参照している $existingPopularityRanks / $rankedRaceGroups は
        //        どちらも $oddsMap 内のキー（＝今回の対象日付）しか使わないため、
        //        対象日付だけ取得すれば結果は同一になる。
        $existingPopularityRanks = [];
        $rankedRaceGroups        = [];

        if (!empty($targetDateList)) {
            DB::table('t_horse_odds_finder_race_result_history')
                ->select('date', 'kaisuu', 'basho_code', 'day', 'race', 'num', 'popularity_rank')
                ->whereIn('date', $targetDateList) // ★変更: 全件→対象日付のみ
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

        // $oddsMap の全馬を対象にレコードを作成（summaryのスキップ対象も含む）
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

        // レースごとに人気順を計算（発走直前の単勝オッズ昇順、1位=最低オッズ）
        // グループ内に1頭でも popularity_rank > 0 の馬がいればスキップ（設定済み）
        $raceGroups = [];
        foreach ($historyRecords as $key => $record) {
            $raceKey = "{$record['date']}_{$record['kaisuu']}_{$record['basho_code']}_{$record['day']}_{$record['race']}";
            $raceGroups[$raceKey][] = $key;
        }

        foreach ($raceGroups as $raceKey => $keys) {
            if (isset($rankedRaceGroups[$raceKey])) {
                continue;
            }
            $withOdds = array_filter($keys, fn($k) => $historyRecords[$k]['tan'] !== null);
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
        $this->info('');
        $this->info('========== keiba:summary 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');



        (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryKeibaInfo::handle\n投入:{$inserted}、飛:{$skipped}、履歴:{$historyCount}");

    }
}
