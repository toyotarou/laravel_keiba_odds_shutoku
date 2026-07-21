<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SummaryAiAnalysisCheck
 *
 * 【概要】
 *   t_horse_odds_finder_ai_analysis の AI 予想と
 *   t_horse_odds_finder_race_result_history の実際の着順を突き合わせ、
 *   的中率（similarity）を t_horse_odds_finder_ai_analysis_check に INSERT する。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】STEP1: t_horse_odds_finder_ai_analysis を全件取得
 *   【ブロック 3】レコードなし → 早期終了
 *   【ブロック 4】STEP2: レースごとに比較して INSERT or UPDATE
 *       ① finishing_horse1 が入力済みのレコードはスキップ（確定済み）
 *       ② AI 予想テキストから PICKUP 馬を抽出
 *       ③ 実際の1〜3着馬を取得
 *       ④ similarity を計算して INSERT or UPDATE（finishing 未入力のみ）
 *   【ブロック 5】完了ログ・WebPush 通知
 *
 * 【使い方】
 *   php artisan keiba:ai-analysis-check
 */
class SummaryAiAnalysisCheck extends Command
{
    protected $signature   = 'keiba:ai-analysis-check';
    protected $description = 'AIの予想結果をチェックして t_horse_odds_finder_ai_analysis_check に INSERT する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_summaryAiAnalysisCheck.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $now = microtime(true);
        $this->info('');
        $this->info('========== keiba:ai-analysis-check 開始 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】STEP1: t_horse_odds_finder_ai_analysis を全件取得
        // ─────────────────────────────────────────────────────────────────
        $records = DB::table('t_horse_odds_finder_ai_analysis')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho_code')
            ->orderBy('day')
            ->orderBy('race')
            ->get();

        $this->info("  取得レコード数: {$records->count()} 件");
        $this->info('');

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】レコードなし → 早期終了
        // ─────────────────────────────────────────────────────────────────
        if ($records->isEmpty()) {
            $this->warn('処理対象のレコードがありません。処理を終了します。');
            $this->info('========== keiba:ai-analysis-check 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');
            (new WebPushService())->sendPushNotifierDeveloperNews(
                'develop',
                'SummaryAiAnalysisCheck::handle' . "\n" . '処理対象なし（空振り）'
            );
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 4】STEP2: レースごとに比較して INSERT or UPDATE
        //   finishing_horse1 が入力済みのレコードは確定済みとみなしてスキップ。
        //   未入力の場合は INSERT または UPDATE して結果を埋める。
        // ─────────────────────────────────────────────────────────────────
        $this->info('集計・挿入中...');
        $insertedCount = 0;

        foreach ($records as $v) {

            // ① finishing が埋まっているレコードはスキップ
            $alreadyFilled = DB::table('t_horse_odds_finder_ai_analysis_check')
                ->where('date',       $v->date)
                ->where('kaisuu',     $v->kaisuu)
                ->where('basho_code', $v->basho_code)
                ->where('day',        $v->day)
                ->where('race',       $v->race)
                ->whereNotNull('finishing_horse1')
                ->exists();

            if ($alreadyFilled) {
                continue;
            }

            // ② AI 予想テキストから PICKUP 馬を抽出
            $horses = [];
            if (preg_match('/^PICKUP:(.+)$/mu', $v->analysis_text, $m)) {
                $parts = explode('/', trim($m[1]));
                foreach ($parts as $idx => $part) {
                    $cols = explode('|', $part);
                    $num  = $idx + 1;
                    $horses["pickup_horse{$num}"] = isset($cols[1]) ? trim($cols[1]) : '';
                }
            }

            // ③ 実際の1〜3着馬を取得
            $result2 = DB::table('t_horse_odds_finder_race_result_history')
                ->where('date',       $v->date)
                ->where('kaisuu',     $v->kaisuu)
                ->where('basho_code', $v->basho_code)
                ->where('day',        $v->day)
                ->where('race',       $v->race)
                ->whereIn('finishing_position', [1, 2, 3])
                ->orderBy('finishing_position')
                ->get();

            $finishing = [];
            $fIdx = 1;
            foreach ($result2 as $r) {
                $finishing["finishing_horse{$fIdx}"] = $r->name;
                $fIdx++;
            }

            // ④ similarity を計算して INSERT or UPDATE
            $sim = $this->horseSimilarity(array_values($horses), array_values($finishing));

            DB::table('t_horse_odds_finder_ai_analysis_check')->updateOrInsert(
                [
                    'date'       => $v->date,
                    'kaisuu'     => $v->kaisuu,
                    'basho_code' => $v->basho_code,
                    'day'        => $v->day,
                    'race'       => $v->race,
                ],
                [
                    'basho'           => $v->basho,
                    'race_name'       => $v->race_name,
                    'pickup_horse1'   => $horses['pickup_horse1']        ?? null,
                    'pickup_horse2'   => $horses['pickup_horse2']        ?? null,
                    'pickup_horse3'   => $horses['pickup_horse3']        ?? null,
                    'finishing_horse1'=> $finishing['finishing_horse1']  ?? null,
                    'finishing_horse2'=> $finishing['finishing_horse2']  ?? null,
                    'finishing_horse3'=> $finishing['finishing_horse3']  ?? null,
                    'similarity'      => $sim['similarity'],
                ]
            );

            $insertedCount++;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 5】完了ログ・WebPush 通知
        // ─────────────────────────────────────────────────────────────────
        $elapsed = round(microtime(true) - $now, 1);
        $this->info('');
        $this->info('========== keiba:ai-analysis-check 終了 ' . date('Y-m-d H:i:s') . ' ==========');
        $this->info('挿入レコード数 : ' . $insertedCount . ' 件');
        $this->info('処理時間       : ' . $elapsed . ' 秒');
        $this->info('');

        (new WebPushService())->sendPushNotifierDeveloperNews(
            'develop',
            "SummaryAiAnalysisCheck::handle\n挿入:{$insertedCount}件、time:{$elapsed}"
        );
    }

    private function horseSimilarity(array $predicted, array $actual): array
    {
        $match = count(array_intersect($predicted, $actual));
        $total = max(count($predicted), count($actual));

        return [
            'similarity' => $total > 0 ? round($match / $total * 100, 2) : 0,
            'match'      => $match,
            'total'      => count($predicted),
        ];
    }
}
