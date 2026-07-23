<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportKeibaJraRaceResult
 *
 * 【概要】
 *   JRA公式サイトから直近の全レース結果（着順）を取得し、
 *   t_horse_odds_finder_summary テーブルの result カラムを更新する。
 *   summary テーブルは「basho コード」で管理しているが、
 *   keibaOddsGetJraRaceResult.mjs の出力は「東京」等の漢字名なので
 *   BASHO_MAP で変換してから UPDATE キーに使う。
 *
 * 【処理フロー】（コード上の出現順）
 *   【ブロック 3】basho名→コード変換マップ（クラス定数・handle()より先に定義）
 *   【ブロック 1】初期化・開始バナー
 *   【ブロック 2】Node.js 実行・JSON パース・件数確認
 *   【ブロック 4】トランザクション内で result を一行ずつ UPDATE
 *   【ブロック 5】完了サマリー・WebPush 通知（finally で必ず実行）
 *   【ブロック 6】fetchJraRaceResult(): Node.js 実行ヘルパー
 *
 * 【使い方】
 *   php artisan keiba:importJraRaceResult
 */
class ImportKeibaJraRaceResult extends Command
{
    protected $signature = 'keiba:importJraRaceResult';
    protected $description = 'JRAからレース結果（着順）を取得し、summaryテーブルのresultを更新する';

    // ─────────────────────────────────────────────────────────────────
    // 【ブロック 3】basho名 → bashoコード変換マップ（クラス定数）
    //   Node.js スクリプトの出力は「東京」「中山」等の漢字名。
    //   summary テーブルの basho カラムは「05」「06」等の2桁コード。
    //   この変換がないと UPDATE の WHERE 条件がヒットせずスキップになる。
    // ─────────────────────────────────────────────────────────────────
    private const BASHO_MAP = [
        '札幌' => '01',
        '函館' => '02',
        '福島' => '03',
        '新潟' => '04',
        '東京' => '05',
        '中山' => '06',
        '中京' => '07',
        '京都' => '08',
        '阪神' => '09',
        '小倉' => '10',
    ];

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】初期化・開始バナー
        //   $status は各 return 経路で上書きされ、finally ブロックでログ出力・通知に使う。
        // ─────────────────────────────────────────────────────────────────
        $updated             = 0;
        $skipped             = 0;
        $insertedResults     = 0;
        $insertedResultsByDate = [];
        $status              = '不明な理由で終了';

        try {
            $this->info('');
            $this->info('========== keiba:importJraRaceResult 開始 ' . date('Y-m-d H:i:s') . ' ==========');

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 2】Node.js 実行・JSON パース・件数確認
            //   fetchJraRaceResult() で Node.js を呼び出し、JRAサイトから
            //   当日の全レース結果を JSON 文字列として受け取る。
            //   results が空（0件）の場合はレース未終了とみなして早期終了する。
            // ─────────────────────────────────────────────────────────────
            $start = microtime(true);
            $json  = $this->fetchJraRaceResult();
            $fetchMs = round((microtime(true) - $start) * 1000);

            if (!$json) {
                $this->error('Node.js スクリプトの実行失敗（出力なし）');
                $status = 'Node.js実行失敗（出力なし）';
                return;
            }

            $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE || !isset($data['results'])) {
                $this->error('JSONパース失敗: ' . json_last_error_msg());
                $status = 'JSONパース失敗';
                return;
            }

            $results = $data['results'];
            $this->info("Node.js 取得完了 → {$fetchMs}ms / " . count($results) . " 件");

            if (empty($results)) {
                $this->warn('取得件数が0件です（レース未終了の可能性）。終了します。');
                $status = '取得0件（レース未終了の可能性）';
                return;
            }

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】トランザクション内で result を一行ずつ UPDATE
            //   照合キー: kaisuu + basho(コード) + day + race + num
            //   whereNull('result') で既に着順が入っている行は UPDATE しない。
            //   $affected = 0 の場合は照合キー不一致、または result 既入力によるスキップ。
            //   （basho名変換失敗は手前の continue で処理済みのためここには到達しない）
            // ─────────────────────────────────────────────────────────────
            // race_results の INSERT 重複チェック用に既存キーを先読み
            $existingResultKeys = DB::table('t_horse_odds_finder_race_results')
                ->get(['kaisuu', 'basho', 'day', 'race', 'num'])
                ->keyBy(fn($r) => "{$r->kaisuu}-{$r->basho}-{$r->day}-{$r->race}-{$r->num}")
                ->keys()
                ->flip()
                ->all();

