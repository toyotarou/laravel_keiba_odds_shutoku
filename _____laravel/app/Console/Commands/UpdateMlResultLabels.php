<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\WebPushService;

/**
 * UpdateMlResultLabels
 *
 * 【概要】
 *   t_horse_odds_finder_ml_snapshot の正解ラベル（M1〜M4）を
 *   t_horse_odds_finder_race_result_history の着順・人気順データから算出し更新する。
 *   レース結果確定後に実行する（レース当日夜〜翌日バッチ想定）。
 *
 * 【断層パターン学習 M1〜M4 定義】
 *   M1: 上位完結 — 5着以内が全て1〜6番人気 → 1 / それ以外 → 0
 *   M2: 下位進入 — 5着以内に7〜10番人気が1頭以上 → 1 / それ以外 → 0
 *   M3: 大穴進入 — 5着以内に11番人気以下が1頭以上 → 1 / それ以外 → 0
 *   M4: 馬別5着以内 — 出走全頭の各馬番が5着以内なら1、それ以外0（取消・除外はnull）
 *       結果（全頭）: {"1": 0, "2": null, "3": 1, ...} のJSONオブジェクト
 *       ※全頭記録により「AIが選ばなかった馬の見逃し検証」が可能になる
 *
 * 【使い方】
 *   php artisan keiba:updateMlResultLabels                    # result_m1 IS NULLの全件処理
 *   php artisan keiba:updateMlResultLabels --date=2026-09-14  # 特定日のみ処理
 *   php artisan keiba:updateMlResultLabels --force            # 既算出分も再計算（上書き）
 *
 * 【取消・除外・競走中止の扱い】
 *   finishing_position が NULL のレコードが5着以内の頭数（通常5頭）分存在する場合、
 *   結果が確定していないとみなし result_label_status = 'excluded' として保存する。
 *   5頭以上の有効着順が確認できた場合のみ M1〜M4 を算出し 'filled' として保存する。
 */
class UpdateMlResultLabels extends Command
{
    protected $signature   = 'keiba:updateMlResultLabels
                                {--date=  : 処理対象レース日 (YYYY-MM-DD)。省略時は result_m1 IS NULL の全件}
                                {--force  : 既算出済み (filled) のレコードも再計算して上書きする}';

    protected $description = '断層パターン学習の正解ラベル(M1〜M4)をレース結果から算出しml_snapshotへ保存する';

    // 5着以内の閾値
    private const TOP_N = 5;

