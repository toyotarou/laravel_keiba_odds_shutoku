<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\AiController;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SummaryAiAnalysisCompensate
 *
 * 【概要】
 *   当日のレースのうち、AI予想が未実行のものを補完実行する。
 *   1st AI (t_horse_odds_finder_ai_analysis) と
 *   2nd AI (t_horse_odds_finder_ai_analysis2) をそれぞれ独立して確認・補完する。
 *
 * 【処理フロー】
 *   【ブロック 1】多重起動防止（日付別ロックファイル）
 *   【ブロック 2】初期化・開始バナー
 *   ─── ガード A（当日レースなし → 早期リターン）─────────────────
 *   【ブロック 3】全レースループ
 *     - 1st AI: ai_analysis に未登録 かつ オッズ（base/6min）が揃っていれば補完
 *     - 2nd AI: ai_analysis2 に未登録であれば補完（オッズ確認不要）
 *   ─── ガード B（補完ゼロ → SKIP 扱い）──────────────────────────
 *   【ブロック 4】完了サマリー・WebPush 通知（finally で必ず実行）
 *
 * 【テーブル対応】
 *   t_horse_odds_finder_races.basho     = AI テーブルの basho_code
 *   t_horse_odds_finder_odds.minutes_before_start:
 *     999 = ODDS_DB_FIRST（基準オッズ）
 *       6 = 6分前オッズ（1st AI 実行の必要条件）
 *
 * 【gapHorseNums / upsetPickupHorseNums について】
 *   本来 Flutter 側で計算する注目馬番①②（仕様「注目馬番（3種類）」）。
 *   【20260924】空文字を渡すと AiController 側でアプリと同じ計算をして埋める
 *   （以前はプロンプトから抜け落ちていた）。
 *
 * 【使い方】
 *   php artisan keiba:ai-analysis-compensate                  … 当日を処理
 *   php artisan keiba:ai-analysis-compensate --date=2026-09-26 … 指定日を処理
 *   php artisan keiba:ai-analysis-compensate --quiet-skip
 *       … 補完対象が無かった回は開始バナー・完了サマリー・WebPush通知を出さない。
 *         発走6分前オッズが入った直後にAI予想を作っておく「先回り実行」用。
 *         毎分動かしても、空振りの回はログにも通知にも残らない。
 *         ※このオプションを付けないと、毎分の実行で1日480通の通知が飛ぶ。
 *   php artisan keiba:ai-analysis-compensate --date=2026-09-26 --remerge
 *       … 保存済みのAI回答を使い、マージ＋フィルターだけやり直す（AI呼び出し0回）
 *         フィルターの仕様を変更したあと、過去レースへ新ルールを適用する用途。
 *         テーブルを削除する必要はない。
 *
 *   php artisan keiba:ai-analysis-compensate --date=2026-10-03 --compare
 *       … 【20261004 追加】保存済みのAI回答を使い、配分だけを4条件で計算し直して
 *         結果を並べて比べる。仕様書10.1「現行、4＋3、3＋4、可変配分を区別して
 *         保存し…比較する」用。
 *         ・AI呼び出しは0回。費用はかからない。
 *         ・DBには一切保存しない（トランザクションを張って必ずロールバックする）。
 *         ・結果は画面と storage/logs/alloc_compare_YYYY-MM-DD.log に出す。
 *
 *   php artisan keiba:ai-analysis-compensate --date=2026-10-03 --remerge --alloc-mode=fixed34
 *       … 【20261004 追加】配分を固定して再マージし、DBへ保存する。
 *         current = 可変配分を無効（9月28日時点の動き）
 *         fixed43 = 人気側4＋穴側3 に固定
 *         fixed34 = 人気側3＋穴側4 に固定
 *         variable = 可変配分（通常の動き）
 *         ※--remerge が必要（AI呼び出しを発生させないため）。
 *
 *   php artisan keiba:ai-analysis-compensate --date=2026-09-26 --compare --compare-note="旧指示あり"
 *       … 【20261004 追加】比較結果の見出しに覚書を付ける。
 *         「旧指示の削除だけ」の前後を比べるとき、同じログファイルの中で
 *         どちらの回答に対する比較なのかを区別するために使う。
 *
 *   php artisan keiba:ai-analysis-compensate --date=2026-09-26 --compare-rerun
 *       … 【20261004 追加】「旧指示の削除だけ」の前後を1回の実行で比べる。
 *         ・新しいお願い文で 1st / 2nd AI を呼び直す（1レース2回）。
 *         ・保存済みのAI回答（ai_analysis / ai_analysis2）は消さず、上書きもしない。
 *         ・プロンプトファイル（public/prompt, public/prompt_2nd）も書き換えない。
 *         ・統合結果・学習用スナップショット・シャドーログも保存しない。
 *         ・さらにトランザクションを張って必ずロールバックする（二重の歯止め）。
 *         1レースにつき次の3通りを並べて出す。
 *           A 旧指示あり      : 保存済み回答 ＋ 現行の統合
 *           B 旧指示削除後    : 呼び直した回答 ＋ 現行の統合（＝お願い文の効果だけ）
 *           C 削除後＋可変配分: 呼び直した回答 ＋ 可変配分（＝両方を入れた結果）
 *
 *   php artisan keiba:ai-analysis-compensate --date=2026-10-10 --rerun-stale
 *       … 【20261004 追加】6分前オッズがお願い文の作成後に変わったレース（取消更新など）
 *         だけを選び、両AIを最新の入力で再実行する（仕様書9.2）。
 *         ・対象は snapshot_id が食い違ったレースだけ。それ以外は触らない。
 *         ・差し替える前に、旧回答の控えを
 *           storage/logs/stale_backup_YYYY-MM-DD.json に書き出す。
 *         ・実行前に対象レースを一覧表示して確認を求める。
 *
 * 【--compare で比べられること・比べられないこと】
 *   比べられる  : 統合（マージ）側の配分だけを変えた結果の違い。AI費用0円。
 *   比べられない: AIへのお願い文を変えた前後の違い。お願い文が変わると
 *                 AIの回答そのものが変わるため、AIの呼び直しが必要になる。
 *                 「旧指示の削除だけ」の前後を比べる場合は --compare-rerun を使う。
 *
 * 【20261006 追記・元データを消す手順は廃止】
 *   よっしー20261006のご指摘・第7節：
 *     「前半には保存済み回答・プロンプトを消す試験手順が残っています。
 *       第19章の試算モードまたは隔離コピーを使い、比較に必要な元データを
 *       失わない手順へ統一するのが適切です」
 *   → 【旧手順（使わない）】
 *       ① --compare を実行して結果を保存
 *       ② ai_analysis / ai_analysis2 の該当行と prompt/*.data を消す
 *       ③ --date=... を付けて通常実行（AIを呼び直す）
 *       ④ もう一度 --compare を実行して①と見比べる
 *     この②でデータが失われるため廃止した。
 *   → 【現行手順】--compare-rerun だけで足りる。
 *       ・保存済み回答・プロンプトファイル・統合結果は一切消さない・上書きしない
 *       ・トランザクションを張って必ずロールバックする
 *       ・生成したお願い文の写しは storage/logs/rerun_prompt/ に別名で残す
 *       ・1回の実行で A（旧指示あり）/ B（削除後）/ C（削除後＋可変配分）を並べて出す
 *       ・まず --race=1 で1〜2レースだけ試してから全レースを回す
 *
 * 【--date について（20260926 追加）】
 *   フィルターの閾値を変更したあと、過去日のAI予想をやり直すために追加した。
 *   省略時は従来どおり当日（date('Y-m-d')）を対象にする。
 *   ロックファイルも対象日付ごとに分かれるため、
 *   別日を指定すれば当日ぶんの実行と同時に走らせても衝突しない。
 *
 *   やり直し手順（閾値だけ変えた場合）:
 *     【20261006 変更】DELETE を使う手順は廃止した。保存済み回答は消さない。
 *     1) 修正版を配置
 *     2) php artisan keiba:ai-analysis-compensate --date=YYYY-MM-DD --remerge
 *        （保存済みAI回答のままマージ・フィルターだけやり直す。AI呼び出し0回）
 *     3) t_horse_odds_finder_ai_merge_result の頭数を確認
 *     ※お願い文そのものを変えた効果を見たい場合は --compare-rerun を使う。
 *       こちらは何も消さず、何も上書きしない。
 */
class SummaryAiAnalysisCompensate extends Command
{
    protected $signature   = 'keiba:ai-analysis-compensate'
                           . ' {--date= : 対象日付 YYYY-MM-DD（省略時は当日）}'
                           . ' {--remerge : 保存済みのAI回答でマージ・フィルターだけやり直す}'
                           . ' {--quiet-skip : 補完対象が無かった回は通知もログ要約も出さない（毎分の先回り実行用）}'
                           . ' {--compare : 配分を4条件で計算し直して並べて比べる（DBへ保存しない・AI呼び出し0回）}'
                           . ' {--alloc-mode= : 配分を固定して再マージする（current / fixed43 / fixed34 / variable）。--remerge と併用}'
                           . ' {--compare-note= : 比較結果の見出しに付ける覚書（例: 旧指示あり / 旧指示削除後）}'
                           . ' {--compare-rerun : 「旧指示の削除だけ」の前後を比べる（AIを呼び直すが、DBとファイルは一切書き換えない）}'
                           . ' {--rerun-stale : 6分前オッズが変わったレースだけ、両AIを最新の入力で再実行する（仕様書9.2）。旧回答は控えを取ってから差し替える}'
                           . ' {--list-dates : どの日付のデータが残っているかを一覧表示する（読み取りだけ・何も変更しない）}'
                           . ' {--compare-history : 洗い替えでオッズが消えた過去日を、当時の統合結果と着順履歴だけで採点する（読み取りだけ）}'
                           // @ANCHOR-20261005-RACE-FILTER  試す前に1レースで確かめられるようにする
                           . ' {--race= : --compare / --compare-rerun の対象レースを絞る（例 --race=1 や --race=1,2）。AI呼び出しを節約して動作確認するために使う}'
                           // @ANCHOR-20261006-ML-STATUS  学習データの蓄積状況を出す
                           . ' {--ml-status : 断層パターン学習の特徴量・正解ラベルの件数を集計する（読み取りだけ）}';
    protected $description = '未実行のAI予想を補完実行する';

