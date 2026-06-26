<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * JRA公式サイトからレース結果（着順）を取得し、
 * t_horse_odds_finder_summary の result カラムを更新する。
 *
 * Usage:
 *   php artisan keiba:importJraRaceResult
 */
class ImportKeibaJraRaceResult extends Command
{
    protected $signature = 'keiba:importJraRaceResult';
    protected $description = 'JRAからレース結果（着順）を取得し、summaryテーブルのresultを更新する';

    // basho名 → bashoコード変換マップ
    // mjs出力は「東京」などの文字列、summaryテーブルは「05」などのコード
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
        $updated = 0;
        $skipped = 0;

        try {
            $this->info('');
            $this->info('========== keiba:importJraRaceResult 開始 ' . date('Y-m-d H:i:s') . ' ==========');

            // Node.js スクリプトを実行してJRAから結果取得
            $start = microtime(true);
            $json  = $this->fetchJraRaceResult();
            $fetchMs = round((microtime(true) - $start) * 1000);

            if (!$json) {
                $this->error('Node.js スクリプトの実行失敗（出力なし）');
                return;
            }

            $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE || !isset($data['results'])) {
                $this->error('JSONパース失敗: ' . json_last_error_msg());
                return;
            }

            $results = $data['results'];
            $this->info("Node.js 取得完了 → {$fetchMs}ms / " . count($results) . " 件");

            if (empty($results)) {
                $this->warn('取得件数が0件です（レース未終了の可能性）。終了します。');
                return;
            }

            // result ごとにupdateを実行
            DB::transaction(function () use ($results, &$updated, &$skipped) {
                foreach ($results as $row) {
                    // basho名をコードに変換
                    $bashoCode = self::BASHO_MAP[$row['basho']] ?? null;
                    if ($bashoCode === null) {
                        $this->warn("  未対応の開催場所: {$row['basho']} → スキップ");
                        $skipped++;
                        continue;
                    }

                    // summaryテーブルのresultを更新
                    // 照合キー: date, kaisuu, basho, day, race, num
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
                }
            });

            $this->info("UPDATE完了 → 更新: {$updated} 件 / スキップ: {$skipped} 件");
            $this->info('========== keiba:importJraRaceResult 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info('');

        } finally {
            (new WebPushService())->sendPushNotifierDeveloperNews('develop', "ImportKeibaJraRaceResult::handle\n更新:{$updated}、飛:{$skipped}");
        }
        
    }

    /**
     * Node.js スクリプトを実行してJRAのレース結果JSONを取得する
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