    public function handle(): void
    {
        $targetDate = $this->option('date') ?: null;
        $force      = $this->option('force');

        $this->info('');
        $this->info('========== keiba:updateMlResultLabels 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        if ($targetDate) {
            $this->info("  対象日: {$targetDate}");
        } else {
            $this->info('  対象: result_m1 IS NULL の全件' . ($force ? '（--force: 全件上書き）' : ''));
        }

        $filled   = 0;
        $excluded = 0;
        $skipped  = 0;

        try {
            // ── 1. 処理対象の ml_snapshot レコードを取得 ────────────────────
            $query = DB::table('t_horse_odds_finder_ml_snapshot')
                ->select(['id', 'date', 'kaisuu', 'basho_code', 'day', 'race', 'features']);

            if ($targetDate) {
                $query->where('date', $targetDate);
                if (!$force) {
                    // --date指定時もforceなければ未処理のみ
                    $query->whereNull('result_m1');
                }
            } else {
                if ($force) {
                    // --force: 全件（日付指定なし）
                    // 件数が多い場合があるので注意
                } else {
                    $query->whereNull('result_m1');
                }
            }

            $snapshots = $query->orderBy('date')->orderBy('kaisuu')->orderBy('day')->orderBy('race')->get();

            if ($snapshots->isEmpty()) {
                $this->info('処理対象のレコードがありません。終了します。');
                // 空振りでも通知を送る（ml_snapshotが未投入の日も把握できるように）
                (new WebPushService())->sendPushNotifierDeveloperNews(
                    'develop',
                    "UpdateMlResultLabels::handle" .
                    ($targetDate ? "対象日:{$targetDate} " : "対象:全件 ") .
                    "処理対象レコードなし（ml_snapshotが空または全件算出済み）"
                );
                return;
            }

            $this->info("  処理対象: {$snapshots->count()} 件");
            $this->info('');

            // ── 2. 各スナップショットごとにレース結果を取得してラベル算出 ──────
            foreach ($snapshots as $snap) {
                $date      = $snap->date;
                $kaisuu    = $snap->kaisuu;
                $bashoCode = $snap->basho_code;
                $day       = $snap->day;
                $race      = $snap->race;

                // features_json から merged_nums を取得（M4算出用）
                $features   = json_decode($snap->features ?? '{}', true);
                $mergedNums = $features['merged_nums'] ?? [];

                // レース結果を取得（有効着順のみ: NULL/0/−1は除外。DBの値: NULL/0=未取得, -1=取消・除外・失格, 1〜=着順確定）
                $results = DB::table('t_horse_odds_finder_race_result_history')
                    ->where('date',       $date)
                    ->where('kaisuu',     $kaisuu)
                    ->where('basho_code', $bashoCode)
                    ->where('day',        $day)
                    ->where('race',       $race)
                    ->whereBetween('finishing_position', [1, 99]) // 有効着順のみ（-1=取消/除外/失格, 0=未取得, NULL は除外）
                    ->get(['num', 'popularity_rank', 'finishing_position']);

                if ($results->isEmpty()) {
                    // レース結果が未確定（まだ取り込まれていない）
                    $this->line("  SKIP [{$date} kaisuu={$kaisuu} {$bashoCode} day={$day} race={$race}] 結果未取込");
                    $skipped++;
                    continue;
                }

                // 5着以内の有効着順が揃っているか確認
                $top5 = $results->filter(fn($r) => (int)$r->finishing_position <= self::TOP_N)
                                 ->sortBy('finishing_position')
                                 ->values();

                // 頭数が少ないレース（出走頭数が5頭未満）の場合は出走頭数分で判定
                $totalHorses = $results->count();
                $requiredTop = min(self::TOP_N, $totalHorses);

                if ($top5->count() < $requiredTop) {
                    // 5着以内が揃っていない → 結果未確定 or 取消馬が多い
                    DB::table('t_horse_odds_finder_ml_snapshot')
                        ->where('id', $snap->id)
                        ->update([
                            'result_label_status' => 'excluded',
                            // M1〜M4はNULLのまま
                        ]);
                    $this->line("  EXCLUDED [{$date} kaisuu={$kaisuu} {$bashoCode} day={$day} race={$race}] top5不足: {$top5->count()}/{$requiredTop}");
                    $excluded++;
                    continue;
                }

                // ── M1: 上位完結（5着以内が全て1〜6番人気） ──────────────────
                $m1 = 1;
                foreach ($top5 as $h) {
                    if ((int)$h->popularity_rank > 6) {
                        $m1 = 0;
                        break;
                    }
                }

                // ── M2: 下位進入（5着以内に7〜10番人気が1頭以上） ────────────
                $m2 = 0;
                foreach ($top5 as $h) {
                    $pop = (int)$h->popularity_rank;
                    if ($pop >= 7 && $pop <= 10) {
                        $m2 = 1;
                        break;
                    }
                }

                // ── M3: 大穴進入（5着以内に11番人気以下が1頭以上） ────────────
                $m3 = 0;
                foreach ($top5 as $h) {
                    if ((int)$h->popularity_rank >= 11) {
                        $m3 = 1;
                        break;
                    }
                }

                // ── M4: 馬別5着以内ラベル（全頭版・馬番 → 0/1/null のJSONオブジェクト） ──
                // 出走全頭を記録することで「AIが選ばなかった馬の見逃し検証」が可能
                // 取消・除外・競走中止（finishing_positionがNULL or 99超）→ null
                // ※ finishing_positionがNULLの全馬も別途取得して全頭マップを作成する
                $allResults = DB::table('t_horse_odds_finder_race_result_history')
                    ->where('date',       $date)
                    ->where('kaisuu',     $kaisuu)
                    ->where('basho_code', $bashoCode)
                    ->where('day',        $day)
                    ->where('race',       $race)
                    ->get(['num', 'finishing_position']);

                $m4Data = [];
                foreach ($allResults as $allRow) {
                    $allNum = (string)(int)$allRow->num;
                    $fp     = $allRow->finishing_position;
                    if ($fp === null || (int)$fp <= 0) {
                        // 取消・除外・競走中止・未取得 → null（学習対象外）
                        // ※ DBの値: NULL/0=未取得, -1=取消・除外・失格, 1〜=着順確定
                        // ※ 旧判定 ($fp > 99) では -1 が 0（5着以外）扱いになるバグがあったため修正
                        $m4Data[$allNum] = null;
                    } else {
                        $m4Data[$allNum] = ((int)$fp <= self::TOP_N) ? 1 : 0;
                    }
                }
                // 馬番順でソート（可読性・一貫性のため）
                ksort($m4Data, SORT_NUMERIC);
                $m4Json = json_encode($m4Data, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);

                // ── DB更新 ────────────────────────────────────────────────────
                DB::table('t_horse_odds_finder_ml_snapshot')
                    ->where('id', $snap->id)
                    ->update([
                        'result_m1'           => $m1,
                        'result_m2'           => $m2,
                        'result_m3'           => $m3,
                        'result_m4_json'      => $m4Json,
                        'result_label_status' => 'filled',
                    ]);

                $this->line("  FILLED [{$date} kaisuu={$kaisuu} {$bashoCode} day={$day} race={$race}]"
                    . " M1={$m1} M2={$m2} M3={$m3} total_horses=" . count($allResults) . " merged_nums=" . count($mergedNums));
                $filled++;
            }

        } catch (\Throwable $e) {
            $this->error('予期しないエラー: ' . $e->getMessage());
            \Log::error('[UpdateMlResultLabels] unexpected error', [
                'err'   => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // ── 3. 完了サマリー ─────────────────────────────────────────────────
        $this->info('');
        $this->info('【完了サマリー】');
        $this->info("  filled   (算出済): {$filled} 件");
        $this->info("  excluded (結果不足): {$excluded} 件");
        $this->info("  skipped  (未取込): {$skipped} 件");
        $this->info('');
        \Log::info('[UpdateMlResultLabels] 完了', [
            'date'     => $targetDate ?? 'all',
            'force'    => $force,
            'filled'   => $filled,
            'excluded' => $excluded,
            'skipped'  => $skipped,
        ]);

        // ── 4. 開発者向けプッシュ通知 ────────────────────────────────────────
        $newsValue = [];
        $newsValue[] = ($targetDate ? "対象日:{$targetDate} " : "対象:全件 ");
        $newsValue[] = "filled:{$filled}件、";
        $newsValue[] = "excluded:{$excluded}件、";
        $newsValue[] = "skipped:{$skipped}件";
        $news = implode("", $newsValue);
        (new WebPushService())->sendPushNotifierDeveloperNews('develop', "UpdateMlResultLabels::handle\n{$news}");

        $this->info('========== keiba:updateMlResultLabels 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');
    }
}



/*

# 毎日23:20 断層パターン学習の正解ラベル(M1〜M4)をレース結果から算出しml_snapshotへ保存する
20 23 * * * flock -n /tmp/keiba_updateMlResultLabels.lock php /var/www/horse_odds_finder/artisan keiba:updateMlResultLabels >> /var/www/horse_odds_finder/storage/logs/updateMlResultLabels.log 2>&1

*/

