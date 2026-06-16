<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use App\Services\LineService;

class ImportKeibaRace extends Command
{
    protected $signature = 'keiba:importRace';
    protected $description = 'ネットケイバからレースのリストを取得する';

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

    public function handle()
    {
        // ── 多重起動防止 ─────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importRace.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        // スケジュールに登録されている日付一覧を取得
        $sql   = "SELECT date FROM t_horse_odds_finder_schedules GROUP BY date";
        $dates = DB::select($sql);

        if (empty($dates)) {
            $this->warn('スケジュールに日付が登録されていません');
            return;
        }

        // スクリプトを all モードで1回起動して全日付まとめて取得
        $this->info('レース一覧を取得中...');
        $json = $this->fetchRaceListAll();

        if (!$json) {
            $this->error('スクリプトからの出力取得に失敗しました');
            return;
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('JSONパース失敗: ' . json_last_error_msg());
            $this->warn('JSON内容: ' . substr($json, 0, 300));
            return;
        }

        // スケジュール登録済みの日付分だけ処理
        foreach ($dates as $row) {
            $date    = $row->date;
            $dateKey = str_replace('-', '', $date);

            if (!isset($data[$dateKey])) {
                $this->warn("  → データなし: {$date}");
                continue;
            }

            $this->info("処理中: {$date}");
            $places      = $data[$dateKey];
            $insertCount = 0;

            foreach ($places as $place) {
                $bashoName = $place['place'];
                $basho     = self::BASHO_MAP[$bashoName] ?? null;

                if (!$basho) {
                    $this->warn("  → 未対応の開催場所: {$bashoName}");
                    continue;
                }

                foreach ($place['races'] as $race) {
                    DB::table('t_horse_odds_finder_netkeiba_races')->updateOrInsert(
                        [
                            'race_id' => $race['race_id'],
                        ],
                        [
                            'date'       => $date,
                            'kaisuu'     => $place['kai'],
                            'basho'      => $basho,
                            'basho_name' => $bashoName,
                            'day'        => $place['nichime'],
                            'race_id'    => $race['race_id'],
                            'race'       => $race['race_num'],
                            'race_name'  => $race['race_name'],
                            'start_time' => $race['start_time'],
                            'num_horses' => $race['horse_count'],
                        ]
                    );
                    $insertCount++;
                }
            }

            $this->info("  → {$insertCount} 件 upsert 完了");
        }

        $this->info('全日程の取り込み完了');
        


        try {
            app(LineService::class)->send('ImportKeibaRace::handle');
        } catch (\Exception $e) {
            \Log::warning('LINE送信失敗: ' . $e->getMessage());
        }
        


    }

    private function fetchRaceListAll(): ?string
    {
        $nodeBin    = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';
        $scriptPath = base_path('scripts/keibaOddsGetRaceList.mjs');
        // timeout 120: Node.js が無応答でもPHPプロセスが永久ブロックしないようにする
        $command    = 'timeout 120 ' . $nodeBin . ' ' . escapeshellarg($scriptPath) . ' all 2>/dev/null';
        $output     = shell_exec($command);

        if (!$output || !str_contains($output, '=== RESULT JSON ===')) {
            return null;
        }

        $parts = explode('=== RESULT JSON ===', $output);
        return trim($parts[1] ?? '');
    }
}
