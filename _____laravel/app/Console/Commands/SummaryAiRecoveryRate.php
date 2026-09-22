<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SummaryAiRecoveryRate
 *
 * 【概要】
 *   t_horse_odds_finder_ai_analysis_check（AIが選んだ馬）と
 *   t_horse_odds_finder_race_result_payout（払戻金）を突き合わせて
 *   レースごとの単勝・複勝回収率を t_horse_odds_finder_ai_recovery に保存する。
 *
 *   AIが複数頭選んでいる場合、全頭に100円ずつ買ったと仮定して計算する。
 *   単勝回収率 = SUM(tan_bet) ÷ (SUM(ai_pick_count) × 100) × 100
 *   複勝回収率 = SUM(fuku_bet) ÷ (SUM(ai_pick_count) × 100) × 100
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（ロックファイル）
 *   【ブロック 2】STEP1: t_horse_odds_finder_ai_analysis_check を取得
 *   【ブロック 3】STEP2: レースごとに馬名→馬番変換＋払戻突き合わせ
 *   【ブロック 4】完了ログ・WebPush 通知
 *
 * 【使い方】
 *   php artisan keiba:SummaryAiRecoveryRate
 *   php artisan keiba:SummaryAiRecoveryRate --from=2025-01-01 --to=2025-12-31
 */
class SummaryAiRecoveryRate extends Command
{
    protected $signature   = 'keiba:SummaryAiRecoveryRate
                                {--from= : 集計開始日 (YYYY-MM-DD)}
                                {--to=   : 集計終了日 (YYYY-MM-DD)}';
    protected $description = 'AIが選んだ馬の単勝・複勝回収率を集計して t_horse_odds_finder_ai_recovery に保存する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（ロックファイル）
        // ─────────────────────────────────────────────────────────────────
        $lockFile = sys_get_temp_dir() . '/keiba_SummaryAiRecoveryRate.lock';
        if (file_exists($lockFile)) {
            $pid       = (int) file_get_contents($lockFile);
            $isRunning = $pid > 0 && (
                (function_exists('posix_kill') && posix_kill($pid, 0))
                || file_exists("/proc/{$pid}")
            );
            if ($isRunning) {
                $this->warn('別のプロセスが実行中のため終了します: ' . $lockFile);
                return;
            }
            $this->warn("ロックファイルの残骸を削除して続行します (PID: {$pid})");
            unlink($lockFile);
        }
        file_put_contents($lockFile, getmypid());
        register_shutdown_function(fn() => @unlink($lockFile));

        $startedAt = now();
        $this->info('');
        $this->info('=== SummaryAiRecoveryRate 開始 ' . $startedAt->format('Y-m-d H:i:s') . ' ===');

