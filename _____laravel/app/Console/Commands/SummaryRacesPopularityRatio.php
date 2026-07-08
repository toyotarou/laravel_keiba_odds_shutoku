<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SummaryRacesPopularityRatio
 *
 * 【概要】
 *   t_horse_odds_finder_race_result_history のデータをもとに、
 *   レースごとの「連続する人気順オッズの比率」（popularity_ratio）を計算して
 *   t_horse_odds_finder_races_popularity_ratio テーブルに INSERT する。
 *   これは ImportRacesPopularityRatio のマスタデータとして機能する。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】開始バナー
 *   【ブロック 3】race_result_history をレース単位でグループ化して取得
 *   【ブロック 4】レースごとのループ
 *                  → 集計済みスキップ（EXISTS チェック）
 *                  → 人気順に tan を取得
 *                  → 連続比率を計算して INSERT
 *   【ブロック 5】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【popularity_ratio の形式】
 *   tan（単勝オッズ）を popularity_rank 昇順に並べ、
 *   隣り合う要素の比（次÷前）を小数第1位で四捨五入して '|' で連結した文字列。
 *   例: tan=[1.5, 2.3, 3.0] → 2.3/1.5=1.5, 3.0/2.3=1.3 → "1.5|1.3"
 *
 * 【ImportRacesPopularityRatio との関係】
 *   このコマンドが INSERT したレコードが RMSE 類似度計算のマスタになる。
 *   ImportRacesPopularityRatio は races テーブル側の popularity_ratio を
 *   このマスタと比較して match_percent を算出・保存する。
 *
 * 【使い方】
 *   php artisan keiba:summaryRacesPopularityRatio
 */
class SummaryRacesPopularityRatio extends Command
{
    protected $signature = 'keiba:summaryRacesPopularityRatio';
    protected $description = '';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        // ─────────────────────────────────────────────────────────────────
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

            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 2】開始バナー
            // ─────────────────────────────────────────────────────────────────
            $this->info('');
            $this->info('╔══════════════════════════════════════════════════╗');
            $this->info('║     レース人気比率 集計処理 ── 開始               ║');
            $this->info('╚══════════════════════════════════════════════════╝');
            $this->info('実行日時: ' . date('Y-m-d H:i:s'));
            $this->info('');

            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 3】race_result_history をレース単位でグループ化して取得
            //   GROUP BY でレースキーを集約し、MAX(race_name) と COUNT(*) を取得する。
            //   num_horses は同レースの行数（= 出走頭数）として使われる。
            // ─────────────────────────────────────────────────────────────────
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

            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 4】レースごとのループ
            //   ① EXISTS チェック: 集計済みのレースはスキップ。
            //   ② 同レースの馬を popularity_rank 昇順（人気順）に取得して tan 配列を得る。
            //   ③ 隣り合う tan の比（次÷前）を '|' 区切りで連結して popularity_ratio を生成。
            //   ④ INSERT（マスタ用テーブルなので更新はしない）。
            //   ⑤ 100件ごとに進捗をログ出力する。
            // ─────────────────────────────────────────────────────────────────
            foreach ($races as $race) {

                $exists = DB::table('t_horse_odds_finder_races_popularity_ratio')
                    ->where('date',   $race->date)
                    ->where('kaisuu', $race->kaisuu)
                    ->where('basho',  $race->basho_code)
                    ->where('day',    $race->day)
                    ->where('race',   $race->race)
                    ->exists();

                if ($exists) {
                    $skippedCount++;
                    continue;
                }

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

            $this->info("INSERT 完了 ── INSERT: {$insertedCount} 件、スキップ: {$skippedCount} 件。");
            $status = '正常終了';

        } finally {

            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 5】完了サマリー・WebPush 通知（finally で必ず実行）
            // ─────────────────────────────────────────────────────────────────
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
