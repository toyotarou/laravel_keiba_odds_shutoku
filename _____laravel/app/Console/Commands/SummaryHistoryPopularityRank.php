<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SummaryHistoryPopularityRank
 *
 * 【概要】
 *   t_horse_odds_finder_race_result_history テーブルの popularity_rank カラムを
 *   レースごとに tan（単勝オッズ）の昇順で採番して更新する。
 *   popularity_rank が全馬設定済みのレースはスキップして処理を効率化する。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】引数チェック（YYYY-MM 形式の検証）
 *   【ブロック 3】初期化・開始バナー
 *   【ブロック 4】レコード取得（date_from〜date_to の全馬）
 *   【ブロック 5】レースごとにグループ化
 *   【ブロック 6】popularity_rank を計算して UPDATE
 *   【ブロック 7】完了サマリー・WebPush 通知
 *
 * 【popularity_rank のソートルール】
 *   tan が数値の馬を昇順（安いほど人気）でソートし、1位から採番する。
 *   NULL・空・非数値の tan は PHP_FLOAT_MAX として最後尾に回す。
 *   同オッズの馬が複数いる場合は元の DB 取得順で採番される。
 *
 * 【使い方】
 *   php artisan keiba:summaryHistoryPopularityRank --yearmonth=2021-01
 *   php artisan keiba:summaryHistoryPopularityRank  # 今月
 */
class SummaryHistoryPopularityRank extends Command
{
    protected $signature   = 'keiba:summaryHistoryPopularityRank {--yearmonth= : 対象年月 (例: 2021-01、省略時は今月)}';
    protected $description = 't_horse_odds_finder_race_result_history の popularity_rank を tan順で設定する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_summaryHistoryPopularityRank.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】引数チェック（YYYY-MM 形式の検証）
        // ─────────────────────────────────────────────────────────────────
        $yearmonth = $this->option('yearmonth') ?: date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $yearmonth)) {
            $this->error('--yearmonth=YYYY-MM の形式で指定してください。例: --yearmonth=2021-01');
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】初期化・開始バナー
        // ─────────────────────────────────────────────────────────────────
        $now = microtime(true);
        $this->info('');
        $this->info('========== keiba:summaryHistoryPopularityRank 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('対象年月     : ' . $yearmonth);
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 4】レコード取得（date_from〜date_to の全馬）
        //   date_from = 月初日（YYYY-MM-01）、date_to = 月末日（date('Y-m-t', ...)）
        //   popularity_rank の有無に関わらず全馬取得する（グループ化後にスキップ判定）。
        //   orderBy で取得順をキーとして利用する（同一レース内での num 順が保証される）。
        // ─────────────────────────────────────────────────────────────────
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

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 5】レースごとにグループ化
        //   $groups[$raceKey] = [馬1, 馬2, ...] の形に整理する。
        //   raceKey = "date_kaisuu_basho_code_day_race" の文字列（一意キー）。
        // ─────────────────────────────────────────────────────────────────
        $groups = [];
        foreach ($records as $row) {
            $raceKey           = "{$row->date}_{$row->kaisuu}_{$row->basho_code}_{$row->day}_{$row->race}";
            $groups[$raceKey][] = $row;
        }

        $totalRaces = count($groups);
        $this->info("グループ化完了: {$totalRaces} レース");
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 6】popularity_rank を計算して UPDATE
        //   全馬がランク済み（popularity_rank > 0）のレースはスキップ。
        //   tan を数値として昇順ソート（NULL・空・非数値は PHP_FLOAT_MAX として最後尾）。
        //   ソート後に 1, 2, 3, ... と採番して各馬を UPDATE する。
        //   100 レースごとに進捗をログ出力する。
        // ─────────────────────────────────────────────────────────────────
        $this->info('popularity_rank を更新中...');
        $updatedRaces  = 0;
        $updatedHorses = 0;
        $skippedRaces  = 0;

        foreach ($groups as $raceKey => $horses) {
            if (collect($horses)->every(fn($h) => $h->popularity_rank > 0)) {
                $skippedRaces++;
                continue;
            }

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

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 7】完了サマリー・WebPush 通知
        // ─────────────────────────────────────────────────────────────────
        $elapsed = round(microtime(true) - $now, 1);
        $this->info('');
        $this->info('========== keiba:summaryHistoryPopularityRank 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info("対象年月     : " . $yearmonth);
        $this->info("処理レース数 : {$updatedRaces} レース");
        $this->info("スキップ     : {$skippedRaces} レース（ランク設定済み）");
        $this->info("更新頭数     : {$updatedHorses} 頭");
        $this->info("処理時間     : {$elapsed} 秒");
        $this->info('');

        (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryHistoryPopularityRank::handle\nR:{$updatedRaces}、H:{$updatedHorses}、time:{$elapsed}");
    }
}
