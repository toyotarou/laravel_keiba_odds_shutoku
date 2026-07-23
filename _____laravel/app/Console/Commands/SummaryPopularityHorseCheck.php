<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SummaryPopularityHorseCheck
 *
 * 【概要】
 *   t_horse_odds_finder_popularity_rank_median のレースごとに
 *   「median値 / レース開始時オッズ」が高い馬を上位X頭ピックアップし、
 *   レース結果（着順1〜3位）と合わせて INSERT する。
 *
 * 【頭立て別ピックアップ頭数】
 *   8頭以下: 4頭 / 9〜13頭: 5頭 / 14〜18頭: 6頭
 *
 * 【INSERT 条件】
 *   popularity_horse が pickCount 頭すべて揃っており、
 *   かつ finishing_horse が 1〜3 位すべて揃っている場合のみ INSERT する。
 *
 * 【使い方】
 *   php artisan keiba:popularityHorseCheck
 */
class SummaryPopularityHorseCheck extends Command
{
    protected $signature   = 'keiba:popularityHorseCheck';
    protected $description = 'median/オッズ上位馬とレース結果を集計して INSERT する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_popularityHorseCheck.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $now = microtime(true);
        $this->info('');
        $this->info('========== keiba:popularityHorseCheck 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】処理対象レースを取得
        // ─────────────────────────────────────────────────────────────────
        $medianRows = DB::table('t_horse_odds_finder_popularity_rank_median')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->get();

        $this->info("  対象レース数: {$medianRows->count()} 件");
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】対象なし → 早期終了
        // ─────────────────────────────────────────────────────────────────
        if ($medianRows->isEmpty()) {
            $this->warn('処理対象のレースがありません。処理を終了します。');
            $this->info('========== keiba:popularityHorseCheck 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');
            (new WebPushService())->sendPushNotifierDeveloperNews(
                'develop',
                'SummaryPopularityHorseCheck::handle' . "\n" . '処理対象なし（空振り）'
            );
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 4】サポートデータを一括取得
        // ─────────────────────────────────────────────────────────────────
        $dates = $medianRows->pluck('date')->unique()->values()->toArray();

        $horses = [];
        $finishing = [];
        foreach (DB::table('t_horse_odds_finder_race_result_history')
            ->whereIn('date', $dates)
            ->get() as $v) {
            $key = "{$v->kaisuu}_{$v->basho_code}_{$v->day}";
            $horses[$v->date][$key][$v->race][$v->num] = $v->name;
            if (in_array($v->finishing_position, [1, 2, 3])) {
                $finishing[$v->date][$key][$v->race][$v->finishing_position] = $v->name;
            }
        }

        $startOdds = [];
        foreach (DB::table('t_horse_odds_finder_odds')
            ->whereIn('date', $dates)
            ->orderBy('odds')
            ->get() as $v) {
            $startOdds[$v->date]["{$v->kaisuu}_{$v->basho}_{$v->day}"][$v->race][$v->minutes_before_start][] = ['num' => $v->num, 'odds' => $v->odds];
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 5】レースごとに集計・INSERT
        // ─────────────────────────────────────────────────────────────────
        $this->info('集計・挿入中...');
        $insertedCount = 0;
        $skippedCount  = 0;

        foreach ($medianRows as $medianRow) {
            $key = "{$medianRow->kaisuu}_{$medianRow->basho}_{$medianRow->day}";

            $exists = DB::table('t_horse_odds_finder_popularity_horse_check')
                ->where('date',   $medianRow->date)
                ->where('kaisuu', $medianRow->kaisuu)
                ->where('basho',  $medianRow->basho)
                ->where('day',    $medianRow->day)
                ->where('race',   $medianRow->race)
                ->exists();
            if ($exists) { continue; }

            // median 配列を組み立て（空文字は除外）
            $median = array_values(array_filter(
                array_map(fn($i) => $medianRow->{"median_" . str_pad($i, 2, '0', STR_PAD_LEFT)}, range(1, 18)),
                fn($val) => trim($val) !== ''
            ));

            // レース開始時オッズを取得（-999 優先、なければ最終に近いもの）
            $raceOdds = $startOdds[$medianRow->date][$key][$medianRow->race] ?? [];
            if (isset($raceOdds[-999])) {
                $raceStartOdds = $raceOdds[-999];
            } elseif (!empty($raceOdds)) {
                $raceStartOdds = $raceOdds[max(array_keys($raceOdds))];
            } else {
                $raceStartOdds = [];
            }

            // median/オッズ 降順でソート
            $popularity = [];
            foreach ($raceStartOdds as $v) {
                $medianIdx = (int)$v['num'] - 1;
                if (isset($median[$medianIdx])) {
                    $popularity[] = ['rate' => (float)$median[$medianIdx] / (float)$v['odds'], 'num' => $v['num']];
                }
            }
            usort($popularity, fn($a, $b) => $b['rate'] <=> $a['rate']);

            // 頭立て別ピックアップ頭数
            $numHorses = count($raceStartOdds);
            if ($numHorses <= 8) {
                $pickCount = 4;
            } elseif ($numHorses <= 13) {
                $pickCount = 5;
            } else {
                $pickCount = 6;
            }

            // popularity_horse を組み立て
            $popularitySlice = array_slice($popularity, 0, $pickCount);
            $raceFinishing   = $finishing[$medianRow->date][$key][$medianRow->race] ?? [];
            ksort($raceFinishing);

            // INSERT 条件チェック：popularity_horse と finishing_horse がすべて揃っているか
            if (count($popularitySlice) < $pickCount || count($raceFinishing) < 3) {
                $this->line("  SKIP {$medianRow->date} kaisuu={$medianRow->kaisuu} basho={$medianRow->basho} day={$medianRow->day} race={$medianRow->race}"
                    . " (popularity=" . count($popularitySlice) . "/{$pickCount}, finishing=" . count($raceFinishing) . "/3)");
                $skippedCount++;
                continue;
            }

            // INSERT データ組み立て
            $insert = [
                'date'   => $medianRow->date,
                'kaisuu' => $medianRow->kaisuu,
                'basho'  => $medianRow->basho,
                'day'    => $medianRow->day,
                'race'   => $medianRow->race,
            ];
            foreach ($popularitySlice as $k => $v) {
                $insert['popularity_horse' . ($k + 1)] = $horses[$medianRow->date][$key][$medianRow->race][$v['num']];
            }
            foreach ($raceFinishing as $pos => $name) {
                $insert["finishing_horse{$pos}"] = $name;
            }

            $popularityNames = array_map(
                fn($v) => $horses[$medianRow->date][$key][$medianRow->race][$v['num']],
                $popularitySlice
            );
            $insert['hit_count'] = count(array_intersect($popularityNames, array_values($raceFinishing)));

            DB::table('t_horse_odds_finder_popularity_horse_check')->insert($insert);
            $insertedCount++;

            $this->line("  INSERT {$medianRow->date} kaisuu={$medianRow->kaisuu} basho={$medianRow->basho} day={$medianRow->day} race={$medianRow->race} hit={$insert['hit_count']}");
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 6】完了ログ・WebPush 通知
        // ─────────────────────────────────────────────────────────────────
        $elapsed = round(microtime(true) - $now, 1);
        $this->info('');
        $this->info('========== keiba:popularityHorseCheck 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info("挿入: {$insertedCount} 件 / スキップ: {$skippedCount} 件");
        $this->info("処理時間: {$elapsed} 秒");
        $this->info('');

        (new WebPushService())->sendPushNotifierDeveloperNews(
            'develop',
            "SummaryPopularityHorseCheck::handle\n挿入:{$insertedCount}件 スキップ:{$skippedCount}件 time:{$elapsed}"
        );
    }
}
