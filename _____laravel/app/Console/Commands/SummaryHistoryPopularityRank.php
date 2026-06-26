<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * t_horse_odds_finder_race_result_history の popularity_rank を
 * レースごとに tan（単勝オッズ）の昇順で採番して更新する。
 *
 * 使い方:
 *   php artisan keiba:summaryHistoryPopularityRank --yearmonth=2021-01
 *   php artisan keiba:summaryHistoryPopularityRank   ← 今月
 */
class SummaryHistoryPopularityRank extends Command
{
    protected $signature   = 'keiba:summaryHistoryPopularityRank {--yearmonth= : 対象年月 (例: 2021-01、省略時は今月)}';
    protected $description = 't_horse_odds_finder_race_result_history の popularity_rank を tan順で設定する';

    public function handle(): void
    {
        // ── 多重起動防止 ─────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_summaryHistoryPopularityRank.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        // ── 引数チェック ──────────────────────────────────────────────
        $yearmonth = $this->option('yearmonth') ?: date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $yearmonth)) {
            $this->error('--yearmonth=YYYY-MM の形式で指定してください。例: --yearmonth=2021-01');
            return;
        }

        $now = microtime(true);
        $this->info('');
        $this->info('========== keiba:summaryHistoryPopularityRank 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('対象年月     : ' . $yearmonth);
        $this->info('');

        // ── レコード取得 ─────────────────────────────────────────────
        $this->info('レコードを取得中...');

        $query = DB::table('t_horse_odds_finder_race_result_history')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho_code')
            ->orderBy('day')
            ->orderBy('race')
            ->orderBy('num');

        [$year, $month] = explode('-', $yearmonth);
        $from = "{$year}-{$month}-01";
        $to   = date('Y-m-t', strtotime($from));
        $query->whereBetween('date', [$from, $to]);

        $records = $query->get();
        $this->info("  取得件数: {$records->count()} 件");
        $this->info('');

        if ($records->isEmpty()) {
            $this->warn('対象レコードがありません。');
            $this->info('========== keiba:summaryHistoryPopularityRank 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');
            return;
        }

        // ── レースごとにグループ化 ───────────────────────────────────
        $groups = [];
        foreach ($records as $row) {
            $raceKey           = "{$row->date}_{$row->kaisuu}_{$row->basho_code}_{$row->day}_{$row->race}";
            $groups[$raceKey][] = $row;
        }
        
        $totalRaces = count($groups);
        $this->info("グループ化完了: {$totalRaces} レース");
        $this->info('');

        // ── popularity_rank を計算して UPDATE ───────────────────────
        $this->info('popularity_rank を更新中...');
        $updatedRaces  = 0;
        $updatedHorses = 0;
        $skippedRaces  = 0;

        foreach ($groups as $raceKey => $horses) {
            // 全馬がランク済みならスキップ
            if (collect($horses)->every(fn($h) => $h->popularity_rank > 0)) {
                $skippedRaces++;
                continue;
            }

            // tan を数値として昇順ソート（NULL・空・非数値は最後尾）
            usort($horses, function ($a, $b) {
                $tanA = is_numeric($a->tan) ? (float) $a->tan : PHP_FLOAT_MAX;
                $tanB = is_numeric($b->tan) ? (float) $b->tan : PHP_FLOAT_MAX;
                return $tanA <=> $tanB;
            });

            $rank = 1;
            foreach ($horses as $horse) {
                DB::table('t_horse_odds_finder_race_result_history')
                    ->where('id', $horse->id)
                    ->update(['popularity_rank' => $rank]);
                $rank++;
                $updatedHorses++;
            }

            $updatedRaces++;

            if ($updatedRaces % 100 === 0) {
                $elapsed = round(microtime(true) - $now, 1);
                $this->info("  {$updatedRaces}/{$totalRaces} レース処理済み ({$elapsed}秒)");
            }
        }

        $elapsed = round(microtime(true) - $now, 1);
        $this->info('');
        $this->info('========== keiba:summaryHistoryPopularityRank 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info("対象年月     : " . $yearmonth);
        $this->info("処理レース数 : {$updatedRaces} レース");
        $this->info("スキップ     : {$skippedRaces} レース（ランク設定済み）");
        $this->info("更新頭数     : {$updatedHorses} 頭");
        $this->info("処理時間     : {$elapsed} 秒");
        $this->info('');
        


        (new WebPushService())->sendPushNotifierDeveloperNews('develop', 'SummaryHistoryPopularityRank::handle' . "\n" . date('Y-m-d H:i:s') . '　処理レース数:' . $updatedRaces . '、処理時間:' . $elapsed);


        
    }
}
