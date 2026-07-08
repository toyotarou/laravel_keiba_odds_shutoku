<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportRacesPopularityRatio
 *
 * 【概要】
 *   t_horse_odds_finder_races の popularity_ratio が NULL のレースに対して、
 *   オッズから人気比率（連続する人気順オッズの比）を計算し、
 *   マスタテーブルとの RMSE 類似度を算出して各カラムに保存する。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】開始バナー
 *   【ブロック 3】popularity_ratio が NULL のレースを取得
 *   【ブロック 4】比較マスタを num_horses 別にメモリ展開（ループ外で一度だけ）
 *   【ブロック 5】レースごとのループ: popularity_ratio の決定
 *   【ブロック 6】フォールバック: ODDS_GET_TIMING から最初に存在するオッズを取得
 *   【ブロック 7】連続比率の計算（2÷1, 3÷2, ... を '|' で連結）
 *   【ブロック 8】RMSE 類似度の計算とマッチングID収集
 *   【ブロック 9】races テーブルを UPDATE
 *   【ブロック 10】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【popularity_ratio の形式】
 *   単勝オッズを人気順に並べ、連続する要素の比を '|' で連結した文字列。
 *   例: オッズ=[1.5, 2.3, 3.0] → 2.3/1.5=1.5, 3.0/2.3=1.3 → "1.5|1.3"
 *
 * 【RMSE 類似度の計算】
 *   matchPercent = max(0, 1 - RMSE) × 100
 *   RMSE = sqrt(Σ(a-b)²/n)
 *   matchPercent >= 70.0% のマスタを $matchedIds に収集し match_percent 降順で保存。
 *
 * 【使い方】
 *   php artisan keiba:ImportRacesPopularityRatio
 */
class ImportRacesPopularityRatio extends Command
{
    protected $signature = 'keiba:ImportRacesPopularityRatio';
    protected $description = '';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        // ─────────────────────────────────────────────────────────────────
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

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 2】開始バナー
            // ─────────────────────────────────────────────────────────────
            $this->info('');
            $this->info('╔══════════════════════════════════════════════════╗');
            $this->info('║     レース人気比率 インポート処理 ── 開始         ║');
            $this->info('╚══════════════════════════════════════════════════╝');
            $this->info('実行日時: ' . date('Y-m-d H:i:s'));
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 3】popularity_ratio が NULL のレースを取得
            //   既に計算済みの行（popularity_ratio が NOT NULL）はスキップされる。
            // ─────────────────────────────────────────────────────────────
            $races = DB::table('t_horse_odds_finder_races')
                ->whereNull('popularity_ratio')
                ->get();

            $this->info('対象レース数: ' . count($races) . ' 件');
            $this->info('');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】比較マスタを num_horses 別にメモリ展開（ループ外で一度だけ）
            //   $ratioMaster[$num_horses] = [ref1, ref2, ...] の形で保持する。
            //   ループごとに DB 問い合わせするとマスタ件数 × 対象レース数で爆発するため
            //   ループ前に全件を PHP のメモリ上に展開しておく。
            // ─────────────────────────────────────────────────────────────
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

                // ─────────────────────────────────────────────────────────
                // 【ブロック 5】レースごとのループ: popularity_ratio の決定
                //   既に値があれば（races テーブルに値が入った状態で取得した場合）そのまま使う。
                //   なければ【ブロック 6】でオッズから計算する。
                // ─────────────────────────────────────────────────────────
                if ($v->popularity_ratio !== null) {
                    $popularityRatio = $v->popularity_ratio;
                } else {
                    // ─────────────────────────────────────────────────────
                    // 【ブロック 6】フォールバック: ODDS_GET_TIMING から最初に存在するオッズを取得
                    //   999(=24h前) から順にフォールバックして最初に取得できたタイミングを使う。
                    //   ODDS_GET_TIMING の 24 は DB 上 999 として保存されているため変換する。
                    //   オッズは単勝昇順（人気順）で取得する（orderByRaw('odds + 0') で数値昇順）。
                    // ─────────────────────────────────────────────────────
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

                    // ─────────────────────────────────────────────────────
                    // 【ブロック 7】連続比率の計算（2÷1, 3÷2, ... を '|' で連結）
                    //   $odds = [1.5, 2.3, 3.0] → ratios = [1.5, 1.3]
                    //   → popularityRatio = "1.5|1.3"
                    // ─────────────────────────────────────────────────────
                    $ratios = [];
                    for ($i = 0; $i < count($odds) - 1; $i++) {
                        $prev = (float) $odds[$i];
                        $next = (float) $odds[$i + 1];
                        $ratios[] = $prev > 0 ? round($next / $prev, 1) : null;
                    }

                    $popularityRatio = implode('|', $ratios);
                }

                // ─────────────────────────────────────────────────────────
                // 【ブロック 8】RMSE 類似度の計算とマッチングID収集
                //   matchPercent = max(0, 1 - RMSE) × 100
                //   完全一致で 100%、平均差 0.3 で約 70%（閾値）。
                //   同じ num_horses のマスタのみを比較対象にする（$ratioMaster[$v->num_horses]）。
                //   要素数が一致しないマスタはスキップ（異なる頭数のレースと比較しない）。
                // ─────────────────────────────────────────────────────────
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

                if ($matchedIds) {
                    array_multisort($matchedPercents, SORT_DESC, $matchedIds);
                }

                // ─────────────────────────────────────────────────────────
                // 【ブロック 9】races テーブルを UPDATE
                //   popularity_ratio: 計算した比率文字列
                //   popularity_ratio_table_ids: マッチしたマスタIDを '|' で連結
                //   popularity_ratio_match_percent: 各マスタの類似度を '|' で連結
                // ─────────────────────────────────────────────────────────
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

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 10】完了サマリー・WebPush 通知（finally で必ず実行）
            // ─────────────────────────────────────────────────────────────
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