        try {
            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 2】STEP1: t_horse_odds_finder_ai_analysis_check を取得
            //   --from / --to オプションで日付範囲を絞り込む（date カラムは YYYYMMDD 文字列）
            // ─────────────────────────────────────────────────────────────────
            $from = $this->option('from');
            $to   = $this->option('to');

            // YYYY-MM-DD → YYYYMMDD に変換
            $fromDate = $from ? str_replace('-', '', $from) : null;
            $toDate   = $to   ? str_replace('-', '', $to)   : null;

            $query = DB::table('t_horse_odds_finder_ai_analysis_check')
                ->orderBy('date')
                ->orderBy('kaisuu')
                ->orderBy('basho_code')
                ->orderBy('day')
                ->orderBy('race');

            if ($fromDate) {
                $query->where('date', '>=', $fromDate);
            }
            if ($toDate) {
                $query->where('date', '<=', $toDate);
            }

            $records = $query->get();

            $rangeLabel = ($fromDate || $toDate)
                ? " [{$fromDate} 〜 {$toDate}]"
                : ' (全件)';
            $this->info("  対象レコード数: {$records->count()} 件{$rangeLabel}");
            $this->info('');

            if ($records->isEmpty()) {
                $this->warn('処理対象のレコードがありません。処理を終了します。');
                (new WebPushService())->sendPushNotifierDeveloperNews(
                    'develop',
                    "SummaryAiRecoveryRate::handle\nSKIP（対象レコードなし）"
                );
                return;
            }

            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 3】STEP2: レースごとに払戻を突き合わせて集計
            //
            //   ① ai_analysis_check から pickup_horse1〜3（馬名）を取得
            //   ② race_result_history で 馬名 → 馬番（num）に変換
            //   ③ race_result_payout から tan / fuku 文字列を取得
            //   ④ parsePayoutString() で '馬番|払戻額' マップに変換
            //   ⑤ AI選択馬番がマップに含まれていれば払戻額を加算
            //   ⑥ t_horse_odds_finder_ai_recovery に UPSERT
            // ─────────────────────────────────────────────────────────────────
            $this->info('集計・挿入中...');
            $upsertCount = 0;
            $skipCount   = 0;

            foreach ($records as $v) {

                // ① AIが選んだ馬名リスト（null・空文字除外）
                $pickNames = array_values(array_filter([
                    $v->pickup_horse1 ?? null,
                    $v->pickup_horse2 ?? null,
                    $v->pickup_horse3 ?? null,
                ], fn($n) => $n !== null && $n !== ''));

                if (empty($pickNames)) {
                    $skipCount++;
                    continue;
                }

                // ② 馬名 → 馬番 変換（race_result_history）
                //    先頭ゼロを ltrim して '01' と '1' のズレを吸収
                $historyRows = DB::table('t_horse_odds_finder_race_result_history')
                    ->where('date',       $v->date)
                    ->where('kaisuu',     $v->kaisuu)
                    ->where('basho_code', $v->basho_code)
                    ->where('day',        $v->day)
                    ->where('race',       $v->race)
                    ->whereIn('name', $pickNames)
                    ->get(['name', 'num']);

                $nameToNum = [];
                foreach ($historyRows as $h) {
                    $nameToNum[$h->name] = ltrim((string) $h->num, '0') ?: '0';
                }

                // 変換できた馬番だけを pickNums に
                $pickNums = [];
                foreach ($pickNames as $name) {
                    if (isset($nameToNum[$name])) {
                        $pickNums[] = $nameToNum[$name];
                    }
                }

                // ③ 払戻金テーブルを取得
                $payout = DB::table('t_horse_odds_finder_race_result_payout')
                    ->where('date',       $v->date)
                    ->where('kaisuu',     $v->kaisuu)
                    ->where('basho_code', $v->basho_code)
                    ->where('day',        $v->day)
                    ->where('race',       $v->race)
                    ->first(['tan', 'fuku']);

                if (!$payout) {
                    // 払戻データ未インポート or レース未確定 → スキップ
                    $skipCount++;
                    continue;
                }

                // ④⑤ 単勝（tan）集計
                //     tan カラム例: '14|180'
                $tanBet = 0;
                $tanHit = 0;
                $tanMap = $this->parsePayoutString($payout->tan);
                foreach ($pickNums as $num) {
                    if (isset($tanMap[$num])) {
                        $tanBet += $tanMap[$num];
                        $tanHit++;
                    }
                }

                // ④⑤ 複勝（fuku）集計
                //     fuku カラム例: '3|110/7|150/14|480'
                $fukuBet = 0;
                $fukuHit = 0;
                $fukuMap = $this->parsePayoutString($payout->fuku);
                foreach ($pickNums as $num) {
                    if (isset($fukuMap[$num])) {
                        $fukuBet += $fukuMap[$num];
                        $fukuHit++;
                    }
                }

                // ⑥ UPSERT
                DB::table('t_horse_odds_finder_ai_recovery')
                    ->upsert(
                        [
                            'date'          => $v->date,
                            'kaisuu'        => $v->kaisuu,
                            'basho_code'    => $v->basho_code,
                            'day'           => $v->day,
                            'race'          => $v->race,
                            'ai_pick_count' => count($pickNames),
                            'tan_bet'       => $tanBet,
                            'fuku_bet'      => $fukuBet,
                            'tan_hit'       => $tanHit,
                            'fuku_hit'      => $fukuHit,
                            'computed_at'   => now()->format('Y-m-d H:i:s'),
                        ],
                        ['date', 'kaisuu', 'basho_code', 'day', 'race'],
                        ['ai_pick_count', 'tan_bet', 'fuku_bet', 'tan_hit', 'fuku_hit', 'computed_at']
                    );

                $upsertCount++;

                $this->line(sprintf(
                    '  [%s %2dR]  選択%d頭  単勝:%4d円(%d的中)  複勝:%4d円(%d的中)',
                    $v->date,
                    $v->race,
                    count($pickNames),
                    $tanBet,
                    $tanHit,
                    $fukuBet,
                    $fukuHit
                ));
            }

            // ─────────────────────────────────────────────────────────────────
            // 【ブロック 4】完了ログ・WebPush 通知
            // ─────────────────────────────────────────────────────────────────
            $elapsed = now()->diffInSeconds($startedAt);
            $this->info('');
            $this->info("=== SummaryAiRecoveryRate 完了 " . now()->format('Y-m-d H:i:s') . " ({$elapsed}秒) ===");
            $this->info("UPSERT: {$upsertCount}件  SKIP: {$skipCount}件");
            $this->info('');

//             Log::info('SummaryAiRecoveryRate 完了', [
//                 'upsert_count' => $upsertCount,
//                 'skip_count'   => $skipCount,
//                 'elapsed_sec'  => $elapsed,
//             ]);

            (new WebPushService())->sendPushNotifierDeveloperNews(
                'develop',
                "SummaryAiRecoveryRate::handle\n正常終了\nUPSERT:{$upsertCount}件 SKIP:{$skipCount}件\n経過:{$elapsed}秒"
            );

        } catch (\Throwable $e) {
            $msg = 'エラー: ' . $e->getMessage();
            $this->error($msg);
            Log::error('SummaryAiRecoveryRate 失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            (new WebPushService())->sendPushNotifierDeveloperNews(
                'develop',
                "SummaryAiRecoveryRate::handle\n{$msg}"
            );
        } finally {
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        }
    }

    /**
     * 払戻文字列をパースして 馬番 => 払戻額 のマップを返す
     *
     * 入力例（単勝）: '14|180'
     * 入力例（複勝）: '3|110/7|150/14|480'
     * 戻り値: ['14' => 180]  または  ['3' => 110, '7' => 150, '14' => 480]
     *
     * ※ 先頭ゼロは ltrim で除去（'01' → '1'）
     *
     * @param  string|null $payoutStr
     * @return array<string, int>  馬番(文字列) => 払戻額(整数)
     */
    private function parsePayoutString(?string $payoutStr): array
    {
        if ($payoutStr === null || $payoutStr === '') {
            return [];
        }

        $map = [];
        foreach (explode('/', $payoutStr) as $entry) {
            $parts = explode('|', trim($entry));
            if (count($parts) === 2 && $parts[0] !== '' && is_numeric($parts[1])) {
                $num       = ltrim($parts[0], '0') ?: '0';
                $map[$num] = (int) $parts[1];
            }
        }

        return $map;
    }
}
