<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ImportKeibaPayoutRaceCondition
 *
 * 【概要】20261028 追加（改良期待数値 v0.1 の class_group 用）
 *   払戻金テーブル (t_horse_odds_finder_race_result_payout) の race_condition が未設定のレースについて、
 *   JRA「レース結果」のレース一覧から条件テキスト（例「3歳以上2勝クラス（混合）［指定］」）を取得して保存する。
 *   名前のついた特別戦（麦秋ステークス等）は race_name にクラスが無いため、この列で補う。
 *
 * 【取得元】keibaOddsGetRaceGrade.mjs --with-condition --kaisai=...
 *   td.race_name > div の中の2つ目の div（特別戦）または1つ目の div（一般戦）。
 *   既存の keiba:importPayoutGrade（グレード補完）の動きは変えていない。
 *
 * 【対象】race_condition IS NULL かつ 開始日（--since、既定 2026-10-31）以降のレースだけ。
 *   過去分の取り直しはしない（2026-10-28 合意：今後分のみ）。
 *   対象が無ければ JRA へはアクセスしない。対象の開催だけを開く（--kaisai）。
 *
 * 【使い方】
 *   php artisan keiba:importPayoutRaceCondition
 *   php artisan keiba:importPayoutRaceCondition --since=2026-10-31
 */
class ImportKeibaPayoutRaceCondition extends Command
{
    protected $signature   = 'keiba:importPayoutRaceCondition {--since=2026-10-31 : この日以降の未設定レースだけを対象にする}';
    protected $description = '払戻金テーブルの race_condition（クラスの条件テキスト）を補完する（今後分のみ）';

    /** 場コード → 場名（JRA の開催リンク「4回東京8日」を組み立てるため） */
    private const BASHO_NAME = [
        '01' => '札幌', '02' => '函館', '03' => '福島', '04' => '新潟', '05' => '東京',
        '06' => '中山', '07' => '中京', '08' => '京都', '09' => '阪神', '10' => '小倉',
    ];

    public function handle(): void
    {
        $since = (string) $this->option('since');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $this->error('--since=YYYY-MM-DD の形式で指定してください。');
            return;
        }

        $now          = microtime(true);
        $script       = base_path('scripts/keibaOddsGetRaceGrade.mjs');
        $logFile      = base_path('scripts/keibaOddsGetRaceGrade.log');
        $nodeBin      = '/home/centos/.nvm/versions/node/v24.15.0/bin/node';
        $totalTarget  = 0;
        $totalUpdated = 0;
        $status       = '不明な理由で終了';

        try {
            $this->info('');
            $this->info('========== keiba:importPayoutRaceCondition 開始 ' . date('Y-m-d H:i:s') . ' ==========');
            $this->info("対象 : race_condition が未設定 かつ {$since} 以降");

            // ── 対象レース（未設定のもの）を開催単位にまとめる ──────────────
            $rows = DB::table('t_horse_odds_finder_race_result_payout')
                ->whereNull('race_condition')
                ->where('date', '>=', $since)
                ->where('date', '<=', date('Y-m-d'))
                ->orderBy('date')->orderBy('basho_code')->orderBy('race')
                ->get(['date', 'kaisuu', 'basho_code', 'day', 'race']);

            if ($rows->isEmpty()) {
                $this->info('  → 対象なし。JRA へはアクセスしません。');
                $status = 'SKIP';
                return;
            }
            $totalTarget = $rows->count();

            // yearmonth => [ kaisaiText => true ]
            $kaisaiByMonth = [];
            foreach ($rows as $r) {
                $name = self::BASHO_NAME[$r->basho_code] ?? null;
                if ($name === null) {
                    $this->warn("  [SKIP] 場コード不明: {$r->date} basho_code={$r->basho_code}");
                    continue;
                }
                $ym = substr((string) $r->date, 0, 7);
                // 「回-場コード-日」の数字だけで mjs に渡す（例 4-05-8 ＝ 4回東京8日）。
                //   escapeshellarg はサーバの言語設定で日本語を落とすため（20261028 に「44」になって取得0件だった）
                $kaisaiByMonth[$ym][sprintf('%d-%s-%d', $r->kaisuu, $r->basho_code, $r->day)] = true;
            }

            if (!file_exists($nodeBin) || !file_exists($script)) {
                $this->error("Node またはスクリプトが見つかりません: {$nodeBin} / {$script}");
                $status = 'Node/スクリプト不在';
                return;
            }

            foreach ($kaisaiByMonth as $ym => $kaisaiSet) {
                $kaisaiList = implode(',', array_keys($kaisaiSet));
                $command = 'timeout 600 ' . $nodeBin . ' ' . escapeshellarg($script)
                    . ' --yearmonth=' . escapeshellarg($ym)
                    . ' --with-condition'
                    . ' --kaisai=' . escapeshellarg($kaisaiList)
                    . ' 2>>' . escapeshellarg($logFile);
                $this->info("[{$ym}] 実行: {$command}");

                $json = null;
                for ($retry = 1; $retry <= 3; $retry++) {
                    $output = shell_exec($command);
                    $json   = json_decode($output ?? '', true);
                    if ($json && !empty($json['data'])) {
                        $this->info("  [試行 {$retry}/3] 取得成功 " . count($json['data']) . ' レース');
                        break;
                    }
                    $this->warn("  [試行 {$retry}/3] 取得失敗。出力: " . substr($output ?? '', 0, 300));
                    if ($retry < 3) {
                        sleep(5);
                    }
                }
                if (!$json || empty($json['data'])) {
                    $this->error("  [{$ym}] 取得できませんでした。次回の実行で再取得します。");
                    continue;
                }

                foreach ($json['data'] as $item) {
                    $cond = trim((string) ($item['race_condition'] ?? ''));
                    if ($cond === '' || empty($item['date'])) {
                        continue;   // 取れなかったレースは NULL のまま（次回再取得）
                    }
                    $affected = DB::table('t_horse_odds_finder_race_result_payout')
                        ->where('date',       $item['date'])
                        ->where('kaisuu',     $item['kaisuu'])
                        ->where('basho_code', $item['basho_code'])
                        ->where('day',        $item['day'])
                        ->where('race',       $item['race'])
                        ->whereNull('race_condition')
                        ->update(['race_condition' => mb_substr($cond, 0, 100)]);
                    if ($affected > 0) {
                        $totalUpdated += $affected;
                        $this->info("  [UPDATE] {$item['date']} {$item['basho_name']}{$item['race']}R → {$cond}");
                    }
                }
            }
            $status = '正常終了';
        } finally {
            $elapsed = round(microtime(true) - $now, 1);
            $this->info("終了理由 : {$status} ／ 対象 {$totalTarget} 件 ／ 更新 {$totalUpdated} 件 ／ {$elapsed} 秒");
            $this->info('========== keiba:importPayoutRaceCondition 終了 ' . date('Y-m-d H:i:s') . ' ==========');
            if ($status !== 'SKIP') {
                (new WebPushService())->sendPushNotifierDeveloperNews('develop',
                    "ImportKeibaPayoutRaceCondition::handle\n{$status}、対象:{$totalTarget}件、更新:{$totalUpdated}件");
            }
        }
    }
}
