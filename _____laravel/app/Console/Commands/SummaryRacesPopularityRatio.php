<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SummaryRacesPopularityRatio extends Command
{
    protected $signature = 'keiba:summaryRacesPopularityRatio';
    protected $description = '';

    public function handle(): void
    {
        // ── 多重起動防止 ─────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_summaryRacesPopularityRatio.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $insertedCount = 0;
        $skippedCount  = 0;
        $status        = '不明な理由で終了';

        try {

            $this->info('');
            $this->info('╔══════════════════════════════════════════════════╗');
            $this->info('║     レース人気比率 集計処理 ── 開始               ║');
            $this->info('╚══════════════════════════════════════════════════╝');
            $this->info('実行日時: ' . date('Y-m-d H:i:s'));
            $this->info('');

            // レース単位でグループ化した基本情報を取得
            $races = DB::table('t_horse_odds_finder_race_result_history')
                ->select(
                    'date',
                    'kaisuu',
                    'basho',
                    'basho_code',
                    'day',
                    'race',
                    DB::raw('MAX(race_name) as race_name'),
                    DB::raw('COUNT(*) as num_horses')
                )
                ->groupBy('date', 'kaisuu', 'basho', 'basho_code', 'day', 'race')
                ->orderBy('date')
                ->orderBy('kaisuu')
                ->orderBy('basho_code')
                ->orderBy('day')
                ->orderBy('race')
                ->get();

            $this->info('対象レース数: ' . count($races) . ' 件');
            $this->info('');
            $this->info('INSERT / UPDATE 中...');

            foreach ($races as $race) {

                // 同レースの馬を人気順に取得してオッズ比率を計算
                $horses = DB::table('t_horse_odds_finder_race_result_history')
                    ->where('date',       $race->date)
                    ->where('kaisuu',     $race->kaisuu)
                    ->where('basho_code', $race->basho_code)
                    ->where('day',        $race->day)
                    ->where('race',       $race->race)
                    ->orderBy('popularity_rank')
                    ->pluck('tan');

                $ratios = [];
                for ($i = 0; $i < count($horses) - 1; $i++) {
                    $prev = (float) $horses[$i];
                    $next = (float) $horses[$i + 1];
                    $ratios[] = $prev > 0 ? round($next / $prev, 1) : null;
                }

                $popularityRatio = implode('|', $ratios);

                $exists = DB::table('t_horse_odds_finder_races_popularity_ratio')
                    ->where('date',   $race->date)
                    ->where('kaisuu', $race->kaisuu)
                    ->where('basho',  $race->basho_code)
                    ->where('day',    $race->day)
                    ->where('race',   $race->race)
                    ->exists();

                if ($exists) {
                    $skippedCount++;
                } else {
                    DB::table('t_horse_odds_finder_races_popularity_ratio')->insert([
                        'date'             => $race->date,
                        'kaisuu'           => $race->kaisuu,
                        'basho'            => $race->basho_code,
                        'basho_name'       => $race->basho,
                        'day'              => $race->day,
                        'race'             => $race->race,
                        'race_name'        => $race->race_name,
                        'num_horses'       => $race->num_horses,
                        'popularity_ratio' => $popularityRatio,
                    ]);
                    $insertedCount++;

                    if ($insertedCount % 100 === 0) {
                        $this->line("  {$insertedCount} 件INSERT済み...");
                    }
                }
            }

            $this->info("INSERT 完了 ── INSERT: {$insertedCount} 件、スキップ: {$skippedCount} 件。");
            $status = '正常終了';

        } finally {

            $this->info('');
            $this->info('╔══════════════════════════════════════════════════╗');
            $this->info('║     処理結果サマリー                              ║');
            $this->info('╚══════════════════════════════════════════════════╝');
            $this->info('終了理由      : ' . $status);
            $this->info('INSERT件数    : ' . $insertedCount . ' 件');
            $this->info('スキップ件数  : ' . $skippedCount  . ' 件');
            $this->info('完了日時      : ' . date('Y-m-d H:i:s'));
            $this->info('=== レース人気比率 集計処理 ── ' . $status . ' ===');
            $this->info('');
            $this->info('========== keiba:summaryRacesPopularityRatio 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryRacesPopularityRatio::handle\n{$status}\nINSERT:{$insertedCount}件、スキップ:{$skippedCount}件");
        }
    }
}
