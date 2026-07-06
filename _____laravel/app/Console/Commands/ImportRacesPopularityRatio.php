<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportRacesPopularityRatio extends Command
{
    protected $signature = 'keiba:ImportRacesPopularityRatio';
    protected $description = '';

    public function handle(): void
    {
        // ── 多重起動防止 ─────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_ImportRacesPopularityRatio.lock';
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
            $this->info('║     レース人気比率 インポート処理 ── 開始         ║');
            $this->info('╚══════════════════════════════════════════════════╝');
            $this->info('実行日時: ' . date('Y-m-d H:i:s'));
            $this->info('');

            // ── popularity_ratio が未設定のレース一覧を取得 ──
            $races = DB::table('t_horse_odds_finder_races')
                ->whereNull('popularity_ratio')
                ->get();

            $this->info('対象レース数: ' . count($races) . ' 件');
            $this->info('');

            // ── 比較用マスタを num_horses ごとにメモリ展開（ループ外で一度だけ）──
            $ratioMaster = [];
            DB::table('t_horse_odds_finder_races_popularity_ratio')
                ->whereNotNull('popularity_ratio')
                ->get(['id', 'num_horses', 'popularity_ratio'])
                ->each(function ($row) use (&$ratioMaster) {
                    $ratioMaster[$row->num_horses][] = $row;
                });

            $this->info('比較マスタ件数: ' . array_sum(array_map('count', $ratioMaster)) . ' 件');
            $this->info('');
            $this->info('UPDATE 中...');

            foreach ($races as $v) {

                // ── popularity_ratio を決定 ──
                // 既に値があればそれを使い、なければオッズから計算する
                if ($v->popularity_ratio !== null) {
                    $popularityRatio = $v->popularity_ratio;
                } else {
                    // 999(=24h前) から順にフォールバックして最初に取得できたタイミングを使う
                    // ODDS_GET_TIMING の 24 は DB 上 999 として保存されている
                    $fallbackTimings = array_map(fn($t) => $t === 24 ? 999 : $t, Constants::ODDS_GET_TIMING);
                    $odds = collect();
                    foreach ($fallbackTimings as $timing) {
                        $odds = DB::table('t_horse_odds_finder_odds')
                            ->where('date',                 $v->date)
                            ->where('kaisuu',               $v->kaisuu)
                            ->where('basho',                $v->basho)
                            ->where('day',                  $v->day)
                            ->where('race',                 $v->race)
                            ->where('minutes_before_start', $timing)
                            ->orderByRaw('odds + 0')
                            ->pluck('odds');
                        if ($odds->isNotEmpty()) break;
                    }

                    if ($odds->isEmpty()) {
                        $skippedCount++;
                        continue;
                    }

                    // 2つ目÷1つ目、3つ目÷2つ目 … を繰り返して | で連結
                    $ratios = [];
                    for ($i = 0; $i < count($odds) - 1; $i++) {
                        $prev = (float) $odds[$i];
                        $next = (float) $odds[$i + 1];
                        $ratios[] = $prev > 0 ? round($next / $prev, 1) : null;
                    }

                    $popularityRatio = implode('|', $ratios);
                }

                // ── 同頭数マスタとユークリッド類似度（RMSE）を比較 ──
                // match = max(0, 1 - RMSE) × 100 ── 完全一致で100%、差0.3/要素で70%
                $currentRatios   = array_map('floatval', explode('|', $popularityRatio));
                $n               = count($currentRatios);
                $matchedIds      = [];
                $matchedPercents = [];

                foreach ($ratioMaster[$v->num_horses] ?? [] as $ref) {
                    $refRatios = array_map('floatval', explode('|', $ref->popularity_ratio));
                    if (count($refRatios) !== $n) continue;

                    $sumSq = 0.0;
                    for ($i = 0; $i < $n; $i++) {
                        $sumSq += ($currentRatios[$i] - $refRatios[$i]) ** 2;
                    }
                    $rmse         = sqrt($sumSq / $n);
                    $matchPercent = round(max(0.0, 1.0 - $rmse) * 100, 1);

                    if ($matchPercent >= 70.0) {
                        $matchedIds[]      = $ref->id;
                        $matchedPercents[] = $matchPercent;
                    }
                }

                // match_percent 降順にソート
                if ($matchedIds) {
                    array_multisort($matchedPercents, SORT_DESC, $matchedIds);
                }

                DB::table('t_horse_odds_finder_races')
                    ->where('id', $v->id)
                    ->update([
                        'popularity_ratio'               => $popularityRatio,
                        'popularity_ratio_table_ids'     => $matchedIds      ? implode('|', $matchedIds)      : null,
                        'popularity_ratio_match_percent' => $matchedPercents ? implode('|', $matchedPercents) : null,
                    ]);

                $insertedCount++;


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
            $this->info('=== レース人気比率 インポート処理 ── ' . $status . ' ===');
            $this->info('');
            $this->info('========== keiba:ImportRacesPopularityRatio 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "ImportRacesPopularityRatio::handle\n{$status}\nINSERT:{$insertedCount}件、スキップ:{$skippedCount}件");
        }
    }
}