    public function handle(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 1】多重起動防止（日付別ロックファイル）
        //   同日に複数のプロセスが同時実行されるのを防ぐ。
        // ─────────────────────────────────────────────────────────────────
        // ── 20260926: --date= で対象日を指定できるようにした ──────────────
        //   省略時は従来どおり当日。過去日のやり直しに使う。
        //   不正な日付でDBを引かないよう、書式と実在日をここで弾く。
        // ── 20261004 追加: 残っているデータの日付を一覧する（読み取りだけ）──────
        //   過去日の比較・再実行ができるかどうかは、
        //   t_horse_odds_finder_races にその日の行が残っているかで決まる。
        //   （1st・2nd のどちらのエンドポイントもこのテーブルからレースを引くため）
        if ((bool) $this->option('list-dates')) {
            $this->runListDates();
            return;
        }

        // @ANCHOR-20261006-ML-STATUS  学習データの蓄積状況（読み取りだけ）
        if ((bool) $this->option('ml-status')) {
            $this->runMlStatus();
            return;
        }

        // ── 20261004 追加: 洗い替え済みの過去日を、残っているデータだけで採点する ──
        //   毎週土曜5:50の keiba:deleteKeibaTableRecords で
        //   races / odds / horses / race_results は消える（仕様どおりの運用）。
        //   残るのは ai_analysis / ai_analysis2 / ai_merge_result /
        //   race_result_history / race_result_payout / プロンプトファイル。
        //   それだけで「当時どう選び、結果どうだったか」は採点できる。
        if ((bool) $this->option('compare-history')) {
            $this->runHistoryCompare((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        $date = (string) ($this->option('date') ?: date('Y-m-d'));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dm)
            || !checkdate((int) $dm[2], (int) $dm[3], (int) $dm[1])) {
            $this->error("--date の書式が不正です（YYYY-MM-DD で指定してください）: {$date}");
            return;
        }

        // ── 20261004 追加: 配分の比較モード ───────────────────────────────
        //   仕様書10.1「現行、4＋3、3＋4、可変配分を区別して保存し…比較する」用。
        //   保存済みのAI回答を使って配分だけを計算し直す。AI呼び出しは0回。
        //   DBへは保存しない（トランザクションを張って必ずロールバックする）。
        if ((bool) $this->option('compare')) {
            $this->runAllocCompare($date, (string) ($this->option('compare-note') ?: ''));
            return;
        }

        // ── 20261004 追加: 「旧指示の削除だけ」の前後比較（呼び直しあり）──────
        //   よっしー20261004 2回目のご回答④B。
        //   保存済み回答とプロンプトファイルは消さない・上書きしない（試算モード）。
        if ((bool) $this->option('compare-rerun')) {
            $this->runRerunCompare($date, (string) ($this->option('compare-note') ?: ''));
            return;
        }

        // ── 20261004 追加: 入力が変わったレースの再実行（仕様書9.2）──────────
        //   よっしー20261004 2回目のご回答③A：
        //     「取消更新などで入力が変わった場合は、仕様書9.2どおり、
        //       両AIを同じ最新版の入力で再実行してください。」
        if ((bool) $this->option('rerun-stale')) {
            $this->runStaleRerun($date);
            return;
        }

        // ── 20261004 追加: 配分の固定（--alloc-mode=）───────────────────────
        //   こちらはDBへ保存する。AIを呼ばないよう --remerge を必須にする。
        $allocModeOpt = (string) ($this->option('alloc-mode') ?: '');
        if ($allocModeOpt !== '') {
            if (!(bool) $this->option('remerge')) {
                $this->error('--alloc-mode は --remerge と一緒に指定してください（AI呼び出しを発生させないため）。');
                return;
            }
            $ovr = self::allocOverrideFor($allocModeOpt);
            if ($ovr === null) {
                $this->error('--alloc-mode の値が不正です（current / fixed43 / fixed34 / variable）: ' . $allocModeOpt);
                return;
            }
            AiController::$allocOverride = $ovr;
            $this->warn('配分を固定して再マージします: ' . $ovr['label'] . '（DBへ保存します）');
        }

        // ── 20260926 追加: 再マージモード ─────────────────────────────────
        //   フィルターの仕様を変えたあと、過去レースへ新ルールを適用するためのモード。
        //   保存済みの1st/2nd AI回答をそのまま使い、DeepSeekを呼ばずに
        //   マージ＋フィルターだけやり直して ai_merge_result を上書きする。
        //   外部AI呼び出しは0回。入力が同じなので結果は決定的。
        $remerge = (bool) $this->option('remerge');

        // ── 20260928 追加: 先回り実行（毎分cron）用の静音モード ─────────────
        //   補完対象が1件も無かった回は、開始バナー・完了サマリー・WebPush通知を
        //   すべて省く。これを付けずに毎分動かすと、空振りの通知でdeveloper宛の
        //   WebPushが1日480通になり、ログも同じだけ膨らむ。
        //   補完を実際に行った回は、従来どおり全部出力する。
        $quietSkip = (bool) $this->option('quiet-skip');

        $lockFile = sys_get_temp_dir() . '/keiba_aiAnalysisCompensate_' . str_replace('-', '', $date) . '.lock';

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

        // ─────────────────────────────────────────────────────────────────
        // 【ブロック 2】初期化・開始バナー
        // ─────────────────────────────────────────────────────────────────
        $now            = microtime(true);
        $compensated1st = 0;
        $compensated2nd = 0;
        $skipped        = 0;
        $status         = '不明な理由で終了';

        try {
            if (!$quietSkip) {
                $this->info('');
                $this->info('========== keiba:ai-analysis-compensate 開始 ' . date('Y-m-d H:i:s') . ' ==========');
                $this->info('対象日付 : ' . $date);
                if ($remerge) {
                    $this->info('モード   : 再マージ（DeepSeekを呼ばず、保存済み回答でフィルターを再適用）');
                }
                $this->info('');
            }

            // ═════════════════════════════════════════════════════════════
            // 【ガード A】対象日のレースが存在しなければ早期リターン
            // ═════════════════════════════════════════════════════════════
            $races = DB::table('t_horse_odds_finder_races')
                ->where('date', $date)
                ->orderBy('kaisuu')
                ->orderBy('basho')
                ->orderBy('day')
                ->orderBy('race')
                ->get();

            if ($races->isEmpty()) {
                // 静音モードでは何も出さない（開催のない日に毎分1行ずつ積み上がるのを防ぐ）
                if (!$quietSkip) {
                    $this->info("[ガードA] {$date} のレースが存在しません。");
                }
                $status = 'SKIP';
                return;
            }

            if (!$quietSkip) {
                $this->info("対象レース数 : {$races->count()} 件");
                $this->info('');
            }

            // ─────────────────────────────────────────────────────────────
            // 【ブロック 3】全レースループ
            //   1st AI → オッズ確認 → 補完
            //   2nd AI → 補完（オッズ確認不要）
            // ─────────────────────────────────────────────────────────────
            /** @var AiController $controller */
            $controller = app(AiController::class);

            // ── 20261004 追加: 保存されなかったレースを毎分やり直さないための記録 ──
            //   1st AI が 200 を返しても ai_analysis に保存されないレースがあると、
            //   毎分AIを呼び直し、毎分「補完1件」として通知が飛んでいた（東京5Rで発生）。
            //   静音モード（毎分cron）では、保存されなかったレースを当日は再実行しない。
            //   夜間の補完（--quiet-skip なし）は従来どおり再実行する。
            $giveUpFile = sys_get_temp_dir() . '/keiba_aiCompensateGiveUp_' . str_replace('-', '', $date) . '.json';
            $giveUpKeys = [];
            if ($quietSkip && file_exists($giveUpFile)) {
                $giveUpKeys = json_decode((string) file_get_contents($giveUpFile), true) ?: [];
            }

            foreach ($races as $raceRow) {
                $label = "{$raceRow->kaisuu}回{$raceRow->basho_name}{$raceRow->day}日 {$raceRow->race}R";

                // ── 1st AI チェック ───────────────────────────────────────
                // races.basho = ai_analysis.basho_code
                $exists1st = DB::table('t_horse_odds_finder_ai_analysis')
                    ->where('date',       $raceRow->date)
                    ->where('kaisuu',     $raceRow->kaisuu)
                    ->where('basho_code', $raceRow->basho)
                    ->where('day',        $raceRow->day)
                    ->where('race',       $raceRow->race)
                    ->exists();

                $giveUpKey = "{$raceRow->kaisuu}_{$raceRow->basho}_{$raceRow->day}_{$raceRow->race}";
                if (!$exists1st && $quietSkip && in_array($giveUpKey, $giveUpKeys, true)) {
                    $skipped++;
                    continue;   // 20261004: 保存に失敗済みのレースは毎分やり直さない
                }

                if (!$exists1st) {
                    // オッズが揃っているか確認（base=999 と 6分前 の両方が必要）
                    $hasBase = DB::table('t_horse_odds_finder_odds')
                        ->where('date',                $raceRow->date)
                        ->where('kaisuu',              $raceRow->kaisuu)
                        ->where('basho',               $raceRow->basho)
                        ->where('day',                 $raceRow->day)
                        ->where('race',                $raceRow->race)
                        ->where('minutes_before_start', 999)
                        ->exists();

                    $has6min = DB::table('t_horse_odds_finder_odds')
                        ->where('date',                $raceRow->date)
                        ->where('kaisuu',              $raceRow->kaisuu)
                        ->where('basho',               $raceRow->basho)
                        ->where('day',                 $raceRow->day)
                        ->where('race',                $raceRow->race)
                        ->where('minutes_before_start', 6)
                        ->exists();

                    if (!$hasBase || !$has6min) {
                        // 静音モードでは出さない。発走6分前まではこれが全レース分出るため、
                        // 毎分実行では1日あたり数千行になってログが読めなくなる。
                        if (!$quietSkip) {
                            $this->info("[スキップ] {$label} : オッズ未取得 (base={$hasBase}, 6min={$has6min})");
                        }
                        $skipped++;
                        continue;
                    }

                    $this->info("[1st AI] {$label} : 実行開始");

                    try {
                        $req = new Request();
                        $req->query->add([
                            'date'                 => $raceRow->date,
                            'kaisuu'               => $raceRow->kaisuu,
                            'basho'                => $raceRow->basho,
                            'day'                  => $raceRow->day,
                            'race'                 => (string) $raceRow->race,
                            'gapHorseNums'         => '',   // 空 → AiController がアプリと同じ計算で①を埋める（20260924）
                            'upsetPickupHorseNums' => '',   // 空 → 同上で②を埋める
                        ]);

                        $response1st = $controller->getHorseOddsFinderAiAnalysis($req);
                        $status1st   = $response1st->getStatusCode();

                        if ($status1st === 200) {
                            // 20261004: 200でも保存されていなければ「補完できた」とは数えない
                            $saved1st = DB::table('t_horse_odds_finder_ai_analysis')
                                ->where('date',       $raceRow->date)
                                ->where('kaisuu',     $raceRow->kaisuu)
                                ->where('basho_code', $raceRow->basho)
                                ->where('day',        $raceRow->day)
                                ->where('race',       $raceRow->race)
                                ->exists();
                            if ($saved1st) {
                                $this->info("[1st AI] {$label} : 成功");
                                $compensated1st++;
                            } else {
                                $this->error("[1st AI] {$label} : 200が返ったがDB未保存（AiController内で例外の可能性。storage/logs/laravel.log の RACE_PROCESSING_ERROR を確認）");
                                if ($quietSkip) {
                                    $giveUpKeys[] = $giveUpKey;
                                    @file_put_contents($giveUpFile, json_encode(array_values(array_unique($giveUpKeys))));
                                }
                            }
                        } else {
                            $this->error("[1st AI] {$label} : 失敗 status={$status1st} body=" . $response1st->getContent());
                        }
                    } catch (\Throwable $e) {
                        $this->error("[1st AI] {$label} : 例外発生 " . $e->getMessage());
                    }
                }

                // ── 1st AI 未登録なら 2nd AI は呼ばない（20260924 追加） ─────────
                //   最終確定版：1st AI が失敗したレースは通常予測を公開しない。
                //   1st AI 結果がないまま DeepSeek を呼んでも公開されず、API 呼び出しが無駄になるためスキップする。
                $has1stNow = DB::table('t_horse_odds_finder_ai_analysis')
                    ->where('date',       $raceRow->date)
                    ->where('kaisuu',     $raceRow->kaisuu)
                    ->where('basho_code', $raceRow->basho)
                    ->where('day',        $raceRow->day)
                    ->where('race',       $raceRow->race)
                    ->exists();
                if (!$has1stNow) {
                    $this->warn("[2nd AI] {$label} : 1st AI 結果がないためスキップ");
                    $skipped++;
                    continue;
                }

                // ── 2nd AI チェック ───────────────────────────────────────
                $exists2nd = DB::table('t_horse_odds_finder_ai_analysis2')
                    ->where('date',       $raceRow->date)
                    ->where('kaisuu',     $raceRow->kaisuu)
                    ->where('basho_code', $raceRow->basho)
                    ->where('day',        $raceRow->day)
                    ->where('race',       $raceRow->race)
                    ->exists();

                // 再マージモードでは、2nd AI の結果が既にあっても処理する。
                // （保存済み回答を使ってマージ・フィルターだけやり直すため）
                if (!$exists2nd || $remerge) {
                    $this->info($remerge && $exists2nd
                        ? "[再マージ] {$label} : 開始"
                        : "[2nd AI] {$label} : 実行開始");

                    try {
                        $req2ndParams = [
                            'date'   => $raceRow->date,
                            'kaisuu' => $raceRow->kaisuu,
                            'basho'  => $raceRow->basho,
                            'day'    => $raceRow->day,
                            'race'   => (string) $raceRow->race,
                        ];
                        if ($remerge) {
                            $req2ndParams['remerge'] = '1';
                        }
                        $req2nd = new Request();
                        $req2nd->query->add($req2ndParams);

                        $response2nd = $controller->getHorseOddsFinderSecondAiOpinion($req2nd);
                        $status2nd   = $response2nd->getStatusCode();

                        if ($status2nd === 200) {
                            $this->info("[2nd AI] {$label} : 成功");
                            $compensated2nd++;
                        } else {
                            $this->error("[2nd AI] {$label} : 失敗 status={$status2nd} body=" . $response2nd->getContent());
                        }
                    } catch (\Throwable $e) {
                        $this->error("[2nd AI] {$label} : 例外発生 " . $e->getMessage());
                    }
                }
            }

            // ═════════════════════════════════════════════════════════════
            // 【ガード B】補完ゼロ → 空振り（SKIP 扱い）
            // ═════════════════════════════════════════════════════════════
            if ($compensated1st === 0 && $compensated2nd === 0) {
                // 静音モードでは何も出さない（補完対象が無い回が1日の大半を占めるため）
                if (!$quietSkip) {
                    $this->info('');
                    $this->info('[ガードB] 補完対象なし（全レースのAI予想は実行済み）。');
                }
                $status = 'SKIP';
                return;
            }

            $status = '正常終了';

        } finally {
            // ─────────────────────────────────────────────────────────────
            // 【ブロック 4】完了サマリー・WebPush 通知（finally で必ず実行）
            // ─────────────────────────────────────────────────────────────
            $totalElapsed = round(microtime(true) - $now, 1);

            // ── 20260928: 静音モード（--quiet-skip）で空振りだった回は何も出さない ──
            //   「補完0件」＝ SKIP。毎分の先回り実行では、これが1日の大半を占める。
            //   ※ finally の中で return すると、try で発生した例外を握り潰してしまう。
            //     必ず if で囲うこと（return を書いてはいけない）。
            $quietSilent = ($quietSkip && $compensated1st === 0 && $compensated2nd === 0);

            if (!$quietSilent) {
                $this->info('');
                $this->info("終了理由       : {$status}");
                $this->info("対象日付       : {$date}");
                $this->info("1st AI 補完    : {$compensated1st} 件");
                $this->info("2nd AI 補完    : {$compensated2nd} 件");
                $this->info("オッズ未取得   : {$skipped} 件（スキップ）");
                $this->info("処理時間       : {$totalElapsed} 秒");
                $this->info('');
                $this->info('========== keiba:ai-analysis-compensate 終了 ' . date('Y-m-d H:i:s') . ' ==========');
                $this->info('');

                $newsValue   = [];
                $newsValue[] = $status;
                if ($status !== 'SKIP') {
                    $newsValue[] = "対象日:{$date}、";
                    $newsValue[] = "1st AI:{$compensated1st}件、";
                    $newsValue[] = "2nd AI:{$compensated2nd}件、";
                    $newsValue[] = "スキップ:{$skipped}件";
                }
                $news = implode('', $newsValue);

                (new WebPushService())->sendPushNotifierDeveloperNews('develop', "SummaryAiAnalysisCompensate::handle\n{$news}");
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261004-ALLOC-COMPARE  配分の比較（--compare）
    // ═════════════════════════════════════════════════════════════════════
    //   仕様書10.1 ／ よっしー20261004回答「修正前後の比較」用。
    //   保存済みのAI回答を使い、配分だけを4条件で計算し直して並べる。
    //     現行     … 可変配分を無効（9月28日時点の動き：人気帯別目安＋旧保護枠）
    //     固定4＋3 … 人気側4頭＋資格あり穴側3頭に固定
    //     固定3＋4 … 人気側3頭＋資格あり穴側4頭に固定
    //     可変配分 … プロンプトに凍結された配分モードのまま（通常の動き）
    //   AI呼び出しは0回。DBへは保存しない（必ずロールバックする）。
    // ─────────────────────────────────────────────────────────────────────
    private function runAllocCompare(string $date, string $note = ''): void
    {
        $conds = ['current', 'fixed43', 'fixed34', 'variable'];

        $races = DB::table('t_horse_odds_finder_races')
            ->where('date', $date)
            ->orderBy('kaisuu')->orderBy('basho')->orderBy('day')->orderBy('race')
            ->get();

        $races = $this->filterRaces($races);   // @ANCHOR-20261005-RACE-FILTER

        if ($races->isEmpty()) {
            $this->error("{$date} のレースが見つかりません。");
            return;
        }

        /** @var AiController $controller */
        $controller = app(AiController::class);

        $lines   = [];
        $lines[] = '========== 配分の比較 ' . $date . ' =========='
                 . ($note !== '' ? '  【' . $note . '】' : '');
        $lines[] = '実行時刻: ' . date('Y-m-d H:i:s') . '（JST）';
        if ($note !== '') {
            // 【20261004 回答④B】「旧指示の削除だけ」の比較と「配分4条件」の比較を分けて記録する
            $lines[] = '覚書    : ' . $note;
        }
        $lines[] = '仕様の版: ' . AiController::ALLOC_SPEC_VERSION;
        $lines[] = 'AI呼び出し: 0回（保存済み回答を再利用）／DB保存: なし（ロールバック）';
        $lines[] = '';
        $lines[] = '【この比較の設定（よっしー20261004回答④Bの条件）】';
        $lines[] = '  比較の種類   : 配分4条件の比較（統合側だけを入れ替え。AIへのお願い文は共通）';
        $lines[] = '  入力         : 6分前オッズ（保存済み・AIへは再送しない）';
        $lines[] = '  AI回答       : 保存済みのものを再利用（1st=claude-haiku-4-5 / 2nd=deepseek-chat）';
        $lines[] = '  AI呼び出し   : 0回';
        $lines[] = '  仕様の版     : ' . AiController::ALLOC_SPEC_VERSION;
        $lines[] = '  統合式の版   : ' . AiController::MERGE_FORMULA_VERSION;
        $lines[] = '  変更点       : 配分モード（現行／中4＋3／強3＋4／可変配分）のみ';
        $lines[] = '  結果・着順   : AIへは入力せず、出力が確定したあとに照合';
        $lines[] = '';
        $lines[] = '【指標の定義】';
        $lines[] = '  3着内完全捕捉 : 1〜3着の3頭すべてが最終候補に入っていたレース数';
        $lines[] = '  5着内捕捉数   : 最終候補のうち5着以内に来た頭数の合計';
        $lines[] = '  人気薄捕捉数  : 7番人気以下で3着以内に来た馬を候補に入れていた数';
        $lines[] = '  人気側取り逃し: 1〜6番人気で3着以内に来た馬を候補に入れていなかった数';
        $lines[] = '  ※人気順は6分前単勝オッズの昇順（同値は馬番昇順）で算出';
        $lines[] = '';

        $tot = [];
        foreach ($conds as $c) {
            $tot[$c] = ['races' => 0, 'hit3' => 0, 'in5' => 0, 'long' => 0, 'miss' => 0,
                        'cand' => 0, 'noresult' => 0,
                        // 【20261004 回答②A】未確定は黙って除外せず別に数える
                        'undet' => 0,
                        // 【20261004 回答④B】全頭採点が保存回答に無いレースを数える
                        'noscore' => 0];
        }

        foreach ($races as $raceRow) {
            $label = "{$raceRow->date} {$raceRow->kaisuu}回{$raceRow->basho} {$raceRow->day}日目 {$raceRow->race}R";

            // 1〜5着（結果が無いレースは指標を数えない）
            // @ANCHOR-20261005-RESULT-COLUMNS  テーブルごとに列名が違う
            //   t_horse_odds_finder_race_results        … basho（2桁コード）/ result
            //   t_horse_odds_finder_race_result_history … basho_code / finishing_position
            //   【誤】basho_code と finishing_position を race_results に対して使っていた。
            //        履歴テーブルの列名を取り違えたもの。SQLエラーで実行が止まっていた。
            $resRows = DB::table('t_horse_odds_finder_race_results')
                ->where('date',   $raceRow->date)
                ->where('kaisuu', $raceRow->kaisuu)
                ->where('basho',  $raceRow->basho)
                ->where('day',    $raceRow->day)
                ->where('race',   $raceRow->race)
                ->get(['num', 'result']);
            $top3 = []; $top5 = [];
            foreach ($resRows as $r) {
                $fp = (int) $r->result;
                if ($fp >= 1 && $fp <= 3) $top3[] = (int) $r->num;
                if ($fp >= 1 && $fp <= 5) $top5[] = (int) $r->num;
            }
            $hasResult = (count($top3) === 3);

            // 6分前単勝オッズから人気順を作る（候補に入らなかった馬の人気も必要なため）
            $oddsRows = DB::table('t_horse_odds_finder_odds')
                ->where('date',   $raceRow->date)
                ->where('kaisuu', $raceRow->kaisuu)
                ->where('basho',  $raceRow->basho)
                ->where('day',    $raceRow->day)
                ->where('race',   $raceRow->race)
                ->where('minutes_before_start', 6)
                ->get(['num', 'odds']);
            $pairs = [];
            foreach ($oddsRows as $o) {
                if (!is_numeric($o->odds) || (float) $o->odds <= 0) continue;
                $pairs[] = ['num' => (int) $o->num, 'odds' => (float) $o->odds];
            }
            usort($pairs, fn($a, $b) => ($a['odds'] <=> $b['odds']) ?: ($a['num'] <=> $b['num']));
            $popByNum = [];
            foreach ($pairs as $i => $p) { $popByNum[$p['num']] = $i + 1; }

            $row  = [];
            $note = [];
            foreach ($conds as $cond) {
                $res = $this->mergeOnceForCompare($controller, $raceRow, $cond);
                if ($res === null) {
                    $row[$cond] = null;
                    continue;
                }
                $picked = $res['horses'];
                $nums   = array_map(fn($h) => (int) ($h['num'] ?? 0), $picked);
                $row[$cond] = $nums;

                $tot[$cond]['races']++;
                $tot[$cond]['cand'] += count($nums);

                // 【20261004 回答②A】未確定（採用できるAI回答が無い／入力が混在）は
                //   「正常な候補なし」と区別し、捕捉率の母数からも外して別に数える。
                if ($res['undetermined']) {
                    $tot[$cond]['undet']++;
                    $note[$cond] = '未確定（' . $res['reason'] . '）';
                    continue;
                }
                // 【20261004 回答④B】保存回答に全頭採点が無い場合は比較範囲の限界として明記する
                if (!$res['has_allscore']) { $tot[$cond]['noscore']++; }

                if (!$hasResult) { $tot[$cond]['noresult']++; continue; }

                $m = self::compareMetrics($nums, $top3, $top5, $popByNum);
                $tot[$cond]['hit3'] += $m['hit3'];
                $tot[$cond]['in5']  += $m['in5'];
                $tot[$cond]['long'] += $m['long'];
                $tot[$cond]['miss'] += $m['miss'];
            }

            $lines[] = "── {$label}" . ($hasResult ? '' : '（結果未確定：指標は数えません）');
            if ($hasResult) {
                $t3 = array_map(fn($n) => $n . '(' . ($popByNum[$n] ?? '?') . '人気)', $top3);
                $lines[] = '   1〜3着: ' . implode(' ', $t3);
            }
            foreach ($conds as $cond) {
                $nm = self::condLabel($cond);
                if ($row[$cond] === null) { $lines[] = sprintf('   %-10s : 取得できず', $nm); continue; }
                if (isset($note[$cond])) { $lines[] = sprintf('   %-10s : %s', $nm, $note[$cond]); continue; }
                $mark = [];
                foreach ($row[$cond] as $n) {
                    $m = $n . '(' . ($popByNum[$n] ?? '?') . ')';
                    if ($hasResult && in_array($n, $top3, true)) $m .= '◎';
                    elseif ($hasResult && in_array($n, $top5, true)) $m .= '○';
                    $mark[] = $m;
                }
                $lines[] = sprintf('   %-10s : %d頭  %s', $nm, count($row[$cond]), implode(' ', $mark));
            }
            $lines[] = '';
        }

        $lines[] = '========== 集計 ==========';
        $lines[] = sprintf('%-10s %6s %8s %8s %8s %8s %8s %6s %8s',
            '条件', 'レース', '3着内完全', '5着内捕捉', '人気薄捕捉', '人気側取逃', '平均頭数', '未確定', '採点なし');
        foreach ($conds as $cond) {
            $t = $tot[$cond];
            // 捕捉率の母数は「結果が確定していて、かつ未確定でないレース」
            $n = max(1, $t['races'] - $t['noresult'] - $t['undet']);
            $lines[] = sprintf('%-10s %6d %6d(%4.1f%%) %8d %8d %8d %8.2f %6d %8d',
                self::condLabel($cond), $t['races'], $t['hit3'], 100.0 * $t['hit3'] / $n,
                $t['in5'], $t['long'], $t['miss'],
                $t['races'] > 0 ? $t['cand'] / $t['races'] : 0,
                $t['undet'], $t['noscore']);
        }
        $lines[] = '';
        $lines[] = '※「未確定」は、採用できるAI回答が無かった／入力が混在したレースです。';
        $lines[] = '  正常な「候補なし」とは区別し、捕捉率の母数からも外しています（黙って除外していません）。';
        $lines[] = '※「採点なし」は、保存されたAI回答に全頭採点（内部用JSON）が含まれていなかったレースです。';
        $lines[] = '  この行数ぶんは、配分を変えても採点を共通化できていないため、比較の限界になります。';
        $lines[] = '  10月4日より前に保存された回答は全頭採点を持たないため、ここに入ります。';
        $lines[] = '';
        $lines[] = '※「結果を見てから馬番を救済する」ことはしていません。各条件は同じ6分前入力・同じ保存済みAI回答から機械的に計算しています。';

        $out = implode("\n", $lines) . "\n";
        foreach ($lines as $l) { $this->line($l); }

        $logPath = storage_path('logs/alloc_compare_' . $date . '.log');
        @file_put_contents($logPath, $out, FILE_APPEND);
        $this->info('');
        $this->info('比較結果を書き出しました: ' . $logPath);
    }

    /**
     * 1レース・1条件ぶんだけ統合をやり直して最終候補を取り出す。
     * DBへは保存しない（トランザクションを張って必ずロールバックする）。
     */
    private function mergeOnceForCompare(AiController $controller, object $raceRow, string $cond): ?array
    {
        AiController::$allocOverride = self::allocOverrideFor($cond);
        // @ANCHOR-20261004-DRY-RUN  比較ではDBもファイルも書き換えない
        //   （2nd AI用の整形済みプロンプトファイルも上書きしない）
        AiController::$dryRunNoPersist = true;
        DB::beginTransaction();
        try {
            $req = new Request();
            $req->query->add([
                'date'    => $raceRow->date,
                'kaisuu'  => $raceRow->kaisuu,
                'basho'   => $raceRow->basho,
                'day'     => $raceRow->day,
                'race'    => (string) $raceRow->race,
                'remerge' => '1',   // 保存済み回答を使う。AI呼び出し0回
            ]);
            $res = $controller->getHorseOddsFinderSecondAiOpinion($req);
            if ($res->getStatusCode() !== 200) return null;
            $json = json_decode((string) $res->getContent(), true);
            $data = $json['data'] ?? [];

            // 【20261004 回答②A】未確定（採用できるAI回答が無い／入力が混在）
            $undet  = (($data['status'] ?? '') === 'undetermined');
            $reason = (string) ($data['status_reason'] ?? '');

            // 【20261004 回答④B】保存回答に全頭採点（内部用JSON）が含まれていたか。
            //   統合側が検証OKの採点を受け取れていれば true。
            //   10月4日より前に保存された回答は全頭採点を持たないため false になる。
            $audit    = $controller->lastMergeAudit();
            $hasScore = (bool) (($audit['全頭採点']['1st'] ?? false) || ($audit['全頭採点']['2nd'] ?? false));

            $horses = $data['merged_horses'] ?? null;
            if (!is_array($horses)) return null;
            return ['horses' => $horses, 'undetermined' => $undet,
                    'reason' => $reason, 'has_allscore' => $hasScore];
        } catch (\Throwable $e) {
            $this->error("[比較] {$raceRow->race}R {$cond} : 例外 " . $e->getMessage());
            return null;
        } finally {
            // 比較のための試算なので、DBの変更はすべて取り消す
            DB::rollBack();
            AiController::$allocOverride   = null;
            AiController::$dryRunNoPersist = false;
        }
    }

    /**
     * 1レース1条件ぶんの指標を数える。
     *
     *   hit3 … 1〜3着の3頭すべてが候補に入っていれば1（3着内完全捕捉）
     *   in5  … 候補のうち5着以内に来た頭数
     *   long … 7番人気以下で3着以内に来た馬を候補に入れていた数
     *   miss … 1〜6番人気で3着以内に来た馬を候補に入れていなかった数
     *
     * 人気順は6分前単勝オッズの昇順（同値は馬番昇順）で呼び出し側が作る。
     * 人気が分からない馬（$popByNum に無い）は long / miss のどちらにも数えない。
     *
     * @param array $nums     最終候補の馬番
     * @param array $top3     1〜3着の馬番
     * @param array $top5     1〜5着の馬番
     * @param array $popByNum 馬番 => 人気順
     */
    public static function compareMetrics(array $nums, array $top3, array $top5, array $popByNum): array
    {
        $nums = array_map('intval', $nums);
        $out  = ['hit3' => 0, 'in5' => 0, 'long' => 0, 'miss' => 0];

        if (count($top3) === 3 && count(array_diff($top3, $nums)) === 0) {
            $out['hit3'] = 1;
        }
        $out['in5'] = count(array_intersect($nums, $top5));
        foreach ($top3 as $tn) {
            $tp = $popByNum[(int) $tn] ?? 0;
            if ($tp >= 7 && in_array((int) $tn, $nums, true))              $out['long']++;
            if ($tp >= 1 && $tp <= 6 && !in_array((int) $tn, $nums, true)) $out['miss']++;
        }
        return $out;
    }

    /** 条件名 → 配分の上書き設定 */
    /**
     * @ANCHOR-20261005-RACE-FILTER  --race= で対象レースを絞る
     *
     * 48回のAI呼び出しを使わずに、1〜2レースで動作確認できるようにするための絞り込み。
     * レース番号で絞るので、同じ番号が複数開催にあれば両方が対象になる。
     *
     * @param  \Illuminate\Support\Collection $races
     * @return \Illuminate\Support\Collection
     */
    private function filterRaces($races)
    {
        $opt = trim((string) $this->option('race'));
        if ($opt === '') return $races;

        $want = [];
        foreach (explode(',', $opt) as $v) {
            $v = trim($v);
            if ($v !== '' && ctype_digit($v)) $want[] = (int) $v;
        }
        if (empty($want)) {
            $this->warn('--race の値が不正なため、絞り込みは行いません: ' . $opt);
            return $races;
        }

        $filtered = $races->filter(fn($r) => in_array((int) $r->race, $want, true))->values();
        $this->warn('--race の指定により ' . $filtered->count() . ' レースに絞り込みました（対象R: '
                    . implode(',', $want) . '）。全レースで確認する場合は --race を外してください。');
        return $filtered;
    }

    private static function allocOverrideFor(string $cond): ?array
    {
        switch ($cond) {
            case 'current':  return ['label' => '現行',     'feature' => false];
            case 'fixed43':  return ['label' => '中4＋3',   'feature' => true, 'fav' => 4, 'long' => 3];
            case 'fixed34':  return ['label' => '強3＋4',   'feature' => true, 'fav' => 3, 'long' => 4];
            case 'variable': return ['label' => '可変配分', 'feature' => true];
            default:         return null;
        }
    }

    /** 画面・ログ表示用の条件名 */
    private static function condLabel(string $cond): string
    {
        $o = self::allocOverrideFor($cond);
        return $o === null ? $cond : $o['label'];
    }

    // ═════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261004-RERUN-COMPARE  「旧指示の削除だけ」の前後比較
    // ═════════════════════════════════════════════════════════════════════
    //   よっしー20261004 2回目のご回答④B：
    //     「『旧指示の削除だけ』の比較と、『配分4条件』の比較を分け、
    //       入力・モデル・設定・変更点を記録してください。」
    //     「結果・着順はAIに入力せず、出力確定後に照合してください。」
    //
    //   【データは一切変更しない】
    //     ・保存済みAI回答（ai_analysis / ai_analysis2）を消さない・上書きしない
    //     ・プロンプトファイル（public/prompt, public/prompt_2nd）を書き換えない
    //     ・統合結果・学習用スナップショット・シャドーログも保存しない
    //     ・加えてトランザクションを張って必ずロールバックする
    //
    //   1レースあたりのAI呼び出しは2回（1st 1回・2nd 1回）。
    //   Cは同じ回答を使い回すので追加の呼び出しは発生しない。
    // ─────────────────────────────────────────────────────────────────────
    private function runRerunCompare(string $date, string $note = ''): void
    {
        $races = DB::table('t_horse_odds_finder_races')
            ->where('date', $date)
            ->orderBy('kaisuu')->orderBy('basho')->orderBy('day')->orderBy('race')
            ->get();

        $races = $this->filterRaces($races);   // @ANCHOR-20261005-RACE-FILTER

        if ($races->isEmpty()) {
            $this->error("{$date} のレースが見つかりません。");
            return;
        }

        $this->warn('');
        $this->warn('【確認】これから ' . $races->count() . ' レース分、新しいお願い文でAIを呼び直します。');
        $this->warn('       AI呼び出しは ' . ($races->count() * 2) . ' 回（1レース2回）です。');
        $this->warn('       保存済みのAI回答・プロンプトファイル・統合結果は一切変更しません。');
        $this->warn('');

        /** @var AiController $controller */
        $controller = app(AiController::class);

        $kinds = ['saved_current', 'rerun_current', 'rerun_variable'];
        $kindLabel = [
            'saved_current'  => 'A 旧指示あり',
            'rerun_current'  => 'B 旧指示削除後',
            'rerun_variable' => 'C 削除後＋可変配分',
        ];

        $lines   = [];
        $lines[] = '========== 旧指示の削除だけの前後比較 ' . $date . ' =========='
                 . ($note !== '' ? '  【' . $note . '】' : '');
        $lines[] = '実行時刻: ' . date('Y-m-d H:i:s') . '（JST）';
        $lines[] = '';
        $lines[] = '【この比較の設定】';
        $lines[] = '  比較の種類 : 「旧指示の削除だけ」の前後（お願い文の変更の効果）';
        $lines[] = '  入力       : 同じ6分前オッズ（AIへ着順・払戻は渡していない）';
        $lines[] = '  モデル     : 1st=claude-haiku-4-5 / 2nd=deepseek-chat';
        $lines[] = '  AI呼び出し : 1レース2回（Aは保存済み回答を再利用。Cは Bの回答を使い回し）';
        $lines[] = '  仕様の版   : ' . AiController::ALLOC_SPEC_VERSION;
        $lines[] = '  統合式の版 : ' . AiController::MERGE_FORMULA_VERSION;
        $lines[] = '  変更点     : A→B はお願い文（旧除外指示の削除）だけ／B→C は配分だけ';
        $lines[] = '  保存        : なし（DB・ファイルとも書き換えていない）';
        $lines[] = '';
        $lines[] = '【条件】';
        $lines[] = '  A 旧指示あり       : 保存済み回答 ＋ 現行の統合（可変配分なし）';
        $lines[] = '  B 旧指示削除後     : 呼び直した回答 ＋ 現行の統合（可変配分なし）';
        $lines[] = '  C 削除後＋可変配分 : 呼び直した回答 ＋ 可変配分';
        $lines[] = '';

        $tot = [];
        foreach ($kinds as $k) {
            $tot[$k] = ['races'=>0,'hit3'=>0,'in5'=>0,'long'=>0,'miss'=>0,
                        'cand'=>0,'noresult'=>0,'undet'=>0,'noscore'=>0];
        }

        // @ANCHOR-20261005-RERUN-PROGRESS  48回のAI呼び出しを無言で待たせない
        $rcTotal = $races->count();
        $rcDone  = 0;
        $rcStart = microtime(true);

        foreach ($races as $raceRow) {
            $label = "{$raceRow->date} {$raceRow->kaisuu}回{$raceRow->basho} {$raceRow->day}日目 {$raceRow->race}R";
            $rcDone++;
            $rcElapsed = (int) round(microtime(true) - $rcStart);
            $this->warn(sprintf('[%d/%d] %s を呼び直し中…（経過 %d分%02d秒）',
                $rcDone, $rcTotal, $label, intdiv($rcElapsed, 60), $rcElapsed % 60));

            [$top3, $top5, $popByNum, $hasResult] = $this->resultContext($raceRow);

            $row = []; $note2 = [];

            // ── A: 保存済み回答＋現行統合（呼び直さない）──────────────
            AiController::$dryRunForceFresh = false;
            AiController::$dryRunFirstText   = null;
            AiController::$dryRunFirstPrompt = null;
            AiController::$dryRunSecondText  = null;
            $row['saved_current'] = $this->dryMerge($controller, $raceRow, 'current', false);

            // ── B: 新しいお願い文で呼び直して現行統合 ──────────────────
            //   ここで 1st / 2nd を1回ずつ呼ぶ。保存は一切しない。
            AiController::$dryRunFirstText   = null;
            AiController::$dryRunFirstPrompt = null;
            AiController::$dryRunSecondText  = null;
            $row['rerun_current'] = $this->dryMerge($controller, $raceRow, 'current', true);

            // ── C: Bの回答を使い回して可変配分で統合（AI呼び出しなし）───
            $row['rerun_variable'] = $this->dryMerge($controller, $raceRow, 'variable', false, true);

            $lines[] = "── {$label}" . ($hasResult ? '' : '（結果未確定：指標は数えません）');
            if ($hasResult) {
                $lines[] = '   1〜3着: ' . implode(' ', array_map(
                    fn($n) => $n . '(' . ($popByNum[$n] ?? '?') . '人気)', $top3));
            }
            foreach ($kinds as $k) {
                $res = $row[$k];
                if ($res === null) { $lines[] = sprintf('   %-18s : 取得できず', $kindLabel[$k]); continue; }
                $tot[$k]['races']++;
                $nums = array_map(fn($h) => (int) ($h['num'] ?? 0), $res['horses']);
                $tot[$k]['cand'] += count($nums);
                if ($res['undetermined']) {
                    $tot[$k]['undet']++;
                    $lines[] = sprintf('   %-18s : 未確定（%s）', $kindLabel[$k], $res['reason']);
                    continue;
                }
                if (!$res['has_allscore']) { $tot[$k]['noscore']++; }
                $mark = [];
                foreach ($nums as $n) {
                    $mk = $n . '(' . ($popByNum[$n] ?? '?') . ')';
                    if ($hasResult && in_array($n, $top3, true))      $mk .= '◎';
                    elseif ($hasResult && in_array($n, $top5, true))  $mk .= '○';
                    $mark[] = $mk;
                }
                $lines[] = sprintf('   %-18s : %d頭  %s', $kindLabel[$k], count($nums), implode(' ', $mark));
                // @ANCHOR-20261006-ANSWER-HASH  BとCが同じ回答かを目で確認できるようにする
                $h = $res['hash'] ?? [];
                $lines[] = sprintf('       回答ハッシュ 1st=%s / 2nd=%s',
                    $h['1st_公開部'] ?? '-', $h['2nd_公開部'] ?? '-');
                if (!$res['has_allscore']) {
                    $w1 = $res['why']['1st'] ?? []; $w2 = $res['why']['2nd'] ?? [];
                    if (empty($w1) && empty($w2)) {
                        // 保存済み回答での再マージ（A）は検証そのものを行わない。
                        $lines[] = '       全頭採点なしの理由 : 保存済み回答に全頭採点JSONが無い'
                                 . '（10月4日の改修より前に保存されたため。比較の限界）';
                    } else {
                        $lines[] = sprintf('       全頭採点なしの理由 1st=%s(完了%s・不一致%s) / 2nd=%s(完了%s・不一致%s)',
                            $w1['判定'] ?? '-', $w1['全頭採点完了'] ?? '-', $w1['不一致'] ?? '-',
                            $w2['判定'] ?? '-', $w2['全頭採点完了'] ?? '-', $w2['不一致'] ?? '-');
                    }
                }
                if (!$hasResult) { $tot[$k]['noresult']++; continue; }
                $m = self::compareMetrics($nums, $top3, $top5, $popByNum);
                $tot[$k]['hit3'] += $m['hit3'];
                $tot[$k]['in5']  += $m['in5'];
                $tot[$k]['long'] += $m['long'];
                $tot[$k]['miss'] += $m['miss'];
            }
            $lines[] = '';
        }

        // 後片付け（静的フラグを必ず戻す）
        AiController::$dryRunNoPersist   = false;
        AiController::$dryRunForceFresh  = false;
        AiController::$dryRunFirstText   = null;
        AiController::$dryRunFirstPrompt = null;
        AiController::$dryRunSecondText  = null;
        AiController::$allocOverride     = null;

        $lines[] = '========== 集計 ==========';
        $lines[] = sprintf('%-18s %6s %8s %8s %8s %8s %8s %6s %8s',
            '条件','レース','3着内完全','5着内捕捉','人気薄捕捉','人気側取逃','平均頭数','未確定','採点なし');
        foreach ($kinds as $k) {
            $t = $tot[$k];
            $n = max(1, $t['races'] - $t['noresult'] - $t['undet']);
            $lines[] = sprintf('%-18s %6d %6d(%4.1f%%) %8d %8d %8d %8.2f %6d %8d',
                $kindLabel[$k], $t['races'], $t['hit3'], 100.0 * $t['hit3'] / $n,
                $t['in5'], $t['long'], $t['miss'],
                $t['races'] > 0 ? $t['cand'] / $t['races'] : 0,
                $t['undet'], $t['noscore']);
        }
        $lines[] = '';
        $lines[] = '※A→B の差が「旧指示の削除だけ」の効果、B→C の差が「可変配分」の効果です。';
        $lines[] = '※「採点なし」は全頭採点（内部用JSON）が無かったレース数です。';
        $lines[] = '  Aは10月4日より前に保存された回答のため、ここが全レースになります（比較の限界）。';
        $lines[] = '※「未確定」は採用できるAI回答が無かった／入力が混在したレースです。捕捉率の母数から外しています。';
        $lines[] = '※結果・着順はAIへ入力していません。出力が確定したあとに照合しています。';
        $lines[] = '※保存済みのAI回答・プロンプトファイル・統合結果は変更していません。';

        $out = implode("\n", $lines) . "\n";
        foreach ($lines as $l) { $this->line($l); }
        $logPath = storage_path('logs/rerun_compare_' . $date . '.log');
        @file_put_contents($logPath, $out, FILE_APPEND);
        $this->info('');
        $this->info('比較結果を書き出しました: ' . $logPath);
    }

    /**
     * 1レース1条件ぶんを試算する。DBもファイルも書き換えない。
     *
     * @param string $cond       配分の条件（current / variable など）
     * @param bool   $forceFresh true ならAIを呼び直す（保存済み回答を使わない）
     * @param bool   $reuseDry   true なら直前に呼び直した回答を使い回す（AI呼び出しなし）
     */
    private function dryMerge(
        AiController $controller, object $raceRow, string $cond,
        bool $forceFresh, bool $reuseDry = false
    ): ?array {
        AiController::$dryRunNoPersist  = true;    // DB・ファイルを書き換えない
        AiController::$dryRunForceFresh = $forceFresh;
        AiController::$allocOverride    = self::allocOverrideFor($cond);

        DB::beginTransaction();
        try {
            $params = [
                'date'   => $raceRow->date,
                'kaisuu' => $raceRow->kaisuu,
                'basho'  => $raceRow->basho,
                'day'    => $raceRow->day,
                'race'   => (string) $raceRow->race,
            ];

            // 呼び直す場合は 1st AI から（新しいお願い文で生成される）
            if ($forceFresh) {
                $req1 = new Request();
                $req1->query->add($params);
                $controller->getHorseOddsFinderAiAnalysis($req1);
            }

            $req2 = new Request();
            // @ANCHOR-20261005-DRY-REUSE-PRIORITY
            //   A（保存済み回答）と C（呼び直した回答の使い回し）は、どちらも
            //   「保存済みの統合結果をそのまま返す」キャッシュ分岐を回避する必要がある。
            //   remerge=1 を付けてキャッシュ早期リターンを止める。
            //   Cでは self::$dryRunSecondText が優先されるので、
            //   保存済み回答で上書きされることはない（AiController側で対応済み）。
            $req2->query->add($params + ($forceFresh ? [] : ['remerge' => '1']));
            $res = $controller->getHorseOddsFinderSecondAiOpinion($req2);
            if ($res->getStatusCode() !== 200) return null;

            $data  = json_decode((string) $res->getContent(), true)['data'] ?? [];
            $audit = $controller->lastMergeAudit();
            $horses = $data['merged_horses'] ?? null;
            if (!is_array($horses)) $horses = [];

            // @ANCHOR-20261006-ANSWER-HASH  どの回答で統合したかを必ず持ち帰る
            $hash = $audit['回答ハッシュ'] ?? [];
            $why  = [];
            foreach (['1st', '2nd'] as $w) {
                $a = $audit['AI'][$w] ?? null;
                if (!is_array($a)) continue;
                $why[$w] = [
                    '判定'       => $a['判定'] ?? '-',
                    '全頭採点完了' => $a['全頭採点完了'] ?? '-',
                    '不一致'     => $a['候補行との不一致数'] ?? 0,
                    'エラー'     => array_slice($a['エラー'] ?? [], 0, 2),
                ];
            }
            return [
                'horses'       => $horses,
                'undetermined' => (($data['status'] ?? '') === 'undetermined'),
                'reason'       => (string) ($data['status_reason'] ?? ''),
                'has_allscore' => (bool) (($audit['全頭採点']['1st'] ?? false) || ($audit['全頭採点']['2nd'] ?? false)),
                'hash'         => $hash,
                'why'          => $why,
            ];
        } catch (\Throwable $e) {
            $this->error("[前後比較] {$raceRow->race}R {$cond} : 例外 " . $e->getMessage());
            return null;
        } finally {
            DB::rollBack();   // 何があっても書き戻さない
            AiController::$allocOverride    = null;
            AiController::$dryRunForceFresh = false;
        }
    }

    /**
     * レース結果（1〜3着・1〜5着）と6分前の人気順を用意する。
     * 着順はAIへ渡さず、統合が終わったあとの照合にだけ使う。
     *
     * @return array{0: array, 1: array, 2: array, 3: bool}
     */
    private function resultContext(object $raceRow): array
    {
        $top3 = []; $top5 = [];
        // @ANCHOR-20261005-RESULT-COLUMNS  テーブルごとに列名が違う（上記①と同じ取り違え）
        //   t_horse_odds_finder_race_results        … basho（2桁コード）/ result
        //   t_horse_odds_finder_race_result_history … basho_code / finishing_position
        foreach (DB::table('t_horse_odds_finder_race_results')
                    ->where('date',   $raceRow->date)
                    ->where('kaisuu', $raceRow->kaisuu)
                    ->where('basho',  $raceRow->basho)
                    ->where('day',    $raceRow->day)
                    ->where('race',   $raceRow->race)
                    ->get(['num', 'result']) as $r) {
            $fp = (int) $r->result;
            if ($fp >= 1 && $fp <= 3) $top3[] = (int) $r->num;
            if ($fp >= 1 && $fp <= 5) $top5[] = (int) $r->num;
        }

        $pairs = [];
        foreach (DB::table('t_horse_odds_finder_odds')
                    ->where('date',   $raceRow->date)
                    ->where('kaisuu', $raceRow->kaisuu)
                    ->where('basho',  $raceRow->basho)
                    ->where('day',    $raceRow->day)
                    ->where('race',   $raceRow->race)
                    ->where('minutes_before_start', 6)
                    ->get(['num', 'odds']) as $o) {
            if (!is_numeric($o->odds) || (float) $o->odds <= 0) continue;
            $pairs[] = ['num' => (int) $o->num, 'odds' => (float) $o->odds];
        }
        usort($pairs, fn($a, $b) => ($a['odds'] <=> $b['odds']) ?: ($a['num'] <=> $b['num']));
        $popByNum = [];
        foreach ($pairs as $i => $p) { $popByNum[$p['num']] = $i + 1; }

        return [$top3, $top5, $popByNum, count($top3) === 3];
    }

    // ═════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261004-STALE-RERUN  入力が変わったレースの再実行（仕様書9.2）
    // ═════════════════════════════════════════════════════════════════════
    //   よっしー20261004 2回目のご回答③A：
    //     「不一致を警告するだけで旧入力と新入力を混ぜて統合することは避けてください。
    //       取消更新などで入力が変わった場合は、仕様書9.2どおり、両AIを同じ最新版の
    //       入力で再実行してください。」
    //
    //   ・対象は「お願い文に書かれた snapshot_id」と「いまの6分前オッズから算出した
    //     snapshot_id」が食い違うレースだけ。合っているレースには触らない。
    //   ・差し替える前に、旧回答（1st・2nd）とお願い文の控えを
    //     storage/logs/stale_backup_YYYY-MM-DD.json に書き出す。
    //   ・対象を一覧表示し、確認を取ってから実行する。
    // ─────────────────────────────────────────────────────────────────────
    private function runStaleRerun(string $date): void
    {
        $races = DB::table('t_horse_odds_finder_races')
            ->where('date', $date)
            ->orderBy('kaisuu')->orderBy('basho')->orderBy('day')->orderBy('race')
            ->get();
        if ($races->isEmpty()) {
            $this->error("{$date} のレースが見つかりません。");
            return;
        }

        // ── 対象レースの洗い出し（ここでは何も書き換えない）──────────────
        $targets = [];
        foreach ($races as $raceRow) {
            $label = "{$raceRow->kaisuu}回{$raceRow->basho} {$raceRow->day}日目 {$raceRow->race}R";
            $path  = public_path("prompt/prompt_{$date}_{$raceRow->kaisuu}_{$raceRow->basho}_{$raceRow->day}_{$raceRow->race}.data");
            if (!file_exists($path)) continue;                   // お願い文が無い＝未実行。対象外
            $txt = (string) @file_get_contents($path);
            if (!preg_match('/snapshot_id:\s*([0-9a-f]{16})/u', $txt, $m)) continue;  // 旧形式は対象外
            $promptId = $m[1];

            $nowId = AiController::snapshotIdForRace(
                $date, $raceRow->kaisuu, $raceRow->basho, $raceRow->day, $raceRow->race
            );
            if ($nowId === null || $nowId === $promptId) continue; // 一致または算出不能は対象外

            $targets[] = ['row' => $raceRow, 'label' => $label,
                          'prompt_id' => $promptId, 'now_id' => $nowId, 'path' => $path];
        }

        if (empty($targets)) {
            $this->info("{$date} に、6分前オッズが変わったレースはありません（再実行の対象なし）。");
            return;
        }

        $this->warn('');
        $this->warn('【仕様書9.2】6分前オッズがお願い文の作成後に変わったレースが ' . count($targets) . ' 件あります。');
        $this->warn('両AIを最新の入力で再実行します。AI呼び出しは ' . (count($targets) * 2) . ' 回です。');
        $this->warn('');
        foreach ($targets as $t) {
            $this->line(sprintf('  %-28s お願い文: %s → いま: %s',
                $t['label'], $t['prompt_id'], $t['now_id']));
        }
        $this->warn('');
        $this->warn('旧回答とお願い文の控えを storage/logs/stale_backup_' . $date . '.json に残してから差し替えます。');
        if (!$this->confirm('この ' . count($targets) . ' 件を再実行しますか？', false)) {
            $this->info('中止しました。何も変更していません。');
            return;
        }

        // ── 控えを先に書き出す（ここが終わるまで何も消さない）─────────────
        $backup = ['date' => $date, 'reason' => '6分前オッズの更新（仕様書9.2の再実行）',
                   'backed_up_at' => date('Y-m-d H:i:s') . ' JST', 'races' => []];
        foreach ($targets as $t) {
            $r = $t['row'];
            $first = DB::table('t_horse_odds_finder_ai_analysis')
                ->where('date', $date)->where('kaisuu', $r->kaisuu)
                ->where('basho_code', $r->basho)->where('day', $r->day)->where('race', $r->race)
                ->first();
            $second = DB::table('t_horse_odds_finder_ai_analysis2')
                ->where('date', $date)->where('kaisuu', $r->kaisuu)
                ->where('basho_code', $r->basho)->where('day', $r->day)->where('race', $r->race)
                ->first();
            $second2nd = public_path("prompt_2nd/prompt_2nd_{$date}_{$r->kaisuu}_{$r->basho}_{$r->day}_{$r->race}.data");
            $backup['races'][] = [
                'label'            => $t['label'],
                'prompt_snapshot'  => $t['prompt_id'],
                'current_snapshot' => $t['now_id'],
                'prompt_1st'       => (string) @file_get_contents($t['path']),
                'prompt_2nd'       => file_exists($second2nd) ? (string) @file_get_contents($second2nd) : null,
                'analysis_1st'     => $first->analysis_text  ?? null,
                'analysis_2nd'     => $second->analysis_text ?? null,
            ];
        }
        $backupPath = storage_path('logs/stale_backup_' . $date . '.json');
        if (@file_put_contents($backupPath, json_encode($backup,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) {
            $this->error('控えを書き出せませんでした。中止します（何も変更していません）: ' . $backupPath);
            return;
        }
        $this->info('控えを書き出しました: ' . $backupPath);

        /** @var AiController $controller */
        $controller = app(AiController::class);
        $done = 0;

        foreach ($targets as $t) {
            $r = $t['row'];
            $this->info("[再実行] {$t['label']} : 開始");
            try {
                // 旧回答を外す（控えは上で取得済み）。お願い文は 1st AI が作り直す。
                DB::table('t_horse_odds_finder_ai_analysis')
                    ->where('date', $date)->where('kaisuu', $r->kaisuu)
                    ->where('basho_code', $r->basho)->where('day', $r->day)->where('race', $r->race)
                    ->delete();
                DB::table('t_horse_odds_finder_ai_analysis2')
                    ->where('date', $date)->where('kaisuu', $r->kaisuu)
                    ->where('basho_code', $r->basho)->where('day', $r->day)->where('race', $r->race)
                    ->delete();

                $params = ['date' => $date, 'kaisuu' => $r->kaisuu, 'basho' => $r->basho,
                           'day' => $r->day, 'race' => (string) $r->race];
                $q1 = new Request(); $q1->query->add($params);
                $controller->getHorseOddsFinderAiAnalysis($q1);
                $q2 = new Request(); $q2->query->add($params);
                $res = $controller->getHorseOddsFinderSecondAiOpinion($q2);

                if ($res->getStatusCode() === 200) {
                    $this->info("[再実行] {$t['label']} : 成功");
                    $done++;
                } else {
                    $this->error("[再実行] {$t['label']} : 失敗 status=" . $res->getStatusCode());
                }
            } catch (\Throwable $e) {
                $this->error("[再実行] {$t['label']} : 例外 " . $e->getMessage());
            }
        }

        $this->info('');
        $this->info("再実行が終わりました（成功 {$done} / 対象 " . count($targets) . " 件）。");
        $this->info('旧回答の控え: ' . $backupPath);
    }

    // ═════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261004-LIST-DATES  残っているデータの日付を一覧する
    // ═════════════════════════════════════════════════════════════════════
    //   読み取りだけ。DBもファイルも一切変更しない。
    //
    //   過去日の比較（--compare / --compare-rerun）と再実行（--rerun-stale）は、
    //   t_horse_odds_finder_races にその日のレース行が残っていないと動かない。
    //   1st・2nd のどちらのエンドポイントも、このテーブルから
    //   レース名・コース・頭数などを引くためである（無ければ「レースが見つかりません」）。
    // ─────────────────────────────────────────────────────────────────────
    // ═════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261006-ML-STATUS  断層パターン学習の蓄積状況
    // ═════════════════════════════════════════════════════════════════════
    //   よっしー20261006のご指摘⑤・第6節：
    //     「特徴量ありの件数、正解ラベルまで揃った件数、欠損件数を分けて提示する」
    //     「残っている特徴量・着順履歴から、過去分を正しく補完できる範囲を確認する」
    //     「SQLが通ったことに加えて、実際のM1〜M4が保存され、実着順と一致していることを確認する」
    //
    //   読み取りだけ。DBもファイルも変更しない。
    // ─────────────────────────────────────────────────────────────────────
    private function runMlStatus(): void
    {
        $this->line('');
        $this->line('========== 断層パターン学習の蓄積状況 ==========');
        $this->line('集計時刻: ' . date('Y-m-d H:i:s') . '（JST）');
        $this->line('※読み取りだけです。何も変更していません。');
        $this->line('');

        $rows = DB::table('t_horse_odds_finder_ml_snapshot')
            ->selectRaw("
                COUNT(*)                                                   AS total_rows,
                SUM(features IS NOT NULL AND features <> '')               AS has_features,
                SUM(result_m1 IS NOT NULL)                                 AS has_m1,
                SUM(result_m2 IS NOT NULL)                                 AS has_m2,
                SUM(result_m3 IS NOT NULL)                                 AS has_m3,
                SUM(result_m4_json IS NOT NULL
                    AND result_m4_json <> '' AND result_m4_json <> '[]')   AS has_m4,
                SUM(result_label_status = 'filled')                        AS st_filled,
                SUM(result_label_status = 'excluded')                      AS st_excluded,
                SUM(result_label_status IS NULL)                           AS st_null,
                MIN(date)                                                  AS d_min,
                MAX(date)                                                  AS d_max,
                COUNT(DISTINCT date)                                       AS d_days
            ")->first();

        $this->line(sprintf('  スナップショット総数      : %d 件（%s 〜 %s・%d日分）',
            (int) $rows->total_rows, (string) $rows->d_min, (string) $rows->d_max, (int) $rows->d_days));
        $this->line(sprintf('  特徴量あり                : %d 件', (int) $rows->has_features));
        $this->line(sprintf('  正解ラベルまで揃った件数  : %d 件（status=filled）', (int) $rows->st_filled));
        $this->line(sprintf('    内訳 M1=%d / M2=%d / M3=%d / M4=%d',
            (int) $rows->has_m1, (int) $rows->has_m2, (int) $rows->has_m3, (int) $rows->has_m4));
        $this->line(sprintf('  結果未確定で除外(excluded): %d 件', (int) $rows->st_excluded));
        $this->line(sprintf('  未処理(status NULL)       : %d 件', (int) $rows->st_null));
        $this->line(sprintf('  ラベル欠損                : %d 件',
            (int) $rows->total_rows - (int) $rows->st_filled - (int) $rows->st_excluded));
        $this->line('');

        // 日付別
        $this->line('── 日付別（特徴量 / ラベル確定 / 欠損）');
        $byDate = DB::table('t_horse_odds_finder_ml_snapshot')
            ->selectRaw("
                date,
                COUNT(*) AS n,
                SUM(features IS NOT NULL AND features <> '') AS f,
                SUM(result_label_status = 'filled')          AS l,
                SUM(result_label_status = 'excluded')        AS e
            ")->groupBy('date')->orderByDesc('date')->limit(30)->get();
        foreach ($byDate as $d) {
            $miss = (int) $d->n - (int) $d->l - (int) $d->e;
            $this->line(sprintf('   %s  件数%3d / 特徴量%3d / ラベル%3d / 除外%2d / 欠損%2d',
                (string) $d->date, (int) $d->n, (int) $d->f, (int) $d->l, (int) $d->e, $miss));
        }
        $this->line('');

        // 補完できる範囲（着順履歴が残っている日だけ補完できる）
        $this->line('── 補完できる範囲（ラベル未確定のレースのうち、着順履歴が残っているもの）');
        $pending = DB::table('t_horse_odds_finder_ml_snapshot')
            ->whereNull('result_m1')
            ->orderBy('date')->get(['date', 'kaisuu', 'basho_code', 'day', 'race']);
        if ($pending->isEmpty()) {
            $this->line('   ラベル未確定のレースはありません。');
        } else {
            $ok = 0; $ng = 0;
            foreach ($pending as $pg) {
                $cnt = DB::table('t_horse_odds_finder_race_result_history')
                    ->where('date', $pg->date)->where('kaisuu', $pg->kaisuu)
                    ->where('basho_code', $pg->basho_code)->where('day', $pg->day)
                    ->where('race', $pg->race)
                    ->whereBetween('finishing_position', [1, 99])->count();
                if ($cnt >= 5) { $ok++; } else { $ng++; }
                $this->line(sprintf('   %s %s回%s %s日目 %sR  着順履歴%2d頭 → %s',
                    (string) $pg->date, (string) $pg->kaisuu, (string) $pg->basho_code,
                    (string) $pg->day, (string) $pg->race, $cnt,
                    $cnt >= 5 ? '補完できる' : '補完できない（有効着順が5頭未満）'));
            }
            $this->line('');
            $this->line(sprintf('   補完できる: %d 件 / 補完できない: %d 件', $ok, $ng));
            $this->line('   補完コマンド: php artisan keiba:updateMlResultLabels');
        }
        $this->line('');

        // 実着順との突き合わせ（保存済みM1が着順履歴と一致するか抜き取り確認）
        $this->line('── 保存済みラベルと実着順の突き合わせ（直近10レース）');
        $check = DB::table('t_horse_odds_finder_ml_snapshot')
            ->whereNotNull('result_m1')
            ->orderByDesc('date')->orderByDesc('race')->limit(10)
            ->get(['date', 'kaisuu', 'basho_code', 'day', 'race', 'result_m1', 'result_m4_json']);
        $agree = 0; $disagree = 0;
        foreach ($check as $ck) {
            $hist = DB::table('t_horse_odds_finder_race_result_history')
                ->where('date', $ck->date)->where('kaisuu', $ck->kaisuu)
                ->where('basho_code', $ck->basho_code)->where('day', $ck->day)
                ->where('race', $ck->race)
                ->whereBetween('finishing_position', [1, 5])
                ->orderBy('finishing_position')
                ->get(['num', 'finishing_position']);
            // @ANCHOR-20261006-ML-STATUS
            //   result_m4_json の実構造は {"馬番": 0 or 1} のマップ。
            //   1 が立っている馬番の集合が、着順履歴の5着内の馬番と一致するかを見る。
            $m4  = json_decode((string) $ck->result_m4_json, true);
            $m4n = is_array($m4) ? count($m4) : 0;
            $m4Top = [];
            if (is_array($m4)) {
                foreach ($m4 as $m4Num => $m4Lbl) {
                    if ((int) $m4Lbl === 1) $m4Top[] = (int) $m4Num;
                }
            }
            $histTop = $hist->pluck('num')->map('intval')->all();
            sort($m4Top); sort($histTop);
            $hist5 = count($histTop);
            // 馬番の集合まで一致しているかを見る（頭数が合うだけでは足りない）
            $okRow = ($m4n > 0 && $m4Top === $histTop);
            $okRow ? $agree++ : $disagree++;
            $this->line(sprintf('   %s %2sR  M1=%s / M4全%2d頭・5着内[%s] / 着順履歴5着内[%s] → %s',
                (string) $ck->date, (string) $ck->race, (string) $ck->result_m1,
                $m4n, implode(',', $m4Top), implode(',', $histTop),
                $okRow ? '一致' : '不一致（要確認）'));
        }
        $this->line('');
        $this->line(sprintf('   一致: %d 件 / 要確認: %d 件', $agree, $disagree));
        $this->line('');
        $this->line('【方針】2026年内は蓄積のみ。2027年からの予想への反映は、');
        $this->line('        必要件数と評価条件を満たしたうえで別途判断します。');
        $this->line('        年が変わっただけで自動的に有効化することはありません。');
        $this->line('');
    }

    private function runListDates(): void
    {
        $this->info('');
        $this->info('========== 残っているデータの日付 ==========');
        $this->info('※読み取りだけです。何も変更していません。');
        $this->info('');

        // ── date 列を持つテーブルを全部洗い出し、保持期間を一覧する ────────
        //   どのデータがいつまで残っているかが分かれば、
        //   過去日のどこまで再計算できるかが確定する。
        $labels = [
            't_horse_odds_finder_races'           => 'レース一覧（これが無いと比較・再実行ができません）',
            't_horse_odds_finder_odds'            => 'オッズ時系列（これが無いと資格・断層・人気順を再計算できません）',
            't_horse_odds_finder_horses'          => '出走馬',
            't_horse_odds_finder_ai_analysis'     => '1st AI の回答',
            't_horse_odds_finder_ai_analysis2'    => '2nd AI の回答',
            't_horse_odds_finder_ai_merge_result' => '統合結果（当時選ばれた候補）',
            't_horse_odds_finder_race_results'    => 'レース結果（着順）',
            't_horse_odds_finder_race_result_history' => 'レース結果の履歴',
            't_horse_odds_finder_race_result_payout'  => '払戻',
        ];

        $tableNames = [];
        try {
            foreach (DB::select('SHOW TABLES') as $row) {
                $name = (string) array_values((array) $row)[0];
                if (strpos($name, 't_horse_odds_finder') === 0) $tableNames[] = $name;
            }
        } catch (\Throwable $e) {
            $this->line('テーブル一覧を取得できませんでした: ' . $e->getMessage());
            $tableNames = array_keys($labels);
        }
        sort($tableNames);

        $this->line(sprintf('%-46s %10s %12s %12s %8s', 'テーブル', '行数', '最も古い日', '最も新しい日', '日数'));
        $this->line(str_repeat('-', 94));
        foreach ($tableNames as $table) {
            try {
                $hasDate = false;
                foreach (DB::select("SHOW COLUMNS FROM `{$table}`") as $col) {
                    if ((string) ($col->Field ?? '') === 'date') { $hasDate = true; break; }
                }
                $cnt = (int) DB::table($table)->count();
                if (!$hasDate) {
                    $this->line(sprintf('%-46s %10d %12s %12s %8s', $table, $cnt, '-', '-', '-'));
                    continue;
                }
                $agg = DB::table($table)
                    ->selectRaw('MIN(date) AS mn, MAX(date) AS mx, COUNT(DISTINCT date) AS d')
                    ->first();
                $this->line(sprintf('%-46s %10d %12s %12s %8d',
                    $table, $cnt, (string) ($agg->mn ?? '-'), (string) ($agg->mx ?? '-'), (int) ($agg->d ?? 0)));
            } catch (\Throwable $e) {
                $this->line(sprintf('%-46s %10s', $table, '取得失敗: ' . $e->getMessage()));
            }
        }
        $this->line('');

        // ── 主要テーブルの日付内訳 ───────────────────────────────────────
        foreach ($labels as $table => $label) {
            if (!in_array($table, $tableNames, true)) continue;
            $this->line("── {$table}（{$label}）");
            try {
                $rows = DB::table($table)
                    ->selectRaw('date, COUNT(*) AS cnt')
                    ->groupBy('date')
                    ->orderByDesc('date')
                    ->limit(20)
                    ->get();
                if ($rows->isEmpty()) {
                    $this->line('   （行がありません）');
                } else {
                    foreach ($rows as $r) {
                        $this->line(sprintf('   %s  %d件', $r->date, (int) $r->cnt));
                    }
                    $this->line('   （新しい順に最大20日分）');
                }
            } catch (\Throwable $e) {
                $this->line('   取得できませんでした: ' . $e->getMessage());
            }
            $this->line('');
        }

        // プロンプトファイルの日付分布
        foreach ([['prompt', 'prompt_'], ['prompt_2nd', 'prompt_2nd_']] as [$dir, $prefix]) {
            $this->line("── public/{$dir}/ のプロンプトファイル");
            $path = public_path($dir);
            if (!is_dir($path)) { $this->line('   （フォルダがありません）'); $this->line(''); continue; }
            $byDate = [];
            foreach ((array) glob($path . '/' . $prefix . '*.data') as $f) {
                if (preg_match('/(\d{4}-\d{2}-\d{2})/', basename((string) $f), $m)) {
                    $byDate[$m[1]] = ($byDate[$m[1]] ?? 0) + 1;
                }
            }
            if (empty($byDate)) {
                $this->line('   （ファイルがありません）');
            } else {
                krsort($byDate);
                $n = 0;
                foreach ($byDate as $d => $c) {
                    $this->line(sprintf('   %s  %d件', $d, $c));
                    if (++$n >= 20) { $this->line('   （新しい順に20日分まで）'); break; }
                }
            }
            $this->line('');
        }

        $this->info('【読み方】');
        $this->info('  ・比較と再実行ができるのは、races と odds の両方が残っている日付だけです。');
        $this->info('    races が無いとレースを引けず、odds が無いと資格・断層・人気順を再計算できません。');
        $this->info('  ・AI回答とプロンプトだけが残っている日は、当時の文面と採点は読めますが、再計算はできません。');
        $this->info('');
    }

    // ═════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261004-HISTORY-COMPARE  洗い替え済みの過去日を採点する
    // ═════════════════════════════════════════════════════════════════════
    //   毎週土曜5:50の洗い替え（keiba:deleteKeibaTableRecords）で
    //   races / odds / horses / race_results は消える。これは仕様どおりの運用。
    //   そのため過去日の「呼び直し」や「配分の再計算」はできない。
    //
    //   しかし次は残っている。
    //     ・ai_merge_result        … 当時選ばれた候補（人気順つき）
    //     ・race_result_history    … 着順の履歴（2023年から）
    //     ・race_result_payout     … 払戻
    //     ・public/prompt(_2nd)    … 当時のお願い文そのもの
    //   これで「当時の候補が結果をどれだけ捕まえていたか」は採点できる。
    //   仕様書10.1の指標のうち、修正前の側の数値がここから出る。
    //
    //   読み取りだけ。DBもファイルも一切変更しない。
    // ─────────────────────────────────────────────────────────────────────
    private function runHistoryCompare(string $date): void
    {
        // 着順の列名はこのテーブルで揺れがあるため、実際の列から判定する
        $posCol = null;
        try {
            $cols = [];
            foreach (DB::select('SHOW COLUMNS FROM `t_horse_odds_finder_race_result_history`') as $c) {
                $cols[] = (string) ($c->Field ?? '');
            }
            foreach (['finishing_position', 'rank', 'ranking', 'chakujun', 'order_of_finish', 'result_rank'] as $cand) {
                if (in_array($cand, $cols, true)) { $posCol = $cand; break; }
            }
            if ($posCol === null) {
                $this->error('race_result_history に着順の列が見つかりません。実在する列: ' . implode(', ', $cols));
                return;
            }
        } catch (\Throwable $e) {
            $this->error('race_result_history の列を取得できませんでした: ' . $e->getMessage());
            return;
        }

        $merges = DB::table('t_horse_odds_finder_ai_merge_result')
            ->where('date', $date)
            ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
            ->get();
        if ($merges->isEmpty()) {
            $this->error("{$date} の統合結果（ai_merge_result）がありません。採点できません。");
            return;
        }

        $lines   = [];
        $lines[] = '========== 当時の候補の採点 ' . $date . ' ==========';
        $lines[] = '実行時刻: ' . date('Y-m-d H:i:s') . '（JST）';
        $lines[] = '';
        $lines[] = '【この採点の前提】';
        $lines[] = '  ・毎週土曜5:50の洗い替えで、この日のオッズ・レース一覧・出走馬は消えています（仕様どおりの運用）。';
        $lines[] = '  ・そのため「お願い文を変えて呼び直した結果」は出せません。出せるのは当時の結果だけです。';
        $lines[] = '  ・候補は ai_merge_result（当時の統合結果）、着順は race_result_history から読みました。';
        $lines[] = '  ・人気順は race_result_history の popularity_rank（確定人気）で全頭そろえました。';
        $lines[] = '    6分前人気はオッズが消えているため再現できません。候補側だけ6分前人気を使うと';
        $lines[] = '    同じ人気順の馬が2頭出てしまい、指標が信用できなくなるためです。';
        $lines[] = '    統合結果に記録された6分前人気は、明細の括弧内に併記します（例: 5(確定3/当時1)）。';
        $lines[] = '  ・着順の列: ' . $posCol;
        $lines[] = '  ・1st・2ndの保存済み回答から公開候補の馬番を数え、最終候補と突き合わせています。';
        $lines[] = '    AIが出した馬が最終候補に残っていなければ、PHP側（統合・フィルター）で落ちたことになります。';
        $lines[] = '  ・AI呼び出しは0回。DB・ファイルは一切変更していません。';
        $lines[] = '';
        $lines[] = '【指標の定義】';
        $lines[] = '  3着内完全捕捉 : 1〜3着の3頭すべてが候補に入っていたレース数';
        $lines[] = '  5着内捕捉数   : 候補のうち5着以内に来た頭数の合計';
        $lines[] = '  人気薄捕捉数  : 7番人気以下で3着以内に来た馬を候補に入れていた数';
        $lines[] = '  人気側取り逃し: 1〜6番人気で3着以内に来た馬を候補に入れていなかった数';
        $lines[] = '';

        $tot = ['races'=>0,'hit3'=>0,'in5'=>0,'long'=>0,'miss'=>0,'cand'=>0,'noresult'=>0,'undet'=>0,
                'runners'=>0,'ctarget'=>0,'short'=>0,'shortsum'=>0,
                // @ANCHOR-20261004-DROP-TRACE  AIが出した候補が統合でどれだけ落ちたか
                'ai1'=>0,'ai2'=>0,'aiuniq'=>0,'dropped'=>0,'droppedraces'=>0,'addedraces'=>0,
                // @ANCHOR-20261004-DROP-TRACE  落とした馬のうち入着していた数（これが本題）
                'droplost'=>0,'droplostraces'=>0,'droplost5'=>0];

        foreach ($merges as $mg) {
            $label = "{$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R";
            $decoded = json_decode((string) ($mg->merge_result ?? ''), true);
            $status  = is_array($decoded) ? (string) ($decoded['status'] ?? '') : '';
            $horses  = (is_array($decoded) && isset($decoded['merged_horses']) && is_array($decoded['merged_horses']))
                ? $decoded['merged_horses'] : [];

            $tot['races']++;
            if ($status === 'undetermined') {
                $tot['undet']++;
                $lines[] = sprintf('── %-24s 未確定（%s）', $label, (string) ($decoded['status_reason'] ?? ''));
                continue;
            }

            $nums = []; $popAtPrompt = [];
            foreach ($horses as $h) {
                if (!is_array($h) || !isset($h['num'])) continue;
                $n = (int) $h['num'];
                $nums[] = $n;
                if (isset($h['popularity']) && is_numeric($h['popularity'])) {
                    $popAtPrompt[$n] = (int) $h['popularity'];   // 当時の6分前人気（併記用）
                }
            }
            $tot['cand'] += count($nums);

            // @ANCHOR-20261004-DROP-TRACE  AIが出した候補行の馬番（保存済み回答から）
            $ai1 = []; $ai2 = [];
            try {
                $r1 = DB::table('t_horse_odds_finder_ai_analysis')
                    ->where('date', $date)->where('kaisuu', $mg->kaisuu)
                    ->where('basho_code', $mg->basho_code)->where('day', $mg->day)->where('race', $mg->race)
                    ->value('analysis_text');
                if ($r1 !== null) $ai1 = AiController::publicCandidateNums((string) $r1);
                $r2 = DB::table('t_horse_odds_finder_ai_analysis2')
                    ->where('date', $date)->where('kaisuu', $mg->kaisuu)
                    ->where('basho_code', $mg->basho_code)->where('day', $mg->day)->where('race', $mg->race)
                    ->value('analysis_text');
                if ($r2 !== null) $ai2 = AiController::publicCandidateNums((string) $r2);
            } catch (\Throwable $e) {
                // 回答が読めなくても採点は続ける
            }
            $aiUniq = array_values(array_unique(array_merge($ai1, $ai2)));

            // どの実装で作られた統合結果かを示す（候補数の読み方が変わるため）
            $madeBy = isset($decoded['spec_version'])
                ? ('新実装 ' . (string) $decoded['spec_version'])
                : (isset($decoded['merit_cap_check'])
                    ? '9月末の実装（版の記録なし）'
                    : '当時の実装（版・検証記録なし）');

            // 着順と人気順（候補に無い馬の人気順もここから補う）
            $top3 = []; $top5 = []; $popByNum = []; $runners = 0;
            try {
                $rows = DB::table('t_horse_odds_finder_race_result_history')
                    ->where('date',       $date)
                    ->where('kaisuu',     $mg->kaisuu)
                    ->where('basho_code', $mg->basho_code)
                    ->where('day',        $mg->day)
                    ->where('race',       $mg->race)
                    ->get(['num', 'popularity_rank', $posCol]);
            } catch (\Throwable $e) {
                $rows = collect([]);
            }
            foreach ($rows as $r) {
                $n  = (int) $r->num;
                $fp = (int) ($r->{$posCol} ?? 0);
                $runners++;
                if ($fp >= 1 && $fp <= 3) $top3[] = $n;
                if ($fp >= 1 && $fp <= 5) $top5[] = $n;
                // 人気順は確定人気で全頭そろえる（出所を混ぜない）
                if (is_numeric($r->popularity_rank ?? null)) {
                    $popByNum[$n] = (int) $r->popularity_rank;
                }
            }
            // 確定人気が取れない馬は、当時の6分前人気で補う（無ければ不明のまま）
            foreach ($popAtPrompt as $n => $p) {
                if (!isset($popByNum[$n])) $popByNum[$n] = $p;
            }
            $hasResult = (count($top3) === 3);

            // 出走頭数（着順履歴の行数）から当時の目標数Cを割り出す
            $cTarget = ($runners <= 8) ? min($runners, 4)
                     : (($runners <= 13) ? 5 : (($runners <= 15) ? 6 : 7));
            if ($runners > 0) {
                $tot['runners'] += $runners;
                $tot['ctarget'] += $cTarget;
                if (count($nums) < $cTarget) {
                    $tot['short']++;
                    $tot['shortsum'] += ($cTarget - count($nums));
                }
            }

            $mark = [];
            foreach ($nums as $n) {
                $fin = $popByNum[$n]  ?? '?';
                $at  = $popAtPrompt[$n] ?? null;
                $mk  = $n . '(確定' . $fin . ($at !== null && $at !== $fin ? '/当時' . $at : '') . ')';
                if ($hasResult && in_array($n, $top3, true))     $mk .= '◎';
                elseif ($hasResult && in_array($n, $top5, true)) $mk .= '○';
                $mark[] = $mk;
            }
            $lines[] = sprintf('── %-22s %d頭（%d頭立て・目標%d頭%s）  %s',
                $label, count($nums), $runners, $cTarget,
                (count($nums) < $cTarget ? '・不足' . ($cTarget - count($nums)) . '頭' : ''),
                implode(' ', $mark));
            $lines[] = '   作成: ' . $madeBy;
            $dropped = array_values(array_diff($aiUniq, $nums));
            $added   = array_values(array_diff($nums, $aiUniq));
            $lines[] = sprintf('   AI出力: 1st %d頭 / 2nd %d頭 / 重複除き %d頭 → 最終 %d頭%s%s',
                count($ai1), count($ai2), count($aiUniq), count($nums),
                !empty($dropped) ? '　PHP側で落ちた馬番: ' . implode(',', $dropped) : '',
                !empty($added)   ? '　AI出力に無い馬番: ' . implode(',', $added) : '');
            // ここが本題：PHPが落とした馬のうち、実際に入着していた馬
            $lostIn3 = array_values(array_intersect($dropped, $top3));
            $lostIn5 = array_values(array_intersect($dropped, $top5));
            if (!empty($lostIn3)) {
                $lines[] = '   ★AIが出していたのにPHPが落とし、3着以内に来た馬番: '
                         . implode(' ', array_map(
                             fn($n) => $n . '(確定' . ($popByNum[$n] ?? '?') . '人気)', $lostIn3));
            }
            $tot['ai1']    += count($ai1);
            $tot['ai2']    += count($ai2);
            $tot['aiuniq'] += count($aiUniq);
            if (!empty($dropped)) { $tot['dropped'] += count($dropped); $tot['droppedraces']++; }
            if (!empty($lostIn3)) { $tot['droplost'] += count($lostIn3); $tot['droplostraces']++; }
            if (!empty($lostIn5)) { $tot['droplost5'] += count($lostIn5); }
            if (!empty($added))   { $tot['addedraces']++; }
            if (!$hasResult) {
                $tot['noresult']++;
                $lines[] = '   （着順が揃っていないため指標は数えません）';
                continue;
            }
            $lines[] = '   1〜3着: ' . implode(' ', array_map(
                fn($n) => $n . '(確定' . ($popByNum[$n] ?? '?') . '人気)'
                        . (in_array($n, $nums, true) ? '＝候補' : '＝候補外'), $top3));

            $m = self::compareMetrics($nums, $top3, $top5, $popByNum);
            $tot['hit3'] += $m['hit3'];
            $tot['in5']  += $m['in5'];
            $tot['long'] += $m['long'];
            $tot['miss'] += $m['miss'];
        }

        $n = max(1, $tot['races'] - $tot['noresult'] - $tot['undet']);
        $lines[] = '';
        $lines[] = '========== 集計 ==========';
        $lines[] = sprintf('レース数        : %d（指標の母数 %d／着順なし %d／未確定 %d）',
            $tot['races'], $n, $tot['noresult'], $tot['undet']);
        $lines[] = sprintf('3着内完全捕捉   : %d件（%.1f%%）', $tot['hit3'], 100.0 * $tot['hit3'] / $n);
        $lines[] = sprintf('5着内捕捉数     : %d頭', $tot['in5']);
        $lines[] = sprintf('人気薄捕捉数    : %d頭', $tot['long']);
        $lines[] = sprintf('人気側取り逃し  : %d頭', $tot['miss']);
        $lines[] = sprintf('平均候補数      : %.2f頭（当時の目標数Cの平均 %.2f頭）',
            $tot['races'] > 0 ? $tot['cand'] / $tot['races'] : 0,
            $tot['races'] > 0 ? $tot['ctarget'] / $tot['races'] : 0);
        $lines[] = sprintf('目標数に届かず  : %d件（不足の合計 %d頭）', $tot['short'], $tot['shortsum']);
        $lines[] = sprintf('平均出走頭数    : %.1f頭', $tot['races'] > 0 ? $tot['runners'] / $tot['races'] : 0);
        $lines[] = '';
        $lines[] = '―― どこで候補が減ったか ――';
        $lines[] = sprintf('AI出力（1st）   : 合計 %d頭（平均 %.2f頭）', $tot['ai1'], $tot['races'] > 0 ? $tot['ai1'] / $tot['races'] : 0);
        $lines[] = sprintf('AI出力（2nd）   : 合計 %d頭（平均 %.2f頭）', $tot['ai2'], $tot['races'] > 0 ? $tot['ai2'] / $tot['races'] : 0);
        $lines[] = sprintf('AI出力（重複除き）: 合計 %d頭（平均 %.2f頭）', $tot['aiuniq'], $tot['races'] > 0 ? $tot['aiuniq'] / $tot['races'] : 0);
        $lines[] = sprintf('最終候補        : 合計 %d頭（平均 %.2f頭）', $tot['cand'], $tot['races'] > 0 ? $tot['cand'] / $tot['races'] : 0);
        $lines[] = sprintf('PHP側で落ちた   : 合計 %d頭（%d件のレースで発生）', $tot['dropped'], $tot['droppedraces']);
        $lines[] = sprintf('AI出力に無い馬  : %d件のレースで発生（0件が正常）', $tot['addedraces']);
        $lines[] = '';
        $lines[] = '―― 取り逃しの正体 ――';
        $lines[] = sprintf('★AIが出していたのにPHPが落とし、3着以内に来た馬 : %d頭（%d件のレースで発生）',
            $tot['droplost'], $tot['droplostraces']);
        $lines[] = sprintf('  （同じく5着以内に来た馬 : %d頭）', $tot['droplost5']);
        $lines[] = '  この頭数が、プロンプトの文面ではなくPHP側の処理で取り逃した入着馬です。';
        $lines[] = '';
        $lines[] = '※人気順は確定人気（race_result_history.popularity_rank）でそろえています。';
        $lines[] = '  6分前人気はオッズが消えているため再現できません。候補の括弧内に「当時」として併記した値が';
        $lines[] = '  統合結果に残っていた6分前人気です。確定人気と食い違う馬は直前に売れた／売れなかった馬です。';
        $lines[] = '※「目標数に届かず」は、当時の候補数が目標数Cを下回っていたレース数です。';
        $lines[] = '  出走頭数は着順履歴の行数から数え、Cは8頭以下4／9〜13頭5／14〜15頭6／16頭以上7で算出しました。';
        $lines[] = '※「作成」は、その統合結果がどの実装で書かれたかです。版の記録が無いものは9月末より前の実装です。';
        $lines[] = '※「PHP側で落ちた」は、AIが候補行として出したのに最終候補に残らなかった馬です。';
        $lines[] = '  プロンプトの文面ではなく、PHP側の処理（ハード除外・人気帯上限・目標数の絞り込み）で消えた分です。';
        $lines[] = '※「AI出力に無い馬」は、AIが出していない馬が最終候補に入っていた件数です。0件が正常です。';
        $lines[] = '※これは「修正前」の数値です。お願い文を変えた後の数値は、オッズが残っている日でしか出せません。';
        $lines[] = '※結果・着順はAIへ入力していません。当時の出力に対してあとから照合しただけです。';

        $out = implode("\n", $lines) . "\n";
        foreach ($lines as $l) { $this->line($l); }
        $logPath = storage_path('logs/history_compare_' . $date . '.log');
        @file_put_contents($logPath, $out, FILE_APPEND);
        $this->info('');
        $this->info('採点結果を書き出しました: ' . $logPath);
    }
}