            DB::transaction(function () use ($results, &$updated, &$skipped, &$existingResultKeys, &$insertedResults, &$insertedResultsByDate) {
                foreach ($results as $row) {
                    // basho 漢字名 → 2桁コードへ変換（未対応の場合はスキップ）
                    $bashoCode = self::BASHO_MAP[$row['basho']] ?? null;
                    if ($bashoCode === null) {
                        $this->warn("  未対応の開催場所: {$row['basho']} → スキップ");
                        $skipped++;
                        continue;
                    }

                    // result が NULL の行のみ UPDATE する（確定済みを上書きしない）
                    $affected = DB::table('t_horse_odds_finder_summary')
                        ->where('kaisuu', (string) $row['kaisuu'])
                        ->where('basho',  $bashoCode)
                        ->where('day',    (string) $row['day'])
                        ->where('race',   $row['race'])
                        ->where('num',    $row['horse_num'])
                        ->whereNull('result')
                        ->update(['result' => $row['rank']]);

                    if ($affected > 0) {
                        $updated++;
                    } else {
                        $skipped++;
                    }

                    // t_horse_odds_finder_race_results への INSERT（未登録のみ）
                    $resultKey = "{$row['kaisuu']}-{$bashoCode}-{$row['day']}-{$row['race']}-{$row['horse_num']}";
                    if (isset($existingResultKeys[$resultKey])) {
                        continue;
                    }

                    // date と race_name を t_horse_odds_finder_races から取得
                    $raceRow = DB::table('t_horse_odds_finder_races')
                        ->where('kaisuu', $row['kaisuu'])
                        ->where('basho',  $bashoCode)
                        ->where('day',    $row['day'])
                        ->where('race',   $row['race'])
                        ->first(['date', 'race_name']);

                    if (!$raceRow) {
                        continue;
                    }

                    DB::table('t_horse_odds_finder_race_results')->insert([
                        'date'        => $raceRow->date,
                        'kaisuu'      => $row['kaisuu'],
                        'basho'       => $bashoCode,
                        'basho_name'  => $row['basho'],
                        'day'         => $row['day'],
                        'race'        => $row['race'],
                        'race_name'   => $raceRow->race_name,
                        'num'         => $row['horse_num'],
                        'horse_name'  => $row['horse_name'],
                        'result'      => $row['rank'],
                        'notified_at' => null,
                    ]);

                    $existingResultKeys[$resultKey] = true;
                    $insertedResults++;
                    $insertedResultsByDate[$raceRow->date] = ($insertedResultsByDate[$raceRow->date] ?? 0) + 1;
                }
            });

            $this->info('');
            $this->info('【summary UPDATE】');
            $this->info("  更新: {$updated} 件 / スキップ: {$skipped} 件");
            $this->info('');
            $this->info('【race_results INSERT】');
            if ($insertedResults === 0) {
                $this->info('  0 件（全て登録済み）');
            } else {
                ksort($insertedResultsByDate);
                foreach ($insertedResultsByDate as $date => $count) {
                    $this->info("  {$date}: {$count} 件");
                }
                $this->info("  ─────────────────");
                $this->info("  合計: {$insertedResults} 件");
            }
            $this->info('');
            $status = '正常終了';

        } finally {
            // ─────────────────────────────────────────────────────────────
            // 【ブロック 5】完了サマリー・WebPush 通知（finally で必ず実行）
            //   どの経路で return しても必ず終了バナーと通知が送信される。
            // ─────────────────────────────────────────────────────────────
            $this->info("終了理由: {$status}");
            $this->info('========== keiba:importJraRaceResult 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "ImportKeibaJraRaceResult::handle\n{$status}\n更新:{$updated}、飛:{$skipped}、results挿入:{$insertedResults}");
        }
    }

    /**
     * 【ブロック 6】fetchJraRaceResult: Node.js 実行ヘルパー
     *
     * keibaOddsGetJraRaceResult.mjs を実行し、JRAサイトの結果 JSON 文字列を返す。
     * timeout 300: 6開催分のページ遷移があるため余裕を持たせる。
     * 出力 JSON のパース検証を行い、不正な場合は null を返す（呼び出し元が判定）。
     */
    private function fetchJraRaceResult(): ?string
    {
        $nodeBin    = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';
        $scriptPath = base_path('scripts/keibaOddsGetJraRaceResult.mjs');

        if (!file_exists($scriptPath)) {
            $this->error("スクリプトが見つかりません: {$scriptPath}");
            return null;
        }

        // timeout 300: 6開催×ページ遷移があるため余裕を持たせる
        $command = 'timeout 300 ' . $nodeBin . ' ' . escapeshellarg($scriptPath) . ' 2>/dev/null';
        $output  = shell_exec($command);

        if (!$output) {
            return null;
        }

        json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return trim($output);
        }

        return null;
    }
}
