<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportKeibaRace
 *
 * 【概要】
 *   ★DEPRECATED★
 *   Node.js スクリプト（keibaOddsGetRaceList.mjs）経由でレース一覧を取得して
 *   t_horse_odds_finder_netkeiba_races に保存するコマンド。
 *   現在は DB INSERT 部分が全てコメントアウトされており実質的に無効化されている。
 *   スクレイピング先の廃止に伴い、このコマンドは使用しない。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】スケジュール登録済みの日付一覧を取得
 *   【ブロック 3】Node.js スクリプトを all モードで実行して全日付のレース一覧を取得
 *   【ブロック 4】日付・開催場所ごとにループして upsert（現在 INSERT 部はコメントアウト）
 *   ※ fetchRaceListAll(): Node.js 実行 → '=== RESULT JSON ===' 区切りでJSON部分を取得
 *
 * 【BASHO_MAP】
 *   開催場所の漢字名 → 2桁コードのマッピング（ImportKeibaSchedule と同じ定義）。
 *
 * 【使い方】
 *   php artisan keiba:importRace
 *   ※現在は無効化されているため実行しても何も保存されない。
 */
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
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_importRace.lock';
        if (file_exists($lockFile)) {
            $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
            return;
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】スケジュール登録済みの日付一覧を取得
        //   schedules テーブルに日付がなければ処理不要で即終了する。
        // ─────────────────────────────────────────────────────────────────
        $sql   = "SELECT date FROM t_horse_odds_finder_schedules GROUP BY date";
        $dates = DB::select($sql);

        if (empty($dates)) {
            $this->warn('スケジュールに日付が登録されていません');
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 3】Node.js スクリプトを all モードで実行して全日付のレース一覧を取得
        //   fetchRaceListAll() で keibaOddsGetRaceList.mjs を起動し
        //   '=== RESULT JSON ===' 区切りの後半をJSONとして解析する。
        // ─────────────────────────────────────────────────────────────────
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

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 4】日付・開催場所ごとにループして upsert
        //   $dateKey: 'YYYY-MM-DD' → 'YYYYMMDD' に変換して JSON キーと照合する。
        //   BASHO_MAP で漢字名を2桁コードに変換する。
        //   ★ updateOrInsert() は現在コメントアウト中（廃止済みテーブルへの書き込みを停止）。
        //      $insertCount はカウントのみ行い DB には書き込まない。
        // ─────────────────────────────────────────────────────────────────
        $totalInsertCount = 0;
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
                    // DB::table('t_horse_odds_finder_netkeiba_races')->updateOrInsert(
                    //     ['race_id' => $race['race_id']],
                    //     [
                    //         'date'       => $date,
                    //         'kaisuu'     => $place['kai'],
                    //         'basho'      => $basho,
                    //         'basho_name' => $bashoName,
                    //         'day'        => $place['nichime'],
                    //         'race_id'    => $race['race_id'],
                    //         'race'       => $race['race_num'],
                    //         'race_name'  => $race['race_name'],
                    //         'start_time' => $race['start_time'],
                    //         'num_horses' => $race['horse_count'],
                    //     ]
                    // );
                    $insertCount++;
                }
            }

            $this->info("  → {$insertCount} 件 upsert 完了");
            $totalInsertCount += $insertCount;
        }

        $this->info('全日程の取り込み完了');
    }

    /**
     * Node.js スクリプトを all モードで実行してレース一覧 JSON を返す。
     * '=== RESULT JSON ===' より後の部分をJSONとして返す。
     * 失敗時は null を返す（timeout 120 で無応答を防ぐ）。
     */
    private function fetchRaceListAll(): ?string
    {
        $nodeBin    = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';
        $scriptPath = base_path('scripts/keibaOddsGetRaceList.mjs');
        $command    = 'timeout 120 ' . $nodeBin . ' ' . escapeshellarg($scriptPath) . ' all 2>/dev/null';
        $output     = shell_exec($command);

        if (!$output || !str_contains($output, '=== RESULT JSON ===')) {
            return null;
        }

        $parts = explode('=== RESULT JSON ===', $output);
        return trim($parts[1] ?? '');
    }
}
