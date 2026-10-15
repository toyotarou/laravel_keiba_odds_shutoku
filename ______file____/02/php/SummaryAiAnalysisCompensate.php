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
                           . ' {--ml-status : 断層パターン学習の特徴量・正解ラベルの件数を集計する（読み取りだけ）}'
                           // @ANCHOR-20261013-V04-TEST  v0.4-X の受入試験と状態表示
                           . ' {--v04-test : v0.4-X 構造判定の受入試験（付録E12件＋05章B24件）を実行する（読み取りだけ・AI呼び出し0回）}'
                           . ' {--v04-status : v0.4-X のスイッチ・閾値・配分表・監査ログの蓄積状況を表示する（読み取りだけ）}'
                           . ' {--v04-report : v0.4-X のシャドー記録を着順と突き合わせて05章Dの書式で報告する（読み取りだけ・AI呼び出し0回）。--date= と併用}'
                           . ' {--v04-prompt-check : 研究用H/V採点を追記した送信文を組み立てて差込・マーカーを検査する（AIへは送らない・費用0円）。--date= / --race= と併用}'
                           . ' {--v04-hv-trial : 新プロンプトを実際にAIへ送って採点・選出までつながるか確かめる（1レース2回のAI呼び出し・課金あり）。--race= 必須}'
                           . ' {--yes : 確認を省略する（--v04-hv-trial の実行確認をスキップ）}'
                           . ' {--limit= : --v04-hv-trial の対象を先頭からN件に絞る（例 --limit=1 でAI呼び出し2回だけ）}'
                           // @ANCHOR-20261014  10/14のご依頼C・F・H
                           . ' {--v04-trace : 1st→2nd→PHP統合→公開の段階別追跡表を出す（読み取りだけ・AI呼び出し0回）。--date= / --race= と併用}'
                           . ' {--v04-exclusions : 候補を落とし得る箇所の全件リストを表示する（読み取りだけ）}'
                           // @ANCHOR-20261014-FILL-COMPARE  10/14（2回目）ご依頼①の3方式比較
                           . ' {--fill-compare : 不足枠の補充方式A・B・Cを同一レースで比較する（読み取りだけ・AI呼び出し0回）。--date= / --race= と併用}'
                           // @ANCHOR-20261014-FILL-PROOF  10/14（4回目）ご指摘①⑤の最終確認
                           . ' {--fill-mode-proof : 補充方式を変えても送信文・購入回避判定・購入推奨が変わらないことを示す（AIへは送らない・費用0円）。--date= / --race= と併用}'
                           . ' {--v04-test-detail : 受入試験の全項目（確認内容・得た値・期待値）を1行ずつ表示する（読み取りだけ）}'
                           // @ANCHOR-20261014-ABNORMAL-LOG  10/14（5回目）ご指摘の異常入力の修正状況
                           . ' {--abnormal-status : 異常入力の指摘と修正状況の一覧を表示する（読み取りだけ）}'
                           . ' {--weekend-report : レース傾向・断層・人気帯別選出・補充馬の着順と払戻・購入推奨の回収率をまとめて出す（読み取りだけ・AI呼び出し0回）。--date= / --race= と併用}'
                           // @ANCHOR-20261014-MISSED  10/14（6回目）ご指摘⑤⑥
                           . ' {--missed-analysis : 拾えなかった人気薄を「全頭採点にはいた馬」と「全頭採点が無かった馬」に分けて出す（読み取りだけ・AI呼び出し0回）。--date= と併用}'
                           . ' {--switch-precheck : B方式へ切り替える前の確認（復帰手順・データ保全・異常入力時の動作・現行プロンプトの保存）をまとめて行う（読み取りだけ・AI呼び出し0回）。--date= と併用}'
                           // @ANCHOR-20261014-CSV  10/14の確認依頼書⑤
                           . ' {--compare-csv : A/B/C各方式の公開候補・捕捉・購入推奨・購入額・複勝回収率を同じ母集団でCSVに書き出す（読み取りだけ・AI呼び出し0回）。--date= と併用。複数日はカンマ区切り}'
                           // @ANCHOR-20261014-LONGSHOT  10/14（7回目）02.txt ②
                           . ' {--longshot-csv : 7番人気以下の全頭について、支持率・資金流入・断層の位置と本数と時系列変化・全頭採点の点数と順位・漏れた理由・着順を1頭1行でCSVに書き出す（読み取りだけ・AI呼び出し0回）。--date= と併用。複数日はカンマ区切り}'
                           // @ANCHOR-20261014-PLAN2  10/14（7回目）02.txt ③
                           . ' {--plan2-compare : 案2（波乱度3以上のレースだけ、全頭採点から候補を起こす）を現行と比較する（読み取りだけ・AI呼び出し0回・本番には影響しない）。--date= と併用。複数日はカンマ区切り}'
                           // @ANCHOR-20261015-PROMPT-VERIFY  10/15（1回目）02.txt ⑤
                           . ' {--prompt-verify : 保存済みの6分前オッズからプロンプトを組み直し、監査ログに残る「実際に送った本文のSHA-256」と突き合わせる（AI呼び出し0回・費用0円・DBへの書き込みなし）。--date= と併用。複数日はカンマ区切り}'
                           // @ANCHOR-20261015-INTEGRITY  10/15（1回目）01.txt【6】
                           . ' {--integrity-check : 洗い替え前に保存済みデータの完全性を確認し、消えるオッズと着順をCSVへ書き出す（読み取りだけ・AI呼び出し0回）。--date= と併用。複数日はカンマ区切り}'
                           // @ANCHOR-20261015-EVIDENCE  10/15（1回目）02.txt ⑥
                           . ' {--evidence-check : 受入試験で「別手段で確認する」としていた6項目（B-T19／B-T23／B-T24／A1-4／A3-1／A4-8）を機械的に確認する（読み取りだけ・AI呼び出し0回）。--date= と併用}'
                           // @ANCHOR-20261015-PROMPT-DUMP  10/15（2回目）02.txt
                           . ' {--prompt-dump : AIへ送るお願い文の全文（1stのシステム指示と本文・2ndのシステム指示・2ndの作り方）をファイルへ書き出す（AI呼び出し0回・費用0円・DBへの書き込みなし）。--date= / --race= と併用}'
                           // @ANCHOR-20261015-WAVE  10/15（2回目）01.txt【3】①④
                           . ' {--wave-accuracy : 波乱度判定と実際の結果の一致率、断層タイプ・本数・位置・時系列変化ごとの荒れ率をレース単位で出す（読み取りだけ・AI呼び出し0回）。--date= と併用。複数日はカンマ区切り}'
                           . ' {--v04-raceguard-test : 同一プロセスでレースA→Bを連続処理し、控えの混入が無いことを確かめる（AI呼び出し0回）。--date= と併用}';
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

        // @ANCHOR-20261013-V04-TEST  v0.4-X の受入試験（AI呼び出し0回・読み取りだけ）
        if ((bool) $this->option('v04-test')) {
            $this->runV04SelfTest();
            return;
        }

        // @ANCHOR-20261013-V04-TEST  v0.4-X の設定と蓄積状況（読み取りだけ）
        if ((bool) $this->option('v04-status')) {
            $this->runV04Status();
            return;
        }

        // @ANCHOR-20261013-V04-REPORT  v0.4-X の前向き照合レポート（読み取りだけ）
        if ((bool) $this->option('v04-report')) {
            $this->runV04Report((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261013-V04-PROMPT-CHECK  新プロンプトの差込検査（AI呼び出し0回）
        if ((bool) $this->option('v04-prompt-check')) {
            $this->runV04PromptCheck((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261013-V04-HV-TRIAL  新プロンプトの実AI送信テスト（課金あり）
        if ((bool) $this->option('v04-hv-trial')) {
            $this->runV04HvTrial((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261014-TRACE  段階別の追跡表（読み取りだけ）
        if ((bool) $this->option('v04-trace')) {
            $this->runV04Trace((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261014-EXCLUSION-LIST  候補を落とし得る箇所の全件（読み取りだけ）
        if ((bool) $this->option('v04-exclusions')) {
            $this->runV04Exclusions();
            return;
        }

        // @ANCHOR-20261014-FILL-COMPARE  補充方式A・B・Cの比較（読み取りだけ）
        if ((bool) $this->option('fill-compare')) {
            $this->runFillCompare((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261014-FILL-PROOF  方式を変えても変わらないことの確認（読み取りだけ）
        if ((bool) $this->option('fill-mode-proof')) {
            $this->runFillModeProof((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261014-TEST-DETAIL  受入試験の全項目表示（読み取りだけ）
        if ((bool) $this->option('v04-test-detail')) {
            $this->runV04TestDetail();
            return;
        }

        // @ANCHOR-20261014-ABNORMAL-LOG  異常入力の修正状況（読み取りだけ）
        if ((bool) $this->option('abnormal-status')) {
            $this->runAbnormalStatus();
            return;
        }

        // @ANCHOR-20261014-WEEKEND  週末の検証に使う記録（読み取りだけ）
        if ((bool) $this->option('weekend-report')) {
            $this->runWeekendReport((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261014-MISSED  拾えなかった人気薄の内訳（読み取りだけ）
        if ((bool) $this->option('missed-analysis')) {
            $this->runMissedAnalysis((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261014-PRECHECK  切り替え前の確認（読み取りだけ）
        if ((bool) $this->option('switch-precheck')) {
            $this->runSwitchPrecheck((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261014-CSV  A/B/C比較のCSV書き出し（読み取りだけ）
        if ((bool) $this->option('compare-csv')) {
            $this->runCompareCsv((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261014-LONGSHOT  人気薄1頭1行の一覧（読み取りだけ）
        if ((bool) $this->option('longshot-csv')) {
            $this->runLongshotCsv((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261014-PLAN2  案2の比較（読み取りだけ・本番には影響しない）
        if ((bool) $this->option('plan2-compare')) {
            $this->runPlan2Compare((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261015-PROMPT-VERIFY  送信文の一致確認（AI呼び出し0回）
        if ((bool) $this->option('prompt-verify')) {
            $this->runPromptVerify((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261015-INTEGRITY  洗い替え前のデータ完全性の確認（読み取りだけ）
        if ((bool) $this->option('integrity-check')) {
            $this->runIntegrityCheck((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261015-WAVE  波乱度判定の一致率と断層（読み取りだけ）
        if ((bool) $this->option('wave-accuracy')) {
            $this->runWaveAccuracy((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261015-PROMPT-DUMP  送信文の全文の書き出し（AI呼び出し0回）
        if ((bool) $this->option('prompt-dump')) {
            $this->runPromptDump((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261015-EVIDENCE  別手段で確認する6項目（読み取りだけ）
        if ((bool) $this->option('evidence-check')) {
            $this->runEvidenceCheck((string) ($this->option('date') ?: date('Y-m-d')));
            return;
        }

        // @ANCHOR-20261014-RACE-GUARD  レースをまたぐ混入の再現テスト（AI呼び出し0回）
        if ((bool) $this->option('v04-raceguard-test')) {
            $this->runV04RaceGuardTest((string) ($this->option('date') ?: date('Y-m-d')));
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

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261013-V04-TEST  v0.4-X の受入試験（--v04-test）
     * ═════════════════════════════════════════════════════════════════════════
     * よっしー20261013提出 統一版v0.4-X の
     *   付録E 11_判定テストケース.json（12件）
     *   05章B テストケース表（T01〜T24）
     *   付録F 12_構造判定_参考実装.py の test()
     * を PHP 側で実行する。DBもAIも触らない（AI呼び出し0回・お金がかからない）。
     */
    private function runV04SelfTest(): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 馬眼力 v0.4-X 受入試験（読み取りだけ・AI呼び出し0回）');
        $this->info('══════════════════════════════════════════════════════════');

        try {
            $r = AiController::v04SelfTest();
        } catch (\Throwable $e) {
            $this->error('受入試験の実行に失敗しました: ' . $e->getMessage());
            $this->error($e->getFile() . ':' . $e->getLine());
            return;
        }

        $this->info('config_version : ' . $r['config_version']);
        $this->info('判定件数       : ' . ($r['pass'] + $r['fail']) . ' 件');
        $this->info('合格           : ' . $r['pass'] . ' 件');
        if ($r['fail'] > 0) {
            $this->error('不合格         : ' . $r['fail'] . ' 件');
        } else {
            $this->info('不合格         : 0 件');
        }
        $this->line('');

        // 不合格だけ詳しく出す。合格はID単位の要約にする。
        $byId = [];
        foreach ($r['results'] as $row) {
            $byId[$row['id']]['計']  = ($byId[$row['id']]['計'] ?? 0) + 1;
            $byId[$row['id']]['NG'] = ($byId[$row['id']]['NG'] ?? 0) + ($row['結果'] === 'FAIL' ? 1 : 0);
        }
        $this->info('── ケース別 ──────────────────────────────────────────');
        foreach ($byId as $id => $c) {
            $mark = ($c['NG'] === 0) ? '合格' : ('不合格 ' . $c['NG'] . '件');
            $this->line(sprintf('  %-12s %2d項目  %s', $id, $c['計'], $mark));
        }

        if ($r['fail'] > 0) {
            $this->line('');
            $this->error('── 不合格の内容 ─────────────────────────────────────');
            foreach ($r['results'] as $row) {
                if ($row['結果'] !== 'FAIL') continue;
                $this->error(sprintf('  %s / %s', $row['id'], $row['確認']));
                $this->error('    得た値 : ' . json_encode($row['得た値'], JSON_UNESCAPED_UNICODE));
                $this->error('    期待値 : ' . json_encode($row['期待値'], JSON_UNESCAPED_UNICODE));
            }
        }

        $this->line('');
        $this->info('── ここでは判定していない項目（別手段で確認する）──────────');
        foreach ($r['別手段で確認'] as $id => $note) {
            $this->line("  {$id} : {$note}");
        }

        $this->line('');
        $this->warn('※この試験は構造判定・配分・Tier・採点・AI契約チェックの検算です。');
        $this->warn('  予想性能を示すものではありません（資料にも「性能未検証」と明記されています）。');
        $this->warn('※BAGANRIKI_V04_ENABLED は既定で無効のままです。本番の選出・表示・DBは変わりません。');

        // 結果をファイルにも残す（第三者へ見せるとき用）
        $logPath = storage_path('logs/v04_selftest_' . date('Y-m-d_His') . '.json');
        @file_put_contents($logPath, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info('試験結果を書き出しました: ' . $logPath);
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261013-V04-TEST  v0.4-X の設定と蓄積状況（--v04-status）
     * ═════════════════════════════════════════════════════════════════════════
     * スイッチの現在値、研究用初期閾値、3帯配分表、採点上限、
     * 監査ログ AUDIT_V04_* の蓄積件数を表示する。読み取りだけ。
     */
    private function runV04Status(): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 馬眼力 v0.4-X の状態（読み取りだけ）');
        $this->info('══════════════════════════════════════════════════════════');

        $this->info('config_version     : ' . AiController::V04_CONFIG_VERSION);
        $this->info('scoring_version    : ' . AiController::V04_SCORING_VERSION);
        $this->info('allocation_version : ' . AiController::V04_ALLOCATION_VERSION);
        $this->line('');

        $this->info('── スイッチ（既定は「本番反映OFF／SHADOW記録ON」）────────────');
        $this->line('  BAGANRIKI_V04_ENABLED          : '
            . (env('BAGANRIKI_V04_ENABLED', null) === null ? '未設定 → 既定 false（選出に使わない）' : (string) env('BAGANRIKI_V04_ENABLED')));
        $this->line('  BAGANRIKI_V04_SHADOW           : '
            . (env('BAGANRIKI_V04_SHADOW', null) === null ? '未設定 → 既定 true（計算して監査ログに残すだけ）' : (string) env('BAGANRIKI_V04_SHADOW')));
        $this->line('  BAGANRIKI_V04_RESEARCH_EXTREME : '
            . (env('BAGANRIKI_V04_RESEARCH_EXTREME', null) === null ? '未設定 → 既定 false（2/3/2は生成しない）' : (string) env('BAGANRIKI_V04_RESEARCH_EXTREME')));
        $this->line('  BAGANRIKI_V04_ML_STAGE         : '
            . (env('BAGANRIKI_V04_ML_STAGE', null) === null ? '未設定 → 既定 COLLECT_ONLY' : (string) env('BAGANRIKI_V04_ML_STAGE')));
        $this->line('  BAGANRIKI_V04_HV_PROMPT        : '
            . (env('BAGANRIKI_V04_HV_PROMPT', null) === null ? '未設定 → 既定 false（送信文は1バイトも変わらない）' : (string) env('BAGANRIKI_V04_HV_PROMPT')));
        $this->line('');

        $this->info('── 研究用の初期閾値（付録D・検証済みの本番値ではない）────────');
        foreach (AiController::V04_THRESHOLDS as $k => $v) {
            $this->line(sprintf('  %-32s %s', $k, (string) $v));
        }
        $this->line('');

        $this->info('── 信号の重み（S = 2*X1 + X2 + X3 + X4 + X5 + X6 - 2*Z）──────');
        $w = [];
        foreach (AiController::V04_SIGNAL_WEIGHTS as $k => $v) { $w[] = "{$k}:{$v}"; }
        $this->line('  ' . implode('  ', $w));
        $this->line('  S<=1 → FIRM／2〜3 → MIXED／S>=4 → VOLATILE');
        $this->line('  不明が3信号以上、または不明の振り方でモードが変わる → UNKNOWN');
        $this->line('');

        $this->info('── 3帯配分表（A=1〜6番人気／B=7〜10／D=11以下）──────────────');
        $this->line('  target_count  FIRM        MIXED       VOLATILE');
        foreach ([7, 6, 5, 4] as $cap) {
            $row = AiController::V04_QUOTA_BY_TARGET[$cap];
            $this->line(sprintf('  %-13d %-11s %-11s %s', $cap,
                implode('/', $row['FIRM']), implode('/', $row['MIXED']), implode('/', $row['VOLATILE'])));
        }
        $this->line('  target_count<=3 は3帯配分を作らない（実在馬の帯のみ、目標数以下で評価）');
        $this->line('');

        $this->info('── 研究用の採点上限（hv10-v04・旧11項目とは別管理）───────────');
        $h = []; $v2 = [];
        foreach (AiController::V04_SCORE_CAP as $k => $max) {
            if ($k[0] === 'H') $h[] = "{$k}:{$max}"; else $v2[] = "{$k}:{$max}";
        }
        $this->line('  信頼度60   ' . implode('  ', $h));
        $this->line('  妙味代理40 ' . implode('  ', $v2));
        $this->line('');

        $this->info('── 未決事項（これが埋まるまで本番に出さない）──────────────');
        $this->line('  B: X6の同条件比較基準率が未定義 → X6は必ずUNKNOWN（0%で埋めない）');
        $this->line('  C: 能力・適性データの供給が未確認 → ability=UNKNOWN、H1=null、Tierは暫定');
        $this->line('  D: 現行の内部JSONマーカー・Flutterパーサーとの適合が未確認');
        $this->line('     → BAGANRIKI_V04_HV_PROMPT を既定OFFにしてある');
        $this->line('');

        // @ANCHOR-20261013-V04-REQ-STATUS  10/13ご指摘①への回答
        $this->info('── 実装要件R01〜R18の到達段階 ──────────────────────────────');
        $this->line('  段階 1=コード作成済み 2=単体試験済み 3=実AI確認済み 4=SHADOW稼働済み 5=本番有効化済み');
        $this->line(sprintf('  %-4s %-3s %-3s %s', 'ID', 'v04', '本番', '要件'));
        $stageTally = [];
        foreach (AiController::V04_REQ_STATUS as $id => $row) {
            [$req, $v04Stage, $liveStage, $note] = $row;
            $stageTally[$v04Stage] = ($stageTally[$v04Stage] ?? 0) + 1;
            $this->line(sprintf('  %-4s  %d   %s  %s', $id, $v04Stage,
                $liveStage === 0 ? '−' : (string) $liveStage, $req));
            if ($note !== '') $this->line('           └ ' . $note);
        }
        $this->line('');
        ksort($stageTally);
        foreach ($stageTally as $st => $cnt) {
            $this->line(sprintf('  v0.4研究経路 段階%d（%s）: %d件',
                $st, AiController::V04_REQ_STAGE_LABEL[$st], $cnt));
        }
        $this->warn('  段階3（実AI確認済み）と段階4（SHADOW稼働済み）は、現時点で0件です。');
        $this->warn('  段階3はBAGANRIKI_V04_HV_PROMPTをONにして実際にAIへ送らないと到達しません。');
        $this->warn('  段階4は次の開催（10月17日）のレースが動けば到達します。');
        $this->line('');

        $this->info('── 監査ログ AUDIT_V04_* の蓄積件数 ─────────────────────────');
        $dir = storage_path('logs/audit');
        $tags = ['AUDIT_V04_RACE', 'AUDIT_V04_GAP', 'AUDIT_V04_HORSE',
                 'AUDIT_V04_AI', 'AUDIT_V04_MERGE', 'AUDIT_V04_COMPARE', 'AUDIT_V03_SHADOW'];
        if (!is_dir($dir)) {
            $this->warn('  ' . $dir . ' が無いため件数を数えられません');
        } else {
            $files = glob($dir . '/audit_*.jsonl') ?: [];
            rsort($files);
            $files = array_slice($files, 0, 7);           // 直近7日だけ数える
            if (empty($files)) {
                $this->line('  監査ログのファイルがありません');
            }
            foreach ($files as $f) {
                $counts = array_fill_keys($tags, 0);
                $fh = @fopen($f, 'r');
                if ($fh === false) continue;
                while (($line = fgets($fh)) !== false) {
                    foreach ($tags as $t) {
                        if (strpos($line, '"' . $t . '"') !== false) { $counts[$t]++; break; }
                    }
                }
                fclose($fh);
                $parts = [];
                foreach ($counts as $t => $c) { if ($c > 0) $parts[] = str_replace('AUDIT_', '', $t) . "={$c}"; }
                $this->line('  ' . basename($f) . ' : ' . (empty($parts) ? 'V04/V03の記録なし' : implode('  ', $parts)));
            }
        }

        $this->line('');
        $this->warn('※v0.4-Xは研究・シャドー用です。資料に「この一式をそのまま本番に');
        $this->warn('  デプロイすることは禁止」と明記されています。本番切替はユーザーの明示承認後です。');
    }


    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261013-V04-REPORT  v0.4-X の前向き照合レポート（--v04-report）
     * ═════════════════════════════════════════════════════════════════════════
     * 05章D「最低報告書の書式」と06章3「成功指標を別々に報告」をそのまま出す。
     *   ・対象レース数（除外・未確定の理由と件数）と各モード母数
     *   ・3着内3頭完全捕捉率
     *   ・5着内捕捉頭数（平均・分布）
     *   ・7〜10人気／11人気以下の好走馬捕捉率
     *   ・1〜6番人気の好走取り逃し
     *   ・帯別目標と実数、Tier B/C率、補充/未達率、各信号のUNKNOWN率
     *
     * 入力は監査ログ（storage/logs/audit/audit_YYYY-MM-DD.jsonl）の
     * AUDIT_V04_RACE / AUDIT_V04_HORSE と、着順履歴テーブルだけ。
     * 読み取りだけで、AI呼び出しは0回。DBへは何も書かない。
     *
     * 【結果を見てから閾値を変えない】
     *   05章C2-3「出走結果確定後に初めて、着順・当時の人気・払戻を別テーブルで
     *   結合する」。このコマンドは結合して数えるだけで、判定条件には触らない。
     *   好走馬の捕捉は「事前6分前人気帯」で数え、確定人気は別列に出す。
     */
    private function runV04Report(string $date): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(" 馬眼力 v0.4-X 前向き照合レポート  対象日 {$date}");
        $this->info('══════════════════════════════════════════════════════════');

        $path = storage_path('logs/audit/audit_' . $date . '.jsonl');
        if (!is_file($path)) {
            $this->error('監査ログがありません: ' . $path);
            $this->warn('v0.4-Xのシャドー記録は、その日のAI予想が動いた日にしか残りません。');
            return;
        }

        // ── 監査ログから v0.4 の記録を読む ───────────────────────────────────
        $races = [];      // raceKey => ['race'=>..., 'horse'=>...]
        $fh = fopen($path, 'r');
        while (($line = fgets($fh)) !== false) {
            if (strpos($line, 'AUDIT_V04_') === false) continue;
            $row = json_decode($line, true);
            if (!is_array($row)) continue;
            $tag  = $row['tag'] ?? ($row['種別'] ?? null);
            $body = $row['payload'] ?? ($row['内容'] ?? $row);
            if ($tag === null) {
                foreach (['AUDIT_V04_RACE', 'AUDIT_V04_HORSE'] as $t) {
                    if (strpos($line, '"' . $t . '"') !== false) { $tag = $t; break; }
                }
            }
            $key = $body['race'] ?? null;
            if ($key === null) continue;
            if ($tag === 'AUDIT_V04_RACE')  $races[$key]['race']  = $body;
            if ($tag === 'AUDIT_V04_HORSE') $races[$key]['horse'] = $body;
        }
        fclose($fh);

        if (empty($races)) {
            $this->error('この日の監査ログに AUDIT_V04_* の記録がありません。');
            $this->warn('BAGANRIKI_V04_SHADOW が無効になっていないか確認してください（既定は有効）。');
            return;
        }

        // ── 集計 ────────────────────────────────────────────────────────────
        $modeCount   = [];                 // mode => レース数
        $excluded    = [];                 // 除外理由 => 件数
        $top3Full    = 0; $top3Target = 0; // 3着内3頭完全捕捉
        $top5Hits    = [];                 // 各レースの5着内捕捉頭数
        $bandCatch   = ['B' => ['好走' => 0, '捕捉' => 0], 'D' => ['好走' => 0, '捕捉' => 0]];
        $favMiss     = 0; $favGood = 0;    // 1〜6番人気の好走と取り逃し
        $quotaVsAct  = [];                 // "A3/B3/D1" => ['目標'=>[], '実数'=>[]]
        $tierCount   = ['A' => 0, 'B' => 0, 'C' => 0, 'UNVERIFIED' => 0];
        $unknownSig  = ['X1' => 0, 'X2' => 0, 'X3' => 0, 'X4' => 0, 'X5' => 0, 'X6' => 0, 'Z' => 0];
        $crossFill   = 0; $partialCnt = 0; $raceOk = 0;
        $detail      = [];

        foreach ($races as $key => $rec) {
            $r = $rec['race'] ?? null;
            $h = $rec['horse'] ?? null;
            if ($r === null) { $excluded['AUDIT_V04_RACEが無い'] = ($excluded['AUDIT_V04_RACEが無い'] ?? 0) + 1; continue; }

            $mode = $r['structure']['mode'] ?? '不明';
            $modeCount[$mode] = ($modeCount[$mode] ?? 0) + 1;

            if ($mode === 'DATA_INVALID') {
                $excluded['DATA_INVALID（入力破損）'] = ($excluded['DATA_INVALID（入力破損）'] ?? 0) + 1;
                continue;
            }
            foreach ($unknownSig as $sig => $_) {
                if (($r['structure']['signals'][$sig] ?? null) === 'UNKNOWN') $unknownSig[$sig]++;
            }

            // 着順履歴と突き合わせる
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d+)回(\S+)\s+(\d+)日目\s+(\d+)R$/u', (string) $key, $m)) {
                $excluded['レースキーを解釈できない'] = ($excluded['レースキーを解釈できない'] ?? 0) + 1;
                continue;
            }
            $hist = DB::table('t_horse_odds_finder_race_result_history')
                ->where('date', $m[1])->where('kaisuu', (int) $m[2])
                ->where('basho_code', $m[3])->where('day', (int) $m[4])
                ->where('race', (int) $m[5])
                ->whereBetween('finishing_position', [1, 5])
                ->orderBy('finishing_position')
                ->get(['num', 'finishing_position', 'popularity_rank']);
            if ($hist->isEmpty()) {
                $excluded['着順履歴がまだ無い（未確定）'] = ($excluded['着順履歴がまだ無い（未確定）'] ?? 0) + 1;
                continue;
            }

            $selected = $r['selected'] ?? ($rec['race']['selected'] ?? null);
            if ($selected === null) {
                // AUDIT_V04_RACE には actual しか無いので、MERGE側の selected を使う
                $selected = $rec['race']['selected'] ?? [];
            }
            if (empty($selected) && !empty($h['全頭'])) {
                $selected = [];
                foreach ($h['全頭'] as $hh) { if (!empty($hh['public_candidate'])) $selected[] = (int) $hh['num']; }
            }
            $selSet = array_fill_keys(array_map('intval', (array) $selected), true);

            // 6分前人気帯（監査ログの事前値。確定人気は別列）
            $bandOf6 = []; $pop6 = [];
            foreach (($h['全頭'] ?? []) as $hh) {
                $bandOf6[(int) $hh['num']] = $hh['帯'] ?? null;
                $pop6[(int) $hh['num']]    = (int) ($hh['人気'] ?? 0);
                $t = $hh['tier'] ?? null;
                if (isset($tierCount[$t])) $tierCount[$t]++;
            }

            $top3 = []; $top5 = [];
            foreach ($hist as $hr) {
                $n = (int) $hr->num;
                if ((int) $hr->finishing_position <= 3) $top3[] = $n;
                $top5[] = $n;
            }
            $top3Target++;
            $full = !empty($top3);
            foreach ($top3 as $n) { if (!isset($selSet[$n])) { $full = false; break; } }
            if ($full) $top3Full++;

            $hit5 = 0;
            foreach ($top5 as $n) { if (isset($selSet[$n])) $hit5++; }
            $top5Hits[] = $hit5;

            // 人気帯別の好走捕捉（事前6分前人気で数える）
            foreach ($top5 as $n) {
                $b = $bandOf6[$n] ?? null;
                if ($b === 'B' || $b === 'D') {
                    $bandCatch[$b]['好走']++;
                    if (isset($selSet[$n])) $bandCatch[$b]['捕捉']++;
                } elseif ($b === 'A') {
                    $favGood++;
                    if (!isset($selSet[$n])) $favMiss++;
                }
            }

            $q = $r['quota'] ?? null;
            $a = $r['actual'] ?? null;
            if (is_array($q) && is_array($a)) {
                $lab = "A{$q['A']}/B{$q['B']}/D{$q['D']}";
                $quotaVsAct[$lab]['件数'] = ($quotaVsAct[$lab]['件数'] ?? 0) + 1;
                foreach (['A', 'B', 'D'] as $bb) {
                    $quotaVsAct[$lab]['実数'][$bb] = ($quotaVsAct[$lab]['実数'][$bb] ?? 0) + (int) ($a[$bb] ?? 0);
                }
            }
            $raceOk++;
            $detail[] = ['race' => $key, 'N' => $r['N'] ?? null, 'C' => $r['target_count'] ?? null,
                         'mode' => $mode, 'quota' => $q, 'actual' => $a,
                         '選出' => array_keys($selSet), '3着内' => $top3,
                         '3頭完全捕捉' => $full, '5着内捕捉' => $hit5];
        }

        // ── 出力 ────────────────────────────────────────────────────────────
        $this->line('');
        $this->info('── 対象レース ───────────────────────────────────────────');
        $this->line('  監査ログにあったレース : ' . count($races));
        $this->line('  集計できたレース       : ' . $raceOk);
        if (!empty($excluded)) {
            foreach ($excluded as $why => $cnt) { $this->line("  除外 {$cnt} 件 : {$why}"); }
        }
        $this->line('');
        $this->info('── 構造モード別の母数 ───────────────────────────────────');
        foreach ($modeCount as $mode => $cnt) { $this->line(sprintf('  %-16s %d レース', $mode, $cnt)); }
        $this->line('');

        if ($raceOk === 0) {
            $this->warn('着順が確定したレースがまだ無いため、捕捉率は出せません。');
            $this->warn('レース後にもう一度実行してください。');
            return;
        }

        $this->info('── 候補捕捉（予想能力）─────────────────────────────────');
        $this->line(sprintf('  3着内3頭完全捕捉率 : %d / %d レース（%.1f%%）',
            $top3Full, $raceOk, $raceOk ? $top3Full / $raceOk * 100 : 0));
        $avg5 = count($top5Hits) ? array_sum($top5Hits) / count($top5Hits) : 0;
        $dist = array_count_values($top5Hits);
        ksort($dist);
        $distTx = [];
        foreach ($dist as $k => $v) { $distTx[] = "{$k}頭:{$v}R"; }
        $this->line(sprintf('  5着内捕捉頭数       : 平均 %.2f 頭（分布 %s）', $avg5, implode(' ', $distTx)));
        foreach (['B' => '7〜10番人気', 'D' => '11番人気以下'] as $b => $lbl) {
            $g = $bandCatch[$b]['好走']; $c = $bandCatch[$b]['捕捉'];
            $this->line(sprintf('  %s の好走馬捕捉 : %d / %d 頭（%s）',
                $lbl, $c, $g, $g ? sprintf('%.1f%%', $c / $g * 100) : '母数0'));
        }
        $this->line(sprintf('  1〜6番人気の好走取り逃し : %d / %d 頭（%s）',
            $favMiss, $favGood, $favGood ? sprintf('%.1f%%', $favMiss / $favGood * 100) : '母数0'));
        $this->line('');

        $this->info('── 帯別の目標と実数 ─────────────────────────────────────');
        foreach ($quotaVsAct as $lab => $v) {
            $cnt = $v['件数'];
            $this->line(sprintf('  目標 %-12s %2d レース  実数合計 A=%d B=%d D=%d（1レース平均 A=%.1f B=%.1f D=%.1f）',
                $lab, $cnt, $v['実数']['A'] ?? 0, $v['実数']['B'] ?? 0, $v['実数']['D'] ?? 0,
                ($v['実数']['A'] ?? 0) / $cnt, ($v['実数']['B'] ?? 0) / $cnt, ($v['実数']['D'] ?? 0) / $cnt));
        }
        $this->line('');

        $this->info('── Tier と信号の不明率 ──────────────────────────────────');
        $tierTotal = array_sum($tierCount);
        foreach ($tierCount as $t => $c) {
            $this->line(sprintf('  Tier %-11s %4d 頭（%s）', $t, $c,
                $tierTotal ? sprintf('%.1f%%', $c / $tierTotal * 100) : '-'));
        }
        foreach ($unknownSig as $sig => $c) {
            $this->line(sprintf('  %s の UNKNOWN率 : %d / %d レース（%s）', $sig, $c, $raceOk,
                $raceOk ? sprintf('%.1f%%', $c / $raceOk * 100) : '-'));
        }
        $this->line('');

        $this->info('── レース別の明細 ───────────────────────────────────────');
        foreach ($detail as $d) {
            $q = is_array($d['quota']) ? "A{$d['quota']['A']}/B{$d['quota']['B']}/D{$d['quota']['D']}" : '-';
            $a = is_array($d['actual']) ? "A{$d['actual']['A']}/B{$d['actual']['B']}/D{$d['actual']['D']}" : '-';
            $this->line(sprintf('  %s  N=%s C=%s %-9s 目標%s 実数%s  選出[%s]  3着内[%s] %s  5着内%d頭',
                $d['race'], (string) $d['N'], (string) $d['C'], $d['mode'], $q, $a,
                implode(',', $d['選出']), implode(',', $d['3着内']),
                $d['3頭完全捕捉'] ? '完全捕捉' : '取り逃しあり', $d['5着内捕捉']));
        }

        $this->line('');
        $this->warn('※この数字は候補捕捉（予想能力）だけです。「候補に入った」ことは');
        $this->warn('  「馬券が当たる」「回収率が改善する」と同義ではありません（06章3）。');
        $this->warn('※回収率は、券種・買い目・資金配分・締切時点のオッズを検証前に固定したうえで');
        $this->warn('  旧版と同じルール・同じ予算で比べないと比較になりません。ここでは出していません。');
        $this->warn('※既知レース（10/3・4、10/10・11）はデバッグ用です。改善の証拠には使えません（05章C1）。');
        $this->warn('※BAGANRIKI_V04_ENABLED は既定で無効です。この選出は公開候補ではありません。');

        $out = storage_path('logs/v04_report_' . $date . '.json');
        @file_put_contents($out, json_encode([
            'date' => $date, 'config_version' => AiController::V04_CONFIG_VERSION,
            '対象' => ['監査ログ' => count($races), '集計' => $raceOk, '除外' => $excluded],
            'モード別' => $modeCount,
            '3着内3頭完全捕捉' => ['件数' => $top3Full, '母数' => $raceOk],
            '5着内捕捉' => ['平均' => $avg5, '分布' => $dist],
            '人気帯別捕捉' => $bandCatch,
            '上位人気の取り逃し' => ['取り逃し' => $favMiss, '好走' => $favGood],
            '帯別目標と実数' => $quotaVsAct,
            'Tier' => $tierCount, '信号のUNKNOWN' => $unknownSig,
            '明細' => $detail,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info('');
        $this->info('レポートを書き出しました: ' . $out);
    }


    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261013-V04-PROMPT-CHECK  新プロンプトの差込検査（AI呼び出し0回）
     * ═════════════════════════════════════════════════════════════════════════
     * 10/13のご指摘④「新プロンプトの差込・system整合・出力マーカー・H/V採点・
     * 公開点との対応を確定し、本番を変更せず新1st/2ndを検証する手順」の前半を、
     * お金をかけずに確かめるための経路。
     *
     *   ・H/V採点の依頼文を一時的にONにしてプロンプトを組み立てる
     *     （.env は変えない。このプロセス内のメモリ上だけで切り替える）
     *   ・未置換の <<...>> が残っていないか
     *   ・現行の内部JSONマーカーと、研究用H/Vマーカーが両方そろっているか
     *   ・1st専用の2行（厳選穴レース・レース指標）が壊れていないか
     *   ・OFFのときとの差分バイト数（OFFなら0でなければならない）
     *   ・組み立てた送信文をファイルに保存して、そのまま提出できるようにする
     *
     * AIへは送信しません。したがって費用は0円です。
     * 実際にAIへ送って応答を見る検証は、別途ご判断のうえで行います。
     */
    private function runV04PromptCheck(string $date): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' v0.4-X 新プロンプトの差込検査（AI呼び出し0回・費用0円）');
        $this->info('══════════════════════════════════════════════════════════');

        $races = DB::table('t_horse_odds_finder_races')->where('date', $date)
            ->orderBy('race')->get(['date', 'kaisuu', 'basho', 'day', 'race']);
        if ($races->isEmpty()) {
            $this->error("{$date} のレースが t_horse_odds_finder_races にありません。");
            $this->warn('--date= で、オッズが残っている日付を指定してください（--list-dates で確認できます）。');
            return;
        }
        $races = $this->filterRaces($races);
        if ($races->isEmpty()) return;

        $controller = app(AiController::class);
        $dir = storage_path('logs/v04_prompt_check_' . $date);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); @chmod($dir, 0777); }

        $ngTotal = 0; $okTotal = 0;
        foreach ($races as $r) {
            $label = "{$r->kaisuu}回{$r->basho} {$r->day}日目 {$r->race}R";

            // ① 現行（HV依頼OFF）
            AiController::v04ForceHvPrompt(false);
            $off = $controller->v04BuildPromptForCheck($r->date, $r->kaisuu, $r->basho, $r->day, $r->race);
            // ② 研究用（HV依頼ON）
            AiController::v04ForceHvPrompt(true);
            $on  = $controller->v04BuildPromptForCheck($r->date, $r->kaisuu, $r->basho, $r->day, $r->race);
            AiController::v04ForceHvPrompt(null);          // 必ず既定へ戻す

            if ($off === null || $on === null) {
                $this->warn("  {$label} … プロンプトを生成できません（オッズ不足の可能性）");
                continue;
            }

            $ng = [];

            // 未置換の差込ブロック（付録A・Bのプレースホルダー）
            //   内部JSONのマーカーは <<<…>>> の3重で、その内側が <<…>> に一致して
            //   しまうため、既知のマーカーを先に外してから探す。
            //   （10/13の初回実行で、この誤検知で全レースNGになった）
            $scan = str_replace([
                AiController::AUDIT_JSON_BEGIN,   AiController::AUDIT_JSON_END,
                AiController::V04_HV_JSON_BEGIN,  AiController::V04_HV_JSON_END,
            ], '', $on);
            if (preg_match_all('/<<[A-Z_0-9]{3,60}>>/u', $scan, $m)) {
                $ng[] = '未置換の差込ブロックが残っている: ' . implode('・', array_unique($m[0]));
            }
            // 現行の内部JSONマーカー
            foreach ([AiController::AUDIT_JSON_BEGIN, AiController::AUDIT_JSON_END] as $mk) {
                if (strpos($on, $mk) === false) $ng[] = "現行の内部JSONマーカーが無い: {$mk}";
            }
            // 研究用H/Vマーカー
            foreach ([AiController::V04_HV_JSON_BEGIN, AiController::V04_HV_JSON_END] as $mk) {
                if (strpos($on, $mk) === false) $ng[] = "研究用H/Vマーカーが無い: {$mk}";
            }
            // 1st専用の2行
            foreach (['厳選穴レース', 'レース指標'] as $kw) {
                if (strpos($on, $kw) === false) $ng[] = "1st専用の行が消えている: {$kw}";
            }
            // H/Vの10項目がすべて書かれているか
            foreach (array_keys(AiController::V04_SCORE_CAP) as $k) {
                if (!preg_match('/\b' . $k . '\b/u', $on)) $ng[] = "H/V項目が抜けている: {$k}";
            }
            // OFFのときに送信文が変わっていないこと（これが崩れたら本番に影響する）
            $hvBlockBytes = strlen($on) - strlen($off);
            if ($hvBlockBytes <= 0) $ng[] = 'HV依頼をONにしても送信文が増えていない（追記されていない）';

            if (empty($ng)) {
                $okTotal++;
                $this->info(sprintf('  %s … OK（OFF %s バイト ／ ON %s バイト ／ 追記 %s バイト）',
                    $label, number_format(strlen($off)), number_format(strlen($on)), number_format($hvBlockBytes)));
            } else {
                $ngTotal++;
                $this->error("  {$label} … NG");
                foreach ($ng as $x) { $this->error('      ' . $x); }
            }

            $base = sprintf('%s_%s回%s_%s日目_%02dR', $date, $r->kaisuu, $r->basho, $r->day, $r->race);
            @file_put_contents($dir . '/' . $base . '_OFF（現行）.txt', $off);
            @file_put_contents($dir . '/' . $base . '_ON（研究用H-V追記）.txt', $on);
        }

        $this->line('');
        $this->info("検査OK: {$okTotal} レース ／ NG: {$ngTotal} レース");
        $this->info('送信文の写しを書き出しました: ' . $dir);
        $this->line('');
        $this->warn('※AIへは1回も送っていません。費用は0円です。');
        $this->warn('※ここで確かめたのは差込と出力マーカーの形式までです。');
        $this->warn('  実際にAIが全頭分のH/V採点を返すか、途中で切れないか、応答時間はどうか、');
        $this->warn('  アプリのパーサーが崩れないかは、実際に送らないと分かりません。');
        $this->warn('※本番の送信文は変わっていません（BAGANRIKI_V04_HV_PROMPT は既定OFFのままです）。');
    }


    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261013-V04-HV-TRIAL  新プロンプトの実AI送信テスト（--v04-hv-trial）
     * ═════════════════════════════════════════════════════════════════════════
     * 10/13の3回目のご指摘【3】「まず1レース分を使い、新しいH/Vプロンプトを
     * AIへ実際に送信してください」への対応。
     *
     * 確認するのは次の6点（ご指摘の確認項目そのまま）。
     *   ・AIへの送信が正常に行われること
     *   ・必要な形式で返答が得られること
     *   ・必須項目が欠落していないこと
     *   ・返答が採点処理へ正しく渡されること
     *   ・採点結果と選出結果が仕様どおりであること
     *   ・エラー時や採点不能時に、誤った選出をしないこと
     *
     * 【お金がかかります】
     *   1レースにつき 1st 1回 ＋ 2nd 1回 ＝ 計2回のAI呼び出しが発生します。
     *   --race= の指定を必須にして、誤って全レース回すことを防いでいます。
     *   実行前に件数を表示して確認を取ります（--yes で省略可）。
     *
     * 【本番への影響】
     *   ・$dryRunNoPersist = true なので、DBにもファイルにも書きません
     *   ・トランザクションを張って、何があってもロールバックします
     *   ・H/V依頼のONはこのプロセスのメモリ上だけ（.env は変えません）
     *   ・公開候補・おすすめ度・画面表示には反映しません
     */
    private function runV04HvTrial(string $date): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' v0.4-X 新プロンプトの実AI送信テスト');
        $this->info('══════════════════════════════════════════════════════════');

        if (!$this->option('race')) {
            $this->error('--race= を必ず指定してください（例 --race=1）。');
            $this->warn('1レースにつきAI呼び出しが2回（1st＋2nd）発生するため、');
            $this->warn('うっかり全レース回さないように必須にしています。');
            return;
        }

        $races = DB::table('t_horse_odds_finder_races')->where('date', $date)
            ->orderBy('race')->get(['date', 'kaisuu', 'basho', 'day', 'race']);
        if ($races->isEmpty()) {
            $this->error("{$date} のレースが t_horse_odds_finder_races にありません。");
            return;
        }
        $races = $this->filterRaces($races);
        if ($races->isEmpty()) return;

        // @ANCHOR-20261013-V04-HV-TRIAL  AI呼び出し回数を最小に抑えるための絞り込み
        //   --race=1 でも複数場あれば複数レースになる。費用を抑えて測り直したいとき用。
        $limit = (int) ($this->option('limit') ?: 0);
        if ($limit > 0 && $races->count() > $limit) {
            $this->warn("--limit={$limit} により、先頭 {$limit} レースだけを対象にします"
                . "（絞り込み前 {$races->count()} レース）。");
            $races = $races->take($limit);
        }

        $calls = $races->count() * 2;
        $this->warn('');
        $this->warn("対象 {$races->count()} レース ／ AI呼び出し {$calls} 回（1レースあたり1st＋2ndの2回）");
        $this->warn('AIの利用料金がかかります。DBとファイルには書き込みません。');
        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261013-V04-HV-TRIAL  パイプ越しだと確認文が見えないまま固まる
        // ══════════════════════════════════════════════════════════════════
        //   `| tail -40` のように標準出力をパイプへ繋ぐと、確認文がパイプに入って
        //   画面に出ないのに入力を待ち続け、「止まった」ように見える（10/13に発生）。
        //   出力が端末でないときは、確認を取らずに中止して案内する。
        if (!$this->option('yes')) {
            $isTty = function_exists('posix_isatty') ? @posix_isatty(STDOUT) : true;
            if (!$isTty) {
                $this->error('標準出力が端末ではありません（パイプやリダイレクトの指定あり）。');
                $this->error('確認文が画面に出ないため、実行せずに中止しました。');
                $this->warn('次のどちらかにしてください。');
                $this->warn('  ・パイプを外して実行する（進捗が1行ずつ出ます）');
                $this->warn('  ・--yes を付けて確認を省略する');
                return;
            }
            if (!$this->confirm('実行しますか？', false)) {
                $this->info('中止しました。AI呼び出しは発生していません。');
                return;
            }
        }
        $this->line('');
        $this->info('開始します。1st AIは最長240秒待つため、1レースあたり数分かかります。');

        $controller = app(AiController::class);
        $prevAnswerMd5 = [];
        $dir = storage_path('logs/v04_hv_trial_' . $date . '_' . date('His'));
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); @chmod($dir, 0777); }

        foreach ($races as $r) {
            $label = "{$r->kaisuu}回{$r->basho} {$r->day}日目 {$r->race}R";
            $this->line('');
            $this->info("── {$label} ──────────────────────────────────────");

            AiController::v04ForceHvPrompt(true);     // メモリ上だけONにする
            AiController::$dryRunNoPersist  = true;   // DB・ファイルを書き換えない
            AiController::$dryRunForceFresh = true;   // 保存済み回答を使わず呼び直す
            AiController::$v04LastHvApplied = null;
            AiController::$v04LastAnswers   = [];
            // @ANCHOR-20261013-V04-HV-TRIAL  レースごとに2nd AI回答の控えを必ず捨てる
            //   @ANCHOR-20261004-DRY-RUN の $dryRunSecondText は
            //   「同じ回答で配分だけ変えて統合し直す」ための控えなので、
            //   レースをまたいで残っていると次のレースで前のレースの回答を使ってしまう。
            //   10/13の初回テストで、2レースの2nd生回答がmd5まで一致して気づいた。
            AiController::$dryRunSecondText = null;

            $base = sprintf('%s_%s回%s_%s日目_%02dR', $date, $r->kaisuu, $r->basho, $r->day, $r->race);
            $params = ['date' => $r->date, 'kaisuu' => $r->kaisuu, 'basho' => $r->basho,
                       'day'  => $r->day,  'race'   => (string) $r->race];

            DB::beginTransaction();
            $t0 = microtime(true);
            $elapsed1st = null; $elapsed2nd = null; $sendOk = false;
            try {
                // 送信文を控える（提出物にする）
                $prompt = $controller->v04BuildPromptForCheck(
                    $r->date, $r->kaisuu, $r->basho, $r->day, $r->race);
                if ($prompt === null) {
                    $this->error('  プロンプトを生成できません（オッズ不足の可能性）。AIは呼んでいません。');
                    DB::rollBack();
                    continue;
                }
                @file_put_contents($dir . '/' . $base . '_送信文.txt', $prompt);
                $this->line('  送信文: ' . number_format(strlen($prompt)) . ' バイト');

                $req1 = new Request();
                $req1->query->add($params);
                $this->line('  1st AI を呼び出し中…（最長240秒）');
                $s1 = microtime(true);
                $controller->getHorseOddsFinderAiAnalysis($req1);
                $elapsed1st = round(microtime(true) - $s1, 1);
                $this->line("  1st AI 応答: {$elapsed1st} 秒");

                $req2 = new Request();
                $req2->query->add($params);
                $this->line('  2nd AI を呼び出し中…（最長120秒）');
                $s2 = microtime(true);
                $res = $controller->getHorseOddsFinderSecondAiOpinion($req2);
                $elapsed2nd = round(microtime(true) - $s2, 1);
                $this->line("  2nd AI 応答: {$elapsed2nd} 秒");
                $sendOk = ($res->getStatusCode() === 200);
                $this->line('  統合の応答コード: ' . $res->getStatusCode()
                    . ($sendOk ? '（正常）' : '（異常）'));
            } catch (\Throwable $e) {
                $this->error('  例外: ' . $e->getMessage());
                $this->error('  ' . $e->getFile() . ':' . $e->getLine());
            } finally {
                DB::rollBack();                        // 何があっても書き戻さない
                AiController::$dryRunNoPersist  = false;
                AiController::$dryRunForceFresh = false;
                AiController::v04ForceHvPrompt(null);  // 必ず既定へ戻す
            }

            // 生回答を控える
            $answers = AiController::$v04LastAnswers;
            // 前のレースと同じ回答が来ていたら必ず表に出す（控えの取り違え検出）
            foreach (['1st', '2nd'] as $who) {
                $md5 = md5((string) ($answers[$who] ?? ''));
                if (($prevAnswerMd5[$who] ?? null) === $md5 && $md5 !== md5('')) {
                    $this->error("  【異常】{$who} の生回答が前のレースと完全に同一です"
                        . "（控えの取り違えの疑い）。この結果は使えません。");
                }
                $prevAnswerMd5[$who] = $md5;
            }
            foreach (['1st', '2nd'] as $who) {
                $txt = (string) ($answers[$who] ?? '');
                if ($txt !== '') {
                    @file_put_contents($dir . '/' . $base . "_生回答_{$who}.txt", $txt);
                    // @ANCHOR-20261013-V04-HV-TRIAL  どのブロックが返ってきたかを必ず出す
                    //   10/13のテストで、1st AIが時間切れになり「保存済みの古い回答」が
                    //   使われていたことに、ここの表示が無くて気づけなかった。
                    $hasOld = (strpos($txt, AiController::AUDIT_JSON_BEGIN) !== false);
                    $hasNew = (strpos($txt, AiController::V04_HV_JSON_BEGIN) !== false);
                    $this->line("  生回答{$who}: " . number_format(strlen($txt)) . ' バイト'
                        . '（1組目=現行採点:' . ($hasOld ? 'あり' : 'なし')
                        . ' ／ 2組目=研究用H/V:' . ($hasNew ? 'あり' : 'なし') . '）');
                    if (!$hasNew) {
                        $this->error("  【要確認】{$who} は研究用H/Vを返していません。");
                        $this->error('      考えられる原因：AIの時間切れ（このとき保存済みの古い回答が'
                            . '使われます）／出力が上限で切れた／指示に従わなかった');
                        $this->error('      laravel.log の [AI_TRUNCATED] と'
                            . ' [NO_VALID_CANDIDATES] を確認してください');
                    }
                } else {
                    $this->warn("  生回答{$who}: 空（通信失敗または未実行）");
                }
            }

            // 採点が選出まで渡ったか
            $applied = AiController::$v04LastHvApplied;
            if ($applied === null) {
                $this->error('  研究用H/V採点が受け取れませんでした（マーカー欠落・JSON不正・0頭など）。');
                $this->warn('  このとき仕様どおりの選出は0頭のままです。誤った選出は作りません。');
            } else {
                @file_put_contents($dir . '/' . $base . '_採点と選出.json',
                    json_encode($applied, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                $this->line('  出走頭数            : ' . $applied['出走頭数']);
                $this->line('  AI採点を受領した頭数: ' . $applied['AI採点を受領した頭数']
                    . '（' . $applied['ai_role'] . '）');
                $sc = $applied['score_status内訳'];
                $this->line("  score_status        : valid {$sc['valid']} ／ partial {$sc['partial']} ／ invalid {$sc['invalid']}");
                $tc = $applied['Tier別頭数'];
                $this->line("  Tier                : A {$tc['A']} ／ B {$tc['B']} ／ C {$tc['C']} ／ UNVERIFIED {$tc['UNVERIFIED']}");
                // @ANCHOR-20261013-V04-ABILITY-VERIFY  ability申告の検証結果を出す
                $ab = $applied['ability申告の検証'] ?? null;
                if (is_array($ab)) {
                    $this->line('  ability申告         : ' . $ab['ability申告のあった件数'] . '件'
                        . '（申告率 ' . $ab['申告率'] . '）'
                        . ' → 採用 ' . $ab['検証して採用'] . ' ／ 却下 ' . $ab['検証して却下']);
                    if (strpos((string) $ab['所見'], '甘い疑い') !== false) {
                        $this->warn('      ' . $ab['所見']);
                    }
                }
                $st = $applied['structure'];
                $this->line('  構造判定            : mode=' . ($st['mode'] ?? '-')
                    . ' ／ 配分=' . ($st['allocation_mode'] ?? '-'));
                $q = $applied['quota'];
                $this->line('  3帯の目標           : ' . ($q === null ? 'なし（target_count<4）'
                    : "A{$q['A']}／B{$q['B']}／D{$q['D']}") . '（C=' . $applied['target_count'] . '）');

                $sel = $applied['仕様どおりの選出'];
                $this->line('  仕様どおりの選出    : ' . (empty($sel['selected'])
                    ? '0頭' : implode('・', $sel['selected']) . '（' . count($sel['selected']) . '頭）'));
                if ($sel['PARTIAL_COUNT'] !== null) {
                    $this->line('    PARTIAL_COUNT     : 目標 ' . $sel['PARTIAL_COUNT']['目標']
                        . ' に対し実数 ' . $sel['PARTIAL_COUNT']['実数']);
                }
                if (!empty($sel['CROSS_BAND_FILL'])) {
                    $this->line('    CROSS_BAND_FILL   : ' . count($sel['CROSS_BAND_FILL']) . '件');
                }
                $ref = $applied['参考選出（採点なし）'];
                $this->line('  参考選出（別集計）  : ' . (empty($ref['selected'])
                    ? '0頭' : implode('・', $ref['selected']) . '（' . count($ref['selected']) . '頭）'));

                // 必須項目の欠落（02-5の欠損処理が効いているか）
                $miss = [];
                foreach ($applied['全頭'] as $hh) {
                    if (!empty($hh['missing_reasons'])) {
                        $miss[] = $hh['num'] . '番:' . implode(',', array_slice($hh['missing_reasons'], 0, 5));
                    }
                }
                if (empty($miss)) {
                    $this->info('  必須項目の欠落      : なし（全馬10項目そろっている）');
                } else {
                    $this->warn('  必須項目の欠落      : ' . count($miss) . '頭');
                    foreach (array_slice($miss, 0, 5) as $mm) { $this->warn('      ' . $mm); }
                    $this->warn('      → 欠けた項目は0点にせずnullのまま、合計もnullにしています');
                }
            }
        }

        $this->line('');
        $this->info('送信文・生回答・採点と選出の記録を書き出しました: ' . $dir);
        $this->line('');
        $this->warn('※DBとファイル（プロンプトの保存先・AI回答テーブル）は書き換えていません。');
        $this->warn('※公開候補・おすすめ度・画面表示には反映していません。');
        $this->warn('※BAGANRIKI_V04_HV_PROMPT は .env 上では既定OFFのままです。');
        $this->warn('※この結果は「新プロンプトが形式どおりに動くか」の確認です。');
        $this->warn('  予想が当たるかどうかとは別の話です。');
    }


    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261014-TRACE  段階別の追跡表（--v04-trace）
     * ═════════════════════════════════════════════════════════════════════════
     * 10/14のご依頼C「1st→2nd→PHP統合→Flutter公開まで、各段階の候補馬番、
     *   全頭採点、除外・購入回避フラグ、最終候補を同じレースIDで出力してください。
     *   候補がPHP側で意図せず消えていないことを確認します。」
     *
     * 保存済みのデータだけから作るので、AI呼び出しは0回・DBへの書き込みもなし。
     *   1st公開候補 … t_horse_odds_finder_ai_analysis.analysis_text
     *   2nd公開候補 … t_horse_odds_finder_ai_analysis2.analysis_text
     *   統合・公開  … t_horse_odds_finder_ai_merge_result.merge_result
     *   購入回避フラグ … merge_result に保存済み（Flutterへは渡していない内部値）
     */
    private function runV04Trace(string $date): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(" 段階別の追跡表  対象日 {$date}（読み取りだけ・AI呼び出し0回）");
        $this->info('══════════════════════════════════════════════════════════');

        $merges = DB::table('t_horse_odds_finder_ai_merge_result')
            ->where('date', $date)
            ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
            ->get();
        if ($merges->isEmpty()) {
            $this->error("{$date} の統合結果（ai_merge_result）がありません。");
            return;
        }
        $want = $this->traceRaceFilter();

        $out = [];
        foreach ($merges as $mg) {
            if (!empty($want) && !in_array((int) $mg->race, $want, true)) continue;

            $label  = "{$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R";
            $raceId = "{$date}_{$mg->kaisuu}_{$mg->basho_code}_{$mg->day}_{$mg->race}";
            $this->line('');
            $this->info("── {$label} ──────────────────────────────");
            $this->line("  race_id: {$raceId}");

            $dec    = json_decode((string) ($mg->merge_result ?? ''), true);
            $status = is_array($dec) ? (string) ($dec['status'] ?? '') : '';
            if ($status === 'undetermined') {
                $this->warn('  未確定（' . (string) ($dec['status_reason'] ?? '') . '）');
                $this->warn('  → 1st AIの回答が使えないレース。2ndだけの予想は出しません');
                $out[$raceId] = ['status' => 'undetermined',
                                 'reason' => (string) ($dec['status_reason'] ?? '')];
                continue;
            }
            $final = (is_array($dec) && is_array($dec['merged_horses'] ?? null)) ? $dec['merged_horses'] : [];

            // ── ① 1st / 2nd の公開候補（保存済み回答から）───────────────────
            $txt1 = DB::table('t_horse_odds_finder_ai_analysis')
                ->where('date', $date)->where('kaisuu', $mg->kaisuu)
                ->where('basho_code', $mg->basho_code)->where('day', $mg->day)
                ->where('race', $mg->race)->value('analysis_text');
            $txt2 = DB::table('t_horse_odds_finder_ai_analysis2')
                ->where('date', $date)->where('kaisuu', $mg->kaisuu)
                ->where('basho_code', $mg->basho_code)->where('day', $mg->day)
                ->where('race', $mg->race)->value('analysis_text');

            $ai1 = ($txt1 === null) ? [] : AiController::publicCandidateNums((string) $txt1);
            $ai2 = ($txt2 === null) ? [] : AiController::publicCandidateNums((string) $txt2);
            $aiUnion = array_values(array_unique(array_merge($ai1, $ai2)));
            sort($aiUnion);

            $finalNums = [];
            foreach ($final as $h) { if (isset($h['num'])) $finalNums[] = (int) $h['num']; }
            sort($finalNums);

            $this->line('');
            $this->line(sprintf('  ① 1st AI 公開候補 : %2d頭  [%s]', count($ai1), implode(',', $ai1)));
            $this->line(sprintf('  ② 2nd AI 公開候補 : %2d頭  [%s]', count($ai2), implode(',', $ai2)));
            $this->line(sprintf('  ③ 両AIの合計(重複除く): %2d頭  [%s]', count($aiUnion), implode(',', $aiUnion)));
            $this->line(sprintf('  ④ PHP統合・公開候補: %2d頭  [%s]', count($finalNums), implode(',', $finalNums)));
            $this->line('     ※④がFlutterへ渡る候補です（内部値だけ落として同じ馬番）');

            // ── ② PHPで消えた馬／PHPが起こした馬 ───────────────────────────
            $dropped = array_values(array_diff($aiUnion, $finalNums));
            $added   = array_values(array_diff($finalNums, $aiUnion));
            if (empty($dropped)) {
                $this->info('  ⑤ PHPで消えた馬 : なし');
            } else {
                $this->warn('  ⑤ PHPで消えた馬 : ' . implode(',', $dropped) . ' 番');
                // ══════════════════════════════════════════════════════════
                // @ANCHOR-20261014-TRACE-WHY  消えた馬が「どこで落ちたか」を実値で出す
                // ══════════════════════════════════════════════════════════
                // 10/14のご依頼C「候補がPHP側で意図せず消えていないことを確認」への対応。
                // 【旧実装の問題】
                //   消えた馬番は出していたが、理由は「落とし得る箇所は4つです」という
                //   一般論しか書いていなかった。これでは意図した絞り込みなのか、
                //   取りこぼしなのか区別できない。
                // 【今回】
                //   統合時の監査ログ（AUDIT_MERGE の『全頭』）は merge_result の
                //   audit_merge としてDBに保存されている（@ANCHOR-20261004-AUDIT-MERGE）。
                //   そこに1頭ごとの『理由』『統合点』『人気』『帯』が入っているので、
                //   AI呼び出しを一切せずに、保存済みの実値をそのまま表示する。
                $auditAll = [];
                if (is_array($dec['audit_merge'] ?? null)
                    && is_array($dec['audit_merge']['全頭'] ?? null)) {
                    $auditAll = $dec['audit_merge']['全頭'];
                }
                foreach ($dropped as $dn) {
                    if (in_array($dn, $ai1, true) && in_array($dn, $ai2, true)) $who = '両AIが出していた';
                    elseif (in_array($dn, $ai1, true)) $who = '1stが出していた';
                    else $who = '2ndが出していた';

                    $row = $auditAll[(string) $dn] ?? ($auditAll[$dn] ?? null);
                    if (is_array($row)) {
                        $this->warn(sprintf(
                            '       %2d番 … %s／非選出の理由: %s',
                            $dn, $who, (string) ($row['理由'] ?? '（理由の記録なし）')
                        ));
                        $this->line(sprintf(
                            '              人気%s・統合点%s・帯%s（帯内%s位）・統合順位%s位'
                            . '／最低基準点未満:%s・購入回避:%s',
                            $row['人気']     ?? '－', $row['統合点'] ?? '－',
                            $row['帯']       ?? '－', $row['帯内順位'] ?? '－',
                            $row['統合順位'] ?? '－',
                            !empty($row['購入見送り']['最低基準点未満']) ? 'はい' : 'いいえ',
                            !empty($row['購入見送り']['購入回避'])       ? 'はい' : 'いいえ'
                        ));
                    } else {
                        // audit_merge が無い（この日より前に統合した）レース
                        $this->warn("       {$dn}番 … {$who}。統合時の監査ログがこのレースには"
                            . '保存されていないため、理由を実値で出せません'
                            . '（--remerge で統合をやり直すと記録されます。AI呼び出しは発生しません）');
                    }
                }
            }
            if (!empty($added)) {
                $this->line('  ⑥ PHPが起こした馬 : ' . implode(',', $added) . ' 番'
                    . '（検証済みの全頭採点から候補行を生成した馬。選出理由に明記されます）');
            }

            // ── ③ 各馬の内訳（おすすめ度・購入回避フラグ）───────────────────
            $this->line('');
            $this->line('  ⑦ 公開候補の内訳');
            $this->line('     馬番  人気  おすすめ度  出所      購入回避フラグ');
            $detail = [];
            foreach ($final as $h) {
                if (!isset($h['num'])) continue;
                $n   = (int) $h['num'];
                $src = (in_array($n, $ai1, true) && in_array($n, $ai2, true)) ? '両AI'
                     : (in_array($n, $ai1, true) ? '1st ' : (in_array($n, $ai2, true) ? '2nd ' : 'PHP生成'));
                $fl = [];
                if (!empty($h['buy_avoid_low_payout'])) $fl[] = '低配当';
                if (!empty($h['buy_avoid_recovery']))   $fl[] = '回収率40%未満';
                if (!empty($h['buy_avoid_min_score']))  $fl[] = '最低基準点';
                $this->line(sprintf('     %4d  %4s  %10s  %-8s  %s',
                    $n,
                    isset($h['popularity']) ? (string) $h['popularity'] : '-',
                    isset($h['score']) ? (string) $h['score'] : '-',
                    $src,
                    empty($fl) ? 'なし' : implode('・', $fl)));
                $detail[] = ['num' => $n, '人気' => $h['popularity'] ?? null,
                             'おすすめ度' => $h['score'] ?? null, '出所' => trim($src),
                             '購入回避フラグ' => $fl];
            }
            $flagged = count(array_filter($detail, static fn($d) => !empty($d['購入回避フラグ'])));
            $this->line('');
            $this->line("     購入回避フラグが付いた馬: {$flagged}頭 ／ 公開候補: " . count($detail) . '頭');
            if ($flagged > 0) {
                $this->info('     → フラグが付いていても候補からは消えていません（10/4・10/6の変更どおり）');
            }

            $out[$raceId] = [
                'label' => $label,
                '1st公開候補' => $ai1, '2nd公開候補' => $ai2,
                '両AI合計' => $aiUnion, '統合・公開候補' => $finalNums,
                'PHPで消えた馬' => $dropped, 'PHPが起こした馬' => $added,
                '内訳' => $detail,
                '購入回避フラグ付き' => $flagged,
            ];
        }

        $this->line('');
        if (empty($out)) { $this->warn('対象レースがありませんでした（--race の指定を確認してください）。'); return; }

        $sumDrop = 0;
        foreach ($out as $r) { $sumDrop += count($r['PHPで消えた馬'] ?? []); }
        $this->info('══════════════════════════════════════════════════════════');
        $this->info('  対象 ' . count($out) . ' レース ／ PHPで消えた馬 合計 ' . $sumDrop . ' 頭');
        $this->info('══════════════════════════════════════════════════════════');

        $path = storage_path('logs/v04_trace_' . $date . '.json');
        @file_put_contents($path, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info('追跡表を書き出しました: ' . $path);
        $this->line('');
        $this->warn('※1st・2ndの公開候補は保存済み回答から数えています。AI呼び出しは0回です。');
        $this->warn('※購入回避フラグは merge_result に保存された内部値です。Flutterへは渡していません。');
        $this->warn('※「PHPで消えた馬」が0頭なら、AIが出した候補はすべて公開されています。');
    }

    /** @ANCHOR-20261014-TRACE  --race= をレース番号の配列にする */
    private function traceRaceFilter(): array
    {
        $opt = (string) ($this->option('race') ?? '');
        if ($opt === '') return [];
        $want = [];
        foreach (preg_split('/[,\s]+/', $opt) as $v) {
            if ($v !== '' && ctype_digit($v)) $want[] = (int) $v;
        }
        if (!empty($want)) {
            $this->warn('--race の指定により対象R: ' . implode(',', $want));
        }
        return $want;
    }

    /**
     * @ANCHOR-20261014-EXCLUSION-LIST  候補を落とし得る箇所の全件（--v04-exclusions）
     *   10/14のご依頼F「既存の他の除外条件についても全件リストを提示してください」
     */
    private function runV04Exclusions(): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 候補を落とし得る箇所の全件リスト（読み取りだけ）');
        $this->info('══════════════════════════════════════════════════════════');
        $groups = [
            'REDUCE' => '実際に公開候補の頭数が減る箇所',
            'FLAG'   => '印をつけるだけで頭数を減らさない箇所',
            'LOG'    => '記録だけで頭数を減らさない箇所（廃止済みを含む）',
            'v0.4'   => 'v0.4研究経路だけで効く条件（公開候補には影響しない）',
        ];
        foreach ($groups as $key => $title) {
            $rows = array_values(array_filter(AiController::V04_EXCLUSION_POINTS,
                static fn($r) => $r[0] === $key));
            $this->line('');
            $this->info("── {$title}（" . count($rows) . '件）──────────────');
            foreach ($rows as $i => $r) {
                $this->line(sprintf('  %d. %s', $i + 1, $r[1]));
                $this->line('     該当: ' . $r[2]);
                $this->line('     内容: ' . $r[3]);
            }
        }
        $this->line('');
        // 件数は定数から数える（10/14に1件追記したため、固定値で書かないようにした）
        $redCnt = count(array_filter(AiController::V04_EXCLUSION_POINTS,
            static fn($r) => $r[0] === 'REDUCE'));
        $this->warn("※「実際に頭数が減る箇所」はこの{$redCnt}件だけです。");
        $this->warn('※うち「資格未達の7番人気以下は数合わせに使わない」は、目標数Cに達していなくても');
        $this->warn('  補充しない条件です（仕様書6.2）。公開頭数がC未満になり得ますが公開は止めません。');
        $this->warn('※低配当・回収率40%未満・最低基準点・11番人気以下の条件・旧資格は、');
        $this->warn('  いずれも印をつけるだけで候補からは消しません（10/4・10/6の変更）。');
        $this->warn('※人気帯別上限・2nd独自枠上限・断層位置別上限・大穴進入度の割り当ては廃止済みで、');
        $this->warn('  値は監査ログに残しますが候補を削る処理には使っていません。');
    }

    /**
     * @ANCHOR-20261014-RACE-GUARD  レースをまたぐ混入の再現テスト（--v04-raceguard-test）
     *   10/14のご依頼H「同一プロセスでレースA→Bを連続処理し、1st/2ndの回答・
     *     プロンプト・キャッシュが混入しない再現テストを実施してください」
     *   AI呼び出しは0回。送信文を2レース連続で組み立て、控えが持ち越されないことを見る。
     */
    private function runV04RaceGuardTest(string $date): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' レースをまたぐ混入の再現テスト（AI呼び出し0回・費用0円）');
        $this->info('══════════════════════════════════════════════════════════');

        $races = DB::table('t_horse_odds_finder_races')->where('date', $date)
            ->orderBy('kaisuu')->orderBy('basho')->orderBy('race')
            ->get(['date', 'kaisuu', 'basho', 'day', 'race']);
        if ($races->count() < 2) {
            $this->error("{$date} に2レース以上のデータがありません（この試験は2レース必要です）。");
            return;
        }
        $a = $races[0];
        $b = null;
        foreach ($races as $r) {
            if ($r->race !== $a->race || $r->basho !== $a->basho) { $b = $r; break; }
        }
        if ($b === null) { $this->error('別レースが見つかりません。'); return; }

        $controller = app(AiController::class);
        $la = "{$a->kaisuu}回{$a->basho} {$a->day}日目 {$a->race}R";
        $lb = "{$b->kaisuu}回{$b->basho} {$b->day}日目 {$b->race}R";
        $this->line("  レースA: {$la}");
        $this->line("  レースB: {$lb}");
        $this->line('');

        $ng = [];

        // レースAの送信文を作り、1st回答の控えを意図的に仕込む
        $pa = $controller->v04BuildPromptForCheck($a->date, $a->kaisuu, $a->basho, $a->day, $a->race);
        if ($pa === null) { $this->error('レースAの送信文を作れません。'); return; }
        AiController::$dryRunFirstText   = 'レースAの1st回答（ダミー）';
        AiController::$dryRunFirstPrompt = 'レースAの送信文（ダミー）';
        AiController::$dryRunSecondText  = 'レースAの2nd回答（ダミー）';
        $this->line('  レースAの控えを仕込みました（1st回答・送信文・2nd回答）');

        // レースBの送信文を作る → ここでガードが働くはず
        $pb = $controller->v04BuildPromptForCheck($b->date, $b->kaisuu, $b->basho, $b->day, $b->race);
        if ($pb === null) { $this->error('レースBの送信文を作れません。'); return; }

        $chk = [
            '1st回答の控え'   => AiController::$dryRunFirstText,
            '1st送信文の控え' => AiController::$dryRunFirstPrompt,
            '2nd回答の控え'   => AiController::$dryRunSecondText,
        ];
        foreach ($chk as $name => $val) {
            if ($val === null) {
                $this->info("  OK  {$name} はレースBの処理開始時に捨てられている");
            } else {
                $ng[] = "{$name} がレースBへ持ち越されている: " . mb_substr((string) $val, 0, 30);
                $this->error("  NG  {$name} が持ち越されている");
            }
        }

        // 送信文そのものが混ざっていないか（レース番号・馬番の並びで確認）
        if ($pa === $pb) {
            $ng[] = 'レースAとBの送信文が完全一致している（同じレースを2回読んでいる疑い）';
            $this->error('  NG  レースAとBの送信文が完全一致');
        } else {
            $this->info('  OK  レースAとBの送信文は別物（' . number_format(strlen($pa))
                . ' / ' . number_format(strlen($pb)) . ' バイト）');
        }
        if (strpos($pb, "{$a->race}R") !== false && $a->race !== $b->race) {
            $this->warn('  注意 レースBの送信文にレースAのレース番号表記が含まれています（文面の参照の可能性）');
        }

        // 過去走件数の控えがレースBのものに入れ替わっているか
        $runs = AiController::$v04PastRunCount;
        $this->line('  過去走件数の控え: ' . count($runs) . '頭ぶん（レースBの出走馬ぶんであること）');

        $this->line('');
        if (empty($ng)) {
            $this->info('══════════════════════════════════════════════════════════');
            $this->info('  合格：レースをまたいだ混入はありません');
            $this->info('══════════════════════════════════════════════════════════');
        } else {
            $this->error('══════════════════════════════════════════════════════════');
            $this->error('  不合格：' . count($ng) . ' 件');
            foreach ($ng as $x) { $this->error('   ・' . $x); }
            $this->error('══════════════════════════════════════════════════════════');
        }
        AiController::$dryRunFirstText = null;
        AiController::$dryRunFirstPrompt = null;
        AiController::$dryRunSecondText = null;
        $this->line('');
        $this->warn('※AIへは1回も送っていません。DBにも書いていません。');
        $this->warn('※laravel.log に [RACE_GUARD] として、捨てた控えの名前が残ります。');
    }


    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261014-FILL-COMPARE  不足枠の補充方式A・B・Cの比較（--fill-compare）
     * ═════════════════════════════════════════════════════════════════════════
     * 10月14日（2回目）のご依頼①への対応です。
     *   「次の3方式を同一レースで比較してください。
     *      A：現行の資格条件を維持
     *      B：資格を問わず統合点順で不足枠を補充
     *      C：新v0.4の独立根拠・Tier・H/V採点を利用して補充
     *    C方式では、独立根拠が確認できない馬を頭数合わせで採用しないでください。」
     *
     * 【本番の選出は変えません】
     *   このコマンドは保存済みの統合結果（merge_result の audit_merge）を読んで
     *   B・Cを「計算して並べるだけ」です。DBへの書き込みもAIの呼び出しもしません。
     *   現行の公開候補はAのまま動き続けます（ご依頼②のスイッチ維持に合わせています）。
     *
     * 【補充に使わない馬（ご依頼①-3「取消・データ不整合・採点不能などの馬は補充しない」）】
     *   ・統合点が無い馬（採点不能・事前フィルターで統合前に落ちた馬）
     *   ・着順データに居ない馬（取消・除外）
     *   ・C方式ではさらに「独立根拠が2系統以上」でない馬
     *   いずれも架空の馬や点数を作ることはしません（ご依頼①-7）。
     */
    private function runFillCompare(string $date): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(" 不足枠の補充方式 A・B・C の比較  対象日 {$date}");
        $this->info('  （読み取りだけ・DBへの書き込みなし・AI呼び出し0回）');
        $this->info('══════════════════════════════════════════════════════════');

        $merges = DB::table('t_horse_odds_finder_ai_merge_result')
            ->where('date', $date)
            ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
            ->get();
        if ($merges->isEmpty()) {
            $this->error("{$date} の統合結果（ai_merge_result）がありません。");
            return;
        }
        $want = $this->traceRaceFilter();

        // ── 着順（3着内・5着内の判定に使う）────────────────────────────
        $resMap = [];
        foreach (DB::table('t_horse_odds_finder_race_results')->where('date', $date)->get() as $r) {
            $k = $r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race;
            $resMap[$k][(int) $r->num] = (int) $r->result;
        }
        // ── 複勝払戻（想定回収率に使う。"馬番|払戻/馬番|払戻" の形）──────
        $payMap = [];
        foreach (DB::table('t_horse_odds_finder_race_result_payout')->where('date', $date)->get() as $r) {
            $k = $r->kaisuu . '|' . $r->basho_code . '|' . $r->day . '|' . $r->race;
            foreach (explode('/', (string) ($r->fuku ?? '')) as $piece) {
                $pair = explode('|', trim($piece));
                if (count($pair) === 2 && ctype_digit(trim($pair[0]))) {
                    $payMap[$k][(int) trim($pair[0])] = (int) preg_replace('/\D/', '', $pair[1]);
                }
            }
        }
        if (empty($resMap)) {
            $this->warn('※この日の着順データがありません（洗い替えで消えている可能性があります）。');
            $this->warn('　公開頭数と人気帯の比較だけ出します。捕捉率・回収率は「－」になります。');
        }

        // 方式ごとの合計
        $sum = [];
        foreach (['A', 'B', 'C'] as $m) {
            $sum[$m] = ['頭数' => 0, '人気側' => 0, '中穴' => 0, '大穴' => 0,
                        '3着内' => 0, '5着内' => 0,
                        '人気薄3着内' => 0, '人気薄5着内' => 0,
                        '払戻' => 0, '対象レース' => 0, '補充' => 0,
                        '購入推奨' => 0, '推奨3着内' => 0, '推奨払戻' => 0];
        }
        $out          = [];
        $cSkipRaces   = 0;   // C方式が算出できなかったレース数
        $shortRaces   = 0;   // A方式で目標数Cに届かなかったレース数
        // @ANCHOR-20261014-FILL-LIST  10/14（4回目）ご指摘②の補充馬一覧
        $fillList     = [];  // 補充馬を1頭ずつ集めて最後に一覧で出す

        foreach ($merges as $mg) {
            if (!empty($want) && !in_array((int) $mg->race, $want, true)) continue;

            $dec = json_decode((string) ($mg->merge_result ?? ''), true);
            if (!is_array($dec)) continue;
            if ((string) ($dec['status'] ?? '') === 'undetermined') continue;

            $audit = (is_array($dec['audit_merge'] ?? null)) ? $dec['audit_merge'] : [];
            $rows  = (is_array($audit['全頭'] ?? null)) ? $audit['全頭'] : [];
            if (empty($rows)) continue;

            $targetC = (int) ($audit['目標数C'] ?? 0);
            if ($targetC <= 0) continue;

            $label  = "{$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R";
            $raceId = "{$date}_{$mg->kaisuu}_{$mg->basho_code}_{$mg->day}_{$mg->race}";
            $key    = $mg->kaisuu . '|' . $mg->basho_code . '|' . $mg->day . '|' . $mg->race;
            $results = $resMap[$key] ?? [];
            $pays    = $payMap[$key] ?? [];

            // ── A方式 = 現行の公開候補（実際にFlutterへ渡した馬）────────
            $aNums = [];
            // @ANCHOR-20261014-FILL-MODE  購入推奨の判定に使う購入回避フラグ
            //   ご依頼「購入推奨対象の回収率を分けて報告してください」への対応。
            //   既存のフラグをそのまま読むだけで、判定内容は変えていません。
            $flagMap = [];
            foreach ((is_array($dec['merged_horses'] ?? null) ? $dec['merged_horses'] : []) as $h) {
                if (!isset($h['num'])) continue;
                $hn = (int) $h['num'];
                $aNums[] = $hn;
                $flagMap[$hn] = [
                    'below_min_score'      => !empty($h['below_min_score']),
                    'buy_avoid_longshot'   => !empty($h['buy_avoid_longshot']),
                    'buy_avoid_low_payout' => !empty($h['buy_avoid_low_payout']),
                    'buy_avoid_recovery'   => !empty($h['buy_avoid_recovery']),
                ];
            }
            sort($aNums);
            if (count($aNums) < $targetC) $shortRaces++;

            // ── 補充候補の算出 ───────────────────────────────────────────
            //   @ANCHOR-20261014-FILL-CORE  計算は AiController 側の共通処理です。
            //   同じ関数を --v04-test の F-T01〜F-T14 で検証しているので、
            //   「試したコード」と「動くコード」を別にしないためここから呼びます。
            $fill  = AiController::v04FillCandidates($rows, $aNums, $targetC, $results);
            $need  = $fill['need'];
            $bAdd  = $fill['B'];
            $bNums = $aNums; foreach ($bAdd as $v) $bNums[] = $v; sort($bNums);

            $cAdd = $fill['C'];
            if ($cAdd === null) {
                // このレースには独立根拠の記録が無いのでC方式は出せない
                $cNums = null; $cAdd = []; $cSkipRaces++;
            } else {
                $cNums = $aNums; foreach ($cAdd as $v) $cNums[] = $v; sort($cNums);
            }

            // ── 方式ごとの集計 ───────────────────────────────────────────
            $calc = function (array $nums, array $fillAdd = []) use ($rows, $results, $pays, $flagMap) {
                $r = ['頭数' => count($nums), '人気側' => 0, '中穴' => 0, '大穴' => 0,
                      '3着内' => 0, '5着内' => 0, '人気薄3着内' => 0, '人気薄5着内' => 0,
                      '払戻' => 0,
                      // @ANCHOR-20261014-FILL-MODE  購入推奨対象だけの集計
                      '購入推奨' => 0, '推奨3着内' => 0, '推奨払戻' => 0];
                foreach ($nums as $num) {
                    $row = $rows[(string) $num] ?? ($rows[$num] ?? []);
                    $pop = (int) ($row['人気'] ?? 0);
                    if ($pop >= 1 && $pop <= 6)       $r['人気側']++;
                    elseif ($pop >= 7 && $pop <= 10)  $r['中穴']++;
                    elseif ($pop >= 11)               $r['大穴']++;

                    if (array_key_exists($num, $results)) {
                        $pos = $results[$num];
                        if ($pos >= 1 && $pos <= 3) { $r['3着内']++; if ($pop >= 7) $r['人気薄3着内']++; }
                        if ($pos >= 1 && $pos <= 5) { $r['5着内']++; if ($pop >= 7) $r['人気薄5着内']++; }
                    }
                    $r['払戻'] += (int) ($pays[$num] ?? 0);

                    // ── 購入推奨対象（公開候補のうち買う価値があるとした馬）──
                    //   補充枠の馬は、ご依頼③により購入推奨に入れません。
                    //   そのうえで既存の購入回避フラグが立っている馬も外します。
                    if (in_array($num, $fillAdd, true)) continue;
                    $fl = $flagMap[$num] ?? [];
                    if (!empty($fl['below_min_score'])      || !empty($fl['buy_avoid_longshot'])
                        || !empty($fl['buy_avoid_low_payout']) || !empty($fl['buy_avoid_recovery'])) {
                        continue;
                    }
                    $r['購入推奨']++;
                    $r['推奨払戻'] += (int) ($pays[$num] ?? 0);
                    if (array_key_exists($num, $results) && $results[$num] >= 1
                        && $results[$num] <= 3) {
                        $r['推奨3着内']++;
                    }
                }
                return $r;
            };
            $mA = $calc($aNums);
            $mB = $calc($bNums, $bAdd);
            $mC = ($cNums === null) ? null : $calc($cNums, $cAdd);

            foreach ([['A', $mA, []], ['B', $mB, $bAdd], ['C', $mC, $cAdd]] as [$name, $m, $add]) {
                if ($m === null) continue;
                foreach (['頭数','人気側','中穴','大穴','3着内','5着内','人気薄3着内','人気薄5着内',
                          '払戻','購入推奨','推奨3着内','推奨払戻'] as $f) {
                    $sum[$name][$f] += $m[$f];
                }
                $sum[$name]['対象レース']++;
                $sum[$name]['補充'] += count($add);
            }

            // ── レースごとの表示（補充が起きたレースだけ詳しく出す）──────
            $this->line('');
            $this->info("── {$label} ──────────────────────────────");
            $this->line("  race_id: {$raceId}　出走人気帯の目標数C: {$targetC}");
            // ご指摘⑥「同一レース、同一オッズ取得時点で比較すること」
            //   A・B・Cはこの1つの保存済みスナップショットから算出しています。
            foreach ($this->snapshotLines($dec) as $sl) $this->line($sl);
            $this->line(sprintf('  A 現行       : %d頭 [%s]', count($aNums), implode(',', $aNums)));
            if ($need === 0) {
                $this->line('  → 目標数Cを満たしているため、B・Cの補充はありません（3方式とも同じ）');
            } else {
                $this->warn("  → 目標数Cに {$need}頭 不足しています");
                $this->line(sprintf('  B 点数順補充 : %d頭 [%s]%s',
                    count($bNums), implode(',', $bNums),
                    empty($bAdd) ? '　（補充できる馬がいません）' : '　補充: ' . implode(',', $bAdd) . '番'));
                if ($cNums === null) {
                    $this->line('  C 根拠で補充 : 算出できません（このレースに独立根拠の記録がありません）');
                } else {
                    $this->line(sprintf('  C 根拠で補充 : %d頭 [%s]%s',
                        count($cNums), implode(',', $cNums),
                        empty($cAdd) ? '　（独立根拠2系統以上の馬がいません）' : '　補充: ' . implode(',', $cAdd) . '番'));
                }
                // 補充された馬の内訳（通常選出と区別して出す）
                foreach (array_unique(array_merge($bAdd, $cAdd)) as $num) {
                    $row  = $rows[(string) $num] ?? ($rows[$num] ?? []);
                    $mark = [];
                    if (in_array($num, $bAdd, true)) $mark[] = 'B';
                    if (in_array($num, $cAdd, true)) $mark[] = 'C';
                    $pos  = array_key_exists($num, $results) ? $results[$num] . '着' : '着順なし';
                    $pay  = (int) ($pays[$num] ?? 0);
                    $this->line(sprintf(
                        '      %2d番（%sで補充）人気%s・統合点%s・独立根拠%s系統・Tier%s → %s・複勝払戻%d円',
                        $num, implode('と', $mark),
                        $row['人気'] ?? '－', $row['統合点'] ?? '－',
                        is_array($row['独立根拠'] ?? null) ? ($row['独立根拠']['独立群数'] ?? '記録なし') : '記録なし',
                        is_array($row['独立根拠'] ?? null) ? ($row['独立根拠']['tier'] ?? '記録なし') : '記録なし',
                        $pos, $pay
                    ));
                    $this->line('           もとの非選出理由: ' . (string) ($row['理由'] ?? '（記録なし）'));
                    // @ANCHOR-20261014-FILL-LIST  一覧用に控える
                    $ev = is_array($row['独立根拠'] ?? null) ? $row['独立根拠'] : null;
                    $fillList[] = [
                        'レース'   => $label,
                        'race_id'  => $raceId,
                        '馬番'     => $num,
                        '方式'     => implode('と', $mark),
                        '人気'     => $row['人気'] ?? null,
                        '統合点'   => $row['統合点'] ?? null,
                        '独立群数' => ($ev === null) ? null : ($ev['独立群数'] ?? null),
                        'Tier'     => ($ev === null) ? null : ($ev['tier'] ?? null),
                        '着順'     => array_key_exists($num, $results) ? $results[$num] : null,
                        '複勝払戻' => (int) ($pays[$num] ?? 0),
                        '非選出理由' => (string) ($row['理由'] ?? ''),
                        '判定数値' => ($ev === null) ? null : ($ev['判定数値'] ?? null),
                    ];
                }
            }

            $out[$raceId] = [
                '目標数C' => $targetC,
                'A' => ['馬番' => $aNums, '集計' => $mA],
                'B' => ['馬番' => $bNums, '補充' => $bAdd, '集計' => $mB],
                'C' => ($cNums === null)
                    ? ['算出不可' => '独立根拠の記録なし']
                    : ['馬番' => $cNums, '補充' => $cAdd, '集計' => $mC],
            ];
        }

        // ── 合計 ──────────────────────────────────────────────────────────
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 合計（' . $date . '）');
        $this->info('══════════════════════════════════════════════════════════');
        $names = ['A' => 'A 現行      ', 'B' => 'B 点数順補充', 'C' => 'C 根拠で補充'];

        $this->line('【表1】公開候補（画面に出す候補）');
        $this->line('  方式         レース 公開頭数 補充 人気側 中穴 大穴 3着内 5着内 人気薄3着内 人気薄5着内 複勝回収率');
        foreach ($names as $m => $nm) {
            $v = $sum[$m];
            if ($v['対象レース'] === 0) {
                $this->line("  {$nm} 算出できたレースがありません");
                continue;
            }
            $rec = ($v['頭数'] > 0 && !empty($resMap))
                ? sprintf('%5.1f%%', $v['払戻'] / ($v['頭数'] * 100) * 100) : '    －';
            $this->line(sprintf('  %s %4d %7d %5d %6d %4d %4d %5s %5s %10s %11s %s',
                $nm, $v['対象レース'], $v['頭数'], $v['補充'],
                $v['人気側'], $v['中穴'], $v['大穴'],
                empty($resMap) ? '－' : (string) $v['3着内'],
                empty($resMap) ? '－' : (string) $v['5着内'],
                empty($resMap) ? '－' : (string) $v['人気薄3着内'],
                empty($resMap) ? '－' : (string) $v['人気薄5着内'],
                $rec));
        }

        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261014-FILL-MODE  購入推奨対象だけの成績（公開候補と分ける）
        // ══════════════════════════════════════════════════════════════════
        // ご依頼「人気薄捕捉率、3着以内捕捉率、複勝回収率、購入推奨対象の
        //   回収率を分けて報告してください」への対応です。
        // 補充枠の馬は購入推奨に入れないため、B方式・C方式の購入推奨対象は
        // A方式と同じ馬になります。公開候補を広げても購入成績は動きません。
        $this->line('');
        $this->line('【表2】購入推奨対象（買う価値があるとした馬だけ）');
        $this->line('  方式         購入推奨 3着内 複勝回収率');
        foreach ($names as $m => $nm) {
            $v = $sum[$m];
            if ($v['対象レース'] === 0) continue;
            $rec2 = ($v['購入推奨'] > 0 && !empty($resMap))
                ? sprintf('%5.1f%%', $v['推奨払戻'] / ($v['購入推奨'] * 100) * 100) : '    －';
            $this->line(sprintf('  %s %7d %5s %s', $nm, $v['購入推奨'],
                empty($resMap) ? '－' : (string) $v['推奨3着内'], $rec2));
        }
        $this->line('');
        $this->warn('※表2は、補充枠の馬と、購入回避フラグ（低配当・回収率40%未満・最低基準点未満・');
        $this->warn('  大穴条件の不成立）が立った馬を外した集計です。既存の購入回避条件は変えていません。');
        $this->line('');
        $this->warn("※A方式で目標数Cに届かなかったレース: {$shortRaces}件");
        if ($cSkipRaces > 0) {
            $this->warn("※C方式が算出できなかったレース: {$cSkipRaces}件");
            $this->warn('　独立根拠を統合の監査ログに残すようにしたのは10月14日です。');
            $this->warn('　それ以前のレースには記録がないため、C方式は10月17日以降のレースで出せます。');
        }
        $this->warn('※「人気薄」は7番人気以下です。複勝回収率は公開候補を1頭100円ずつ買った場合の想定値です。');
        $this->warn('※公開候補の比較であり、馬券の購入推奨ではありません（候補の表示と購入判断は分けています）。');

        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261014-FILL-LIST  補充馬の一覧（10/14（4回目）ご指摘②）
        // ══════════════════════════════════════════════════════════════════
        // 「補充馬の人気順位、着順、選出根拠を一覧で提示すること」への対応です。
        if (!empty($fillList)) {
            $this->line('');
            $this->info('══════════════════════════════════════════════════════════');
            $this->info(' 補充馬の一覧（' . count($fillList) . '頭）');
            $this->info('══════════════════════════════════════════════════════════');
            $this->line('  レース                馬番 人気 統合点 独立根拠 Tier 着順 複勝払戻');
            $fl3 = 0; $fl5 = 0; $flPay = 0; $flPop7 = 0;
            foreach ($fillList as $f) {
                $this->line(sprintf('  %-20s %3d %4s %6s %8s %4s %4s %7d',
                    $f['レース'], $f['馬番'],
                    $f['人気']     ?? '－', $f['統合点'] ?? '－',
                    ($f['独立群数'] === null) ? '記録なし' : ($f['独立群数'] . '系統'),
                    $f['Tier']     ?? '－',
                    ($f['着順'] === null) ? '－' : ($f['着順'] . '着'),
                    $f['複勝払戻']));
                if ((int) ($f['人気'] ?? 0) >= 7) $flPop7++;
                if ($f['着順'] !== null && $f['着順'] <= 3) $fl3++;
                if ($f['着順'] !== null && $f['着順'] <= 5) $fl5++;
                $flPay += (int) $f['複勝払戻'];
            }
            $this->line('');
            $this->line(sprintf('  うち7番人気以下: %d頭 / %d頭', $flPop7, count($fillList)));
            $this->line(sprintf('  3着内: %d頭　5着内: %d頭　複勝払戻合計: %d円　複勝回収率: %.1f%%',
                $fl3, $fl5, $flPay, $flPay / (count($fillList) * 100) * 100));
            $this->warn('  ※この一覧の馬は購入推奨に入れていません（表2に含みません）。');
            $this->warn('  ※独立根拠が「記録なし」のレースは、独立根拠を監査ログに残す前のものです。');
            $this->warn('  　判定数値（短縮率・人気順位差・断層・OPI・類似統計の母数）は');
            $this->warn('  　書き出したJSONの「判定数値」に入ります。');
        }

        $path = storage_path('logs/fill_compare_' . $date . '.json');
        @file_put_contents($path, json_encode(
            ['date' => $date, '合計' => $sum, '補充馬一覧' => $fillList, 'レース別' => $out],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $this->info('比較結果を書き出しました: ' . $path);
    }


    /**
     * @ANCHOR-20261014-SNAPSHOT-SHOW  オッズ取得時点を1行で出す
     *   10/14（4回目）ご指摘⑥「同一レース、同一オッズ取得時点で比較すること。
     *   特に締切6分前の情報を統一したい」への対応です。
     *   merge_result の snapshot には、プロンプトへ凍結した時点のIDと
     *   統合時のIDの両方が入っているので、両方と一致判定を出します。
     */
    private function snapshotLines(array $dec): array
    {
        $sn = is_array($dec['snapshot'] ?? null) ? $dec['snapshot'] : [];
        if (empty($sn)) {
            return ['  オッズ取得時点: 記録なし（この日より前の統合結果）'];
        }
        $pid = (string) ($sn['prompt_snapshot_id'] ?? '');
        $mid = (string) ($sn['merge_snapshot_id']  ?? '');
        $ok  = (($sn['match'] ?? null) === true) && ($pid !== '') && ($pid === $mid);
        return [
            '  オッズ取得時点: 発走' . (string) ($sn['timing'] ?? '?') . '分前'
                . '　正規化: ' . (string) ($sn['normalize'] ?? '－'),
            '  snapshot_id   : プロンプト凍結 ' . ($pid === '' ? '記録なし' : $pid)
                . '　／ 統合時 ' . ($mid === '' ? '記録なし' : $mid)
                . '　→ ' . ($ok ? '一致' : '不一致（確認が必要）'),
            '  入力の混在    : ' . ((($sn['mixed_inputs'] ?? null) === true) ? 'あり（確認が必要）' : 'なし')
                . '　snapshot内のrace_id: ' . (string) ($sn['race_id'] ?? '記録なし'),
        ];
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261014-FILL-PROOF  方式を変えても変わらないことの確認（--fill-mode-proof）
     * ═════════════════════════════════════════════════════════════════════════
     * 10月14日（4回目）のご指摘①⑤への対応です。
     *   ① A・Bで購入推奨対象、購入回避判定、購入金額が完全一致すること
     *   ⑤ AIプロンプト、DB、元オッズ、購入回避条件、v0.4の5スイッチが
     *      変更されていないこと
     *
     * やっていること
     *   1. 同じレースのプロンプトを、補充方式をA・B・Cに切り替えて3回組み立て、
     *      MD5が一致することを示します（AIへは送りません・費用0円）。
     *      切り替えはこのプロセスのメモリ上だけで、.env は書き換えません。
     *   2. 保存済みの統合結果から、A・B・Cそれぞれの公開候補と購入推奨対象を
     *      算出し、購入推奨対象が3方式で同一であることを示します。
     *   3. 購入回避フラグが方式に依存しないこと（同じ馬に同じフラグが付くこと）を
     *      馬番ごとに並べて示します。
     *   4. v0.4の5スイッチと補充方式の実効値を表示します。
     *
     * 購入金額について
     *   馬眼力には購入金額（賭け金）を決める処理がありません。回収率は
     *   「1頭100円ずつ買った場合」の想定値として計算しているだけなので、
     *   方式によって変わる余地がありません。コード内に金額を決める箇所は
     *   1つもないことも確認しています。
     */
    private function runFillModeProof(string $date): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(" 補充方式を変えても変わらないことの確認  対象日 {$date}");
        $this->info('  （AIへは送りません・費用0円・DBへの書き込みなし）');
        $this->info('══════════════════════════════════════════════════════════');

        // ── 1. スイッチの実効値 ───────────────────────────────────────
        $this->line('');
        $this->info('── ① スイッチの実効値 ──────────────────────────');
        $this->line('  補充方式（BAGANRIKI_FILL_MODE）: ' . AiController::currentFillMode()
            . '　既定は ' . AiController::FILL_MODE_DEFAULT);
        foreach (AiController::v04SwitchReport() as $k => $v) {
            $this->line(sprintf('  %-34s %s', $k, $v));
        }
        $this->line('  → 補充方式はv0.4の5スイッチとは別の値で、5スイッチには触れていません。');

        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261014-FILL-PROOF  補充方式を読んでいる箇所をソースから数える
        // ══════════════════════════════════════════════════════════════════
        // 確認依頼書②「実送信プロンプトが基準版とバイト単位で一致すること」の
        // 裏付けです。送信文のMD5一致（下の②）に加えて、
        // 「補充方式を読む処理が、送信文の組み立て経路に1か所も無い」ことを
        // ソースコードから示します。
        $this->line('');
        $this->info('── ①-2 補充方式を読んでいる箇所（ソースから抽出）──────');
        $srcPath = app_path('Http/Controllers/Api/AiController.php');
        $src = @file_get_contents($srcPath);
        if ($src === false) {
            $this->warn('  ソースを読めませんでした: ' . $srcPath);
        } else {
            $lines = explode("\n", $src);
            $hits = [];
            foreach ($lines as $i => $ln) {
                if (strpos($ln, 'self::fillMode()') === false) continue;
                if (strpos(ltrim($ln), '//') === 0) continue;          // コメント行は数えない
                if (strpos($ln, 'private static function fillMode') !== false) continue;
                $hits[] = ['行' => $i + 1, '内容' => trim($ln)];
            }
            $this->line('  ' . basename($srcPath) . ' 内で self::fillMode() を読んでいる箇所: '
                . count($hits) . '件');
            foreach ($hits as $h) {
                $this->line(sprintf('    %5d行  %s', $h['行'], mb_substr($h['内容'], 0, 70)));
            }
            // プロンプト生成の関数の範囲を求め、その中に無いことを示す
            $pStart = 0; $pEnd = 0;
            foreach ($lines as $i => $ln) {
                if (strpos($ln, 'function _getAiAnalysisPrompt(') !== false) { $pStart = $i + 1; }
                if ($pStart > 0 && trim($ln) === 'return $promptText;') { $pEnd = $i + 1; break; }
            }
            $inPrompt = 0;
            foreach ($hits as $h) {
                if ($pStart > 0 && $pEnd > 0 && $h['行'] >= $pStart && $h['行'] <= $pEnd) $inPrompt++;
            }
            $this->line("  プロンプト生成 _getAiAnalysisPrompt() の範囲: {$pStart}行〜{$pEnd}行");
            if ($inPrompt === 0) {
                $this->info('  → この範囲に self::fillMode() は 0件。'
                    . '補充方式は送信文の組み立てに関与しません');
            } else {
                $this->error("  → この範囲に {$inPrompt}件 あります。確認が必要です");
            }
            $this->line('  2nd AIへ送る本文は、この関数の戻り値から一部を外して作ります。');
            $this->line('  もとが同じで、方式を読む処理も無いので、2ndの送信文も方式では変わりません。');
            $this->line('  10月17日以降は、1st・2ndそれぞれの送信文のMD5を');
            $this->line('  レースごとに監査ログへ残します（@ANCHOR-20261014-EVIDENCE-AUDIT2）。');
        }

        $merges = DB::table('t_horse_odds_finder_ai_merge_result')
            ->where('date', $date)
            ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
            ->get();
        if ($merges->isEmpty()) {
            $this->error("{$date} の統合結果（ai_merge_result）がありません。");
            return;
        }
        $want = $this->traceRaceFilter();

        $resMap = [];
        foreach (DB::table('t_horse_odds_finder_race_results')->where('date', $date)->get() as $r) {
            $k = $r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race;
            $resMap[$k][(int) $r->num] = (int) $r->result;
        }

        $promptChecked = 0; $promptSame = 0;
        $recoSame = 0; $recoDiff = 0; $flagSame = 0; $flagDiff = 0;

        foreach ($merges as $mg) {
            if (!empty($want) && !in_array((int) $mg->race, $want, true)) continue;

            $dec = json_decode((string) ($mg->merge_result ?? ''), true);
            if (!is_array($dec)) continue;
            if ((string) ($dec['status'] ?? '') === 'undetermined') continue;
            $audit = is_array($dec['audit_merge'] ?? null) ? $dec['audit_merge'] : [];
            $rows  = is_array($audit['全頭'] ?? null) ? $audit['全頭'] : [];
            $targetC = (int) ($audit['目標数C'] ?? 0);
            if (empty($rows) || $targetC <= 0) continue;

            $label  = "{$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R";
            $raceId = "{$date}_{$mg->kaisuu}_{$mg->basho_code}_{$mg->day}_{$mg->race}";
            $key    = $mg->kaisuu . '|' . $mg->basho_code . '|' . $mg->day . '|' . $mg->race;
            $results = $resMap[$key] ?? [];

            $this->line('');
            $this->info("── {$label} ──────────────────────────────");
            $this->line("  race_id: {$raceId}");
            // ご指摘⑥「同一レース、同一オッズ取得時点で比較すること」
            foreach ($this->snapshotLines($dec) as $sl) $this->line($sl);
            // どのAI回答で統合したか（①の「同じ入力で比べている」ことの裏付け）
            $ah = is_array($audit['回答ハッシュ'] ?? null) ? $audit['回答ハッシュ'] : [];
            if (!empty($ah)) {
                $this->line('  使ったAI回答  : 1st ' . (string) ($ah['1st_全文'] ?? '－')
                    . '　2nd ' . (string) ($ah['2nd_全文'] ?? '－'));
            }
            $this->line('  → A・B・Cはこの1つの保存済みスナップショットと回答から算出しています。');

            // ── 2. 送信文のMD5（方式を切り替えて3回組み立てる）──────────
            $md5s = [];
            foreach (['A', 'B', 'C'] as $m) {
                try {
                    AiController::forceFillMode($m);
                    $ctl = app(AiController::class);
                    $txt = $ctl->v04BuildPromptForCheck(
                        $date, $mg->kaisuu, $mg->basho_code, $mg->day, $mg->race);
                    $md5s[$m] = ($txt === null) ? '組み立て不可' : md5($txt);
                } catch (\Throwable $e) {
                    $md5s[$m] = '例外: ' . mb_substr($e->getMessage(), 0, 40);
                } finally {
                    AiController::forceFillMode(null);
                }
            }
            $this->line('  ② 送信文のMD5');
            foreach ($md5s as $m => $v) {
                $this->line("       FILL_MODE={$m} … {$v}");
            }
            $uniq = array_unique(array_values($md5s));
            $promptChecked++;
            if (count($uniq) === 1 && strlen((string) reset($uniq)) === 32) {
                $promptSame++;
                $this->info('       → 3方式で完全に同一。補充方式は送信文に影響しません');
            } else {
                $this->error('       → 一致しませんでした。確認が必要です');
            }

            // ── 3. 公開候補と購入推奨対象 ────────────────────────────
            $aNums = []; $flagMap = [];
            foreach ((is_array($dec['merged_horses'] ?? null) ? $dec['merged_horses'] : []) as $h) {
                if (!isset($h['num'])) continue;
                $hn = (int) $h['num'];
                $aNums[] = $hn;
                $flagMap[$hn] = [
                    'below_min_score'      => !empty($h['below_min_score']),
                    'buy_avoid_longshot'   => !empty($h['buy_avoid_longshot']),
                    'buy_avoid_low_payout' => !empty($h['buy_avoid_low_payout']),
                    'buy_avoid_recovery'   => !empty($h['buy_avoid_recovery']),
                ];
            }
            sort($aNums);

            $fill  = AiController::v04FillCandidates($rows, $aNums, $targetC, $results);
            $sets  = ['A' => [[], $aNums]];
            $bN = $aNums; foreach ($fill['B'] as $v) $bN[] = (int) $v; sort($bN);
            $sets['B'] = [$fill['B'], $bN];
            if ($fill['C'] !== null) {
                $cN = $aNums; foreach ($fill['C'] as $v) $cN[] = (int) $v; sort($cN);
                $sets['C'] = [$fill['C'], $cN];
            }

            $reco = [];
            $this->line('  ③ 公開候補と購入推奨対象');
            foreach ($sets as $m => [$add, $nums]) {
                $r = [];
                foreach ($nums as $num) {
                    if (in_array($num, $add, true)) continue;          // 補充枠は購入推奨にしない
                    $fl = $flagMap[$num] ?? [];
                    if (!empty($fl['below_min_score']) || !empty($fl['buy_avoid_longshot'])
                        || !empty($fl['buy_avoid_low_payout']) || !empty($fl['buy_avoid_recovery'])) {
                        continue;
                    }
                    $r[] = $num;
                }
                sort($r);
                $reco[$m] = $r;
                $this->line(sprintf('       %s方式 公開%2d頭 [%s]',
                    $m, count($nums), implode(',', $nums)));
                $this->line(sprintf('              購入推奨%2d頭 [%s]　md5 %s',
                    count($r), implode(',', $r), md5(implode(',', $r))));
            }
            if (count(array_unique(array_map(static fn($x) => md5(implode(',', $x)), $reco))) === 1) {
                $recoSame++;
                $this->info('       → 購入推奨対象は全方式で同一');
            } else {
                $recoDiff++;
                $this->error('       → 購入推奨対象が方式で違います。確認が必要です');
            }

            // ── 4. 購入回避フラグは方式に依存しない ───────────────────
            //   フラグは統合より前（事前フィルター）で決まり、第2パスより先に
            //   確定しています。方式は第2パスだけを変えるので、同じ馬には
            //   同じフラグが付きます。実データで馬番ごとに並べて示します。
            $flagLines = [];
            foreach ($aNums as $num) {
                $fl = $flagMap[$num] ?? [];
                $on = [];
                if (!empty($fl['below_min_score']))      $on[] = '最低基準点未満';
                if (!empty($fl['buy_avoid_longshot']))   $on[] = '大穴条件の不成立';
                if (!empty($fl['buy_avoid_low_payout'])) $on[] = '低配当';
                if (!empty($fl['buy_avoid_recovery']))   $on[] = '回収率40%未満';
                $flagLines[] = $num . ':' . (empty($on) ? 'なし' : implode('+', $on));
            }
            $this->line('  ④ 購入回避フラグ（公開候補の馬ごと・方式に依存しません）');
            $this->line('       ' . implode('　', $flagLines));
            $this->line('       md5 ' . md5(implode('|', $flagLines)));
            $flagSame++;
        }

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' まとめ');
        $this->info('══════════════════════════════════════════════════════════');
        $this->line("  送信文が3方式で同一だったレース : {$promptSame} / {$promptChecked}");
        $this->line("  購入推奨対象が全方式で同一       : {$recoSame} レース"
            . ($recoDiff > 0 ? "（不一致 {$recoDiff} レース）" : ''));
        $this->line("  購入回避フラグを出したレース     : {$flagSame}");
        $this->line('');
        $this->warn('※購入金額（賭け金）を決める処理は馬眼力にありません。回収率は');
        $this->warn('  「1頭100円ずつ買った場合」の想定値なので、方式で変わる余地がありません。');
        $this->warn('※DBへの書き込みは1件もしていません。オッズも読むだけです。');
        $this->warn('※AIへの送信は0回です（送信文を組み立ててMD5を取っただけ）。');
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261014-TEST-DETAIL  受入試験の全項目表示（--v04-test-detail）
     * ═════════════════════════════════════════════════════════════════════════
     * 10月14日（4回目）のご指摘④「242項目の試験内容と実行結果をまだ直接
     * 確認できていない」への対応です。全項目を1行ずつ、確認内容・得た値・
     * 期待値つきで出します。
     */
    private function runV04TestDetail(): void
    {
        $r = AiController::v04SelfTest();
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 受入試験の全項目（読み取りだけ・AI呼び出し0回）');
        $this->info('══════════════════════════════════════════════════════════');
        $this->line('  config_version : ' . (string) ($r['config_version'] ?? ''));
        $this->line('  判定件数       : ' . (int) ($r['pass'] ?? 0) . ' 合格 / '
            . (int) ($r['fail'] ?? 0) . ' 不合格');
        $this->line('');

        $enc = static function ($v) {
            $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return (mb_strlen((string) $j) > 60) ? mb_substr((string) $j, 0, 60) . '…' : (string) $j;
        };
        $i = 0;
        foreach ((array) ($r['results'] ?? []) as $row) {
            $i++;
            $ok = ((string) ($row['結果'] ?? '')) === 'PASS';
            $line = sprintf('  %3d. [%s] %-14s %s', $i, $ok ? 'OK' : 'NG',
                (string) ($row['id'] ?? ''), (string) ($row['確認'] ?? ''));
            if ($ok) { $this->line($line); } else { $this->error($line); }
            $this->line('        得た値=' . $enc($row['得た値'] ?? null)
                . '　期待値=' . $enc($row['期待値'] ?? null));
        }
        $this->line('');
        $this->info('  合計 ' . $i . ' 項目を表示しました。');
        $this->line('');
        $this->warn('※この試験は構造判定・配分・Tier・採点・AI契約チェック・補充方式の検算です。');
        $this->warn('  予想性能を示すものではありません。');

        $path = storage_path('logs/v04_selftest_detail_' . date('Y-m-d_His') . '.json');
        @file_put_contents($path, json_encode($r,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $this->info('全項目を書き出しました: ' . $path);
    }


    /**
     * @ANCHOR-20261014-ABNORMAL-LOG  異常入力の指摘と修正状況（--abnormal-status）
     *   10月14日（5回目）ご指摘「242項目合格だけで、過去の問題がすべて
     *   解消したとは認定しない」への対応です。
     *   見つかった問題を1件ずつ、修正内容・該当箇所・受入試験の番号つきで出します。
     */
    private function runAbnormalStatus(): void
    {
        $rows = AiController::V04_ABNORMAL_FIX_LOG;
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 異常入力・壊れた入力の指摘と修正状況（' . count($rows) . '件）');
        $this->info('══════════════════════════════════════════════════════════');
        foreach ($rows as $i => $r) {
            $this->line('');
            $this->info(sprintf('  %2d. [%s] %s', $i + 1, $r[0], $r[1]));
            $this->line('      修正: ' . $r[2]);
            $this->line('      該当: ' . $r[3]);
            $this->line('      試験: ' . $r[4]);
        }
        $this->line('');
        $this->warn('※「試験」の番号は --v04-test / --v04-test-detail で1行ずつ確認できます。');
        $this->warn('※AB-T01〜AB-T28 は、壊れた入力・境界の入力を投げる試験です。');
        $this->warn('　例外で落ちないこと、黙って通さず安全側（不明・DATA_INVALID・0頭・null）へ');
        $this->warn('　倒れること、頭数を埋めるための捏造をしないことを確かめています。');
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261014-WEEKEND  週末の検証に使う記録（--weekend-report）
     * ═════════════════════════════════════════════════════════════════════════
     * 10月14日（5回目）のご指摘への対応です。
     *   「17日の検証では、レース傾向判定、断層構造、人気帯別選出、
     *     補充馬の着順・払戻・期待値を記録してください」
     *   「波乱度判定の精度、人気薄の好走捕捉率、購入推奨の複勝回収率を
     *     重視して検証してください」
     *
     * 1レースごとに次を出し、最後に波乱度別の集計を出します。
     *   ・レース傾向（波乱度・下位進入度・大穴進入度・厳選穴レース・断層タイプ）
     *   ・人気帯別選出（配分モード・目標配分・実配分）
     *   ・公開候補と、その3着内・5着内
     *   ・購入推奨対象と、その3着内・複勝払戻
     *   ・補充馬の馬番・人気・着順・複勝払戻
     *   ・実際に3着内に来た3頭の人気（レース全体の6分前人気で数える）
     *
     * 人気は 6分前の単勝オッズの昇順で数えます（公開候補に入っていない馬も含む）。
     * これにより「実際に好走した人気薄を拾えていたか」を出せます。
     * 読み取りだけで、DBへの書き込みもAI呼び出しもありません。
     */
    private function runWeekendReport(string $date): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(" 週末の検証記録  対象日 {$date}");
        $this->info('  （読み取りだけ・DBへの書き込みなし・AI呼び出し0回）');
        $this->info('══════════════════════════════════════════════════════════');
        $this->line('  いま効いている補充方式: ' . AiController::currentFillMode()
            . '（既定 ' . AiController::FILL_MODE_DEFAULT . '）');

        $merges = DB::table('t_horse_odds_finder_ai_merge_result')
            ->where('date', $date)
            ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
            ->get();
        if ($merges->isEmpty()) {
            $this->error("{$date} の統合結果（ai_merge_result）がありません。");
            return;
        }
        $want = $this->traceRaceFilter();

        // ── 着順 ───────────────────────────────────────────────────────
        $resMap = [];
        foreach (DB::table('t_horse_odds_finder_race_results')->where('date', $date)->get() as $r) {
            $resMap[$r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race][(int) $r->num]
                = (int) $r->result;
        }
        // ── 複勝払戻 ───────────────────────────────────────────────────
        $payMap = [];
        foreach (DB::table('t_horse_odds_finder_race_result_payout')->where('date', $date)->get() as $r) {
            $k = $r->kaisuu . '|' . $r->basho_code . '|' . $r->day . '|' . $r->race;
            foreach (explode('/', (string) ($r->fuku ?? '')) as $piece) {
                $pair = explode('|', trim($piece));
                if (count($pair) === 2 && ctype_digit(trim($pair[0]))) {
                    $payMap[$k][(int) trim($pair[0])] = (int) preg_replace('/\D/', '', $pair[1]);
                }
            }
        }
        // ── 6分前の単勝オッズから、レース全体の人気を作る ──────────────
        //   公開候補に入っていない馬の人気も必要なので、オッズ表から数えます。
        $oddsMap = [];
        foreach (DB::table('t_horse_odds_finder_odds')
                     ->where('date', $date)->where('minutes_before_start', 6)->get() as $r) {
            $k = $r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race;
            if (is_numeric($r->odds) && (float) $r->odds > 0) {
                $oddsMap[$k][(int) $r->num] = (float) $r->odds;
            }
        }
        $popMap = [];
        foreach ($oddsMap as $k => $byNum) {
            asort($byNum);                       // オッズの安い順 = 人気順
            $rank = 0;
            foreach (array_keys($byNum) as $num) {
                $rank++;
                $popMap[$k][$num] = $rank;
            }
        }
        if (empty($resMap)) {
            $this->warn('※この日の着順データがありません。捕捉率・回収率は出せません。');
        }
        if (empty($popMap)) {
            $this->warn('※6分前のオッズが無いため、レース全体の人気は出せません。');
        }

        // 波乱度別の集計
        $byWave = [];
        $tot = ['レース' => 0, '公開' => 0, '公開3着内' => 0, '公開5着内' => 0,
                '推奨' => 0, '推奨3着内' => 0, '推奨払戻' => 0,
                '補充' => 0, '補充3着内' => 0, '補充払戻' => 0,
                '好走人気薄' => 0, '拾えた人気薄' => 0, '荒れたレース' => 0,
                // @ANCHOR-20261014-WEEKEND-B  B方式なら拾えたかを同じ画面で比べる
                'B相当で拾えた人気薄' => 0, 'B相当の補充' => 0];
        $out = [];

        foreach ($merges as $mg) {
            if (!empty($want) && !in_array((int) $mg->race, $want, true)) continue;

            $dec = json_decode((string) ($mg->merge_result ?? ''), true);
            if (!is_array($dec)) continue;
            if ((string) ($dec['status'] ?? '') === 'undetermined') {
                $this->line('');
                $this->warn("── {$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R … 未確定（予想なし）");
                continue;
            }
            $audit = is_array($dec['audit_merge'] ?? null) ? $dec['audit_merge'] : [];
            $rows  = is_array($audit['全頭'] ?? null) ? $audit['全頭'] : [];

            $label  = "{$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R";
            $raceId = "{$date}_{$mg->kaisuu}_{$mg->basho_code}_{$mg->day}_{$mg->race}";
            $key    = $mg->kaisuu . '|' . $mg->basho_code . '|' . $mg->day . '|' . $mg->race;
            $results = $resMap[$key] ?? [];
            $pays    = $payMap[$key] ?? [];
            $pops    = $popMap[$key] ?? [];

            $this->line('');
            $this->info("── {$label} ──────────────────────────────");
            $this->line("  race_id: {$raceId}");
            foreach ($this->snapshotLines($dec) as $sl) $this->line($sl);

            // ① レース傾向と断層構造
            $wave = ($mg->wave_level === null) ? null : (int) $mg->wave_level;
            $this->line('  ① レース傾向 : 波乱度 ' . ($wave ?? '－')
                . '／下位進入度 ' . (($mg->lower_entry === null) ? '－' : (int) $mg->lower_entry)
                . '／大穴進入度 ' . (($mg->big_gap_entry === null) ? '－' : (int) $mg->big_gap_entry)
                . '　厳選穴レース: ' . (($mg->upset_race === null) ? '不明'
                    : (((int) $mg->upset_race === 1) ? '該当' : '非該当'))
                . '　断層タイプ: ' . ((string) ($mg->gap_type ?? '－')));

            // ② 人気帯別選出
            $this->line('  ② 人気帯別選出: 配分モード ' . (string) ($audit['配分モード'] ?? '－')
                . '／目標配分 ' . (string) ($audit['目標配分'] ?? '－')
                . '／実配分 人気側' . (string) ($audit['実配分']['人気側'] ?? '－')
                . '・資格あり穴側' . (string) ($audit['実配分']['資格あり穴側'] ?? '－')
                . '／目標数C ' . (string) ($audit['目標数C'] ?? '－'));

            // ③ 公開候補と購入推奨
            $pubNums = []; $flag = [];
            foreach ((is_array($dec['merged_horses'] ?? null) ? $dec['merged_horses'] : []) as $h) {
                if (!isset($h['num'])) continue;
                $hn = (int) $h['num'];
                $pubNums[] = $hn;
                $flag[$hn] = [
                    'fill'  => !empty($h['fill_slot_unqualified']),
                    'avoid' => (!empty($h['below_min_score']) || !empty($h['buy_avoid_longshot'])
                                || !empty($h['buy_avoid_low_payout']) || !empty($h['buy_avoid_recovery'])),
                    'reco'  => array_key_exists('purchase_recommended', $h)
                                ? (bool) $h['purchase_recommended'] : null,
                ];
            }
            sort($pubNums);

            $p3 = 0; $p5 = 0; $r3 = 0; $rPay = 0; $recoNums = []; $fillNums = [];
            foreach ($pubNums as $hn) {
                $pos = $results[$hn] ?? null;
                if ($pos !== null && $pos <= 3) $p3++;
                if ($pos !== null && $pos <= 5) $p5++;
                $isFill  = $flag[$hn]['fill'] ?? false;
                if ($isFill) $fillNums[] = $hn;
                // 購入推奨は保存値があればそれを使い、無ければ同じ条件で算出する
                $reco = $flag[$hn]['reco'];
                if ($reco === null) $reco = (!$isFill && !($flag[$hn]['avoid'] ?? false));
                if ($reco) {
                    $recoNums[] = $hn;
                    if ($pos !== null && $pos <= 3) $r3++;
                    $rPay += (int) ($pays[$hn] ?? 0);
                }
            }
            $this->line(sprintf('  ③ 公開候補   : %2d頭 [%s]　3着内%d・5着内%d',
                count($pubNums), implode(',', $pubNums), $p3, $p5));
            $this->line(sprintf('     購入推奨   : %2d頭 [%s]　3着内%d・複勝払戻%d円%s',
                count($recoNums), implode(',', $recoNums), $r3, $rPay,
                count($recoNums) > 0
                    ? sprintf('・回収率%.1f%%', $rPay / (count($recoNums) * 100) * 100) : ''));

            // ④ 補充馬
            if (empty($fillNums)) {
                $this->line('  ④ 補充枠     : なし（A方式、または不足がなかったレース）');
            } else {
                $this->line('  ④ 補充枠     : ' . count($fillNums) . '頭');
                foreach ($fillNums as $hn) {
                    $row = $rows[(string) $hn] ?? ($rows[$hn] ?? []);
                    $ev  = is_array($row['独立根拠'] ?? null) ? $row['独立根拠'] : null;
                    $pos = $results[$hn] ?? null;
                    $pay = (int) ($pays[$hn] ?? 0);
                    $tot['補充']++;
                    if ($pos !== null && $pos <= 3) $tot['補充3着内']++;
                    $tot['補充払戻'] += $pay;
                    $this->line(sprintf(
                        '       %2d番　人気%s（全体%s番人気）統合点%s　独立根拠%s　Tier%s → %s・複勝払戻%d円',
                        $hn, $row['人気'] ?? '－', $pops[$hn] ?? '－', $row['統合点'] ?? '－',
                        ($ev === null) ? '記録なし' : (($ev['独立群数'] ?? '－') . '系統'),
                        ($ev === null) ? '－' : ($ev['tier'] ?? '－'),
                        ($pos === null) ? '着順なし' : ($pos . '着'), $pay));
                }
            }

            // ⑤ 実際に3着内に来た馬の人気（レース全体）と、拾えていたか
            $top3 = [];
            foreach ($results as $hn => $pos) {
                if ($pos >= 1 && $pos <= 3) $top3[$hn] = $pos;
            }
            ksort($top3);
            if (empty($top3)) {
                $this->line('  ⑤ 3着内の馬 : 着順データなし');
            } else {
                $parts = []; $longshotHit = 0; $longshotCaught = 0;
                foreach ($top3 as $hn => $pos) {
                    $pp = $pops[$hn] ?? null;
                    $inPub = in_array($hn, $pubNums, true);
                    $parts[] = $pos . '着:' . $hn . '番(' . (($pp === null) ? '人気不明' : $pp . '人気')
                        . ($inPub ? '・候補' : '・候補外') . ')';
                    if ($pp !== null && $pp >= 7) {
                        $longshotHit++;
                        if ($inPub) $longshotCaught++;
                    }
                }
                $this->line('  ⑤ 3着内の馬 : ' . implode('　', $parts));
                if ($longshotHit > 0) {
                    $this->line(sprintf('     → 7番人気以下の好走 %d頭のうち %d頭を候補に入れていました',
                        $longshotHit, $longshotCaught));
                    $tot['荒れたレース']++;
                } else {
                    $this->line('     → 3着内はすべて6番人気以内（荒れていません）');
                }
                $tot['好走人気薄']   += $longshotHit;
                $tot['拾えた人気薄'] += $longshotCaught;

                // ══════════════════════════════════════════════════════════
                // @ANCHOR-20261014-WEEKEND-B  B方式なら拾えていたかを並べる
                // ══════════════════════════════════════════════════════════
                // A方式で動かしているあいだは補充枠が0頭なので、同じ保存データから
                // 「B方式ならどうだったか」を計算して並べます。
                // ご指摘の「人気薄の好走捕捉率」をA・Bで比べられるようにするためです。
                $bFill = AiController::v04FillCandidates(
                    $rows, $pubNums, (int) ($audit['目標数C'] ?? 0), $results);
                $bNums = $pubNums;
                foreach ($bFill['B'] as $bn) $bNums[] = (int) $bn;
                $tot['B相当の補充'] += count($bFill['B']);
                $bCaught = 0;
                foreach ($top3 as $hn => $pos) {
                    $pp = $pops[$hn] ?? null;
                    if ($pp !== null && $pp >= 7 && in_array($hn, $bNums, true)) $bCaught++;
                }
                $tot['B相当で拾えた人気薄'] += $bCaught;
                if ($longshotHit > 0 && $bCaught > $longshotCaught) {
                    $this->line(sprintf('     → B方式なら %d頭を拾えていました（補充: %s番）',
                        $bCaught, implode(',', $bFill['B'])));
                }
            }

            // 集計
            $tot['レース']++; $tot['公開'] += count($pubNums);
            $tot['公開3着内'] += $p3; $tot['公開5着内'] += $p5;
            $tot['推奨'] += count($recoNums); $tot['推奨3着内'] += $r3; $tot['推奨払戻'] += $rPay;
            $wk = ($wave === null) ? '不明' : (string) $wave;
            if (!isset($byWave[$wk])) {
                // @ANCHOR-20261014-WEEKEND-B  10/14（6回目）ご指摘⑥のA・B同条件比較
                $byWave[$wk] = ['レース' => 0, '荒れた' => 0, '好走人気薄' => 0, '拾えた' => 0,
                                '公開' => 0, '公開3着内' => 0, '推奨' => 0, '推奨払戻' => 0,
                                'B拾えた' => 0, 'B公開' => 0, 'B補充' => 0];
            }
            $byWave[$wk]['レース']++;
            $byWave[$wk]['公開']      += count($pubNums);
            $byWave[$wk]['公開3着内'] += $p3;
            $byWave[$wk]['推奨']      += count($recoNums);
            $byWave[$wk]['推奨払戻']  += $rPay;
            if (!empty($top3)) {
                // @ANCHOR-20261014-WEEKEND-B  同じレースでA方式とB方式相当を並べる
                $bFill2 = AiController::v04FillCandidates(
                    $rows, $pubNums, (int) ($audit['目標数C'] ?? 0), $results);
                $bNums2 = $pubNums;
                foreach ($bFill2['B'] as $bn) $bNums2[] = (int) $bn;
                $byWave[$wk]['B公開'] += count($bNums2);
                $byWave[$wk]['B補充'] += count($bFill2['B']);

                $lh = 0; $lc = 0; $lb = 0;
                foreach ($top3 as $hn => $pos) {
                    $pp = $pops[$hn] ?? null;
                    if ($pp !== null && $pp >= 7) {
                        $lh++;
                        if (in_array($hn, $pubNums, true)) $lc++;
                        if (in_array($hn, $bNums2,  true)) $lb++;
                    }
                }
                if ($lh > 0) $byWave[$wk]['荒れた']++;
                $byWave[$wk]['好走人気薄'] += $lh;
                $byWave[$wk]['拾えた']     += $lc;
                $byWave[$wk]['B拾えた']    += $lb;
            }

            $out[$raceId] = [
                'レース傾向' => ['波乱度' => $wave, '下位進入度' => $mg->lower_entry,
                                 '大穴進入度' => $mg->big_gap_entry,
                                 '厳選穴レース' => $mg->upset_race, '断層タイプ' => $mg->gap_type],
                '人気帯別選出' => ['配分モード' => $audit['配分モード'] ?? null,
                                   '目標配分' => $audit['目標配分'] ?? null,
                                   '実配分' => $audit['実配分'] ?? null,
                                   '目標数C' => $audit['目標数C'] ?? null],
                '公開候補' => $pubNums, '公開3着内' => $p3, '公開5着内' => $p5,
                '購入推奨' => $recoNums, '購入推奨3着内' => $r3, '購入推奨払戻' => $rPay,
                '補充枠' => $fillNums,
                '3着内の馬' => $top3,
            ];
        }

        // ── 合計 ──────────────────────────────────────────────────────
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 合計（' . $date . '）');
        $this->info('══════════════════════════════════════════════════════════');
        $this->line('  対象レース: ' . $tot['レース'] . '　うち3着内に7番人気以下が来たレース: '
            . $tot['荒れたレース']);
        $this->line(sprintf('  公開候補  : %d頭　3着内%d・5着内%d',
            $tot['公開'], $tot['公開3着内'], $tot['公開5着内']));
        $this->line(sprintf('  購入推奨  : %d頭　3着内%d・複勝払戻%d円%s',
            $tot['推奨'], $tot['推奨3着内'], $tot['推奨払戻'],
            $tot['推奨'] > 0 ? sprintf('・複勝回収率 %.1f%%',
                $tot['推奨払戻'] / ($tot['推奨'] * 100) * 100) : ''));
        if ($tot['補充'] > 0) {
            $this->line(sprintf('  補充枠    : %d頭　3着内%d・複勝払戻%d円・複勝回収率 %.1f%%（購入推奨には入れていません）',
                $tot['補充'], $tot['補充3着内'], $tot['補充払戻'],
                $tot['補充払戻'] / ($tot['補充'] * 100) * 100));
        } else {
            $this->line('  補充枠    : 0頭（A方式のため）');
        }
        if ($tot['好走人気薄'] > 0) {
            $this->line(sprintf('  人気薄の好走捕捉率（いまの方式）: %d頭中%d頭 = %.1f%%',
                $tot['好走人気薄'], $tot['拾えた人気薄'],
                $tot['拾えた人気薄'] / $tot['好走人気薄'] * 100));
            // @ANCHOR-20261014-WEEKEND-B  B方式相当との比較
            $this->line(sprintf('  人気薄の好走捕捉率（B方式相当）  : %d頭中%d頭 = %.1f%%　'
                . '（補充 %d頭を加えた場合）',
                $tot['好走人気薄'], $tot['B相当で拾えた人気薄'],
                $tot['B相当で拾えた人気薄'] / $tot['好走人気薄'] * 100,
                $tot['B相当の補充']));
            $this->line('  ※「3着内に来た7番人気以下を公開候補に入れていた割合」です。');
            $this->warn('  ※補充はAIが候補に挙げた馬からしか行いません。AIが名前を挙げていない馬は');
            $this->warn('  　どの方式でも拾えません。これが捕捉率の上限になります。');
        } else {
            $this->line('  人気薄の好走捕捉率: 対象なし（3着内に7番人気以下が来ませんでした）');
        }

        $this->line('');
        $this->info('── 波乱度別 ──────────────────────────────');
        $this->line('  波乱度 レース 荒れた 荒れ率 人気薄好走  A公開 A捕捉 A捕捉率   B公開 B捕捉 B捕捉率  購入推奨 複勝回収率');
        ksort($byWave);
        foreach ($byWave as $wk => $v) {
            $this->line(sprintf(
                '  %-6s %5d %6d %5.0f%% %9d %6d %5d %6s %7d %5d %6s %9d %s',
                $wk, $v['レース'], $v['荒れた'],
                $v['レース'] > 0 ? $v['荒れた'] / $v['レース'] * 100 : 0,
                $v['好走人気薄'],
                $v['公開'], $v['拾えた'],
                $v['好走人気薄'] > 0
                    ? sprintf('%.0f%%', $v['拾えた'] / $v['好走人気薄'] * 100) : '－',
                $v['B公開'], $v['B拾えた'],
                $v['好走人気薄'] > 0
                    ? sprintf('%.0f%%', $v['B拾えた'] / $v['好走人気薄'] * 100) : '－',
                $v['推奨'],
                $v['推奨'] > 0
                    ? sprintf('%.1f%%', $v['推奨払戻'] / ($v['推奨'] * 100) * 100) : '－'));
        }
        $this->line('');
        $this->warn('※A公開はいまの方式の公開頭数、B公開はB方式相当（補充を加えた場合）の公開頭数です。');
        $this->warn('※購入推奨と複勝回収率はA・Bで同じになります（補充枠は購入推奨に入れないため）。');
        $this->line('');
        $this->warn('※「荒れた」は3着内に7番人気以下が1頭以上入ったレースです。');
        $this->warn('※人気は6分前の単勝オッズの昇順で、公開候補に入っていない馬も含めて数えています。');
        $this->warn('※複勝回収率は1頭100円ずつ買った場合の想定値です。');
        $this->warn('※補充枠の馬は購入推奨に入れていないため、購入推奨の集計には含みません。');
        $this->line('');
        $this->info('── 旧方式への復帰 ──────────────────────────────');
        $this->line('  いまの補充方式: ' . AiController::currentFillMode());
        $this->line('  B方式から現行Aへ戻すには、.env の BAGANRIKI_FILL_MODE を A にする');
        $this->line('  （または行を削除する）だけです。そのあと php artisan config:clear を実行します。');
        $this->line('  過去に保存した統合結果は書き換えないので、戻しても記録は残ります。');

        $path = storage_path('logs/weekend_report_' . $date . '.json');
        @file_put_contents($path, json_encode(
            ['date' => $date, '補充方式' => AiController::currentFillMode(),
             '合計' => $tot, '波乱度別' => $byWave, 'レース別' => $out],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $this->info('検証記録を書き出しました: ' . $path);
    }


    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261014-MISSED  拾えなかった人気薄の内訳（--missed-analysis）
     * ═════════════════════════════════════════════════════════════════════════
     * 10月14日（6回目）のご指摘⑤への対応です。
     *   「今回捕捉できなかった40頭について、AI全頭採点には存在したが
     *     候補化されなかった馬と、全頭採点自体が欠損・未受領だった馬を
     *     区別してください」
     *
     * 3着内に来た7番人気以下のうち、公開候補に入っていなかった馬を、
     * 次の3つに分けます。
     *   ① 全頭採点にいたが候補行にならなかった … AIは見ていたが選ばなかった
     *   ② 全頭採点を受け取れたが馬番が無い     … AIが採点から落とした
     *   ③ 全頭採点そのものが未受領            … マーカー欠落・JSON不正など
     *
     * ①なら「選び方」の問題なので、選出側（配分・補充・採点の使い方）で動きます。
     * ②③なら「AIが採点していない」ので、お願い文の側しか直せません。
     *
     * ─────────────────────────────────────────────────────────────────────────
     * @ANCHOR-20261014-MISSED2  10月14日（7回目）のご指摘への追加
     * ─────────────────────────────────────────────────────────────────────────
     * 7回目の01.txt ④⑤、および02.txt ②への対応です。
     *   「全頭採点の段階で評価されていなかったのか、評価されていたのに
     *     選出枠・条件・フィルターで落ちたのかを区別してください」
     *   「波乱度3・4で人気薄の捕捉が0頭だった原因を優先して分析してください。
     *     断層の種類だけでなく、位置・本数・時系列変化との関係も検証対象にします」
     *
     * ①をさらに2つに割りました。
     *   ①-a AIが候補行に出さなかった  … 採点はされていたが、AIが候補として出さない
     *                                   （PHPにはそもそも届いていない）
     *   ①-b AIは候補行に出したがPHPで落ちた
     *                                 … 選出枠・条件・フィルターで落ちた
     *                                   （どの段階で落ちたかを監査ログから出します）
     * あわせて、その馬が全頭採点で何点・何位だったかを出します。
     * 「評価されていなかった」のか「評価はされたが選ばれなかった」のかは、
     * 点数と順位を見ないと区別できないためです。
     *
     * 波乱度ごとの集計と、波乱度3以上のレースだけを並べた一覧
     * （断層タイプ・断層の明細・段階ごとの頭数）も末尾に付けます。
     * 断層の明細と段階ごとの頭数は 10/14 に記録を始めたので、
     * それ以前のレースは「記録なし」と出ます（10/17以降のレースで埋まります）。
     *
     * 支持率・資金流入・断層の位置や時系列変化を1頭1行で並べた一覧は、
     * 行数が多くなるので別のオプション（--longshot-csv）にしました。
     *
     * ─────────────────────────────────────────────────────────────────────────
     * @ANCHOR-20261015-DIG  10月15日（1回目）03.txtへの追加
     * ─────────────────────────────────────────────────────────────────────────
     *   「昨日の33頭について、なぜAIが選ばなかったのかを
     *     採点・人気順位・断層・資金流入まで掘り下げれば、
     *     次に修正すべきプロンプトの箇所がかなり明確になると思う」
     *
     * ①-a（AIが候補行に出さなかった馬）について、次を1頭ずつ出し、
     * さらに【同じ日の7番人気以下の全頭】を比べる相手として同じ項目を集計します。
     *   ・採点     … 全頭採点の点数と順位（何頭中何位）
     *   ・人気順位 … レース全体での人気と人気帯
     *   ・断層     … 6番人気以内と全体の本数、位置、直上が断層か、
     *                 断層の下側先頭か、直上の境界の時系列変化
     *   ・資金流入 … 単勝・複勝の支持率と短縮率、人気帯内の流入ランク
     *
     * 比べる相手を2つ置きます。
     *   ・7番人気以下で3着内に来た馬すべて（好走）
     *   ・7番人気以下で4着以下だった馬すべて（凡走）
     * ①-aの33頭が凡走の平均と変わらないなら、AIが選ばなかったのは妥当だった
     * ことになります。好走の平均に近く凡走と違うなら、
     * 「その特徴を見るように」お願い文へ書く余地があることになります。
     * どちらなのかは数字が出てから判断します。こちらで決め打ちはしません。
     * 人気は6分前の単勝オッズの昇順でレース全体から数えます。
     * 読み取りだけで、AI呼び出しもDBへの書き込みもありません。
     */
    private function runMissedAnalysis(string $date): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(" 拾えなかった人気薄の内訳  対象日 {$date}");
        $this->info('  （読み取りだけ・DBへの書き込みなし・AI呼び出し0回）');
        $this->info('══════════════════════════════════════════════════════════');

        $merges = DB::table('t_horse_odds_finder_ai_merge_result')
            ->where('date', $date)
            ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
            ->get();
        if ($merges->isEmpty()) {
            $this->error("{$date} の統合結果（ai_merge_result）がありません。");
            return;
        }

        $resMap = [];
        foreach (DB::table('t_horse_odds_finder_race_results')->where('date', $date)->get() as $r) {
            $resMap[$r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race][(int) $r->num]
                = (int) $r->result;
        }
        $payMap = [];
        foreach (DB::table('t_horse_odds_finder_race_result_payout')->where('date', $date)->get() as $r) {
            $k = $r->kaisuu . '|' . $r->basho_code . '|' . $r->day . '|' . $r->race;
            foreach (explode('/', (string) ($r->fuku ?? '')) as $piece) {
                $pair = explode('|', trim($piece));
                if (count($pair) === 2 && ctype_digit(trim($pair[0]))) {
                    $payMap[$k][(int) trim($pair[0])] = (int) preg_replace('/\D/', '', $pair[1]);
                }
            }
        }
        $popMap = [];
        $oddsRows = DB::table('t_horse_odds_finder_odds')
            ->where('date', $date)->where('minutes_before_start', 6)->get();
        $tmp = [];
        foreach ($oddsRows as $r) {
            if (is_numeric($r->odds) && (float) $r->odds > 0) {
                $tmp[$r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race][(int) $r->num]
                    = (float) $r->odds;
            }
        }
        foreach ($tmp as $k => $byNum) {
            asort($byNum);
            $rank = 0;
            foreach (array_keys($byNum) as $num) { $rank++; $popMap[$k][$num] = $rank; }
        }

        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261015-MISSED3  複勝流入ランクを照合するためのオッズ時系列
        // ══════════════════════════════════════════════════════════════════
        // 10/15（1回目）01.txt【3】への対応です。
        //   「この33頭について、全頭採点の順位・点数・複勝流入ランク・波乱度を
        //     照合してください」
        // 複勝流入ランクは計測開始前と6分前の複勝最小オッズから計算するので、
        // 6分前だけでは足りません。全時点を読みます。
        $timings  = [\App\Constants\Constants::ODDS_DB_FIRST, 21, 18, 15, 12, 9, 6];
        $seriesAll = [];
        foreach (DB::table('t_horse_odds_finder_odds')
                    ->where('date', $date)
                    ->whereIn('minutes_before_start', $timings)
                    ->get(['kaisuu', 'basho', 'day', 'race', 'num',
                           'minutes_before_start', 'odds', 'fuku_min']) as $r) {
            $k = $r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race;
            $t = (int) $r->minutes_before_start; $nn = (int) $r->num;
            if (is_numeric($r->odds)     && (float) $r->odds     > 0) $seriesAll[$k][$nn]['tan'][$t]  = (float) $r->odds;
            if (is_numeric($r->fuku_min) && (float) $r->fuku_min > 0) $seriesAll[$k][$nn]['fuku'][$t] = (float) $r->fuku_min;
        }
        if (empty($resMap) || empty($popMap)) {
            $this->error('着順または6分前オッズが無いため、この日は判定できません。');
            return;
        }

        // @ANCHOR-20261014-MISSED2  ①を a／b に割る
        $cat = ['①-a AIが候補行に出さなかった' => 0,
                '①-b AIは候補行に出したがPHPで落ちた' => 0,
                '②採点に馬番が無い' => 0, '③採点が未受領' => 0];
        $rowsOut = [];
        $missTotal = 0;
        // @ANCHOR-20261014-MISSED2  波乱度別の集計と、波乱度3以上の一覧
        $byWave    = [];   // 波乱度 => ['レース'=>n,'人気薄好走'=>n,'捕捉'=>n,'区分'=>[...]]
        $waveRaces = [];   // 波乱度3以上のレースの明細
        // @ANCHOR-20261015-MISSED3  ①-a（AIが候補行に出さなかった馬）の切り分け
        $aBreak = ['選出判断（採点は目標数C位以内）' => 0,
                   '中間（C位より下だが上位半分以内）' => 0,
                   '採点不足（下位半分）' => 0,
                   '順位が読めない' => 0];
        $aFuku  = ['複勝流入1位か2位' => 0, 'それ以外' => 0, 'データなし' => 0];
        $aWave  = [];      // 波乱度 => ①-aの頭数
        $aRows  = [];      // ①-aの明細
        // @ANCHOR-20261015-MISSED3  AIの候補行の人気帯（枠制限が働いていないかの実態）
        $candBand = ['1〜6番人気' => 0, '7〜10番人気' => 0, '11番人気以下' => 0, '不明' => 0];
        $candRace = 0;
        // @ANCHOR-20261015-DIG  採点・人気順位・断層・資金流入の掘り下げ（03.txt）
        $feat = ['①-a' => [], '好走' => [], '凡走' => []];
        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261015-WHYLOW  AIが低く採点した理由の分類（10/15 2回目【3】②【4】）
        // ══════════════════════════════════════════════════════════════════
        //   「AIが高配当の人気薄を低く採点した原因」
        //   「33頭についても、AIが低く採点した理由を可能な範囲で分類し、
        //     今後の採点基準の修正材料として保存してください」
        //
        // AIの頭の中は分かりません。分かるのは
        // 「同じレースでAIが候補行に出した馬と比べて、この馬のどの数値が上だったか」
        // です。上なのに採点が低いなら、AIがその数値を見ていない可能性があります。
        // 推測は推測と分かる形で、当てはまった項目を並べます。
        $whyLow = ['複勝流入が候補馬より上なのに低採点'   => 0,
                   '単勝支持率が候補馬より上なのに低採点' => 0,
                   '断層の下側先頭なのに低採点'           => 0,
                   '11番人気以下（人気だけで低く見た可能性）' => 0,
                   'どれも当てはまらない（低採点は妥当そう）' => 0];
        $whyRows = [];

        foreach ($merges as $mg) {
            $dec = json_decode((string) ($mg->merge_result ?? ''), true);
            if (!is_array($dec)) continue;
            if ((string) ($dec['status'] ?? '') === 'undetermined') continue;

            $label = "{$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R";
            $key   = $mg->kaisuu . '|' . $mg->basho_code . '|' . $mg->day . '|' . $mg->race;
            $results = $resMap[$key] ?? [];
            $pops    = $popMap[$key] ?? [];
            $pays    = $payMap[$key] ?? [];
            if (empty($results) || empty($pops)) continue;

            $pubNums = [];
            foreach ((is_array($dec['merged_horses'] ?? null) ? $dec['merged_horses'] : []) as $h) {
                if (isset($h['num'])) $pubNums[] = (int) $h['num'];
            }

            // ══════════════════════════════════════════════════════════════
            // @ANCHOR-20261014-MISSED2  波乱度・断層・段階ごとの頭数
            // ══════════════════════════════════════════════════════════════
            $audit  = is_array($dec['audit_merge'] ?? null) ? $dec['audit_merge'] : [];
            $auRows = is_array($audit['全頭'] ?? null) ? $audit['全頭'] : [];
            $wave   = ($mg->wave_level === null) ? 0 : (int) $mg->wave_level;
            if (!isset($byWave[$wave])) {
                $byWave[$wave] = ['レース' => 0, '人気薄好走' => 0, '捕捉' => 0,
                                  '区分' => ['①-a' => 0, '①-b' => 0, '②' => 0, '③' => 0]];
            }
            $byWave[$wave]['レース']++;

            // そのレースの7番人気以下の3着内と、そのうち捕捉できた頭数
            $lsHit = 0; $lsCaught = 0;
            foreach ($results as $hn0 => $pos0) {
                if ($pos0 < 1 || $pos0 > 3) continue;
                $pp0 = $pops[$hn0] ?? null;
                if ($pp0 === null || $pp0 < 7) continue;
                $lsHit++;
                if (in_array((int) $hn0, $pubNums, true)) $lsCaught++;
            }
            $byWave[$wave]['人気薄好走'] += $lsHit;
            $byWave[$wave]['捕捉']       += $lsCaught;

            if ($wave >= 3) {
                $waveRaces[] = [
                    'レース' => $label, '波乱度' => $wave,
                    '断層タイプ'   => (string) ($mg->gap_type ?? '－'),
                    '下位進入度'   => ($mg->lower_entry === null) ? null : (int) $mg->lower_entry,
                    '大穴進入度'   => ($mg->big_gap_entry === null) ? null : (int) $mg->big_gap_entry,
                    '厳選穴レース' => ($mg->upset_race === null) ? null : ((int) $mg->upset_race === 1),
                    '断層の明細'     => is_array($audit['断層の明細'] ?? null) ? $audit['断層の明細'] : null,
                    '段階ごとの頭数' => is_array($audit['段階ごとの頭数'] ?? null) ? $audit['段階ごとの頭数'] : null,
                    '人気薄好走' => $lsHit, '捕捉' => $lsCaught,
                    '公開頭数'   => count($pubNums),
                ];
            }

            // 拾えなかった人気薄（3着内・7番人気以下・公開候補外）
            $miss = [];
            foreach ($results as $hn => $pos) {
                if ($pos < 1 || $pos > 3) continue;
                $pp = $pops[$hn] ?? null;
                if ($pp === null || $pp < 7) continue;
                if (in_array((int) $hn, $pubNums, true)) continue;
                $miss[(int) $hn] = ['着順' => $pos, '人気' => $pp];
            }
            // ══════════════════════════════════════════════════════════════
            // 保存済みのAI回答から全頭採点を読む
            // ══════════════════════════════════════════════════════════════
            // @ANCHOR-20261015-DIG-ALLRACE  拾えなかった馬がいないレースも数える
            //   10/15の最初の実行で、比べる相手（7番人気以下の全頭）と
            //   AIの候補行の人気帯を【拾えなかった馬がいるレースだけ】から
            //   集めていました。それでは「AIが人気薄を出さなかったレース」に
            //   偏った母数になり、比較になりません。
            //   読み取りはDBだけでAI呼び出しは増えないので、全レースで集めます。
            $txt1 = DB::table('t_horse_odds_finder_ai_analysis')
                ->where('date', $date)->where('kaisuu', $mg->kaisuu)
                ->where('basho_code', $mg->basho_code)->where('day', $mg->day)
                ->where('race', $mg->race)->value('analysis_text');
            $txt2 = DB::table('t_horse_odds_finder_ai_analysis2')
                ->where('date', $date)->where('kaisuu', $mg->kaisuu)
                ->where('basho_code', $mg->basho_code)->where('day', $mg->day)
                ->where('race', $mg->race)->value('analysis_text');
            $as1 = ($txt1 === null) ? ['received' => false, 'nums' => [], 'count' => 0,
                                       'reason' => '1st AIの回答が保存されていない']
                                    : AiController::allScoreReport((string) $txt1);
            $as2 = ($txt2 === null) ? ['received' => false, 'nums' => [], 'count' => 0,
                                       'reason' => '2nd AIの回答が保存されていない']
                                    : AiController::allScoreReport((string) $txt2);

            // ══════════════════════════════════════════════════════════════
            // @ANCHOR-20261014-MISSED2  AIの「候補行」の馬番
            // ══════════════════════════════════════════════════════════════
            // 全頭採点に居たかどうかだけでは、
            //   「AIが候補として出さなかった」のか
            //   「AIは出したがPHPの選出枠・条件・フィルターで落ちた」のか
            // が区別できません。公開候補行の馬番を別に読んで突き合わせます。
            $cand1 = ($txt1 === null) ? [] : AiController::publicCandidateNums((string) $txt1);
            $cand2 = ($txt2 === null) ? [] : AiController::publicCandidateNums((string) $txt2);

            // ══════════════════════════════════════════════════════════════
            // @ANCHOR-20261015-MISSED3  複勝流入ランクと、候補行の人気帯
            // ══════════════════════════════════════════════════════════════
            $sr   = $seriesAll[$key] ?? [];
            $info = empty($sr) ? ['pop' => []] : $this->longshotRaceInfo($sr);
            $fuRk = $info['fukuInflowRank'] ?? [];

            // ══════════════════════════════════════════════════════════════
            // @ANCHOR-20261015-DIG  比べる相手（7番人気以下の全頭）を集める
            // ══════════════════════════════════════════════════════════════
            foreach (($info['pop'] ?? []) as $bn => $bp) {
                if ($bp < 7) continue;
                $bpos = $results[$bn] ?? null;
                if ($bpos === null) continue;
                $kind0 = ($bpos >= 1 && $bpos <= 3) ? '好走' : '凡走';
                $feat[$kind0][] = $this->longshotFeatures($info, (int) $bn, $as1);
            }

            // AIの候補行が人気側に偏っていないか（枠制限の実態）を数える
            if (!empty($cand1)) {
                $candRace++;
                foreach ($cand1 as $cn) {
                    $cp = $pops[$cn] ?? null;
                    if      ($cp === null) $candBand['不明']++;
                    elseif  ($cp <= 6)     $candBand['1〜6番人気']++;
                    elseif  ($cp <= 10)    $candBand['7〜10番人気']++;
                    else                   $candBand['11番人気以下']++;
                }
            }

            // ここから下は「拾えなかった人気薄がいたレース」だけの処理です
            if (empty($miss)) continue;

            $this->line('');
            $this->info("── {$label} ──────────────────────────────");
            $this->line('  レース傾向: 波乱度 ' . ($wave > 0 ? $wave : '－')
                . '／断層タイプ ' . (string) ($mg->gap_type ?? '－')
                . '／厳選穴レース ' . (($mg->upset_race === null) ? '不明'
                    : (((int) $mg->upset_race === 1) ? '該当' : '非該当')));
            $this->line(sprintf('  全頭採点: 1st %s（%d頭）／2nd %s（%d頭）',
                $as1['received'] ? '受領' : '未受領：' . $as1['reason'], $as1['count'],
                $as2['received'] ? '受領' : '未受領：' . $as2['reason'], $as2['count']));
            $this->line(sprintf('  AIの候補行: 1st %d頭 [%s]／2nd %d頭 [%s]',
                count($cand1), implode(',', $cand1), count($cand2), implode(',', $cand2)));
            $this->line(sprintf('  公開候補: %d頭 [%s]', count($pubNums), implode(',', $pubNums)));

            foreach ($miss as $hn => $m) {
                $missTotal++;
                $in1 = in_array($hn, $as1['nums'], true);
                $in2 = in_array($hn, $as2['nums'], true);
                // @ANCHOR-20261014-MISSED2  AIの候補行に居たか
                $row1 = in_array($hn, $cand1, true);
                $row2 = in_array($hn, $cand2, true);

                if ($row1 || $row2) {
                    // AIは候補として出していた。落ちたのはPHP側。
                    $c    = '①-b AIは候補行に出したがPHPで落ちた';
                    $key2 = '①-b';
                    $where = '（' . ($row1 ? '1st' : '') . ($row1 && $row2 ? 'と' : '')
                             . ($row2 ? '2nd' : '') . 'の候補行に居た）';
                } elseif ($in1 || $in2) {
                    // 採点はされていたが、AIは候補として出していない。
                    $c    = '①-a AIが候補行に出さなかった';
                    $key2 = '①-a';
                    $where = '（' . ($in1 ? '1st' : '') . ($in1 && $in2 ? 'と' : '')
                             . ($in2 ? '2nd' : '') . 'の採点に居たが、候補行には無い）';
                } elseif ($as1['received'] || $as2['received']) {
                    $c    = '②採点に馬番が無い';
                    $key2 = '②';
                    $where = '（採点は受け取れたが、この馬番が入っていない）';
                } else {
                    $c    = '③採点が未受領';
                    $key2 = '③';
                    $where = '（全頭採点そのものを受け取れていない）';
                }
                $cat[$c]++;
                if (isset($byWave[$wave])) $byWave[$wave]['区分'][$key2]++;

                // @ANCHOR-20261014-MISSED2  全頭採点での点数と順位
                //   「評価されていなかった」のか「評価はされたが選ばれなかった」のかは、
                //   点数と順位が分からないと区別できません。
                $rk1 = $this->scoreRankText($as1, $hn);
                $rk2 = $this->scoreRankText($as2, $hn);

                // @ANCHOR-20261014-MISSED2  PHPのどの段階まで残っていたか
                $auRow = $auRows[(string) $hn] ?? ($auRows[$hn] ?? []);
                $stage = $this->phpStageText($audit, $hn);

                // ══════════════════════════════════════════════════════════
                // @ANCHOR-20261015-MISSED3  ①-aの切り分けと複勝流入ランク
                // ══════════════════════════════════════════════════════════
                // 「AIが候補に出さなかった原因が人気帯別の枠制限なのか、
                //   採点不足なのか、選出判断なのか、可能な範囲で切り分けてください」
                // 全頭採点での順位を基準に3つに分けます。
                //   ・目標数C位以内        … AIは高く採点していたのに候補行に出さなかった
                //                            → 選出判断
                //   ・C位より下だが上位半分 … 中間
                //   ・下位半分             … そもそも低く採点していた → 採点不足
                $fr = $fuRk[$hn] ?? null;
                if ($key2 === '①-a') {
                    $tc = (int) ($audit['目標数C'] ?? 0);
                    $rk = $rk1['rank']; $tt = $rk1['total'];
                    if ($rk === null || $tt <= 0)          $aBreak['順位が読めない']++;
                    elseif ($tc > 0 && $rk <= $tc)         $aBreak['選出判断（採点は目標数C位以内）']++;
                    elseif ($rk <= (int) ceil($tt / 2))    $aBreak['中間（C位より下だが上位半分以内）']++;
                    else                                   $aBreak['採点不足（下位半分）']++;

                    if      ($fr === null)                 $aFuku['データなし']++;
                    elseif  (in_array($fr, [1, 2], true))  $aFuku['複勝流入1位か2位']++;
                    else                                   $aFuku['それ以外']++;

                    $aWave[$wave] = ($aWave[$wave] ?? 0) + 1;
                    // @ANCHOR-20261015-DIG  採点・人気順位・断層・資金流入
                    $fx = $this->longshotFeatures($info, $hn, $as1);
                    $feat['①-a'][] = $fx;

                    // ══════════════════════════════════════════════════════
                    // @ANCHOR-20261015-WHYLOW  低採点の理由を推し量る
                    // ══════════════════════════════════════════════════════
                    // 同じレースでAIが候補行に出した馬の数値と比べます。
                    // 「候補馬より上」は、候補馬の中でいちばん悪い値より上、で判定します。
                    $why = [];
                    $candFuku = []; $candTan = [];
                    foreach ($cand1 as $cn2) {
                        $fr2 = $info['fukuInflowRank'][$cn2] ?? null;
                        $ts2 = $info['tanShare'][$cn2] ?? null;
                        if ($fr2 !== null) $candFuku[] = (int) $fr2;
                        if ($ts2 !== null) $candTan[]  = (float) $ts2;
                    }
                    // 複勝流入ランクは小さいほど資金が入っている → 候補馬の最大値より小さければ「上」
                    if ($fr !== null && !empty($candFuku) && $fr < max($candFuku)) {
                        $why[] = '複勝流入が候補馬より上なのに低採点';
                    }
                    // 単勝支持率は大きいほど買われている → 候補馬の最小値より大きければ「上」
                    $myTan = $fx['単勝支持率'] ?? null;
                    if ($myTan !== null && !empty($candTan) && $myTan > min($candTan)) {
                        $why[] = '単勝支持率が候補馬より上なのに低採点';
                    }
                    if (($fx['断層の下側先頭'] ?? '') === 'はい') {
                        $why[] = '断層の下側先頭なのに低採点';
                    }
                    if (($fx['人気'] ?? 0) >= 11) {
                        $why[] = '11番人気以下（人気だけで低く見た可能性）';
                    }
                    if (empty($why)) $why[] = 'どれも当てはまらない（低採点は妥当そう）';
                    foreach ($why as $w) $whyLow[$w] = ($whyLow[$w] ?? 0) + 1;
                    $whyRows[] = ['レース' => $label, '馬番' => $hn, '波乱度' => $wave,
                                  '着順' => $m['着順'], '人気' => $m['人気'],
                                  '複勝払戻' => (int) ($pays[$hn] ?? 0),
                                  '全頭採点の点数' => $rk1['score'],
                                  '全頭採点の順位' => $rk1['rank'],
                                  '全頭採点の頭数' => $rk1['total'],
                                  '複勝流入ランク' => $fr,
                                  '単勝支持率' => $myTan,
                                  '断層の下側先頭' => $fx['断層の下側先頭'] ?? null,
                                  '候補行の複勝流入ランク' => $candFuku,
                                  '候補行の単勝支持率' => $candTan,
                                  '当てはまった項目' => $why];
                    $aRows[] = ['レース' => $label, '波乱度' => $wave, '馬番' => $hn,
                                '着順' => $m['着順'], '人気' => $m['人気'],
                                '複勝払戻' => (int) ($pays[$hn] ?? 0),
                                '全頭採点の点数' => $rk1['score'],
                                '全頭採点の順位' => $rk1['rank'],
                                '全頭採点の頭数' => $rk1['total'],
                                '目標数C' => (int) ($audit['目標数C'] ?? 0),
                                '複勝流入ランク' => $fr,
                                '断層タイプ' => (string) ($mg->gap_type ?? '－'),
                                '掘り下げ' => $fx];
                }

                $this->warn(sprintf('    %2d番　%d着・全体%d番人気・複勝払戻%d円 → %s %s',
                    $hn, $m['着順'], $m['人気'], (int) ($pays[$hn] ?? 0), $c, $where));
                $this->line('        全頭採点での位置: 1st ' . $rk1['text'] . '／2nd ' . $rk2['text']);
                $this->line('        複勝流入ランク  : '
                    . (($fr === null) ? 'データなし' : ($fr . '位（人気帯内）'
                        . (in_array($fr, [1, 2], true) ? '★' : ''))));
                // @ANCHOR-20261015-DIG  断層と資金流入も1頭ごとに出す
                if (!empty($info['pop'])) {
                    $fx0 = $this->longshotFeatures($info, $hn, $as1);
                    $this->line('        資金流入        : 単勝支持率 '
                        . $this->na($fx0['単勝支持率']) . '%／複勝支持率 '
                        . $this->na($fx0['複勝支持率']) . '%／単勝短縮率 '
                        . $this->na($fx0['単勝短縮率']) . '%／複勝短縮率 '
                        . $this->na($fx0['複勝短縮率']) . '%／単勝流入 '
                        . $this->na($fx0['単勝流入ランク']) . '位');
                    $this->line('        断層            : 6番人気以内 '
                        . $fx0['断層本数_6番人気以内'] . '本／全体 '
                        . $fx0['断層本数_全体'] . '本／位置 '
                        . ($fx0['断層位置'] === '' ? 'なし' : $fx0['断層位置'])
                        . '／直上が断層 ' . $fx0['直上が断層']
                        . '／直上の変化 ' . $fx0['直上の時系列変化']);
                }
                $this->line('        統合の監査ログ  : 統合点 ' . ($auRow['統合点'] ?? '－')
                    . '／' . implode('・', $stage));

                $rowsOut[] = ['レース' => $label, '波乱度' => $wave,
                              '断層タイプ' => (string) ($mg->gap_type ?? '－'),
                              '馬番' => $hn, '着順' => $m['着順'],
                              '人気' => $m['人気'], '複勝払戻' => (int) ($pays[$hn] ?? 0),
                              '区分' => $c,
                              '1stの採点にいた' => $in1, '2ndの採点にいた' => $in2,
                              '1stの候補行にいた' => $row1, '2ndの候補行にいた' => $row2,
                              '1st全頭採点の点数' => $rk1['score'], '1st全頭採点の順位' => $rk1['rank'],
                              '1st全頭採点の頭数' => $rk1['total'],
                              '2nd全頭採点の点数' => $rk2['score'], '2nd全頭採点の順位' => $rk2['rank'],
                              '2nd全頭採点の頭数' => $rk2['total'],
                              '統合点' => $auRow['統合点'] ?? null,
                              '複勝流入ランク' => $fr,
                              'PHPの段階' => $stage,
                              '1st全頭採点' => $as1['received'] ? $as1['count'] . '頭' : $as1['reason'],
                              '2nd全頭採点' => $as2['received'] ? $as2['count'] . '頭' : $as2['reason']];
            }
        }

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 内訳（' . $date . '）');
        $this->info('══════════════════════════════════════════════════════════');
        $this->line('  拾えなかった人気薄（3着内・7番人気以下・公開候補外）: ' . $missTotal . '頭');
        foreach ($cat as $k => $v) {
            $this->line(sprintf('    %-26s %3d頭 %s', $k, $v,
                $missTotal > 0 ? sprintf('(%.1f%%)', $v / $missTotal * 100) : ''));
        }
        $this->line('');
        $this->warn('※①-a は「AIは採点していたが、候補行に出さなかった」馬です。');
        $this->warn('　PHPには届いていないので、PHP側の選び方では拾えません。');
        $this->warn('　拾うには、全頭採点から候補を起こす（第42章の案1〜3）必要があります。');
        $this->warn('※①-b は「AIは候補行に出したが、PHPの選出枠・条件・フィルターで落ちた」馬です。');
        $this->warn('　PHP側の選び方（配分・補充・最低基準点）で拾える可能性があります。');
        $this->warn('※②③は「AIが採点していない」馬です。お願い文の側を変えるしかありません。');
        $this->warn('　いずれの変更も、ご判断をいただいてからにします。');

        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261014-MISSED2  波乱度別の集計
        // ══════════════════════════════════════════════════════════════════
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 波乱度別（' . $date . '）');
        $this->info('══════════════════════════════════════════════════════════');
        $this->line('  波乱度 レース 人気薄好走 捕捉 捕捉率   ①-a   ①-b    ②    ③');
        ksort($byWave);
        foreach ($byWave as $w => $v) {
            $this->line(sprintf('  %-6s %5d %9d %4d %6s %5d %5d %5d %5d',
                ($w > 0 ? (string) $w : '－'), $v['レース'], $v['人気薄好走'], $v['捕捉'],
                $v['人気薄好走'] > 0
                    ? sprintf('%.1f%%', $v['捕捉'] / $v['人気薄好走'] * 100) : '－',
                $v['区分']['①-a'], $v['区分']['①-b'], $v['区分']['②'], $v['区分']['③']));
        }

        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261014-MISSED2  波乱度3以上のレースの一覧
        // ══════════════════════════════════════════════════════════════════
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 波乱度3以上のレース（断層と段階ごとの頭数）');
        $this->info('══════════════════════════════════════════════════════════');
        if (empty($waveRaces)) {
            $this->line('  該当レースがありません。');
        } else {
            foreach ($waveRaces as $wr) {
                $this->line('');
                $this->line('  ' . $wr['レース'] . '　波乱度' . $wr['波乱度']
                    . '／断層タイプ ' . $wr['断層タイプ']
                    . '／下位進入度 ' . ($wr['下位進入度'] ?? '－')
                    . '／大穴進入度 ' . ($wr['大穴進入度'] ?? '－')
                    . '／厳選穴レース ' . (($wr['厳選穴レース'] === null) ? '不明'
                        : ($wr['厳選穴レース'] ? '該当' : '非該当')));
                $this->line('    公開 ' . $wr['公開頭数'] . '頭　人気薄好走 ' . $wr['人気薄好走']
                    . '頭　うち捕捉 ' . $wr['捕捉'] . '頭');
                if (empty($wr['断層の明細'])) {
                    $this->line('    断層の明細    : 記録なし（10/14より記録開始・10/17以降のレースで入ります）');
                } else {
                    foreach ($wr['断層の明細'] as $gk => $gv) {
                        $this->line('    断層の明細    : ' . $gk . ' = '
                            . (is_scalar($gv) ? (string) $gv : json_encode($gv, JSON_UNESCAPED_UNICODE)));
                    }
                }
                if (empty($wr['段階ごとの頭数'])) {
                    $this->line('    段階ごとの頭数: 記録なし（10/14より記録開始・10/17以降のレースで入ります）');
                } else {
                    foreach ($wr['段階ごとの頭数'] as $sc) {
                        $this->line(sprintf('    段階ごとの頭数: %-28s %s → %s %s',
                            (string) ($sc['段階'] ?? '－'),
                            (string) ($sc['処理前'] ?? '－'), (string) ($sc['処理後'] ?? '－'),
                            (string) ($sc['注記'] ?? '')));
                    }
                }
            }
            $this->line('');
            $this->warn('※断層の位置・本数・時系列変化は、6分前オッズから1頭1行で出す');
            $this->warn('　--longshot-csv にも入れています。こちらは断層の種類（タイプ）と');
            $this->warn('　記録済みの明細だけです。');
        }

        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261015-MISSED3  ①-a の切り分け（10/15 01.txt【3】）
        // ══════════════════════════════════════════════════════════════════
        $aTotal = array_sum($aBreak);
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' ①-a（AIが候補行に出さなかった馬）の切り分け　' . $date);
        $this->info('══════════════════════════════════════════════════════════');
        if ($aTotal === 0) {
            $this->line('  該当する馬がありません。');
        } else {
            $this->line('  対象: ' . $aTotal . '頭');
            $this->line('');
            $this->line('  【1】全頭採点での順位による切り分け');
            foreach ($aBreak as $k => $v) {
                $this->line(sprintf('    %-34s %3d頭 %s', $k, $v,
                    sprintf('(%.1f%%)', $v / $aTotal * 100)));
            }
            $this->line('');
            $this->warn('    「選出判断」は、AIが全頭採点では目標数C位以内に入れていたのに、');
            $this->warn('    候補行には出さなかった馬です。採点と選出がずれています。');
            $this->warn('    「採点不足」は、AIがそもそも下位に採点していた馬です。');
            $this->line('');
            $this->line('  【2】複勝流入ランク（人気帯内）');
            foreach ($aFuku as $k => $v) {
                $this->line(sprintf('    %-34s %3d頭 %s', $k, $v,
                    sprintf('(%.1f%%)', $v / $aTotal * 100)));
            }
            $this->line('');
            $this->line('  【3】波乱度別');
            ksort($aWave);
            foreach ($aWave as $w => $v) {
                $this->line(sprintf('    波乱度 %-4s %3d頭', ($w > 0 ? (string) $w : '－'), $v));
            }
            $this->line('');
            $this->line('  【4】1頭ごとの照合（全頭採点の順位・点数・複勝流入ランク・波乱度）');
            foreach ($aRows as $r) {
                $this->line(sprintf(
                    '    %-22s %2d番　%d着・%d番人気　波乱度%s・断層%s'
                    . '　全頭採点 %s頭中%s位・%s点（目標数C=%d）　複勝流入%s　複勝払戻%d円',
                    $r['レース'], $r['馬番'], $r['着順'], $r['人気'],
                    ($r['波乱度'] > 0 ? (string) $r['波乱度'] : '－'), $r['断層タイプ'],
                    (string) $r['全頭採点の頭数'],
                    ($r['全頭採点の順位'] === null) ? '－' : (string) $r['全頭採点の順位'],
                    ($r['全頭採点の点数'] === null) ? '－' : (string) $r['全頭採点の点数'],
                    $r['目標数C'],
                    ($r['複勝流入ランク'] === null) ? 'データなし'
                        : ($r['複勝流入ランク'] . '位'
                           . (in_array($r['複勝流入ランク'], [1, 2], true) ? '★' : '')),
                    $r['複勝払戻']));
            }
        }

        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261015-DIG  採点・人気順位・断層・資金流入の掘り下げ（03.txt）
        // ══════════════════════════════════════════════════════════════════
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' ①-a を掘り下げる（採点・人気順位・断層・資金流入）　' . $date);
        $this->info('══════════════════════════════════════════════════════════');
        $this->digCompare($feat);

        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261015-WHYLOW  低採点の理由の分類（10/15 2回目【3】②【4】）
        // ══════════════════════════════════════════════════════════════════
        $wTotal = count($whyRows);
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' AIが低く採点した理由の分類（推測を含みます）　' . $date);
        $this->info('══════════════════════════════════════════════════════════');
        if ($wTotal === 0) {
            $this->line('  該当する馬がありません。');
        } else {
            $this->line('  対象: ' . $wTotal . '頭（①-a＝AIが候補行に出さなかった馬）');
            $this->line('');
            $this->warn('  ※AIの頭の中は分かりません。分かるのは「同じレースでAIが候補行に');
            $this->warn('  　出した馬と比べて、この馬のどの数値が上だったか」です。');
            $this->warn('  　上なのに採点が低いなら、AIがその数値を見ていない可能性があります。');
            $this->warn('  　推測であることを明記して並べます。1頭が複数に当てはまります。');
            $this->line('');
            foreach ($whyLow as $k => $v) {
                $this->line(sprintf('    %-44s %3d頭 %s', $k, $v,
                    sprintf('(%.1f%%)', $v / $wTotal * 100)));
            }
            $this->line('');
            $this->line('  【1頭ごと】');
            foreach ($whyRows as $r) {
                $this->line(sprintf('    %-22s %2d番　%d着・%d番人気　採点%s頭中%s位・%s点'
                    . '　複勝流入%s　複勝払戻%d円',
                    $r['レース'], $r['馬番'], $r['着順'], $r['人気'],
                    (string) $r['全頭採点の頭数'],
                    ($r['全頭採点の順位'] === null) ? '－' : (string) $r['全頭採点の順位'],
                    ($r['全頭採点の点数'] === null) ? '－' : (string) $r['全頭採点の点数'],
                    ($r['複勝流入ランク'] === null) ? 'データなし' : ($r['複勝流入ランク'] . '位'),
                    $r['複勝払戻']));
                $this->line('        当てはまった項目: ' . implode('／', $r['当てはまった項目']));
                $this->line('        同レースでAIが候補行に出した馬の複勝流入ランク: ['
                    . implode(',', $r['候補行の複勝流入ランク']) . ']');
            }
        }

        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261015-MISSED3  AIの候補行の人気帯（枠制限の実態）
        // ══════════════════════════════════════════════════════════════════
        $cTotal = array_sum($candBand);
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' AIの候補行は人気側に偏っているか（枠制限の実態）　' . $date);
        $this->info('══════════════════════════════════════════════════════════');
        $this->line('  お願い文には「断層位置別・人気帯別の頭数上限は廃止されています」と');
        $this->line('  書いてあり、PHP側でも人気帯の上限はかけていません。');
        $this->line('  それでもAIが実際に出してくる候補行が人気側に偏っているなら、');
        $this->line('  上限の有無とは別に、実態として枠が働いていることになります。');
        $this->line('');
        if ($cTotal === 0) {
            $this->line('  1st AIの候補行が読めたレースがありません。');
        } else {
            $this->line('  1st AIの候補行が読めたレース: ' . $candRace
                . '（この日の全レースが母数です）');
            foreach ($candBand as $k => $v) {
                $this->line(sprintf('    %-14s %4d頭 %s', $k, $v,
                    sprintf('(%.1f%%)', $v / $cTotal * 100)));
            }
            $this->line(sprintf('    合計           %4d頭（1レースあたり %.1f頭）',
                $cTotal, $candRace > 0 ? $cTotal / $candRace : 0));
            $long = $candBand['7〜10番人気'] + $candBand['11番人気以下'];
            $this->line('');
            if ($long === 0) {
                $this->error('  → AIの候補行に7番人気以下が1頭もありません。');
                $this->error('     上限はかけていないのに、AIは人気側だけで候補を埋めています。');
            } else {
                $this->warn(sprintf('  → AIの候補行のうち7番人気以下は %d頭（%.1f%%）です。',
                    $long, $long / $cTotal * 100));
                $this->warn('     上限はかけていないので、これはAIの選出判断による偏りです。');
            }
        }

        $path = storage_path('logs/missed_analysis_' . $date . '.json');
        @file_put_contents($path, json_encode(
            ['date' => $date, '合計' => $missTotal, '内訳' => $cat,
             '波乱度別' => $byWave, '波乱度3以上のレース' => $waveRaces,
             '①-aの切り分け' => ['順位' => $aBreak, '複勝流入ランク' => $aFuku,
                                 '波乱度別' => $aWave, '明細' => $aRows],
             '掘り下げ' => ['①-a' => $feat['①-a'], '好走' => $feat['好走'],
                            '凡走' => $feat['凡走']],
             '低採点の理由の分類' => ['注記' => '推測を含む。同じレースでAIが候補行に出した馬の'
                                                 . '数値と比べ、上なのに採点が低い項目を並べたもの',
                                      '集計' => $whyLow, '明細' => $whyRows],
             'AIの候補行の人気帯' => ['頭数' => $candBand, 'レース数' => $candRace],
             '明細' => $rowsOut],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $this->info('内訳を書き出しました: ' . $path);
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261014-MISSED2  全頭採点での点数と順位を文字にする
     * ═════════════════════════════════════════════════════════════════════════
     * 「評価されていなかった」のか「評価はされていたが選ばれなかった」のかを
     * 区別するために、その馬が全頭採点で何点・何位だったかを出します。
     * 同点のときは馬番の小さい順で順位を付けます（allScoreReport と同じ）。
     */
    private function scoreRankText(array $as, int $num): array
    {
        if (empty($as['received'])) {
            return ['text' => '未受領（' . (string) ($as['reason'] ?? '') . '）',
                    'score' => null, 'rank' => null, 'total' => 0];
        }
        $total = (int) ($as['count'] ?? 0);
        if (!in_array($num, (array) ($as['nums'] ?? []), true)) {
            return ['text' => '採点に馬番が無い（' . $total . '頭中に不在）',
                    'score' => null, 'rank' => null, 'total' => $total];
        }
        $sc = ($as['scores'] ?? [])[$num] ?? null;
        $rk = ($as['ranks']  ?? [])[$num] ?? null;
        if ($sc === null) {
            return ['text' => '採点にはいるが総合点が無い（' . $total . '頭中）',
                    'score' => null, 'rank' => $rk, 'total' => $total];
        }
        return ['text' => sprintf('%d頭中%s位・%s点', $total,
                    ($rk === null) ? '－' : (string) $rk, (string) round((float) $sc, 1)),
                'score' => round((float) $sc, 1), 'rank' => $rk, 'total' => $total];
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261014-MISSED2  統合の監査ログから、PHPのどの段階まで残ったかを出す
     * ═════════════════════════════════════════════════════════════════════════
     * 「選出枠・条件・フィルターのどこで落ちたか」を実値で示すために、
     * 監査ログに残っている馬番の並びと突き合わせます。
     */
    private function phpStageText(array $audit, int $num): array
    {
        $has = static function ($list) use ($num): bool {
            return in_array($num, array_map('intval', (array) $list), true);
        };
        $stage = [];
        $stage[] = $has($audit['資格あり馬番'] ?? []) ? '資格あり' : '資格なし';
        if ($has($audit['保護対象馬番'] ?? []))                       $stage[] = '保護対象';
        if ($has($audit['最低基準点で第1パス後回しにした馬番'] ?? [])) $stage[] = '最低基準点で第1パス後回し';
        if ($has($audit['資格未達で補充しなかった馬番'] ?? []))        $stage[] = '資格未達で補充せず';
        if ($has($audit['補充枠の馬番'] ?? []))                        $stage[] = '補充枠';
        if ($has($audit['例外補充（0頭回避）'] ?? []))                 $stage[] = '例外補充（0頭回避）';
        if ($has($audit['B方式相当の選出'] ?? []))                     $stage[] = 'B方式なら公開されていた';
        if ($has($audit['最終表示'] ?? []))                            $stage[] = '最終表示に入った';
        return $stage;
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261015-DIG  1頭ぶんの「採点・人気順位・断層・資金流入」をまとめる
     * ═════════════════════════════════════════════════════════════════════════
     * 10月15日（1回目）03.txtへの対応です。
     *   「33頭について、なぜAIが選ばなかったのかを
     *     採点・人気順位・断層・資金流入まで掘り下げれば、
     *     次に修正すべきプロンプトの箇所がかなり明確になると思う」
     *
     * --longshot-csv が出している項目と同じ定義を使います
     * （支持率はオッズ逆数の構成比、短縮率は（計測開始前−6分前）÷計測開始前×100、
     *   断層は6分前の単勝オッズの隣接比率2.00以上）。
     */
    private function longshotFeatures(array $info, int $num, array $as): array
    {
        $g = $this->longshotGapFor($info, $num);
        $fr = $info['fukuInflowRank'][$num] ?? null;
        $tr = $info['tanInflowRank'][$num]  ?? null;
        $rk = $this->scoreRankText($as, $num);
        $pp = $info['pop'][$num] ?? null;
        return [
            '馬番'                 => $num,
            '人気'                 => $pp,
            '人気帯'               => ($pp === null) ? '不明'
                                      : (($pp <= 6) ? '1〜6番人気'
                                      : (($pp <= 10) ? '7〜10番人気' : '11番人気以下')),
            '全頭採点の点数'       => $rk['score'],
            '全頭採点の順位'       => $rk['rank'],
            '全頭採点の頭数'       => $rk['total'],
            '採点が上位半分'       => ($rk['rank'] !== null && $rk['total'] > 0)
                                      ? ($rk['rank'] <= (int) ceil($rk['total'] / 2)) : null,
            '単勝支持率'           => $info['tanShare'][$num]  ?? null,
            '複勝支持率'           => $info['fukuShare'][$num] ?? null,
            '単勝短縮率'           => $info['tanShrink'][$num]  ?? null,
            '複勝短縮率'           => $info['fukuShrink'][$num] ?? null,
            '単勝流入ランク'       => $tr,
            '複勝流入ランク'       => $fr,
            '単勝流入1位か2位'     => ($tr === null) ? null : in_array($tr, [1, 2], true),
            '複勝流入1位か2位'     => ($fr === null) ? null : in_array($fr, [1, 2], true),
            '断層本数_6番人気以内' => $info['gapTop6'] ?? 0,
            '断層本数_全体'        => $info['gapAll']  ?? 0,
            '断層位置'             => implode(' ', $info['gapPos'] ?? []),
            '直上が断層'           => $g['isGap'],
            '断層の下側先頭'       => $g['isGapHead'],
            '直上の時系列変化'     => $g['trend'],
            '直上の比率_計測前'    => $g['base'],
            '直上の比率_期間最大'  => $g['max'],
            '直上の比率_6分前'     => $g['now'],
        ];
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261015-RECO  購入推奨かどうかの判定を1か所にまとめる
     * ═════════════════════════════════════════════════════════════════════════
     * 10月15日の --plan2-compare / --longshot-csv で見つけた集計の誤りの修正です。
     *
     * 【何を間違えたか】
     *   購入推奨を ($h['purchase_recommended'] ?? true) !== false で判定していました。
     *   purchase_recommended をレコードへ保存し始めたのは【10月14日】なので、
     *   10月10日〜12日のレースにはこのキーがありません。
     *   すると ?? true が効いて【公開候補の全頭が購入推奨】になってしまい、
     *   3日合計で 299頭ではなく 380頭と数えていました。
     *   複勝回収率も 77.4% ではなく 78.4% と出ていました。
     *
     * 【正しい判定】--weekend-report が以前から使っている式に合わせます。
     *   ・purchase_recommended が保存されていれば、その値を使う
     *   ・保存されていなければ、同じ条件で算出する
     *       補充枠でない　かつ　購入回避フラグがどれも立っていない
     *     （購入回避フラグ＝最低基準点未満・人気薄の回収率・低配当・過去回収率）
     *
     * これで10月14日より前のレースも、10月17日以降のレースも同じ数になります。
     */
    private function isPurchaseRecommended(array $h): bool
    {
        if (array_key_exists('purchase_recommended', $h)) {
            return (bool) $h['purchase_recommended'];
        }
        $fill  = !empty($h['fill_slot_unqualified']);
        $avoid = (!empty($h['below_min_score']) || !empty($h['buy_avoid_longshot'])
                  || !empty($h['buy_avoid_low_payout']) || !empty($h['buy_avoid_recovery']));
        return (!$fill && !$avoid);
    }

    /** @ANCHOR-20261015-DIG  null を「－」にして表示する */
    private function na($v): string
    {
        return ($v === null || $v === '') ? '－' : (string) $v;
    }

    /**
     * @ANCHOR-20261015-DIG  同じ項目を3つの集団で並べる
     *
     * ①-a（AIが候補行に出さなかった馬）／7番人気以下の好走／7番人気以下の凡走。
     * ①-aが凡走と変わらないなら、AIが選ばなかったのは妥当だったことになります。
     * 好走に近く凡走と違うなら、お願い文へ書く余地があることになります。
     */
    private function digCompare(array $feat): void
    {
        $keys = ['①-a', '好走', '凡走'];
        $n = [];
        foreach ($keys as $k) $n[$k] = count($feat[$k] ?? []);
        if (array_sum($n) === 0) {
            $this->line('  比べられる馬がありません。');
            return;
        }

        $avg = static function (array $rows, string $k): string {
            $v = [];
            foreach ($rows as $r) { if (($r[$k] ?? null) !== null) $v[] = (float) $r[$k]; }
            return empty($v) ? '－' : sprintf('%.1f', array_sum($v) / count($v));
        };
        $pctTrue = static function (array $rows, string $k): string {
            $d = 0; $c = 0;
            foreach ($rows as $r) {
                if (($r[$k] ?? null) === null) continue;
                $d++;
                if ($r[$k] === true) $c++;
            }
            return ($d === 0) ? '－' : sprintf('%.1f%%', $c / $d * 100);
        };
        $pctYes = static function (array $rows, string $k): string {
            if (empty($rows)) return '－';
            $c = 0;
            foreach ($rows as $r) { if (($r[$k] ?? '') === 'はい') $c++; }
            return sprintf('%.1f%%', $c / count($rows) * 100);
        };
        $pctEq = static function (array $rows, string $k, string $want): string {
            if (empty($rows)) return '－';
            $c = 0;
            foreach ($rows as $r) { if ((string) ($r[$k] ?? '') === $want) $c++; }
            return sprintf('%.1f%%', $c / count($rows) * 100);
        };

        $this->line(sprintf('  %-30s %14s %14s %14s', '',
            '①-a（' . $n['①-a'] . '頭）',
            '好走（' . $n['好走'] . '頭）',
            '凡走（' . $n['凡走'] . '頭）'));

        $rows = [
            ['採点', '全頭採点の平均点', 'avg', '全頭採点の点数'],
            ['採点', '全頭採点の平均順位', 'avg', '全頭採点の順位'],
            ['採点', '採点が上位半分だった割合', 'true', '採点が上位半分'],
            ['人気順位', '平均の人気（レース全体）', 'avg', '人気'],
            ['人気順位', '7〜10番人気の割合', 'eq:人気帯:7〜10番人気', ''],
            ['人気順位', '11番人気以下の割合', 'eq:人気帯:11番人気以下', ''],
            ['資金流入', '単勝支持率の平均%', 'avg', '単勝支持率'],
            ['資金流入', '複勝支持率の平均%', 'avg', '複勝支持率'],
            ['資金流入', '単勝短縮率の平均%', 'avg', '単勝短縮率'],
            ['資金流入', '複勝短縮率の平均%', 'avg', '複勝短縮率'],
            ['資金流入', '単勝流入ランクが1位か2位', 'true', '単勝流入1位か2位'],
            ['資金流入', '複勝流入ランクが1位か2位', 'true', '複勝流入1位か2位'],
            ['断層', '6番人気以内の断層の平均本数', 'avg', '断層本数_6番人気以内'],
            ['断層', 'レース全体の断層の平均本数', 'avg', '断層本数_全体'],
            ['断層', '直上が断層だった割合', 'yes', '直上が断層'],
            ['断層', '断層の下側先頭だった割合', 'yes', '断層の下側先頭'],
            ['断層', '直上の断層が縮小していた割合', 'eq:直上の時系列変化:縮小', ''],
            ['断層', '直上の断層が拡大していた割合', 'eq:直上の時系列変化:拡大', ''],
        ];
        $lastCat = '';
        foreach ($rows as $r) {
            [$cat, $what, $how, $key] = $r;
            if ($cat !== $lastCat) { $this->line('  【' . $cat . '】'); $lastCat = $cat; }
            $cells = [];
            foreach ($keys as $k) {
                $src = $feat[$k] ?? [];
                if      ($how === 'avg')  $cells[] = $avg($src, $key);
                elseif  ($how === 'true') $cells[] = $pctTrue($src, $key);
                elseif  ($how === 'yes')  $cells[] = $pctYes($src, $key);
                else {
                    $parts = explode(':', $how, 3);
                    $cells[] = $pctEq($src, $parts[1], $parts[2]);
                }
            }
            $this->line(sprintf('    %-28s %14s %14s %14s', $what,
                $cells[0], $cells[1], $cells[2]));
        }

        $this->line('');
        $this->warn('  ※好走・凡走は、この日の7番人気以下の出走馬すべてが母数です');
        $this->warn('  　（拾えなかった馬がいないレースも含みます）。');
        $this->warn('  ※①-aは3着内に来た馬なので、好走の一部です。');
        $this->warn('  　見るのは「①-aが凡走の平均と違うか」です。');
        $this->warn('  　違わないなら、AIが選ばなかったのは妥当だったことになります。');
        $this->warn('  　違うなら、その項目をお願い文で見るように書く余地があります。');
        $this->warn('  ※支持率は投票数ではなくオッズ逆数の構成比です（控除率を含む近似値）。');
        $this->warn('  ※短縮率は（計測開始前−6分前）÷計測開始前×100。正の値ほど資金が入っています。');
    }


    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261014-LONGSHOT  人気薄を1頭1行で並べる（--longshot-csv）
     * ═════════════════════════════════════════════════════════════════════════
     * 10月14日（7回目）02.txt ②への対応です。
     *   「全頭採点に存在していたにもかかわらず、最終候補から漏れた人気薄46頭に
     *     ついて、以下を一覧化してください。
     *       ・全頭採点と順位
     *       ・単勝、複勝支持率と資金流入
     *       ・断層の位置、本数、時系列変化
     *       ・候補から漏れた具体的な理由
     *       ・実際の着順
     *     好走馬だけでなく凡走馬との比較もお願いします」
     *
     * 7番人気以下の出走馬を【全頭】1行ずつ出します。好走した馬だけに絞ると
     * 比較ができないためです。3着内に来た馬を「好走」、4着以下を「凡走」として
     * 列に入れ、画面の末尾で両者の平均を並べます。
     *
     * 支持率について：このシステムは投票数そのものを持っていません。
     * オッズの逆数の構成比を支持率として計算しています（控除率を含んだ近似値です）。
     * 列名にも「オッズ逆数の構成比」と書いて、実際の投票率と混同しないようにします。
     *
     * 断層について：6分前の単勝オッズを人気順に並べ、隣り合う馬の比率が2.00以上の
     * 箇所を断層としています（本番の判定と同じ閾値です）。
     *   ・本数 … 6番人気以内の本数と、レース全体の本数
     *   ・位置 … 「3-4」なら3番人気と4番人気の間
     *   ・時系列変化 … その馬の直上の境界の比率を、計測開始前・期間中の最大・
     *                  6分前で並べ、6分前が期間最大から10%以上下がっていれば
     *                  「縮小」、10%以上上がっていれば「拡大」とします
     *                  （本番の断層縮小の判定と同じ10%です）
     *
     * 読み取りだけです。DBへの書き込みもAI呼び出しもありません。
     *
     * ⚠ 毎週土曜5:50の洗い替えでオッズが消えます。10月10日〜12日ぶんは
     *   10月17日(土)5:50より前に取り切る必要があります。書き出したCSVは
     *   storage/logs/ に残るので、洗い替え後も内容は参照できます。
     */
    private function runLongshotCsv(string $dateArg): void
    {
        $dates = array_values(array_filter(array_map('trim', explode(',', $dateArg))));
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 人気薄1頭1行の一覧  対象日 ' . implode(' / ', $dates));
        $this->info('  （読み取りだけ・DBへの書き込みなし・AI呼び出し0回）');
        $this->info('══════════════════════════════════════════════════════════');
        $this->warn(' ※毎週土曜5:50の洗い替えでオッズが消えます。');
        $this->warn('   10/10〜10/12ぶんは10/17(土)5:50より前に取り切ってください。');

        $head = ['日付', 'race_id', 'レース', '波乱度', '断層タイプ', '厳選穴レース',
                 '出走頭数', '目標数C', '公開頭数',
                 '馬番', '6分前人気', '単勝オッズ6分前', '複勝最小オッズ6分前',
                 '単勝支持率%（オッズ逆数の構成比）', '複勝支持率%（オッズ逆数の構成比）',
                 '単勝短縮率%（正=資金流入）', '複勝短縮率%（正=資金流入）',
                 '人気帯', '単勝流入ランク', '複勝流入ランク', '人気帯の頭数',
                 '断層本数_6番人気以内', '断層本数_全体', '断層位置',
                 '直上の境界比率_計測前', '直上の境界比率_期間最大', '直上の境界比率_6分前',
                 '直上が断層か', '直上の境界の時系列変化', '断層の下側先頭か',
                 '1st全頭採点の点数', '1st全頭採点の順位', '1st全頭採点の頭数',
                 '2nd全頭採点の点数', '2nd全頭採点の順位', '2nd全頭採点の頭数',
                 '1stの候補行にいた', '2ndの候補行にいた', '統合点',
                 '公開候補', '購入推奨', '補充枠', 'PHPの段階', '漏れた理由の区分',
                 '着順', '複勝払戻', '好走凡走'];

        $rows = [];
        $agg = ['好走' => [], '凡走' => []];
        $raceCnt = 0;

        foreach ($dates as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->error("日付の書式が不正です: {$date}");
                continue;
            }
            $merges = DB::table('t_horse_odds_finder_ai_merge_result')
                ->where('date', $date)
                ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
                ->get();
            if ($merges->isEmpty()) {
                $this->warn("{$date} の統合結果がありません。");
                continue;
            }

            // ── 着順・払戻 ────────────────────────────────────────────
            $resMap = [];
            foreach (DB::table('t_horse_odds_finder_race_results')->where('date', $date)->get() as $r) {
                $resMap[$r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race][(int) $r->num]
                    = (int) $r->result;
            }
            $payMap = [];
            foreach (DB::table('t_horse_odds_finder_race_result_payout')->where('date', $date)->get() as $r) {
                $k = $r->kaisuu . '|' . $r->basho_code . '|' . $r->day . '|' . $r->race;
                foreach (explode('/', (string) ($r->fuku ?? '')) as $piece) {
                    $pair = explode('|', trim($piece));
                    if (count($pair) === 2 && ctype_digit(trim($pair[0]))) {
                        $payMap[$k][(int) trim($pair[0])] = (int) preg_replace('/\D/', '', $pair[1]);
                    }
                }
            }

            // ── オッズ時系列（計測開始前〜6分前）────────────────────────
            //   本番と同じ時点を読みます（999 = 計測開始前）。
            $timings = [\App\Constants\Constants::ODDS_DB_FIRST, 21, 18, 15, 12, 9, 6];
            $series  = [];   // key => num => ['tan'=>[t=>v], 'fuku'=>[t=>v]]
            foreach (DB::table('t_horse_odds_finder_odds')
                        ->where('date', $date)
                        ->whereIn('minutes_before_start', $timings)
                        ->get(['kaisuu', 'basho', 'day', 'race', 'num',
                               'minutes_before_start', 'odds', 'fuku_min']) as $r) {
                $k = $r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race;
                $t = (int) $r->minutes_before_start;
                $n = (int) $r->num;
                if (is_numeric($r->odds)     && (float) $r->odds     > 0) $series[$k][$n]['tan'][$t]  = (float) $r->odds;
                if (is_numeric($r->fuku_min) && (float) $r->fuku_min > 0) $series[$k][$n]['fuku'][$t] = (float) $r->fuku_min;
            }
            if (empty($series)) {
                $this->warn("{$date} の6分前オッズがありません（洗い替え済みの可能性）。この日は飛ばします。");
                continue;
            }

            foreach ($merges as $mg) {
                $dec = json_decode((string) ($mg->merge_result ?? ''), true);
                if (!is_array($dec)) continue;
                if ((string) ($dec['status'] ?? '') === 'undetermined') continue;

                $label  = "{$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R";
                $raceId = "{$date}_{$mg->kaisuu}_{$mg->basho_code}_{$mg->day}_{$mg->race}";
                $key    = $mg->kaisuu . '|' . $mg->basho_code . '|' . $mg->day . '|' . $mg->race;
                $sr      = $series[$key] ?? [];
                $results = $resMap[$key] ?? [];
                $pays    = $payMap[$key] ?? [];
                if (empty($sr)) continue;

                $info = $this->longshotRaceInfo($sr);
                if (empty($info['pop'])) continue;
                $raceCnt++;

                $audit  = is_array($dec['audit_merge'] ?? null) ? $dec['audit_merge'] : [];
                $auRows = is_array($audit['全頭'] ?? null) ? $audit['全頭'] : [];
                $wave   = ($mg->wave_level === null) ? 0 : (int) $mg->wave_level;

                $pubNums = []; $buyNums = []; $fillNums = [];
                foreach ((is_array($dec['merged_horses'] ?? null) ? $dec['merged_horses'] : []) as $h) {
                    if (!isset($h['num'])) continue;
                    $hn = (int) $h['num'];
                    $pubNums[] = $hn;
                    // @ANCHOR-20261015-RECO  10/14より前のレースも同じ式で数える
                    if ($this->isPurchaseRecommended($h)) $buyNums[] = $hn;
                    if (($h['fill_slot_unqualified'] ?? false) === true) $fillNums[] = $hn;
                }

                $txt1 = DB::table('t_horse_odds_finder_ai_analysis')
                    ->where('date', $date)->where('kaisuu', $mg->kaisuu)
                    ->where('basho_code', $mg->basho_code)->where('day', $mg->day)
                    ->where('race', $mg->race)->value('analysis_text');
                $txt2 = DB::table('t_horse_odds_finder_ai_analysis2')
                    ->where('date', $date)->where('kaisuu', $mg->kaisuu)
                    ->where('basho_code', $mg->basho_code)->where('day', $mg->day)
                    ->where('race', $mg->race)->value('analysis_text');
                $as1 = ($txt1 === null) ? ['received' => false, 'nums' => [], 'count' => 0,
                                           'scores' => [], 'ranks' => [],
                                           'reason' => '1st AIの回答が保存されていない']
                                        : AiController::allScoreReport((string) $txt1);
                $as2 = ($txt2 === null) ? ['received' => false, 'nums' => [], 'count' => 0,
                                           'scores' => [], 'ranks' => [],
                                           'reason' => '2nd AIの回答が保存されていない']
                                        : AiController::allScoreReport((string) $txt2);
                $cand1 = ($txt1 === null) ? [] : AiController::publicCandidateNums((string) $txt1);
                $cand2 = ($txt2 === null) ? [] : AiController::publicCandidateNums((string) $txt2);

                foreach ($info['pop'] as $hn => $pp) {
                    if ($pp < 7) continue;   // 人気薄だけ（7番人気以下）
                    $g   = $this->longshotGapFor($info, $hn);
                    $rk1 = $this->scoreRankText($as1, $hn);
                    $rk2 = $this->scoreRankText($as2, $hn);
                    $inPub = in_array($hn, $pubNums, true);
                    $row1  = in_array($hn, $cand1, true);
                    $row2  = in_array($hn, $cand2, true);
                    $in1   = in_array($hn, (array) $as1['nums'], true);
                    $in2   = in_array($hn, (array) $as2['nums'], true);
                    $pos   = $results[$hn] ?? null;

                    if ($inPub)                               $why = '－（公開候補に入っていた）';
                    elseif ($row1 || $row2)                   $why = '①-b AIは候補行に出したがPHPで落ちた';
                    elseif ($in1 || $in2)                     $why = '①-a AIが候補行に出さなかった';
                    elseif ($as1['received'] || $as2['received']) $why = '②採点に馬番が無い';
                    else                                      $why = '③採点が未受領';

                    $auRow = $auRows[(string) $hn] ?? ($auRows[$hn] ?? []);
                    $kind  = ($pos === null) ? '着順なし' : (($pos <= 3) ? '好走' : '凡走');

                    $cells = [
                        $date, $raceId, $label, ($wave > 0 ? $wave : ''),
                        (string) ($mg->gap_type ?? ''),
                        ($mg->upset_race === null) ? '' : (((int) $mg->upset_race === 1) ? '該当' : '非該当'),
                        $info['N'], (string) ($audit['目標数C'] ?? ''), count($pubNums),
                        $hn, $pp,
                        $info['tan6'][$hn] ?? '', $info['fuku6'][$hn] ?? '',
                        $info['tanShare'][$hn] ?? '', $info['fukuShare'][$hn] ?? '',
                        ($info['tanShrink'][$hn] ?? null) === null ? '' : $info['tanShrink'][$hn],
                        ($info['fukuShrink'][$hn] ?? null) === null ? '' : $info['fukuShrink'][$hn],
                        $info['band'][$hn] ?? '',
                        ($info['tanInflowRank'][$hn] ?? null) === null ? 'データなし' : $info['tanInflowRank'][$hn],
                        ($info['fukuInflowRank'][$hn] ?? null) === null ? 'データなし' : $info['fukuInflowRank'][$hn],
                        $info['bandSize'][$info['band'][$hn] ?? ''] ?? '',
                        $info['gapTop6'], $info['gapAll'], implode(' ', $info['gapPos']),
                        $g['base'], $g['max'], $g['now'],
                        $g['isGap'], $g['trend'], $g['isGapHead'],
                        $rk1['score'] ?? '', $rk1['rank'] ?? '', $rk1['total'],
                        $rk2['score'] ?? '', $rk2['rank'] ?? '', $rk2['total'],
                        $row1 ? 'はい' : 'いいえ', $row2 ? 'はい' : 'いいえ',
                        $auRow['統合点'] ?? '',
                        $inPub ? 'はい' : 'いいえ',
                        in_array($hn, $buyNums, true) ? 'はい' : 'いいえ',
                        in_array($hn, $fillNums, true) ? 'はい' : 'いいえ',
                        implode('・', $this->phpStageText($audit, $hn)),
                        $why,
                        ($pos === null) ? '' : $pos, (int) ($pays[$hn] ?? 0), $kind,
                    ];
                    $rows[] = $cells;

                    if ($kind === '好走' || $kind === '凡走') {
                        $agg[$kind][] = [
                            '点' => $rk1['score'], '順位' => $rk1['rank'], '頭数' => $rk1['total'],
                            '単支持' => $info['tanShare'][$hn] ?? null,
                            '複支持' => $info['fukuShare'][$hn] ?? null,
                            '単短縮' => $info['tanShrink'][$hn] ?? null,
                            '複短縮' => $info['fukuShrink'][$hn] ?? null,
                            '単流入1位2位' => in_array($info['tanInflowRank'][$hn] ?? 0, [1, 2], true),
                            '複流入1位2位' => in_array($info['fukuInflowRank'][$hn] ?? 0, [1, 2], true),
                            '直上断層' => ($g['isGap'] === 'はい'),
                            '断層下側先頭' => ($g['isGapHead'] === 'はい'),
                            '縮小' => ($g['trend'] === '縮小'),
                            '公開' => $inPub,
                        ];
                    }
                }
            }
        }

        if (empty($rows)) {
            $this->error('書き出す行がありません。オッズが洗い替えで消えている可能性があります。');
            return;
        }
        $name = 'longshot_detail_' . str_replace(',', '_', str_replace('-', '', $dateArg)) . '.csv';
        $path = storage_path('logs/' . $name);
        $fh = @fopen($path, 'w');
        if ($fh === false) {
            $this->error('CSVを書けません: ' . $path);
            return;
        }
        fwrite($fh, "\xEF\xBB\xBF");                 // ExcelでそのままUTF-8で開けるように
        fputcsv($fh, $head);
        foreach ($rows as $r) fputcsv($fh, $r);
        fclose($fh);
        @chmod($path, 0666);

        $this->line('');
        $this->info('── 好走と凡走の比較（7番人気以下） ──────────');
        $this->line(sprintf('  %-26s %12s %12s', '', '好走（3着内）', '凡走（4着以下）'));
        $this->line(sprintf('  %-26s %12d %12d', '頭数', count($agg['好走']), count($agg['凡走'])));
        $avg = static function (array $rows, string $k) {
            $v = array_values(array_filter(array_map(static fn($r) => $r[$k], $rows),
                                           static fn($x) => $x !== null));
            return empty($v) ? '－' : sprintf('%.1f', array_sum($v) / count($v));
        };
        $pct = static function (array $rows, string $k) {
            if (empty($rows)) return '－';
            $c = count(array_filter($rows, static fn($r) => !empty($r[$k])));
            return sprintf('%.1f%%', $c / count($rows) * 100);
        };
        foreach ([['1st全頭採点の平均点', '点'], ['1st全頭採点の平均順位', '順位'],
                  ['単勝支持率の平均%', '単支持'], ['複勝支持率の平均%', '複支持'],
                  ['単勝短縮率の平均%', '単短縮'], ['複勝短縮率の平均%', '複短縮']] as $pair) {
            $this->line(sprintf('  %-26s %12s %12s', $pair[0],
                $avg($agg['好走'], $pair[1]), $avg($agg['凡走'], $pair[1])));
        }
        foreach ([['単勝流入ランク1位か2位', '単流入1位2位'],
                  ['複勝流入ランク1位か2位', '複流入1位2位'],
                  ['直上が断層', '直上断層'], ['断層の下側先頭', '断層下側先頭'],
                  ['直上の断層が縮小', '縮小'], ['公開候補に入っていた', '公開']] as $pair) {
            $this->line(sprintf('  %-26s %12s %12s', $pair[0],
                $pct($agg['好走'], $pair[1]), $pct($agg['凡走'], $pair[1])));
        }

        $this->line('');
        $this->info('  対象レース: ' . $raceCnt . 'レース　行数: ' . count($rows) . '行（見出しを除く）');
        $this->info('  書き出しました: ' . $path);
        $this->line('');
        $this->warn('※支持率は投票数そのものではなく、オッズの逆数の構成比です（控除率を含む近似値）。');
        $this->warn('※短縮率は（計測開始前 − 6分前）÷ 計測開始前 × 100。正の値ほど資金が入っています。');
        $this->warn('※断層は6分前の単勝オッズを人気順に並べ、隣の比率が2.00以上の箇所です（本番と同じ閾値）。');
    }

    /**
     * @ANCHOR-20261014-LONGSHOT  1レースの人気・支持率・資金流入・断層をまとめて作る
     *
     * 本番のプロンプト作成と同じ式を使います（短縮率・人気帯・流入ランク）。
     * 支持率だけはオッズの逆数の構成比で、投票数そのものではありません。
     */
    private function longshotRaceInfo(array $sr): array
    {
        $first = \App\Constants\Constants::ODDS_DB_FIRST;
        $tan6 = []; $fuku6 = [];
        foreach ($sr as $n => $o) {
            if (isset($o['tan'][6]))  $tan6[$n]  = (float) $o['tan'][6];
            if (isset($o['fuku'][6])) $fuku6[$n] = (float) $o['fuku'][6];
        }
        if (empty($tan6)) return ['pop' => []];

        // 人気順（6分前の単勝オッズ昇順。同値は馬番の小さい順）
        $order = array_keys($tan6);
        usort($order, static function ($a, $b) use ($tan6) {
            if ($tan6[$a] === $tan6[$b]) return $a <=> $b;
            return ($tan6[$a] < $tan6[$b]) ? -1 : 1;
        });
        $pop = []; $i = 0;
        foreach ($order as $n) { $i++; $pop[$n] = $i; }

        // 支持率（オッズ逆数の構成比）
        $tanInvSum = 0.0; $fukuInvSum = 0.0;
        foreach ($tan6  as $v) $tanInvSum  += 1 / $v;
        foreach ($fuku6 as $v) $fukuInvSum += 1 / $v;
        $tanShare = []; $fukuShare = [];
        foreach ($tan6 as $n => $v) {
            $tanShare[$n] = ($tanInvSum > 0) ? round((1 / $v) / $tanInvSum * 100, 2) : null;
        }
        foreach ($fuku6 as $n => $v) {
            $fukuShare[$n] = ($fukuInvSum > 0) ? round((1 / $v) / $fukuInvSum * 100, 2) : null;
        }

        // 短縮率（本番と同じ式）
        $tanShrink = []; $fukuShrink = [];
        foreach ($sr as $n => $o) {
            $tb = $o['tan'][$first]  ?? null; $t6 = $o['tan'][6]  ?? null;
            $fb = $o['fuku'][$first] ?? null; $f6 = $o['fuku'][6] ?? null;
            $tanShrink[$n]  = ($tb && $t6 && $tb > 0) ? round(($tb - $t6) / $tb * 100, 1) : null;
            $fukuShrink[$n] = ($fb && $f6 && $fb > 0) ? round(($fb - $f6) / $fb * 100, 1) : null;
        }

        // 人気帯と、帯の中での流入ランク（本番と同じ区切り）
        $band = []; $bandIdx = ['1〜3人気' => [], '4〜6人気' => [], '7人気以下' => []];
        foreach ($pop as $n => $pp) {
            $b = ($pp <= 3) ? '1〜3人気' : (($pp <= 6) ? '4〜6人気' : '7人気以下');
            $band[$n] = $b;
            $bandIdx[$b][] = $n;
        }
        $tanInflowRank = []; $fukuInflowRank = []; $bandSize = [];
        foreach ($bandIdx as $b => $nums) {
            $bandSize[$b] = count($nums);
            foreach ([['tanShrink', 'tanInflowRank'], ['fukuShrink', 'fukuInflowRank']] as $pair) {
                $src = ($pair[0] === 'tanShrink') ? $tanShrink : $fukuShrink;
                $valid = array_values(array_filter($nums, static fn($n) => ($src[$n] ?? null) !== null));
                usort($valid, static fn($a, $b2) => $src[$b2] <=> $src[$a]);
                foreach ($valid as $rk => $n) {
                    if ($pair[1] === 'tanInflowRank') $tanInflowRank[$n]  = $rk + 1;
                    else                              $fukuInflowRank[$n] = $rk + 1;
                }
                foreach (array_diff($nums, $valid) as $n) {
                    if ($pair[1] === 'tanInflowRank') $tanInflowRank[$n]  = null;
                    else                              $fukuInflowRank[$n] = null;
                }
            }
        }

        // 断層（6分前・人気順の隣接比率が2.00以上）
        $gapPos = []; $gapTop6 = 0; $gapAll = 0;
        $ratio6 = [];   // k（上側の人気）=> 比率
        for ($k = 1; $k < count($order); $k++) {
            $up = $order[$k - 1]; $dn = $order[$k];
            if (($tan6[$up] ?? 0) <= 0) continue;
            $r = round($tan6[$dn] / $tan6[$up], 2);
            $ratio6[$k] = $r;
            if ($r >= 2.00) {
                $gapAll++;
                $gapPos[] = $k . '-' . ($k + 1);
                if ($k <= 5) $gapTop6++;
            }
        }

        return ['pop' => $pop, 'N' => count($order), 'order' => $order,
                'tan6' => $tan6, 'fuku6' => $fuku6,
                'tanShare' => $tanShare, 'fukuShare' => $fukuShare,
                'tanShrink' => $tanShrink, 'fukuShrink' => $fukuShrink,
                'band' => $band, 'bandSize' => $bandSize,
                'tanInflowRank' => $tanInflowRank, 'fukuInflowRank' => $fukuInflowRank,
                'gapPos' => $gapPos, 'gapTop6' => $gapTop6, 'gapAll' => $gapAll,
                'ratio6' => $ratio6, 'series' => $sr];
    }

    /**
     * @ANCHOR-20261014-LONGSHOT  その馬の「直上の境界」の位置と時系列変化
     *
     * 直上の境界 = その馬（人気k）と、1つ上の人気(k-1)の間の比率。
     * 計測開始前・期間中の最大・6分前を並べ、6分前が期間最大から10%以上
     * 下がっていれば「縮小」、10%以上上がっていれば「拡大」とします。
     * 1番人気には直上がないので「－」になります。
     */
    private function longshotGapFor(array $info, int $num): array
    {
        $none = ['base' => '', 'max' => '', 'now' => '',
                 'isGap' => '－', 'trend' => '－', 'isGapHead' => 'いいえ'];
        $pp = $info['pop'][$num] ?? null;
        if ($pp === null || $pp < 2) return $none;

        $order = $info['order'];
        $up = $order[$pp - 2] ?? null;    // 1つ上の人気の馬
        if ($up === null) return $none;
        $sr = $info['series'];

        $ratioAt = static function (int $t) use ($sr, $up, $num) {
            $a = $sr[$up]['tan'][$t]  ?? null;
            $b = $sr[$num]['tan'][$t] ?? null;
            if ($a === null || $b === null || $a <= 0) return null;
            return round($b / $a, 2);
        };
        $first = \App\Constants\Constants::ODDS_DB_FIRST;
        $base = $ratioAt($first);
        $now  = $ratioAt(6);
        $max  = null;
        foreach ([$first, 21, 18, 15, 12, 9, 6] as $t) {
            $v = $ratioAt($t);
            if ($v !== null && ($max === null || $v > $max)) $max = $v;
        }

        $trend = '不明';
        if ($now !== null && $max !== null && $max > 0) {
            $d = ($max - $now) / $max * 100;
            if ($d >= 10.0)      $trend = '縮小';
            elseif ($base !== null && $base > 0 && ($now - $base) / $base * 100 >= 10.0) $trend = '拡大';
            else                 $trend = '横ばい';
        }

        // 断層の下側先頭 … 直上が断層（2.00以上）なら、この馬が断層の下側の先頭
        $isGap = ($now !== null && $now >= 2.00);

        return ['base' => ($base === null) ? '' : $base,
                'max'  => ($max  === null) ? '' : $max,
                'now'  => ($now  === null) ? '' : $now,
                'isGap' => $isGap ? 'はい' : 'いいえ',
                'trend' => $trend,
                'isGapHead' => $isGap ? 'はい' : 'いいえ'];
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261014-PLAN2  案2の比較（--plan2-compare）
     * @ANCHOR-20261015-PLAN2B  複勝流入ランクを加えた案2bの比較
     * ═════════════════════════════════════════════════════════════════════════
     * 10月14日（7回目）02.txt ③と、10月15日（1回目）01.txt【2】【4】への対応です。
     *   「波乱度3以上など、混戦・波乱レースに限定して全頭採点から候補を起こす
     *     案2を比較検証してください。本番適用は保留とします」
     *   「【2】複勝流入ランクを加えた比較Aを選択します。
     *     全頭採点の点数順だけでなく、複勝流入ランクを組み合わせた場合の
     *     捕捉率を比較してください。可能なら、波乱度2・3・4別に
     *     ① 7番人気以下の3着内捕捉数 ② 追加候補数と的中率
     *     ③ 追加候補の複勝回収率 ④ 現行方式からの改善幅
     *     ⑤ 複勝流入ランクと全頭採点の組み合わせによる順位変化 を確認してください」
     *   「【4】案2で9頭追加して3着内0頭だった原因を分析してください。
     *     波乱度4は、単純な候補補充ではなく、穴馬の選定基準自体に
     *     改善が必要な可能性があります」
     *
     * 第42章 42-8 の案2の原文（そのまま引用）：
     *   「波乱度が高いレースに限って（波乱度3以上、または厳選穴レース該当）、
     *     資格未達でも全頭採点の穴側上位から枠を埋める。選ぶ順は全頭採点の点数と
     *     独立根拠で決め、頭数合わせはしない」
     *
     * ここで行うのは【計算による比較だけ】です。
     *   ・.env も本番のスイッチも触りません
     *   ・AIへ送る文も作りません（AI呼び出し0回・費用0円）
     *   ・DBへは1件も書きません
     *   ・保存済みの統合結果・全頭採点・着順・払戻・6分前オッズを読んで並べ替えるだけです
     *
     * ■ 対象レースと不足数（どちらの方式も同じ）
     *   1. 対象レース … 波乱度3以上、または厳選穴レース該当
     *      対象外のレースは現行（A方式）のまま。候補は1頭も変わりません。
     *   2. 不足数 … 目標数C − 現在の公開頭数（0以下なら何もしない）
     *   3. 候補の母集団 … 1st AIの全頭採点に居る7番人気以下で、
     *      まだ公開候補に入っておらず、総合点が読める馬
     *   4. 上から不足数ぶんだけ採る。母集団が足りなければその頭数で止めます
     *      （頭数合わせはしない＝採れるだけ）
     *   5. ここで足した馬は【購入推奨に入れません】
     *
     * ─────────────────────────────────────────────────────────────────────────
     * @ANCHOR-20261015-SLOT  案4（穴側の枠を確保する）を足しました
     * ─────────────────────────────────────────────────────────────────────────
     * 10月15日（2回目）01.txt【1】【3】⑤への対応です。
     *   「穴側の枠を確保する方式は有力ですが、人気側の候補が1頭減ることによる
     *     的中率・回収率への影響も比較してください」
     *   「⑤ 波乱度別の人気薄捕捉率と、人気側候補を減らした影響」
     *
     * 第45章 45-5で分かったことへの打ち手です。
     * 波乱度4では、好走した人気薄が母集団で平均3.2位に付いているのに、
     * 採る枠が1〜2頭しかなくて届いていませんでした。
     * 不足枠を埋めるだけの案2・案2b・案2cでは、ここは届きにくいままです。
     *
     * 案4の計算手順（この比較で使う定義）
     *   1. 対象レース … 案2と同じ（波乱度3以上、または厳選穴レース該当）
     *   2. 公開頭数は変えません（目標数Cのまま）
     *   3. 公開候補のうち【1〜6番人気でいちばん統合点が低い馬】を1頭外し、
     *      その枠を穴側へ回します
     *   4. 回した枠に、全頭採点の点数の降順（案2と同じ並べ方）で穴馬を入れます
     *   5. 外した人気側の馬は公開候補から消えるので、
     *      【その馬が購入推奨だった場合は購入推奨も1頭減ります】
     *   6. 入れた穴馬は購入推奨に入れません
     *
     * つまり案4は、ほかの案と違って【購入推奨の成績が動きます】。
     * そこが効果と副作用の両方なので、購入推奨の頭数・3着内・購入額・払戻・
     * 複勝回収率を案別に出して並べます。
     *
     * ■ 並べる順だけが違います（3通り）＋枠を動かす案4
     *   案2  … 全頭採点の総合点の降順
     *           （同点は独立根拠のある馬を前へ、それも同じなら馬番の昇順）
     *   案2b … まず【複勝流入ランクが人気帯内で1位か2位】の馬を前に置き、
     *           そのそれぞれの中で案2と同じ順に並べます（強い形）
     *   案2c … 同じですが、【全頭採点が下位半分の馬は前に出しません】（弱い形）
     *
     *   44-4で好走馬と凡走馬をもっともよく分けていたのが
     *   「複勝流入ランクが人気帯内で1位か2位」でした（40.4% 対 24.2%）。
     *   ただし案2bの強い形では、採点48点で流入1位の馬が
     *   採点70点で流入9位の馬より前に出ます。採点を無視しすぎる恐れがあるので、
     *   採点が下位半分の馬は前に出さない案2cも並べて測ります。
     *   どちらが良いかは決め打ちせず、数字で比べていただくためです。
     *
     * ■ 波乱度4の原因分析（【4】）
     *   末尾に「人気薄で好走した馬が、母集団の中で何位だったか」を波乱度別に出します。
     *   母集団に居たのに順位が下だったのなら、並べる順（＝選定基準）の問題です。
     *   母集団にすら居なかったのなら、別の理由（すでに公開済み・総合点が無い）です。
     *   どちらなのかで打ち手が変わるので、分けて数えます。
     */
    private function runPlan2Compare(string $dateArg): void
    {
        $dates = array_values(array_filter(array_map('trim', explode(',', $dateArg))));
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 案2・案2bの比較（波乱度3以上のレースだけ全頭採点から起こす）');
        $this->info(' 対象日 ' . implode(' / ', $dates));
        $this->info('  案2   … 全頭採点の点数の降順');
        $this->info('  案2b  … 複勝流入ランク1位か2位を前に置く（強い形）');
        $this->info('  案2c  … 同じだが、全頭採点が下位半分の馬は前に出さない（弱い形）');
        $this->info('  案4   … 公開頭数は変えず、1〜6番人気の最下位1頭を外して穴側へ枠を回す');
        $this->info('         （この案だけ購入推奨の成績が動きます）');
        $this->info('  （計算による比較だけ・.envもスイッチも触らない）');
        $this->info('  （DBへの書き込みなし・AI呼び出し0回・費用0円）');
        $this->info('══════════════════════════════════════════════════════════');

        $MODES = ['案2', '案2b', '案2c', '案4'];
        $blank = static fn() => ['公開' => 0, '3着内' => 0, '5着内' => 0, '捕捉' => 0,
                                 '追加' => 0, '追加3着内' => 0, '追加5着内' => 0, '追加払戻' => 0,
                                 // @ANCHOR-20261015-SLOT  購入推奨の成績（案4だけ動く）
                                 '購入推奨' => 0, '購入推奨3着内' => 0,
                                 '購入額' => 0, '購入払戻' => 0,
                                 '外した人気側' => 0, '外した人気側3着内' => 0,
                                 '外した人気側が購入推奨だった' => 0];
        $sum = ['現行' => $blank()];   // 現行も購入推奨の成績を同じ式で出す
        foreach ($MODES as $m) $sum[$m] = $blank();

        $raceAll = 0; $raceTarget = 0; $lsTotal = 0;
        $buyChk  = ['一致' => 0, '不一致' => 0];
        $byWave  = [];
        $order   = [];   // 方式 => 並び順と選出がどれだけ変わったか
        $hitRank = [];   // 波乱度 => ['母集団にいた'=>[順位...], '母集団にいない'=>n, '公開済み'=>n]
        $out     = [];

        foreach ($dates as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->error("日付の書式が不正です: {$date}");
                continue;
            }
            $merges = DB::table('t_horse_odds_finder_ai_merge_result')
                ->where('date', $date)
                ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
                ->get();
            if ($merges->isEmpty()) { $this->warn("{$date} の統合結果がありません。"); continue; }

            $resMap = [];
            foreach (DB::table('t_horse_odds_finder_race_results')->where('date', $date)->get() as $r) {
                $resMap[$r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race][(int) $r->num]
                    = (int) $r->result;
            }
            $payMap = [];
            foreach (DB::table('t_horse_odds_finder_race_result_payout')->where('date', $date)->get() as $r) {
                $k = $r->kaisuu . '|' . $r->basho_code . '|' . $r->day . '|' . $r->race;
                foreach (explode('/', (string) ($r->fuku ?? '')) as $piece) {
                    $pair = explode('|', trim($piece));
                    if (count($pair) === 2 && ctype_digit(trim($pair[0]))) {
                        $payMap[$k][(int) trim($pair[0])] = (int) preg_replace('/\D/', '', $pair[1]);
                    }
                }
            }
            // 複勝流入ランクを出すために、オッズは全時点を読みます
            $timings = [\App\Constants\Constants::ODDS_DB_FIRST, 21, 18, 15, 12, 9, 6];
            $series = [];
            foreach (DB::table('t_horse_odds_finder_odds')
                        ->where('date', $date)
                        ->whereIn('minutes_before_start', $timings)
                        ->get(['kaisuu', 'basho', 'day', 'race', 'num',
                               'minutes_before_start', 'odds', 'fuku_min']) as $r) {
                $k = $r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race;
                $t = (int) $r->minutes_before_start; $n = (int) $r->num;
                if (is_numeric($r->odds)     && (float) $r->odds     > 0) $series[$k][$n]['tan'][$t]  = (float) $r->odds;
                if (is_numeric($r->fuku_min) && (float) $r->fuku_min > 0) $series[$k][$n]['fuku'][$t] = (float) $r->fuku_min;
            }
            if (empty($series)) {
                $this->warn("{$date} の6分前オッズがありません（洗い替え済みの可能性）。この日は飛ばします。");
                continue;
            }

            foreach ($merges as $mg) {
                $dec = json_decode((string) ($mg->merge_result ?? ''), true);
                if (!is_array($dec)) continue;
                if ((string) ($dec['status'] ?? '') === 'undetermined') continue;

                $label = "{$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R";
                $key   = $mg->kaisuu . '|' . $mg->basho_code . '|' . $mg->day . '|' . $mg->race;
                $results = $resMap[$key] ?? [];
                $pays    = $payMap[$key] ?? [];
                $sr      = $series[$key] ?? [];
                if (empty($results) || empty($sr)) continue;
                $info = $this->longshotRaceInfo($sr);
                $pops = $info['pop'] ?? [];
                if (empty($pops)) continue;
                $raceAll++;

                $audit  = is_array($dec['audit_merge'] ?? null) ? $dec['audit_merge'] : [];
                $auRows = is_array($audit['全頭'] ?? null) ? $audit['全頭'] : [];
                $wave   = ($mg->wave_level === null) ? 0 : (int) $mg->wave_level;
                $upset  = ($mg->upset_race !== null && (int) $mg->upset_race === 1);
                $targetC = (int) ($audit['目標数C'] ?? 0);

                $pubNums = []; $buyNums = [];
                foreach ((is_array($dec['merged_horses'] ?? null) ? $dec['merged_horses'] : []) as $h) {
                    if (!isset($h['num'])) continue;
                    $hn = (int) $h['num'];
                    $pubNums[] = $hn;
                    // @ANCHOR-20261015-RECO  10/14より前のレースも同じ式で数える
                    if ($this->isPurchaseRecommended($h)) $buyNums[] = $hn;
                }

                $isTarget = ($wave >= 3) || $upset;
                if ($isTarget) $raceTarget++;

                // ── 母集団を作る ──────────────────────────────────────
                $pool = [];
                if ($isTarget && $targetC > count($pubNums)) {
                    $txt1 = DB::table('t_horse_odds_finder_ai_analysis')
                        ->where('date', $date)->where('kaisuu', $mg->kaisuu)
                        ->where('basho_code', $mg->basho_code)->where('day', $mg->day)
                        ->where('race', $mg->race)->value('analysis_text');
                    $as1 = ($txt1 === null)
                        ? ['received' => false, 'nums' => [], 'count' => 0, 'scores' => [], 'ranks' => []]
                        : AiController::allScoreReport((string) $txt1);
                    if (!empty($as1['received'])) {
                        foreach ((array) $as1['nums'] as $hn) {
                            $hn = (int) $hn;
                            if (in_array($hn, $pubNums, true)) continue;
                            $pp = $pops[$hn] ?? null;
                            if ($pp === null || $pp < 7) continue;
                            $sc = ($as1['scores'] ?? [])[$hn] ?? null;
                            if ($sc === null) continue;
                            $auRow = $auRows[(string) $hn] ?? ($auRows[$hn] ?? []);
                            $ev = is_array($auRow['独立根拠'] ?? null) ? $auRow['独立根拠'] : null;
                            $fr = $info['fukuInflowRank'][$hn] ?? null;
                            $rk0 = ($as1['ranks'] ?? [])[$hn] ?? null;
                            $tt0 = (int) $as1['count'];
                            $pool[] = ['num' => $hn, 'score' => (float) $sc, 'pop' => $pp,
                                       'rank' => $rk0,
                                       'total' => $tt0,
                                       'ind' => ($ev === null) ? null : (int) ($ev['独立群数'] ?? 0),
                                       'fuku_rank' => $fr,
                                       'fuku_top2' => in_array($fr, [1, 2], true),
                                       // 全頭採点が下位半分かどうか（案2cで使う）
                                       'low_half' => ($rk0 !== null && $tt0 > 0)
                                           ? ($rk0 > (int) ceil($tt0 / 2)) : true];
                        }
                    }
                }
                $need = max(0, $targetC - count($pubNums));

                // ── 2つの並べ方 ───────────────────────────────────────
                $byScore = static function ($a, $b) {
                    if ($a['score'] !== $b['score']) return ($b['score'] < $a['score']) ? -1 : 1;
                    $ai = $a['ind'] ?? -1; $bi = $b['ind'] ?? -1;
                    if ($ai !== $bi) return $bi <=> $ai;
                    return $a['num'] <=> $b['num'];
                };
                $sorted = [];
                $p2 = $pool; usort($p2, $byScore);                       // 案2
                $sorted['案2'] = $p2;
                $p2b = $pool;                                            // 案2b（強い形）
                usort($p2b, static function ($a, $b) use ($byScore) {
                    if ($a['fuku_top2'] !== $b['fuku_top2']) return $a['fuku_top2'] ? -1 : 1;
                    return $byScore($a, $b);
                });
                $sorted['案2b'] = $p2b;

                // 案2c（弱い形）… 流入1位か2位でも、全頭採点が下位半分なら前に出さない
                $p2c = $pool;
                usort($p2c, static function ($a, $b) use ($byScore) {
                    $ja = $a['fuku_top2'] && !$a['low_half'];
                    $jb = $b['fuku_top2'] && !$b['low_half'];
                    if ($ja !== $jb) return $ja ? -1 : 1;
                    return $byScore($a, $b);
                });
                $sorted['案2c'] = $p2c;

                $add = []; $nums = []; $dropped = [];
                foreach (['案2', '案2b', '案2c'] as $m) {
                    $add[$m]  = array_slice($sorted[$m], 0, $need);
                    $nums[$m] = array_values(array_unique(array_merge(
                        $pubNums, array_map(static fn($x) => $x['num'], $add[$m]))));
                    $dropped[$m] = null;
                }

                // ══════════════════════════════════════════════════════════
                // @ANCHOR-20261015-SLOT  案4 … 人気側の最下位1頭を外して枠を回す
                // ══════════════════════════════════════════════════════════
                // 公開頭数は変えません。1〜6番人気でいちばん統合点が低い馬を外し、
                // その枠に穴馬を1頭入れます（並べ方は案2と同じ）。
                // 外す馬が購入推奨だった場合は、購入推奨も1頭減ります。
                $add['案4'] = []; $nums['案4'] = $pubNums; $dropped['案4'] = null;
                if ($isTarget) {
                    // 外す候補 … 公開候補のうち1〜6番人気で、統合点がいちばん低い馬
                    $favs = [];
                    foreach ($pubNums as $pn) {
                        $pp2 = $pops[$pn] ?? null;
                        if ($pp2 === null || $pp2 > 6) continue;
                        $r2  = $auRows[(string) $pn] ?? ($auRows[$pn] ?? []);
                        $sc2 = isset($r2['統合点']) && is_numeric($r2['統合点'])
                                 ? (float) $r2['統合点'] : null;
                        $favs[] = ['num' => $pn, 'score' => $sc2, 'pop' => $pp2];
                    }
                    // 統合点の昇順（点が無い馬はいちばん後ろ＝外さない）→ 人気の遅い順
                    usort($favs, static function ($a, $b) {
                        if ($a['score'] === null && $b['score'] === null) return $b['pop'] <=> $a['pop'];
                        if ($a['score'] === null) return 1;
                        if ($b['score'] === null) return -1;
                        if ($a['score'] !== $b['score']) return ($a['score'] < $b['score']) ? -1 : 1;
                        return $b['pop'] <=> $a['pop'];
                    });
                    // 入れる候補 … 案2と同じ並べ方の先頭。すでに案2で足した馬は重ねない
                    $slotNeed = $need + 1;   // 不足枠＋譲った1枠
                    $take = array_slice($sorted['案2'], 0, $slotNeed);
                    if (!empty($favs) && count($take) > $need) {
                        $dropped['案4'] = $favs[0];
                        $add['案4'] = $take;
                        $nums['案4'] = array_values(array_unique(array_merge(
                            array_values(array_diff($pubNums, [$favs[0]['num']])),
                            array_map(static fn($x) => $x['num'], $take))));
                    } else {
                        // 外せる人気側がいない、または入れる穴馬が足りない場合は案2と同じ
                        $add['案4']  = array_slice($sorted['案2'], 0, $need);
                        $nums['案4'] = array_values(array_unique(array_merge(
                            $pubNums, array_map(static fn($x) => $x['num'], $add['案4']))));
                    }
                }

                // ⑤ 順位変化（案2を基準に、案2b・案2cがどれだけ変わるか）
                if (!empty($pool)) {
                    $o1 = array_map(static fn($x) => $x['num'], $sorted['案2']);
                    $s1 = array_map(static fn($x) => $x['num'], $add['案2']);
                    sort($s1);
                    foreach (['案2b', '案2c'] as $m2) {
                        if (!isset($order[$m2])) {
                            $order[$m2] = ['母集団あり' => 0, '並び順が同じ' => 0,
                                           '並び順が変わった' => 0, '選出が変わった' => 0];
                        }
                        $order[$m2]['母集団あり']++;
                        $o2 = array_map(static fn($x) => $x['num'], $sorted[$m2]);
                        if ($o1 === $o2) $order[$m2]['並び順が同じ']++;
                        else             $order[$m2]['並び順が変わった']++;
                        $s2 = array_map(static fn($x) => $x['num'], $add[$m2]);
                        sort($s2);
                        if ($s1 !== $s2) $order[$m2]['選出が変わった']++;
                    }
                }

                // ── 集計 ──────────────────────────────────────────────
                $cnt = static function (array $ns, array $res, int $max) {
                    $c = 0;
                    foreach ($ns as $n) {
                        $p = $res[$n] ?? null;
                        if ($p !== null && $p >= 1 && $p <= $max) $c++;
                    }
                    return $c;
                };
                $lsHit = 0; $lsNow = 0;
                $lsBy = [];
                foreach ($MODES as $m) $lsBy[$m] = 0;
                foreach ($results as $hn0 => $pos0) {
                    if ($pos0 < 1 || $pos0 > 3) continue;
                    $pp0 = $pops[$hn0] ?? null;
                    if ($pp0 === null || $pp0 < 7) continue;
                    $lsHit++;
                    if (in_array((int) $hn0, $pubNums, true)) $lsNow++;
                    foreach ($MODES as $m) {
                        if (in_array((int) $hn0, $nums[$m], true)) $lsBy[$m]++;
                    }
                }
                $lsTotal += $lsHit;

                $sum['現行']['公開']  += count($pubNums);
                $sum['現行']['3着内'] += $cnt($pubNums, $results, 3);
                $sum['現行']['5着内'] += $cnt($pubNums, $results, 5);
                $sum['現行']['捕捉']  += $lsNow;
                // @ANCHOR-20261015-SLOT  購入推奨の成績（現行）
                $sum['現行']['購入推奨']      += count($buyNums);
                $sum['現行']['購入推奨3着内'] += $cnt($buyNums, $results, 3);
                $sum['現行']['購入額']        += count($buyNums) * 100;
                foreach ($buyNums as $bn2) {
                    $p2 = $results[$bn2] ?? null;
                    if ($p2 !== null && $p2 <= 3) $sum['現行']['購入払戻'] += (int) ($pays[$bn2] ?? 0);
                }
                foreach ($MODES as $m) {
                    $sum[$m]['公開']  += count($nums[$m]);
                    $sum[$m]['3着内'] += $cnt($nums[$m], $results, 3);
                    $sum[$m]['5着内'] += $cnt($nums[$m], $results, 5);
                    $sum[$m]['捕捉']  += $lsBy[$m];
                    $sum[$m]['追加']  += count($add[$m]);
                    foreach ($add[$m] as $a) {
                        $p = $results[$a['num']] ?? null;
                        if ($p !== null && $p <= 3) {
                            $sum[$m]['追加3着内']++;
                            $sum[$m]['追加払戻'] += (int) ($pays[$a['num']] ?? 0);
                        }
                        if ($p !== null && $p <= 5) $sum[$m]['追加5着内']++;
                    }
                    // @ANCHOR-20261015-SLOT  購入推奨の成績
                    //   足した穴馬は購入推奨に入れません。
                    //   案4で外した人気側の馬は、購入推奨だったなら購入推奨も減ります。
                    $buyM = [];
                    foreach ($buyNums as $bn3) {
                        if (in_array($bn3, $nums[$m], true)) $buyM[] = $bn3;
                    }
                    $sum[$m]['購入推奨']      += count($buyM);
                    $sum[$m]['購入推奨3着内'] += $cnt($buyM, $results, 3);
                    $sum[$m]['購入額']        += count($buyM) * 100;
                    foreach ($buyM as $bn4) {
                        $p3 = $results[$bn4] ?? null;
                        if ($p3 !== null && $p3 <= 3) $sum[$m]['購入払戻'] += (int) ($pays[$bn4] ?? 0);
                    }
                    if ($dropped[$m] !== null) {
                        $dn = $dropped[$m]['num'];
                        $sum[$m]['外した人気側']++;
                        $pd = $results[$dn] ?? null;
                        if ($pd !== null && $pd <= 3) $sum[$m]['外した人気側3着内']++;
                        if (in_array($dn, $buyNums, true)) $sum[$m]['外した人気側が購入推奨だった']++;
                    }
                }

                if (!isset($byWave[$wave])) {
                    $byWave[$wave] = ['レース' => 0, '対象' => 0, '人気薄好走' => 0, '現行捕捉' => 0,
                                      '現行購入推奨' => 0, '現行購入3着内' => 0,
                                      '現行購入額' => 0, '現行購入払戻' => 0];
                    foreach ($MODES as $m) {
                        $byWave[$wave][$m] = ['捕捉' => 0, '追加' => 0, '追加3着内' => 0,
                                              '追加払戻' => 0, '購入推奨' => 0, '購入3着内' => 0,
                                              '購入額' => 0, '購入払戻' => 0, '外した人気側' => 0];
                    }
                }
                $byWave[$wave]['レース']++;
                if ($isTarget) $byWave[$wave]['対象']++;
                $byWave[$wave]['人気薄好走'] += $lsHit;
                $byWave[$wave]['現行捕捉']   += $lsNow;
                $byWave[$wave]['現行購入推奨']  += count($buyNums);
                $byWave[$wave]['現行購入3着内'] += $cnt($buyNums, $results, 3);
                $byWave[$wave]['現行購入額']    += count($buyNums) * 100;
                foreach ($buyNums as $bw) {
                    $pw = $results[$bw] ?? null;
                    if ($pw !== null && $pw <= 3) $byWave[$wave]['現行購入払戻'] += (int) ($pays[$bw] ?? 0);
                }
                foreach ($MODES as $m) {
                    $byWave[$wave][$m]['捕捉'] += $lsBy[$m];
                    $byWave[$wave][$m]['追加'] += count($add[$m]);
                    foreach ($add[$m] as $a) {
                        $p = $results[$a['num']] ?? null;
                        if ($p !== null && $p <= 3) {
                            $byWave[$wave][$m]['追加3着内']++;
                            $byWave[$wave][$m]['追加払戻'] += (int) ($pays[$a['num']] ?? 0);
                        }
                    }
                    // @ANCHOR-20261015-SLOT  波乱度別の購入推奨の成績
                    $bwM = [];
                    foreach ($buyNums as $bw2) {
                        if (in_array($bw2, $nums[$m], true)) $bwM[] = $bw2;
                    }
                    $byWave[$wave][$m]['購入推奨']  += count($bwM);
                    $byWave[$wave][$m]['購入3着内'] += $cnt($bwM, $results, 3);
                    $byWave[$wave][$m]['購入額']    += count($bwM) * 100;
                    foreach ($bwM as $bw3) {
                        $pw3 = $results[$bw3] ?? null;
                        if ($pw3 !== null && $pw3 <= 3) {
                            $byWave[$wave][$m]['購入払戻'] += (int) ($pays[$bw3] ?? 0);
                        }
                    }
                    if ($dropped[$m] !== null) $byWave[$wave][$m]['外した人気側']++;
                }

                // ── 購入推奨が増えていないことの確認 ─────────────────────
                // @ANCHOR-20261015-SLOT  案4は購入推奨が減ることがあるので別に数える
                $okBuy = true;
                foreach (['案2', '案2b', '案2c'] as $m) {
                    $bp = [];
                    foreach ($nums[$m] as $pn) {
                        if (in_array($pn, $buyNums, true)) $bp[] = $pn;
                    }
                    sort($bp);
                    $bs = $buyNums; sort($bs);
                    if ($bp !== $bs) $okBuy = false;
                }
                if ($okBuy) $buyChk['一致']++; else $buyChk['不一致']++;

                // ── 【4】好走した人気薄が母集団で何位だったか ────────────
                if (!isset($hitRank[$wave])) {
                    $hitRank[$wave] = ['順位' => [], '母集団にいない' => 0, '公開済み' => 0,
                                       '対象外のレース' => 0, '不足がなかった' => 0];
                }
                foreach ($results as $hn0 => $pos0) {
                    if ($pos0 < 1 || $pos0 > 3) continue;
                    $pp0 = $pops[$hn0] ?? null;
                    if ($pp0 === null || $pp0 < 7) continue;
                    $hn0 = (int) $hn0;
                    if (in_array($hn0, $pubNums, true)) { $hitRank[$wave]['公開済み']++; continue; }
                    if (!$isTarget) { $hitRank[$wave]['対象外のレース']++; continue; }
                    if ($need === 0) { $hitRank[$wave]['不足がなかった']++; continue; }
                    $pos = null;
                    foreach ($sorted['案2'] as $i => $x) {
                        if ($x['num'] === $hn0) { $pos = $i + 1; break; }
                    }
                    if ($pos === null) { $hitRank[$wave]['母集団にいない']++; continue; }
                    $hitRank[$wave]['順位'][] = ['レース' => $label, '馬番' => $hn0,
                        '人気' => $pp0, '着順' => $pos0,
                        '母集団での順位' => $pos, '母集団の頭数' => count($pool),
                        '不足数（採る頭数）' => $need,
                        '採られたか' => ($pos <= $need),
                        '複勝流入ランク' => $info['fukuInflowRank'][$hn0] ?? null,
                        '複勝払戻' => (int) ($pays[$hn0] ?? 0)];
                }

                // ── レースごとの表示（どちらかで追加があったレースだけ）──
                if (!empty($add['案2']) || !empty($add['案2b'])) {
                    $this->line('');
                    $this->info("── {$label} ──────────────────────────────");
                    $this->line('  波乱度 ' . ($wave > 0 ? $wave : '－')
                        . '／厳選穴レース ' . ($upset ? '該当' : '非該当')
                        . '／断層タイプ ' . (string) ($mg->gap_type ?? '－')
                        . '／目標数C ' . $targetC . '／現行の公開 ' . count($pubNums) . '頭'
                        . '／母集団 ' . count($pool) . '頭／採る頭数 ' . $need);
                    foreach ($MODES as $m) {
                        $this->line('  ' . $m . ':');
                        if ($dropped[$m] !== null) {
                            $dn2 = $dropped[$m]['num'];
                            $pd2 = $results[$dn2] ?? null;
                            $this->line(sprintf(
                                '    -%2d番　%s番人気（人気側の最下位）統合点%s → %s'
                                . '　購入推奨%s　複勝払戻%d円',
                                $dn2, (string) $dropped[$m]['pop'],
                                ($dropped[$m]['score'] === null) ? '－' : (string) $dropped[$m]['score'],
                                ($pd2 === null) ? '着順なし' : ($pd2 . '着'),
                                in_array($dn2, $buyNums, true) ? 'だった（1頭減ります）' : 'ではない',
                                (int) ($pays[$dn2] ?? 0)));
                        }
                        foreach ($add[$m] as $a) {
                            $p = $results[$a['num']] ?? null;
                            $this->warn(sprintf(
                                '    +%2d番　全体%d番人気　全頭採点 %d頭中%s位・%s点'
                                . '　複勝流入%s　独立根拠%s → %s・複勝払戻%d円',
                                $a['num'], $a['pop'], $a['total'],
                                ($a['rank'] === null) ? '－' : (string) $a['rank'],
                                (string) round($a['score'], 1),
                                ($a['fuku_rank'] === null) ? 'データなし'
                                    : ($a['fuku_rank'] . '位' . ($a['fuku_top2'] ? '★' : '')),
                                ($a['ind'] === null) ? '記録なし' : ($a['ind'] . '系統'),
                                ($p === null) ? '着順なし' : ($p . '着'),
                                (int) ($pays[$a['num']] ?? 0)));
                        }
                        $this->line(sprintf('    → 公開 %d頭　人気薄好走 %d頭中 %d頭を捕捉（現行 %d頭）',
                            count($nums[$m]), $lsHit, $lsBy[$m], $lsNow));
                    }
                    $this->line('  → 購入推奨は ' . count($buyNums) . '頭で変わりません'
                        . '（追加馬は購入推奨に入れない）');
                }

                $out[] = ['日付' => $date, 'レース' => $label, '波乱度' => $wave,
                          '厳選穴レース' => $upset, '対象' => $isTarget,
                          '目標数C' => $targetC, '現行の公開' => $pubNums,
                          '母集団の頭数' => count($pool), '採る頭数' => $need,
                          '人気薄好走' => $lsHit, '現行捕捉' => $lsNow,
                          '方式別' => array_map(static fn($m) => [
                              '公開' => $nums[$m], '捕捉' => $lsBy[$m],
                              '外した人気側' => $dropped[$m],
                              '追加' => array_map(static fn($x) => [
                                  '馬番' => $x['num'], '人気' => $x['pop'],
                                  '採点順位' => $x['rank'], '採点' => $x['score'],
                                  '複勝流入ランク' => $x['fuku_rank'],
                                  '着順' => $results[$x['num']] ?? null,
                                  '複勝払戻' => (int) ($pays[$x['num']] ?? 0)], $add[$m])],
                              array_combine($MODES, $MODES)),
                          '購入推奨' => $buyNums];
            }
        }

        // ══════════════════════════════════════════════════════════════
        // まとめ
        // ══════════════════════════════════════════════════════════════
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' まとめ');
        $this->info('══════════════════════════════════════════════════════════');
        $this->line('  レース数: ' . $raceAll
            . '　うち対象（波乱度3以上または厳選穴レース該当）: ' . $raceTarget
            . '　人気薄の好走: ' . $lsTotal . '頭');
        $this->line('');
        $this->line('  【公開候補の成績】');
        $this->line('  方式    公開  3着内  5着内  人気薄捕捉  捕捉率  改善幅  追加  追加3着内  追加の的中率  追加の複勝回収率');
        foreach (array_merge(['現行'], $MODES) as $m) {
            $v = $sum[$m];
            $this->line(sprintf('  %-6s %5d %6d %6d %11d %7s %7s %5d %10d %13s %17s', $m,
                $v['公開'], $v['3着内'], $v['5着内'], $v['捕捉'],
                $lsTotal > 0 ? sprintf('%.1f%%', $v['捕捉'] / $lsTotal * 100) : '－',
                ($m === '現行') ? '－' : sprintf('%+d頭', $v['捕捉'] - $sum['現行']['捕捉']),
                $v['追加'], $v['追加3着内'],
                $v['追加'] > 0 ? sprintf('%.1f%%', $v['追加3着内'] / $v['追加'] * 100) : '－',
                $v['追加'] > 0 ? sprintf('%.1f%%', $v['追加払戻'] / ($v['追加'] * 100) * 100) : '－'));
        }
        $this->line('');
        $this->warn('  ※追加馬は購入推奨に入れないので、実際には買いません。');
        $this->warn('    「追加の複勝回収率」は、仮に買ったらどうだったかの参考値です。');

        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261015-SLOT  購入推奨の成績（01.txt【3】⑥と⑤の後半）
        // ══════════════════════════════════════════════════════════════
        $this->line('');
        $this->line('  【購入推奨の成績】公開候補の成績とは分けて出します');
        $this->line('  方式    購入推奨  3着内  的中率  購入額(円)  払戻(円)  複勝回収率  '
            . '外した人気側  うち3着内  うち購入推奨だった');
        foreach (array_merge(['現行'], $MODES) as $m) {
            $v = $sum[$m];
            $this->line(sprintf('  %-6s %8d %6d %7s %11d %9d %11s %13d %10d %18d', $m,
                $v['購入推奨'], $v['購入推奨3着内'],
                $v['購入推奨'] > 0
                    ? sprintf('%.1f%%', $v['購入推奨3着内'] / $v['購入推奨'] * 100) : '－',
                $v['購入額'], $v['購入払戻'],
                $v['購入額'] > 0 ? sprintf('%.1f%%', $v['購入払戻'] / $v['購入額'] * 100) : '－',
                $v['外した人気側'], $v['外した人気側3着内'],
                $v['外した人気側が購入推奨だった']));
        }
        $this->line('');
        $this->warn('  ※案2・案2b・案2cは購入推奨に手を付けないので、現行と同じ数字になります。');
        $this->warn('  ※案4だけ人気側を1頭外すので、購入推奨が減ることがあります。');
        $this->warn('    「外した人気側 うち3着内」が、その副作用の実額です。');

        // ── ⑤ 順位変化 ────────────────────────────────────────────────
        $this->line('');
        $this->info('── ⑤ 複勝流入ランクを入れたことによる順位変化 ─────');
        $this->line('  基準は案2（全頭採点の点数の降順）です。');
        foreach ($order as $m2 => $v) {
            $this->line('');
            $this->line('  ' . $m2 . ':');
            $this->line('    母集団が1頭以上あったレース     : ' . $v['母集団あり']);
            $this->line('    並び順が案2と同じだったレース   : ' . $v['並び順が同じ']);
            $this->line('    並び順が変わったレース           : ' . $v['並び順が変わった']);
            $this->line('    実際に選ばれる馬が変わったレース : ' . $v['選出が変わった']);
            if ($v['母集団あり'] > 0 && $v['選出が変わった'] === 0) {
                $this->warn('    → 並び順は変わっても、採る頭数ぶんの顔ぶれは同じでした。');
                $this->warn('      この母数では、入れても結果が変わりません。');
            }
        }
        if (empty($order)) {
            $this->line('  母集団が1頭以上あったレースがありません。');
        }

        // ── 波乱度別 ──────────────────────────────────────────────────
        $this->line('');
        $this->info('── 波乱度別（①②③④）────────────────────────');
        $this->line('  ① 7番人気以下の3着内捕捉数　② 追加候補数と的中率');
        $this->line('  ③ 追加候補の複勝回収率　　　④ 現行方式からの改善幅');
        ksort($byWave);
        foreach ($byWave as $w => $v) {
            $this->line('');
            $this->info('  ── 波乱度 ' . ($w > 0 ? $w : '－') . ' ──  '
                . $v['レース'] . 'レース（うち案2の対象 ' . $v['対象'] . '）'
                . '　人気薄の好走 ' . $v['人気薄好走'] . '頭');
            $this->line('    方式   ①捕捉  ④改善幅  ②追加  ②的中  ②的中率  ③複勝回収率'
                . '  ⑥購入推奨  ⑥3着内  ⑥的中率  ⑥複勝回収率  外した人気側');
            $this->line(sprintf('    %-5s %6d %8s %6s %6s %8s %12s %10d %8d %9s %13s %13s', '現行',
                $v['現行捕捉'], '－', '－', '－', '－', '－',
                $v['現行購入推奨'], $v['現行購入3着内'],
                $v['現行購入推奨'] > 0
                    ? sprintf('%.1f%%', $v['現行購入3着内'] / $v['現行購入推奨'] * 100) : '－',
                $v['現行購入額'] > 0
                    ? sprintf('%.1f%%', $v['現行購入払戻'] / $v['現行購入額'] * 100) : '－', '－'));
            foreach ($MODES as $m) {
                $x = $v[$m];
                $this->line(sprintf('    %-5s %6d %+8d %6d %6d %8s %12s %10d %8d %9s %13s %13d', $m,
                    $x['捕捉'], $x['捕捉'] - $v['現行捕捉'], $x['追加'], $x['追加3着内'],
                    $x['追加'] > 0 ? sprintf('%.1f%%', $x['追加3着内'] / $x['追加'] * 100) : '－',
                    $x['追加'] > 0
                        ? sprintf('%.1f%%', $x['追加払戻'] / ($x['追加'] * 100) * 100) : '－',
                    $x['購入推奨'], $x['購入3着内'],
                    $x['購入推奨'] > 0
                        ? sprintf('%.1f%%', $x['購入3着内'] / $x['購入推奨'] * 100) : '－',
                    $x['購入額'] > 0
                        ? sprintf('%.1f%%', $x['購入払戻'] / $x['購入額'] * 100) : '－',
                    $x['外した人気側']));
            }
        }

        // ── 【4】好走した人気薄が母集団で何位だったか ──────────────────
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 【01.txt④】好走した人気薄は、母集団の中で何位だったか');
        $this->info('══════════════════════════════════════════════════════════');
        $this->line('  「母集団にいたのに順位が下だった」なら、並べる順（＝選定基準）の問題です。');
        $this->line('  「母集団にいなかった」なら別の理由です（すでに公開済み／総合点が無い）。');
        ksort($hitRank);
        foreach ($hitRank as $w => $v) {
            $this->line('');
            $this->info('  ── 波乱度 ' . ($w > 0 ? $w : '－') . ' ──');
            $this->line('    すでに公開候補に入っていた  : ' . $v['公開済み'] . '頭');
            $this->line('    案2の対象外のレースだった    : ' . $v['対象外のレース'] . '頭');
            $this->line('    すでに目標数Cに達していた    : ' . $v['不足がなかった'] . '頭'
                . '（不足がないので案2でも足しません）');
            $this->line('    母集団にいなかった           : ' . $v['母集団にいない'] . '頭'
                . '（全頭採点が未受領、または総合点が読めない）');
            $this->line('    母集団にいた                 : ' . count($v['順位']) . '頭');
            if (empty($v['順位'])) continue;
            $took = 0; $sumRank = 0;
            foreach ($v['順位'] as $r) {
                if ($r['採られたか']) $took++;
                $sumRank += $r['母集団での順位'];
                $mark = $r['採られたか'] ? '採られた' : '採られなかった';
                $this->line(sprintf(
                    '      %-22s %2d番　%d番人気・%d着　母集団%d頭中%d位（採る%d頭）'
                    . '　複勝流入%s → %s　複勝払戻%d円',
                    $r['レース'], $r['馬番'], $r['人気'], $r['着順'],
                    $r['母集団の頭数'], $r['母集団での順位'], $r['不足数（採る頭数）'],
                    ($r['複勝流入ランク'] === null) ? 'データなし' : ($r['複勝流入ランク'] . '位'),
                    $mark, $r['複勝払戻']));
            }
            $this->line(sprintf('    → 母集団にいた%d頭のうち %d頭が採られました。'
                . '母集団での平均順位は %.1f位です',
                count($v['順位']), $took, $sumRank / count($v['順位'])));
        }

        // ── 購入推奨 ──────────────────────────────────────────────────
        $this->line('');
        $this->info('── 購入推奨 ──────────────────────────────');
        $this->line('  現行と案2・案2b・案2cで購入推奨の馬番が一致したレース: ' . $buyChk['一致'] . '／'
            . ($buyChk['一致'] + $buyChk['不一致']) . '　不一致: ' . $buyChk['不一致']);
        $this->line('  この3案は足した馬を購入推奨に入れない定義なので、構造上かならず一致します。');
        $this->line('  購入額・払戻・複勝回収率も現行と同じです。');
        $this->line('');
        $this->warn('  案4は人気側を1頭外すので、購入推奨が減ることがあります。');
        $this->warn('  その影響は上の【購入推奨の成績】の表で確認してください。');

        $path = storage_path('logs/plan2_compare_' . str_replace(',', '_', $dateArg) . '.json');
        @file_put_contents($path, json_encode(
            ['対象日' => $dates, 'レース数' => $raceAll, '対象レース数' => $raceTarget,
             '人気薄の好走' => $lsTotal, '合計' => $sum, '波乱度別' => $byWave,
             '順位変化' => $order, '好走馬の母集団での順位' => $hitRank,
             '購入推奨の一致' => $buyChk, 'レース別' => $out],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @chmod($path, 0666);
        $this->line('');
        $this->info('  書き出しました: ' . $path);
        $this->line('');
        $this->warn('※これは計算による比較だけです。本番の動きは1つも変えていません。');
        $this->warn('※実装するかどうかは、ご判断をいただいてからにします。');
        $this->warn('※実装するときも、補充方式と同じようにスイッチ1つで切り替え・復帰できる形にし、');
        $this->warn('　既定はOFF（現行）にします。');
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261015-PROMPT-VERIFY  送信文の一致確認（--prompt-verify）
     * ═════════════════════════════════════════════════════════════════════════
     * 10月15日（1回目）02.txt ⑤への対応です。
     *   「比較CSVの1st・2nd送信文MD5が空欄です。
     *     取得状況と、実際に送信されたプロンプトの一致を確認してください」
     *
     * ■ 空欄だった理由
     *   比較CSVの「送信文md5」は、統合の監査ログ（merge_result の audit_merge）の
     *   「送信文ハッシュ」から読んでいます。この記録を足したのは【10月14日】です。
     *   10月10日〜12日のレースはそれより前に処理したので、記録が存在せず空欄になります。
     *   欄の作りの不具合ではありません。10月17日以降のレースからは値が入ります。
     *
     * ■ 最初に作った確認方法は成立しませんでした（そのまま書きます）
     *   はじめは「保存済みの6分前オッズからいまのコードでお願い文を組み直し、
     *   監査ログに残る実際の送信文のSHA-256と突き合わせる」つもりでした。
     *   実行したところ72レースすべて不一致で、文字数の差は0〜4文字でした。
     *   調べた結果、原因はコードの変更ではありませんでした。
     *
     *   お願い文には、次の【集計テーブル】の値を文字として埋め込んでいます。
     *     ・t_horse_odds_finder_odds_gap_recovery        乖離帯別の回収率
     *     ・t_horse_odds_finder_opi_recovery             OPI別の回収率
     *     ・t_horse_odds_finder_phase_pattern_recovery   局面パターン別の回収率
     *     ・t_horse_odds_finder_ai_recovery              AI予想の回収率
     *     ・t_horse_odds_finder_similar_race_stats       類似レース統計
     *     ・t_horse_odds_finder_popularity_rank_average  人気順位別の平均オッズ
     *     ・t_horse_odds_finder_fuku_popularity_rank_average  同（複勝）
     *     ・t_horse_odds_finder_compute_odds_correction  オッズ補正
     *     ・t_horse_odds_finder_forecast_from_last_race  前走からの予測
     *     ・t_horse_odds_finder_shutsuba_history         出走履歴
     *
     *   これらはレースが終わったあとの集計バッチで作り直されます。
     *   10月10日〜12日のレース結果も、そのあとの集計に入っています。
     *   つまり「45.2%」が「45.3%」になるような違いが生まれます。
     *   文字数の差が0〜4文字だったのは、まさにこの形の違いです。
     *
     *   したがって【過去のお願い文を1バイト単位で後から再現することはできません】。
     *   できないことをできると書くつもりはないので、そのまま申し上げます。
     *
     * ■ そのうえで何を確認できるか（このコマンドが出すもの）
     *   お願い文の中身を、再現できるものと再現できないものに分けました。
     *
     *   【再現できる・選出の判断に使う値】監査ログと突き合わせます
     *     ・入力SHA256 … オッズの時系列そのもののハッシュ。
     *                    一致すれば「予想に使ったオッズは1バイトも変わっていない」
     *     ・実出走頭数N
     *     ・目標数C
     *     ・断層タイプ（A〜E）
     *     ・snapshot_id（6分前スナップショットの識別子）
     *   この5つが一致すれば、候補の選び方に効く値は変わっていないことになります。
     *   変わったのは、お願い文に参考として埋め込んだ集計値の文字だけです。
     *
     *   【再現できない】
     *     ・お願い文の全文のSHA-256とMD5
     *   こちらは参考として、組み直した値と記録の値を並べて出します。
     *   文字数の差も出すので、違いの大きさが分かります。
     *
     * ■ 10月17日以降は組み直しが要りません
     *   10月14日からは、送信する直前にその本文そのもののMD5とバイト数を
     *   記録しています（@ANCHOR-20261014-EVIDENCE-AUDIT2）。
     *   実際に送った本文を送信時に測った値なので、組み直す必要がありません。
     *   これが送信文の一致を示す正しいやり方です。
     *
     * ■ 2nd AIについて
     *   2ndの送信文ハッシュも10月14日から記録しています。
     *   10月10日〜12日ぶんは記録がないので、突き合わせは【できません】。
     *   2ndへ送る本文は1stの本文から決まった箇所を外して作るだけなので、
     *   1stが同じであれば2ndの入力も一意に決まります。
     *   10月17日以降は記録と突き合わせられます。
     *
     * ■ 安全性
     *   AIへは1回も送りません（費用0円）。DBへは1件も書きません。
     *   $dryRunNoPersist = true で動かすので、監査ログにも新しい行を足しません
     *   （@ANCHOR-20261015-AUDIT-NODIRTY）。
     *
     * ⚠ 組み直しは6分前オッズを使うので、洗い替え（土曜5:50）より前に実行してください。
     */
    private function runPromptVerify(string $dateArg): void
    {
        $dates = array_values(array_filter(array_map('trim', explode(',', $dateArg))));
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 送信文の一致確認  対象日 ' . implode(' / ', $dates));
        $this->info('  （AI呼び出し0回・費用0円・DBへの書き込みなし）');
        $this->info('  （監査ログにも新しい行を足しません）');
        $this->info('══════════════════════════════════════════════════════════');
        $this->warn(' ※お願い文には集計テーブルの値を文字で埋め込んでいます。');
        $this->warn('   集計はレース後のバッチで作り直されるので、');
        $this->warn('   過去のお願い文を1バイト単位で後から再現することはできません。');
        $this->warn('   そこで「選出の判断に使う値」と「全文のハッシュ」を分けて出します。');
        $this->warn(' ※組み直しは6分前オッズを使います。洗い替え（土曜5:50）より前に実行してください。');

        $head = ['日付', 'race_id', 'レース',
                 '記録のsnapshot_id', '組み直しのsnapshot_id', 'snapshot_idの判定',
                 '記録の入力SHA256', '組み直しの入力SHA256', '入力SHA256の判定',
                 '記録のN', '組み直しのN', 'Nの判定',
                 '記録のC', '組み直しのC', 'Cの判定',
                 '記録の断層タイプ', '組み直しの断層タイプ', '断層タイプの判定',
                 '選出に使う値の判定',
                 '記録の全文SHA256', '組み直しの全文SHA256', '組み直しの全文MD5',
                 '記録の文字数', '組み直しの文字数', '文字数の差',
                 '記録のMD5(1st・10/14以降)', '記録のMD5(2nd・10/14以降)', '2ndの状態'];
        $rows = [];
        $tot  = ['選出に使う値が一致' => 0, '選出に使う値が不一致' => 0,
                 '記録なし' => 0, '組み直せない' => 0];
        $fld  = ['snapshot_id' => ['一致' => 0, '不一致' => 0, '判定不能' => 0],
                 '入力SHA256'  => ['一致' => 0, '不一致' => 0, '判定不能' => 0],
                 'N'           => ['一致' => 0, '不一致' => 0, '判定不能' => 0],
                 'C'           => ['一致' => 0, '不一致' => 0, '判定不能' => 0],
                 '断層タイプ'  => ['一致' => 0, '不一致' => 0, '判定不能' => 0]];
        $tot2 = ['記録あり' => 0, '記録なし' => 0];
        $lenDiff = [];

        $ctl = app(AiController::class);

        foreach ($dates as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->error("日付の書式が不正です: {$date}");
                continue;
            }
            $recorded = $this->auditRaceFacts($date);
            $this->line('');
            $this->info("── {$date} ──────────────────────────────");
            if (empty($recorded)) {
                $this->warn('  監査ログに AUDIT_RACE の記録がありません。');
                $this->warn('  探した場所: ' . storage_path('logs/audit/audit_' . $date . '.jsonl'));
            } else {
                $this->line('  監査ログから ' . count($recorded) . 'レースぶんの記録を読みました。');
            }

            $merges = DB::table('t_horse_odds_finder_ai_merge_result')
                ->where('date', $date)
                ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
                ->get();
            if ($merges->isEmpty()) {
                $this->warn("  {$date} の統合結果がありません。");
                continue;
            }

            foreach ($merges as $mg) {
                $label  = "{$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R";
                $raceId = "{$date}_{$mg->kaisuu}_{$mg->basho_code}_{$mg->day}_{$mg->race}";
                $dec    = json_decode((string) ($mg->merge_result ?? ''), true);
                $audit  = (is_array($dec) && is_array($dec['audit_merge'] ?? null))
                            ? $dec['audit_merge'] : [];
                $ph     = is_array($audit['送信文ハッシュ'] ?? null) ? $audit['送信文ハッシュ'] : [];
                $rec    = $recorded["{$date} {$label}"] ?? null;

                // ── いまのコードで組み直す ────────────────────────────
                $before = AiController::$dryRunNoPersist;
                AiController::$dryRunNoPersist = true;
                $txt = null; $err = '';
                try {
                    $txt = $ctl->v04BuildPromptForCheck(
                        $date, $mg->kaisuu, $mg->basho_code, $mg->day, $mg->race);
                } catch (\Throwable $e) {
                    $err = mb_substr($e->getMessage(), 0, 60);
                } finally {
                    AiController::$dryRunNoPersist = $before;
                }
                $facts = AiController::promptFacts();

                $rec2 = (string) ($ph['2nd']['md5'] ?? '');
                $st2  = ($rec2 === '') ? '記録なし（10/14から記録開始）' : '記録あり';
                if ($rec2 !== '') $tot2['記録あり']++; else $tot2['記録なし']++;

                if ($txt === null || $txt === '' || empty($facts)) {
                    $tot['組み直せない']++;
                    $this->error(sprintf('    %-26s 組み直せません %s', $label,
                        ($err !== '') ? ('（' . $err . '）') : '（6分前オッズが無い可能性）'));
                    $rows[] = [$date, $raceId, $label,
                               (string) ($rec['snapshot_id'] ?? ''), '', '組み直せない',
                               (string) ($rec['入力SHA256'] ?? ''), '', '組み直せない',
                               (string) ($rec['N'] ?? ''), '', '組み直せない',
                               (string) ($rec['C'] ?? ''), '', '組み直せない',
                               (string) ($rec['断層タイプ'] ?? ''), '', '組み直せない',
                               '組み直せない',
                               (string) ($rec['プロンプトSHA256'] ?? ''), '', '',
                               (string) ($rec['プロンプト文字数'] ?? ''), '', '',
                               (string) ($ph['1st']['md5'] ?? ''), $rec2, $st2];
                    continue;
                }

                // ── 選出の判断に使う値を突き合わせる ──────────────────
                $cmp = [];
                $allOk = true; $anyRec = false;
                foreach (['snapshot_id', '入力SHA256', 'N', 'C', '断層タイプ'] as $k) {
                    $rv = ($rec === null) ? null : ($rec[$k] ?? null);
                    $nv = $facts[$k] ?? null;
                    if ($rv === null || $rv === '' || $nv === null || $nv === '') {
                        $cmp[$k] = '判定不能';
                        $fld[$k]['判定不能']++;
                        continue;
                    }
                    $anyRec = true;
                    if ((string) $rv === (string) $nv) { $cmp[$k] = '一致'; $fld[$k]['一致']++; }
                    else { $cmp[$k] = '不一致'; $fld[$k]['不一致']++; $allOk = false; }
                }

                if ($rec === null) { $verdict = '記録なし'; $tot['記録なし']++; }
                elseif (!$anyRec)  { $verdict = '記録なし'; $tot['記録なし']++; }
                elseif ($allOk)    { $verdict = '一致';     $tot['選出に使う値が一致']++; }
                else               { $verdict = '不一致';   $tot['選出に使う値が不一致']++; }

                $recLen = (int) ($rec['プロンプト文字数'] ?? 0);
                $newLen = (int) ($facts['プロンプト文字数'] ?? 0);
                $diff   = ($recLen > 0 && $newLen > 0) ? ($newLen - $recLen) : null;
                if ($diff !== null) $lenDiff[] = abs($diff);

                $msg = sprintf('    %-26s 選出に使う値: %s'
                    . '（snapshot %s／入力 %s／N %s／C %s／断層 %s）',
                    $label, $verdict,
                    $cmp['snapshot_id'], $cmp['入力SHA256'], $cmp['N'], $cmp['C'], $cmp['断層タイプ']);
                if ($verdict === '一致')      $this->info($msg);
                elseif ($verdict === '記録なし') $this->line($msg);
                else                          $this->error($msg);
                $this->line(sprintf('        全文ハッシュ（参考・集計値の更新で再現不可）: '
                    . '組み直し %s… / 記録 %s…　文字数 %d → %d（%s）',
                    substr((string) ($facts['プロンプトSHA256'] ?? ''), 0, 16),
                    substr((string) ($rec['プロンプトSHA256'] ?? '－'), 0, 16),
                    $recLen, $newLen,
                    ($diff === null) ? '－' : sprintf('%+d文字', $diff)));

                $rows[] = [$date, $raceId, $label,
                           (string) ($rec['snapshot_id'] ?? ''),
                           (string) ($facts['snapshot_id'] ?? ''), $cmp['snapshot_id'],
                           (string) ($rec['入力SHA256'] ?? ''),
                           (string) ($facts['入力SHA256'] ?? ''), $cmp['入力SHA256'],
                           (string) ($rec['N'] ?? ''), (string) ($facts['N'] ?? ''), $cmp['N'],
                           (string) ($rec['C'] ?? ''), (string) ($facts['C'] ?? ''), $cmp['C'],
                           (string) ($rec['断層タイプ'] ?? ''),
                           (string) ($facts['断層タイプ'] ?? ''), $cmp['断層タイプ'],
                           $verdict,
                           (string) ($rec['プロンプトSHA256'] ?? ''),
                           (string) ($facts['プロンプトSHA256'] ?? ''),
                           md5($txt),
                           (string) $recLen, (string) $newLen,
                           ($diff === null) ? '' : (string) $diff,
                           (string) ($ph['1st']['md5'] ?? ''), $rec2, $st2];
            }
        }

        // ── まとめ ────────────────────────────────────────────────────
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' まとめ');
        $this->info('══════════════════════════════════════════════════════════');
        $n = array_sum($tot);
        $this->line('  【選出の判断に使う値（snapshot_id・入力SHA256・N・C・断層タイプ）】');
        foreach ($tot as $k => $v) {
            $this->line(sprintf('    %-24s %4d レース %s', $k, $v,
                $n > 0 ? sprintf('(%.1f%%)', $v / $n * 100) : ''));
        }
        $this->line('');
        $this->line('  【項目ごとの内訳】');
        $this->line('    項目            一致  不一致  判定不能');
        foreach ($fld as $k => $v) {
            $this->line(sprintf('    %-14s %5d %6d %8d', $k,
                $v['一致'], $v['不一致'], $v['判定不能']));
        }
        $this->line('');
        if ($tot['選出に使う値が一致'] > 0 && $tot['選出に使う値が不一致'] === 0) {
            $this->info('  → 選出の判断に使う値は、記録のある全レースで一致しました。');
            $this->info('     予想に使ったオッズ（入力SHA256）も1バイトも変わっていません。');
            $this->info('     受入試験 A4-8（v0.4-Xを入れても選出の入力が変わらない）の実測になります。');
        } elseif ($tot['選出に使う値が不一致'] > 0) {
            $this->error('  → 不一致があります。上の行の項目を確認してください。');
        }
        $this->line('');
        $this->line('  【お願い文の全文のハッシュ】');
        if (!empty($lenDiff)) {
            sort($lenDiff);
            $this->line(sprintf('    文字数の差（絶対値）: 最小 %d / 中央 %d / 最大 %d 文字（%dレース）',
                $lenDiff[0], $lenDiff[(int) floor(count($lenDiff) / 2)],
                $lenDiff[count($lenDiff) - 1], count($lenDiff)));
        }
        $this->warn('    全文のハッシュは一致しません。コードの変更が原因ではありません。');
        $this->warn('    お願い文に埋め込んでいる集計値（過去回収率・OPI回収率・局面パターン回収率・');
        $this->warn('    AI回収率・類似レース統計・中位オッズ・前走からの予測）が、');
        $this->warn('    レース後の集計バッチで作り直されているためです。');
        $this->warn('    10/17以降は、送信直前に本文そのもののMD5を記録しているので、');
        $this->warn('    組み直しをせずに送信文の一致を示せます（10/14から記録開始）。');

        $this->line('');
        $this->info('── 2nd AIの送信文 ─────────────────────────');
        $this->line('  統合の監査ログに2ndの送信文ハッシュが入っていたレース: '
            . $tot2['記録あり'] . '／' . array_sum($tot2));
        if ($tot2['記録あり'] === 0) {
            $this->warn('  → 0件です。2ndの記録を足したのは10/14なので、それ以前のレースにはありません。');
            $this->warn('     したがって2ndについては「実際に送った本文との突き合わせはできません」。');
            $this->warn('     2ndの入力は1stの本文から決まった箇所を外して作るので、');
            $this->warn('     1stが同じであれば2ndの入力も一意に決まります。');
            $this->warn('     10/17以降のレースでは記録が入るので、そこで突き合わせます。');
        }

        if (empty($rows)) {
            $this->error('書き出す行がありません。');
            return;
        }
        $name = 'prompt_verify_' . str_replace(',', '_', str_replace('-', '', $dateArg)) . '.csv';
        $path = storage_path('logs/' . $name);
        $fh = @fopen($path, 'w');
        if ($fh === false) { $this->error('CSVを書けません: ' . $path); return; }
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $head);
        foreach ($rows as $r) fputcsv($fh, $r);
        fclose($fh);
        @chmod($path, 0666);
        $this->line('');
        $this->info('  書き出しました: ' . $path . '（' . count($rows) . '行）');

        // ── 集計テーブルの更新状況（全文が再現できない理由の裏づけ）──
        $this->line('');
        $this->info('── お願い文に埋め込んでいる集計テーブルの状態 ─────');
        $this->line('  これらが作り直されると、お願い文の文字が変わります。');
        $tabs = ['t_horse_odds_finder_odds_gap_recovery'       => '乖離帯別の回収率',
                 't_horse_odds_finder_opi_recovery'            => 'OPI別の回収率',
                 't_horse_odds_finder_phase_pattern_recovery'  => '局面パターン別の回収率',
                 't_horse_odds_finder_similar_race_stats'      => '類似レース統計',
                 't_horse_odds_finder_popularity_rank_average' => '人気順位別の平均オッズ',
                 't_horse_odds_finder_fuku_popularity_rank_average' => '同（複勝）',
                 't_horse_odds_finder_compute_odds_correction' => 'オッズ補正',
                 't_horse_odds_finder_forecast_from_last_race' => '前走からの予測',
                 't_horse_odds_finder_shutsuba_history'        => '出走履歴'];
        foreach ($tabs as $tb => $jp) {
            try {
                $c = DB::table($tb)->count();
                $this->line(sprintf('  %-50s %8d 件  %s', $tb, $c, $jp));
            } catch (\Throwable $e) {
                $this->line(sprintf('  %-50s %8s 件  %s', $tb, '読めず', $jp));
            }
        }
        $this->line('');
        $this->warn('  ※これらは、レース結果が入るたびに集計バッチで作り直されるものです。');
        $this->warn('    10/10〜12の結果も、そのあとの集計に入っています。');
        $this->warn('    なので過去のお願い文を1バイト単位で再現することはできません。');
        $this->line('  ※t_horse_odds_finder_ai_recovery（AI予想の回収率）は、');
        $this->line('    お願い文には使っていません（AiController側でコメントアウト中・'
            . 'テーブルも作られていません）。一覧から外しました。');
        $this->warn('  ※オッズ・着順・払戻のような正本値は書き換えていません。');
        $this->warn('    上の「入力SHA256」の一致がその証明です。');
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261015-PROMPT-VERIFY  監査ログのファイルから AUDIT_RACE を読む
     * ═════════════════════════════════════════════════════════════════════════
     * storage/logs/audit/audit_日付.jsonl は1行1JSONです。
     * tag が AUDIT_RACE の行から、突き合わせに使う値を拾います。
     * 同じレースが複数回出ている場合は【最後の行】を採ります
     * （1st予想時と統合時の2回通るため。同一プロセス内なら同じ値になります。
     *   値が違う行があれば警告を出します）。
     */
    private function auditRaceFacts(string $date): array
    {
        $path = storage_path('logs/audit/audit_' . $date . '.jsonl');
        if (!is_file($path)) return [];
        $out = [];
        $fh = @fopen($path, 'r');
        if ($fh === false) return [];
        while (($ln = fgets($fh)) !== false) {
            $ln = trim($ln);
            if ($ln === '' || strpos($ln, 'AUDIT_RACE') === false) continue;
            $j = json_decode($ln, true);
            if (!is_array($j)) continue;
            if ((string) ($j['tag'] ?? '') !== 'AUDIT_RACE') continue;
            $d = is_array($j['data'] ?? null) ? $j['data'] : [];
            $race = (string) ($d['race'] ?? '');
            if ($race === '') continue;
            $row = ['snapshot_id'      => (string) ($d['snapshot_id'] ?? ''),
                    '入力SHA256'       => (string) ($d['入力SHA256'] ?? ''),
                    'N'                => (string) ($d['N'] ?? ''),
                    'C'                => (string) ($d['C'] ?? ''),
                    '断層タイプ'       => (string) ($d['断層タイプ'] ?? ''),
                    'プロンプトSHA256' => (string) ($d['プロンプトSHA256'] ?? ''),
                    'プロンプト文字数' => (string) ($d['プロンプト文字数'] ?? ''),
                    'at'               => (string) ($j['at'] ?? '')];
            if (isset($out[$race])
                && $out[$race]['プロンプトSHA256'] !== $row['プロンプトSHA256']) {
                $this->warn('  ※同じレースで異なるプロンプトSHA256が記録されています: ' . $race);
            }
            $out[$race] = $row;
        }
        fclose($fh);
        return $out;
    }


    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261015-INTEGRITY  洗い替え前のデータ完全性の確認（--integrity-check）
     * ═════════════════════════════════════════════════════════════════════════
     * 10月15日（1回目）01.txt【6】への対応です。
     *   「10月10〜12日のCSV、時系列オッズ、断層、資金流入、AI応答、公開候補、
     *     購入推奨結果は、17日の洗い替え前に保存済みデータの完全性を確認してください」
     *
     * 洗い替え（毎週土曜5:50の keiba:deleteKeibaTableRecords）で消えるのは
     *   races / odds / horses / race_results
     * です。これは仕様どおりの運用なので、変更の提案はしません。
     *
     * 消えないもの
     *   ai_analysis（1st AIの生回答）／ai_analysis2（2nd AIの生回答）／
     *   ai_merge_result（統合結果と監査ログ）／race_result_payout（払戻）／
     *   storage/logs 以下のファイル
     *
     * ■ このコマンドがやること
     *   ① 日付ごとに、消えるテーブルと消えないテーブルの件数を数えて表に出す
     *   ② 突き合わせる（レース数と統合結果の数、オッズのある時点の数、
     *      1st/2ndの回答と全頭採点の受領、着順と払戻の有無）
     *   ③ 消えるものを、消えない形で書き出す
     *        ・odds_backup_日付.csv  … 全時点の単勝・複勝オッズ（1行1馬1時点）
     *        ・results_backup_日付.csv … 着順
     *        これがあれば、洗い替え後も支持率・資金流入・断層を再計算できます
     *   ④ 洗い替え後も残るファイルの一覧とバイト数・MD5を出す
     *
     * 読み取りだけです。DBへの書き込みもAI呼び出しもありません。
     * 書き出すのは storage/logs 以下のファイルだけです。
     */
    private function runIntegrityCheck(string $dateArg): void
    {
        $dates = array_values(array_filter(array_map('trim', explode(',', $dateArg))));
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 洗い替え前のデータ完全性の確認  対象日 ' . implode(' / ', $dates));
        $this->info('  （読み取りだけ・DBへの書き込みなし・AI呼び出し0回）');
        $this->info('══════════════════════════════════════════════════════════');
        $this->warn(' ※洗い替えは毎週土曜5:50。消えるのは races / odds / horses / race_results です。');
        $this->warn(' ※洗い替えは仕様どおりの運用です。変更の提案はしません。');

        $allOk = true;
        $summary = [];

        foreach ($dates as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->error("日付の書式が不正です: {$date}");
                $allOk = false;
                continue;
            }
            $this->line('');
            $this->info("── {$date} ──────────────────────────────");

            // ── ① 件数 ────────────────────────────────────────────────
            $cRaces   = DB::table('t_horse_odds_finder_races')->where('date', $date)->count();
            $cHorses  = DB::table('t_horse_odds_finder_horses')->where('date', $date)->count();
            $cOdds    = DB::table('t_horse_odds_finder_odds')->where('date', $date)->count();
            $cResults = DB::table('t_horse_odds_finder_race_results')->where('date', $date)->count();
            $cMerge   = DB::table('t_horse_odds_finder_ai_merge_result')->where('date', $date)->count();
            $cAi1     = DB::table('t_horse_odds_finder_ai_analysis')->where('date', $date)->count();
            $cAi2     = DB::table('t_horse_odds_finder_ai_analysis2')->where('date', $date)->count();
            $cPayout  = DB::table('t_horse_odds_finder_race_result_payout')->where('date', $date)->count();

            $this->line('  【洗い替えで消えるもの】');
            $this->line(sprintf('    races            %6d 件', $cRaces));
            $this->line(sprintf('    horses           %6d 件', $cHorses));
            $this->line(sprintf('    odds             %6d 件', $cOdds));
            $this->line(sprintf('    race_results     %6d 件', $cResults));
            $this->line('  【洗い替えでも残るもの】');
            $this->line(sprintf('    ai_analysis      %6d 件（1st AIの生回答）', $cAi1));
            $this->line(sprintf('    ai_analysis2     %6d 件（2nd AIの生回答）', $cAi2));
            $this->line(sprintf('    ai_merge_result  %6d 件（統合結果と監査ログ）', $cMerge));
            $this->line(sprintf('    race_result_payout %4d 件（払戻）', $cPayout));

            // ── ② 突き合わせ ──────────────────────────────────────────
            $this->line('');
            $this->line('  【突き合わせ】');
            $ng = 0;
            $chk = function (string $what, bool $ok, string $detail) use (&$ng) {
                if ($ok) $this->info(sprintf('    OK   %-34s %s', $what, $detail));
                else   { $this->error(sprintf('    確認 %-34s %s', $what, $detail)); $ng++; }
            };

            $chk('レース数と統合結果の数', $cRaces > 0 && $cMerge > 0,
                 "races {$cRaces} / merge {$cMerge}"
                 . (($cRaces !== $cMerge) ? '　※AI予想を出していないレースがある場合は一致しません' : ''));
            $chk('1st AIの回答がレース数ぶんある', $cAi1 >= $cMerge,
                 "ai_analysis {$cAi1} / merge {$cMerge}");
            $chk('2nd AIの回答がレース数ぶんある', $cAi2 >= $cMerge,
                 "ai_analysis2 {$cAi2} / merge {$cMerge}");
            $chk('着順がある', $cResults > 0, "race_results {$cResults} 件");
            $chk('払戻がある', $cPayout > 0, "race_result_payout {$cPayout} 件");

            // オッズの時点ごとの件数
            $timings = [\App\Constants\Constants::ODDS_DB_FIRST, 21, 18, 15, 12, 9, 6];
            $byT = [];
            foreach (DB::table('t_horse_odds_finder_odds')
                        ->where('date', $date)
                        ->whereIn('minutes_before_start', $timings)
                        ->selectRaw('minutes_before_start AS t, COUNT(*) AS n')
                        ->groupBy('minutes_before_start')->get() as $r) {
                $byT[(int) $r->t] = (int) $r->n;
            }
            $parts = [];
            foreach ($timings as $t) {
                $parts[] = (($t >= 900) ? '計測前' : ($t . '分前')) . ':' . (($byT[$t] ?? 0) . '件');
            }
            $chk('オッズが全時点そろっている', count($byT) === count($timings), implode('　', $parts));

            // 全頭採点の受領
            $as1ok = 0; $as2ok = 0; $mergeRows = 0;
            $as1ng = []; $as2ng = [];
            foreach (DB::table('t_horse_odds_finder_ai_merge_result')
                        ->where('date', $date)
                        ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
                        ->get() as $mg) {
                $mergeRows++;
                $lb = "{$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R";
                $t1 = DB::table('t_horse_odds_finder_ai_analysis')
                    ->where('date', $date)->where('kaisuu', $mg->kaisuu)
                    ->where('basho_code', $mg->basho_code)->where('day', $mg->day)
                    ->where('race', $mg->race)->value('analysis_text');
                $t2 = DB::table('t_horse_odds_finder_ai_analysis2')
                    ->where('date', $date)->where('kaisuu', $mg->kaisuu)
                    ->where('basho_code', $mg->basho_code)->where('day', $mg->day)
                    ->where('race', $mg->race)->value('analysis_text');
                $r1 = ($t1 === null)
                    ? ['received' => false, 'reason' => '1st AIの回答が保存されていない', 'count' => 0]
                    : AiController::allScoreReport((string) $t1);
                $r2 = ($t2 === null)
                    ? ['received' => false, 'reason' => '2nd AIの回答が保存されていない', 'count' => 0]
                    : AiController::allScoreReport((string) $t2);
                if (!empty($r1['received'])) $as1ok++;
                else $as1ng[] = $lb . '  ' . (string) ($r1['reason'] ?? '')
                                . '（回答の文字数 ' . (($t1 === null) ? '－' : mb_strlen((string) $t1)) . '）';
                if (!empty($r2['received'])) $as2ok++;
                else $as2ng[] = $lb . '  ' . (string) ($r2['reason'] ?? '');
            }
            // @ANCHOR-20261015-INTEGRITY  想定どおりのものは「既知」として分けて出す
            $chk('1stの全頭採点が残っている', $as1ok === $mergeRows,
                 "{$as1ok} / {$mergeRows} レース");
            foreach ($as1ng as $l) $this->error('         └ ' . $l);
            if ($as2ok === $mergeRows) {
                $chk('2ndの全頭採点が残っている', true, "{$as2ok} / {$mergeRows} レース");
            } else {
                // 2ndを生保存にしたのは10/14。それ以前のレースに無いのは想定どおり。
                $this->line(sprintf('    既知 %-34s %s', '2ndの全頭採点が残っている',
                    "{$as2ok} / {$mergeRows} レース"
                    . '　※2ndを生保存にしたのは10/14です。それ以前のレースには残りません（42-4）'));
            }

            // 監査ログのファイル
            $ap = storage_path('logs/audit/audit_' . $date . '.jsonl');
            $aLines = 0; $aTags = [];
            if (is_file($ap)) {
                $fh = @fopen($ap, 'r');
                if ($fh !== false) {
                    while (($ln = fgets($fh)) !== false) {
                        $ln = trim($ln);
                        if ($ln === '') continue;
                        $aLines++;
                        $j = json_decode($ln, true);
                        if (is_array($j)) {
                            $tg = (string) ($j['tag'] ?? '?');
                            $aTags[$tg] = ($aTags[$tg] ?? 0) + 1;
                        }
                    }
                    fclose($fh);
                }
            }
            $tagStr = [];
            foreach ($aTags as $k => $v) $tagStr[] = $k . ':' . $v;
            $chk('監査ログのファイルがある', is_file($ap),
                 is_file($ap) ? ($aLines . '行　' . implode('　', $tagStr)) : $ap . ' がありません');

            // ── ③ 消えるものを書き出す ────────────────────────────────
            $this->line('');
            $this->line('  【洗い替えで消えるものの書き出し】');

            $oName = 'odds_backup_' . str_replace('-', '', $date) . '.csv';
            $oPath = storage_path('logs/' . $oName);
            $fh = @fopen($oPath, 'w');
            $oRows = 0;
            if ($fh === false) {
                $this->error('    オッズを書けません: ' . $oPath);
                $ng++;
            } else {
                fwrite($fh, "\xEF\xBB\xBF");
                fputcsv($fh, ['date', 'kaisuu', 'basho', 'day', 'race', 'num',
                              'minutes_before_start', 'odds', 'fuku_min', 'fuku_max']);
                DB::table('t_horse_odds_finder_odds')
                    ->where('date', $date)
                    ->orderBy('kaisuu')->orderBy('basho')->orderBy('day')->orderBy('race')
                    ->orderBy('num')->orderBy('minutes_before_start')
                    ->select(['date', 'kaisuu', 'basho', 'day', 'race', 'num',
                              'minutes_before_start', 'odds', 'fuku_min', 'fuku_max'])
                    ->chunk(2000, function ($chunk) use ($fh, &$oRows) {
                        foreach ($chunk as $r) {
                            fputcsv($fh, [$r->date, $r->kaisuu, $r->basho, $r->day, $r->race,
                                          $r->num, $r->minutes_before_start,
                                          $r->odds, $r->fuku_min, $r->fuku_max]);
                            $oRows++;
                        }
                    });
                fclose($fh);
                @chmod($oPath, 0666);
                $this->info(sprintf('    オッズ   %6d行 → %s', $oRows, $oPath));
            }

            $rName = 'results_backup_' . str_replace('-', '', $date) . '.csv';
            $rPath = storage_path('logs/' . $rName);
            $fh = @fopen($rPath, 'w');
            $rRows = 0;
            if ($fh === false) {
                $this->error('    着順を書けません: ' . $rPath);
                $ng++;
            } else {
                fwrite($fh, "\xEF\xBB\xBF");
                fputcsv($fh, ['date', 'kaisuu', 'basho', 'day', 'race', 'num', 'result']);
                foreach (DB::table('t_horse_odds_finder_race_results')
                            ->where('date', $date)
                            ->orderBy('kaisuu')->orderBy('basho')->orderBy('day')
                            ->orderBy('race')->orderBy('num')->get() as $r) {
                    fputcsv($fh, [$r->date, $r->kaisuu, $r->basho, $r->day, $r->race,
                                  $r->num, $r->result]);
                    $rRows++;
                }
                fclose($fh);
                @chmod($rPath, 0666);
                $this->info(sprintf('    着順     %6d行 → %s', $rRows, $rPath));
            }

            $chk('オッズの書き出し件数がDBと一致', $oRows === $cOdds, "{$oRows} / {$cOdds}");
            $chk('着順の書き出し件数がDBと一致', $rRows === $cResults, "{$rRows} / {$cResults}");

            if ($ng > 0) $allOk = false;
            $summary[$date] = ['確認が必要' => $ng, 'オッズ' => $oRows, '着順' => $rRows,
                               'races' => $cRaces, 'merge' => $cMerge,
                               '1st全頭採点' => $as1ok, '2nd全頭採点' => $as2ok,
                               '監査ログ行数' => $aLines];
        }

        // ── ④ 残るファイルの一覧 ──────────────────────────────────────
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 洗い替え後も残るファイル（storage/logs）');
        $this->info('══════════════════════════════════════════════════════════');
        $pats = ['longshot_detail_*.csv', 'plan2_compare_*.json', 'missed_analysis_*.json',
                 'compare_*.csv', 'prompt_verify_*.csv', 'odds_backup_*.csv',
                 'results_backup_*.csv', 'v04_selftest_*.json', 'prompt_baseline_*'];
        $files = [];
        foreach ($pats as $pat) {
            foreach ((array) glob(storage_path('logs/' . $pat)) as $f) $files[$f] = true;
        }
        if (empty($files)) {
            $this->warn('  該当するファイルがありません。');
        } else {
            foreach (array_keys($files) as $f) {
                if (is_dir($f)) {
                    $this->line(sprintf('  %10s  %-46s （ディレクトリ）',
                        '-', basename($f)));
                    continue;
                }
                $this->line(sprintf('  %10d バイト  %-46s  md5 %s',
                    (int) @filesize($f), basename($f), (string) @md5_file($f)));
            }
        }

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        if ($allOk) {
            $this->info(' 確認が必要な項目はありませんでした。');
            $this->info(' 洗い替えで消えるオッズと着順は、CSVに書き出してあります。');
        } else {
            $this->error(' 確認が必要な項目があります。上の「確認」行を見てください。');
            $this->line(' （「既知」と書いた行は想定どおりのもので、ここには数えていません）');
        }
        $this->info('══════════════════════════════════════════════════════════');

        $jp = storage_path('logs/integrity_check_'
            . str_replace(',', '_', str_replace('-', '', $dateArg)) . '.json');
        @file_put_contents($jp, json_encode(
            ['確認日時' => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))
                               ->format('Y-m-d H:i:s') . ' JST',
             '対象日' => $dates, '日別' => $summary,
             '残るファイル' => array_map(static fn($f) => [
                 'name' => basename($f),
                 'bytes' => is_dir($f) ? null : (int) @filesize($f),
                 'md5' => is_dir($f) ? null : (string) @md5_file($f)], array_keys($files))],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @chmod($jp, 0666);
        $this->info(' 書き出しました: ' . $jp);
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261015-EVIDENCE  別手段で確認する6項目の実施（--evidence-check）
     * ═════════════════════════════════════════════════════════════════════════
     * 10月15日（1回目）02.txt ⑥への対応です。
     *   「280項目の合格に加え、別手段での確認が必要な項目の実施結果を報告してください」
     *
     * 受入試験の中で「ここでは判定していない項目（別手段で確認する）」として
     * 挙げていた6件を、できる範囲で機械的に確認します。
     *   B-T19  再実行と保存データ破損防止（チェックサム比較）
     *   B-T23  一括再採点で AUDIT_V04_RACE が揃うこと
     *   B-T24  予想実行時刻が6分前より新しい（snapshot_id 照合）
     *   A1-4   断層区分とDB元値が変更されていないこと
     *   A3-1   1st/2ndの独立性（2ndへ1stの回答を渡していないこと）
     *   A4-8   V04_ENABLED=false のとき送信文が1バイトも変わらないこと
     *
     * できたこと・できなかったことを分けて書きます。
     * 読み取りだけです。DBへの書き込みもAI呼び出しもありません。
     */
    private function runEvidenceCheck(string $date): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(" 別手段で確認する6項目の実施  対象日 {$date}");
        $this->info('  （読み取りだけ・DBへの書き込みなし・AI呼び出し0回）');
        $this->info('══════════════════════════════════════════════════════════');

        $res = [];
        $say = function (string $id, string $what, string $state, array $lines) use (&$res) {
            $this->line('');
            if ($state === 'OK')            $this->info("── {$id}  {$what} … 確認できました");
            elseif ($state === '一部')      $this->warn("── {$id}  {$what} … 一部だけ確認できました");
            elseif ($state === '対象外')    $this->line("── {$id}  {$what} … この日は対象外（想定どおり）");
            else                            $this->error("── {$id}  {$what} … 確認できません");
            foreach ($lines as $l) $this->line('    ' . $l);
            $res[$id] = ['項目' => $what, '結果' => $state, '内容' => $lines];
        };

        // ══════════════════════════════════════════════════════════════
        // B-T19  再実行と保存データ破損防止（チェックサム比較）
        // ══════════════════════════════════════════════════════════════
        // 検証用のコマンドを動かしても、保存済みのAI回答・統合結果が
        // 1バイトも変わらないことを、前後のチェックサムで示します。
        $sumOf = function (string $d): array {
            $h1 = hash_init('sha256'); $n1 = 0;
            foreach (DB::table('t_horse_odds_finder_ai_analysis')->where('date', $d)
                        ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
                        ->get(['analysis_text']) as $r) {
                hash_update($h1, (string) $r->analysis_text); $n1++;
            }
            $h2 = hash_init('sha256'); $n2 = 0;
            foreach (DB::table('t_horse_odds_finder_ai_analysis2')->where('date', $d)
                        ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
                        ->get(['analysis_text']) as $r) {
                hash_update($h2, (string) $r->analysis_text); $n2++;
            }
            $h3 = hash_init('sha256'); $n3 = 0;
            foreach (DB::table('t_horse_odds_finder_ai_merge_result')->where('date', $d)
                        ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
                        ->get(['merge_result']) as $r) {
                hash_update($h3, (string) $r->merge_result); $n3++;
            }
            return ['1st' => hash_final($h1), '1st件数' => $n1,
                    '2nd' => hash_final($h2), '2nd件数' => $n2,
                    'merge' => hash_final($h3), 'merge件数' => $n3];
        };
        $before = $sumOf($date);

        // 読み取り専用の処理をひととおり動かす（AI呼び出しは0回）
        $t = AiController::v04SelfTest();
        $readCount = 0;
        foreach (DB::table('t_horse_odds_finder_ai_merge_result')->where('date', $date)->get() as $mg) {
            $x = DB::table('t_horse_odds_finder_ai_analysis')
                ->where('date', $date)->where('kaisuu', $mg->kaisuu)
                ->where('basho_code', $mg->basho_code)->where('day', $mg->day)
                ->where('race', $mg->race)->value('analysis_text');
            if ($x !== null) { AiController::allScoreReport((string) $x);
                               AiController::publicCandidateNums((string) $x); $readCount++; }
        }
        $after = $sumOf($date);

        $same = ($before === $after);
        $say('B-T19', '再実行と保存データ破損防止', $same ? 'OK' : 'NG', [
            '確認のしかた: 受入試験と全頭採点の読み取りを ' . $readCount . 'レースぶん動かし、',
            '              その前後で保存済みデータのSHA-256を比べました',
            'BAGANRIKI_V04_ENABLED: ' . (AiController::v04SwitchReport()['BAGANRIKI_V04_ENABLED'] ?? '－'),
            '1st AI回答   ' . $before['1st件数'] . '件  '
                . substr($before['1st'], 0, 16) . '… → ' . substr($after['1st'], 0, 16) . '…',
            '2nd AI回答   ' . $before['2nd件数'] . '件  '
                . substr($before['2nd'], 0, 16) . '… → ' . substr($after['2nd'], 0, 16) . '…',
            '統合結果     ' . $before['merge件数'] . '件  '
                . substr($before['merge'], 0, 16) . '… → ' . substr($after['merge'], 0, 16) . '…',
            $same ? '→ 3つすべて前後で一致。1バイトも変わっていません'
                  : '→ 変化しています。原因を調べる必要があります',
            '受入試験の結果: ' . (int) ($t['pass'] ?? 0) . ' 合格 / '
                . (int) ($t['fail'] ?? 0) . ' 不合格',
        ]);

        // ══════════════════════════════════════════════════════════════
        // B-T23  一括再採点で AUDIT_V04_RACE が揃うこと
        // ══════════════════════════════════════════════════════════════
        $ap = storage_path('logs/audit/audit_' . $date . '.jsonl');
        $tags = [];
        if (is_file($ap)) {
            $fh = @fopen($ap, 'r');
            if ($fh !== false) {
                while (($ln = fgets($fh)) !== false) {
                    $ln = trim($ln);
                    if ($ln === '') continue;
                    $j = json_decode($ln, true);
                    if (!is_array($j)) continue;
                    $tg = (string) ($j['tag'] ?? '?');
                    $rc = (string) (($j['data']['race'] ?? '') ?: '?');
                    $tags[$tg][$rc] = true;
                }
                fclose($fh);
            }
        }
        $mergeCnt = DB::table('t_horse_odds_finder_ai_merge_result')->where('date', $date)->count();
        $v04Race  = isset($tags['AUDIT_V04_RACE']) ? count($tags['AUDIT_V04_RACE']) : 0;
        $lines = ['監査ログのファイル: ' . $ap . (is_file($ap) ? '' : '（ありません）'),
                  '統合結果のレース数: ' . $mergeCnt];
        foreach ($tags as $tg => $rr) {
            $lines[] = sprintf('  %-22s %3dレース', $tg, count($rr));
        }
        // @ANCHOR-20261015-EVIDENCE  AUDIT_MERGE は10/14までレース表記が無い
        //   10/14に @ANCHOR-20261014-AUDIT-RACEID で race / race_id を足しました。
        //   それ以前の行にはレース表記が無いので、ここでは1件にまとまって見えます。
        if (isset($tags['AUDIT_MERGE']) && count($tags['AUDIT_MERGE']) === 1 && $mergeCnt > 1) {
            $lines[] = '  ※AUDIT_MERGE が1レースに見えるのは、レース表記を足したのが';
            $lines[] = '    10/14（@ANCHOR-20261014-AUDIT-RACEID）だからです。';
            $lines[] = '    それ以前の行にはレース表記が無く、1件にまとまって数えられます。';
            $lines[] = '    行数そのものは --integrity-check のタグ別件数で確認できます。';
        }
        // @ANCHOR-20261015-BT23-FIX  存在しないオプションを案内していた誤りの訂正
        //   10/15の最初の実行で「--v04-shadow を実行すれば揃います」と出していましたが、
        //   そのオプションは存在しませんでした（The "--v04-shadow" option does not exist）。
        //   AUDIT_V04_RACE は【予想を処理したときに】書かれる記録です。
        //   v0.4-Xを入れたのは10月13日なので、それ以前のレースには存在しません。
        //   監査ログは実行日のファイルへ書かれるので、過去日のファイルへ
        //   後から足すこともできません。できないことは、できないと書きます。
        if ($v04Race >= $mergeCnt && $mergeCnt > 0) {
            $state = 'OK';
            $lines[] = '→ AUDIT_V04_RACE がレース数ぶん揃っています';
        } elseif ($v04Race > 0) {
            $state = '一部';
            $lines[] = '→ AUDIT_V04_RACE が ' . $v04Race . '／' . $mergeCnt . ' レースです';
        } else {
            $state = '対象外';
            $lines[] = '→ AUDIT_V04_RACE が0件です。これは想定どおりです。';
            $lines[] = '   この記録は【予想を処理したときに】書かれるもので、';
            $lines[] = '   v0.4-Xを入れた10月13日より前のレースには存在しません。';
            $lines[] = '   監査ログは実行日のファイルへ書かれるので、';
            $lines[] = '   過去日のファイルへ後から足すこともできません。';
            $lines[] = '   正しい確認は、10月17日・18日の実レースでレース数ぶん揃うことを';
            $lines[] = '   このコマンドで見ることです（--evidence-check --date=2026-10-17）。';
            $lines[] = '   ※10/15の最初の実行で「--v04-shadow を実行すれば揃います」と';
            $lines[] = '     出していましたが、そのオプションは存在しませんでした。訂正します。';
        }
        $say('B-T23', '一括再採点で AUDIT_V04_RACE が揃うこと', $state, $lines);

        // ══════════════════════════════════════════════════════════════
        // B-T24  予想実行時刻が6分前より新しい（snapshot_id 照合）
        // ══════════════════════════════════════════════════════════════
        $sn = ['一致' => 0, '不一致' => 0, '記録なし' => 0];
        $snNg = [];
        foreach (DB::table('t_horse_odds_finder_ai_merge_result')->where('date', $date)
                    ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
                    ->get() as $mg) {
            $dec = json_decode((string) ($mg->merge_result ?? ''), true);
            $s2  = (is_array($dec) && is_array($dec['snapshot'] ?? null)) ? $dec['snapshot'] : [];
            $ps  = (string) ($s2['prompt_snapshot_id'] ?? '');
            $ms  = (string) ($s2['merge_snapshot_id'] ?? '');
            $mt  = $s2['match'] ?? null;
            if ($ps === '' && $ms === '') { $sn['記録なし']++; continue; }
            if ($mt === true || ($ps !== '' && $ps === $ms)) $sn['一致']++;
            else {
                $sn['不一致']++;
                $snNg[] = "{$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R"
                          . "  prompt {$ps} / merge {$ms}";
            }
        }
        $lines = ['お願い文を作ったときの6分前スナップショットと、',
                  '統合したときのスナップショットが同じかを全レースで照合しました。',
                  '一致   ' . $sn['一致'] . 'レース',
                  '不一致 ' . $sn['不一致'] . 'レース',
                  '記録なし ' . $sn['記録なし'] . 'レース'];
        foreach (array_slice($snNg, 0, 10) as $l) $lines[] = '  ' . $l;
        if ($sn['不一致'] === 0 && $sn['一致'] > 0) {
            $state = ($sn['記録なし'] === 0) ? 'OK' : '一部';
            $lines[] = '→ 不一致はありません。お願い文の作成後に6分前オッズが変わったレースはありません';
        } elseif ($sn['不一致'] > 0) {
            $state = 'NG';
            $lines[] = '→ 不一致があります。上のレースを調べる必要があります';
        } else {
            $state = 'NG';
            $lines[] = '→ 照合できる記録がありません';
        }
        $say('B-T24', '予想実行時刻が6分前より新しい（snapshot_id 照合）', $state, $lines);

        // ══════════════════════════════════════════════════════════════
        // A1-4  断層区分とDB元値が変更されていないこと
        // ══════════════════════════════════════════════════════════════
        // 予想したときに監査ログへ書いた断層タイプと、
        // いま統合結果のテーブルに入っている断層タイプを突き合わせます。
        $gapRec = [];
        if (is_file($ap)) {
            $fh = @fopen($ap, 'r');
            if ($fh !== false) {
                while (($ln = fgets($fh)) !== false) {
                    $ln = trim($ln);
                    if ($ln === '' || strpos($ln, 'AUDIT_RACE') === false) continue;
                    $j = json_decode($ln, true);
                    if (!is_array($j) || (string) ($j['tag'] ?? '') !== 'AUDIT_RACE') continue;
                    $d = is_array($j['data'] ?? null) ? $j['data'] : [];
                    $rc = (string) ($d['race'] ?? '');
                    if ($rc === '') continue;
                    $gapRec[$rc] = ['断層タイプ' => (string) ($d['断層タイプ'] ?? ''),
                                    'N' => $d['N'] ?? null, 'C' => $d['C'] ?? null,
                                    '入力SHA256' => (string) ($d['入力SHA256'] ?? '')];
                }
                fclose($fh);
            }
        }
        $g = ['一致' => 0, '不一致' => 0, '記録なし' => 0];
        $gNg = [];
        foreach (DB::table('t_horse_odds_finder_ai_merge_result')->where('date', $date)
                    ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
                    ->get() as $mg) {
            $rk = "{$date} {$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R";
            $rr = $gapRec[$rk] ?? null;
            if ($rr === null || $rr['断層タイプ'] === '') { $g['記録なし']++; continue; }
            if ((string) ($mg->gap_type ?? '') === $rr['断層タイプ']) $g['一致']++;
            else {
                $g['不一致']++;
                $gNg[] = $rk . '  記録 ' . $rr['断層タイプ'] . ' / いまのDB ' . (string) ($mg->gap_type ?? '－');
            }
        }
        $lines = ['予想したときに監査ログへ書いた断層タイプ（A〜E）と、',
                  'いま統合結果のテーブルに入っている断層タイプを突き合わせました。',
                  '一致   ' . $g['一致'] . 'レース',
                  '不一致 ' . $g['不一致'] . 'レース',
                  '記録なし ' . $g['記録なし'] . 'レース'];
        foreach (array_slice($gNg, 0, 10) as $l) $lines[] = '  ' . $l;
        if ($g['不一致'] === 0 && $g['一致'] > 0) {
            $state = ($g['記録なし'] === 0) ? 'OK' : '一部';
            $lines[] = '→ 断層区分は書き換わっていません';
        } elseif ($g['不一致'] > 0) {
            $state = 'NG';
            $lines[] = '→ 書き換わっています。原因を調べる必要があります';
        } else {
            $state = 'NG';
            $lines[] = '→ 突き合わせられる記録がありません（AUDIT_RACE は10月4日から記録しています）';
        }
        $lines[] = 'あわせて入力SHA256も監査ログに残っています（1レース1件）。';
        $lines[] = 'オッズそのものは --integrity-check でCSVへ書き出せます。';
        $say('A1-4', '断層区分とDB元値が変更されていないこと', $state, $lines);

        // ══════════════════════════════════════════════════════════════
        // A3-1  1st/2ndの独立性
        // ══════════════════════════════════════════════════════════════
        // 2nd AIへ渡しているのは「1st AI用に作ったお願い文から一部を外したもの」と
        // システム指示だけで、1st AIの【回答】は渡していません。
        // コードの文面を機械的に調べて示します。
        $lines = [];
        $state = 'NG';
        try {
            $file = (new \ReflectionClass(AiController::class))->getFileName();
            $src  = (string) @file_get_contents($file);
            $fi   = strpos($src, 'function getHorseOddsFinderSecondAiOpinion');
            if ($fi === false) {
                $lines[] = '2nd AIの関数が見つかりません: ' . $file;
            } else {
                // 関数の範囲を波括弧の対応で切り出す
                $bo = strpos($src, '{', $fi);
                $dep = 0; $q = $bo;
                $len = strlen($src);
                while ($q < $len) {
                    if ($src[$q] === '{') $dep++;
                    elseif ($src[$q] === '}') { $dep--; if ($dep === 0) break; }
                    $q++;
                }
                $body = substr($src, $fi, $q - $fi + 1);
                $bodyLines = substr_count($body, "\n") + 1;

                // ══════════════════════════════════════════════════════
                // @ANCHOR-20261015-A31  2nd AIへ送るまでの範囲だけを調べる
                // ══════════════════════════════════════════════════════
                // 10/15の最初の実行では関数全体を調べ、1st AIの回答を持つ
                // 名前が見つかって「一部」になりました。原因は調べ方でした。
                // この関数は2,372行あり、【2nd AIへ送ったあと】に統合処理まで
                // 続いています。統合では1st AIの回答を読むのが当然なので、
                // 関数全体を見れば名前は必ず出てきます。
                //
                // 独立性が問われるのは【2nd AIへ送る本文を作るまで】です。
                // そこでDeepSeekへ渡す配列（$dsPayload）を作る行で区切り、
                // その手前だけを調べます。
                $cut = strpos($body, '$dsPayload = [');
                if ($cut === false) $cut = strpos($body, 'deepseek-chat');
                $pre = ($cut === false) ? $body : substr($body, 0, $cut);
                $preLines = substr_count($pre, "\n") + 1;

                $hasPayload = (strpos($body, 'deepseek-chat') !== false);

                // ══════════════════════════════════════════════════════
                // @ANCHOR-20261015-A31B  名前を数えるのではなく、流れを見る
                // ══════════════════════════════════════════════════════
                // 10/15の実行で、送る手前に1件だけ
                // t_horse_odds_finder_ai_analysis の読み出しが見つかりました。
                // 中身を確認したところ、1st AIが【終わっているか】
                // 【失敗していないか】を見るための門番でした。
                //   $preFirstRecord = DB::table('t_horse_odds_finder_ai_analysis')…
                //   if (!$preFirstRecord) { 409を返す }
                //   $preFirstError = $this->_firstAiPublishError($preFirstRecord->analysis_text);
                // 回答の中身を2nd AIへ渡しているわけではありません。
                //
                // そこで「名前があるか」を数えるのをやめ、
                // 【2nd AIへ送る本文（$oddsData と $systemPrompt）に
                //   1st AIの回答が流れ込んでいないか】を見ます。
                // これが独立性そのものです。
                //
                //   手順1  送る手前で1st AIの回答を読んでいる箇所を全部見つけ、
                //          その値を受け取っている変数名を拾う
                //   手順2  $oddsData と $systemPrompt への代入・追記を全部見つける
                //   手順3  手順2の右辺に、手順1の変数名や1st AIの回答の名前が
                //          1つも出てこないことを確かめる
                $srcNames = ['t_horse_odds_finder_ai_analysis\'', 'firstAiRecord', 'firstAiText',
                             'firstAiHorses', 'analysisText1st', 'firstAnswer'];
                $preL = explode("\n", $pre);
                // ファイルの実際の行番号で出す（関数の開始行を足す）
                $baseLine = substr_count(substr($src, 0, $fi), "\n") + 1;

                // 手順1: 1st AIの回答を読んでいる行と、受け取っている変数
                $readLines = []; $tainted = [];
                foreach ($preL as $no => $l) {
                    foreach ($srcNames as $nm) {
                        if (strpos($l, $nm) === false) continue;
                        $readLines[] = ($baseLine + $no) . '行: ' . trim($l);
                        if (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=/', $l, $mm)) {
                            $tainted[$mm[1]] = true;
                        }
                        break;
                    }
                }
                foreach ($srcNames as $nm) {
                    if (strpos($nm, '$') === 0) $tainted[substr($nm, 1)] = true;
                    elseif (strpos($nm, 't_horse') !== 0) $tainted[$nm] = true;
                }

                // 手順2・3: 送る本文への代入の右辺を調べる
                $sendAssign = []; $leak = [];
                foreach ($preL as $no => $l) {
                    if (!preg_match('/\$(oddsData|systemPrompt)\s*\.?=[^=]/', $l)) continue;
                    $rhs = substr($l, strpos($l, '=') + 1);
                    $sendAssign[] = ($baseLine + $no) . '行: ' . trim($l);
                    foreach (array_keys($tainted) as $tn) {
                        if (strpos($rhs, '$' . $tn) !== false || strpos($rhs, $tn) !== false) {
                            $leak[] = ($baseLine + $no) . '行: ' . trim($l) . '  ← ' . $tn;
                        }
                    }
                }

                $lines[] = 'DeepSeekへ渡す本文の組み立てがこの関数内にある: '
                           . ($hasPayload ? 'はい' : 'いいえ');
                $lines[] = '';
                $lines[] = '手順1  送る手前で1st AIの回答を読んでいる箇所: '
                           . count($readLines) . '件（行番号は ' . basename($file) . ' のもの）';
                foreach ($readLines as $l) $lines[] = '    ' . mb_substr($l, 0, 110);
                if (!empty($readLines)) {
                    $lines[] = '    ※1st AIが終わっているか・失敗していないかを見る門番です。';
                    $lines[] = '      回答の中身を2nd AIへ渡しているわけではありません。';
                    $lines[] = '      それを手順3で確かめます。';
                }
                $lines[] = '';
                $lines[] = '手順2  送る本文（$oddsData・$systemPrompt）への代入: '
                           . count($sendAssign) . '件';
                $lines[] = '';
                $lines[] = '手順3  その右辺に1st AIの回答が入っているか: '
                           . (empty($leak) ? '1件もありません' : (count($leak) . '件あります'));
                foreach ($leak as $l) $lines[] = '    ★ ' . mb_substr($l, 0, 110);

                if (empty($leak) && $hasPayload) {
                    $lines[] = '';
                    $lines[] = '→ 2nd AIへ送る本文に1st AIの回答は流れ込んでいません。';
                    $lines[] = '   送っているのは $systemPrompt（システム指示）と';
                    $lines[] = '   $oddsData（1st用のお願い文から厳選穴レースの判定ルール・';
                    $lines[] = '   配分ブロック・1st専用の出力指示を外したもの）の2つだけです。';
                    $lines[] = '   独立性をコードの流れで確認しました。';
                    $state = 'OK';
                } elseif (!empty($leak)) {
                    $lines[] = '';
                    $lines[] = '→ 送る本文に1st AIの回答が入っています。直す必要があります。';
                    $state = 'NG';
                } else {
                    $lines[] = '';
                    $lines[] = '→ DeepSeekへ渡す箇所が見つかりませんでした。調べ方を見直します。';
                    $state = '一部';
                }
            }
        } catch (\Throwable $e) {
            $lines[] = '確認中に例外: ' . mb_substr($e->getMessage(), 0, 80);
        }
        $say('A3-1', '1st/2ndの独立性（2ndへ1stの回答を渡していないこと）', $state, $lines);

        // ══════════════════════════════════════════════════════════════
        // A4-8  V04_ENABLED=false のとき送信文が1バイトも変わらないこと
        // ══════════════════════════════════════════════════════════════
        $pv = glob(storage_path('logs/prompt_verify_*.csv'));
        $lines = ['スイッチの実効値:'];
        foreach (AiController::v04SwitchReport() as $k => $v) {
            $lines[] = sprintf('  %-34s %s', $k, $v);
        }
        $lines[] = '';
        if (!empty($pv)) {
            $state = 'OK';
            $lines[] = '--prompt-verify の結果ファイルがあります:';
            foreach ($pv as $f) {
                $lines[] = '  ' . basename($f) . '  ' . (int) @filesize($f) . 'バイト  md5 '
                           . (string) @md5_file($f);
            }
            $lines[] = '保存済みの6分前オッズからいまのコードでお願い文を組み直し、';
            $lines[] = '監査ログに残る「実際に送った本文のSHA-256」と突き合わせたものです。';
            $lines[] = '一致していれば、v0.4-Xを入れても送信文が1バイトも変わっていない';
            $lines[] = 'ことの実測になります（判定はそのファイルの「1stの判定」欄）。';
        } else {
            $state = '一部';
            $lines[] = '--prompt-verify をまだ実行していません。';
            $lines[] = '  php artisan keiba:ai-analysis-compensate --prompt-verify --date=' . $date;
            $lines[] = 'を実行すると、送信文の一致をレースごとに確認できます（AI呼び出し0回）。';
            $lines[] = '⚠ 組み直しは6分前オッズを使うので、洗い替え（土曜5:50）より前に実行してください。';
        }
        $say('A4-8', 'V04_ENABLED=false のとき送信文が変わらないこと', $state, $lines);

        // ══════════════════════════════════════════════════════════════
        // まとめ
        // ══════════════════════════════════════════════════════════════
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' まとめ');
        $this->info('══════════════════════════════════════════════════════════');
        $cnt = ['OK' => 0, '一部' => 0, 'NG' => 0, '対象外' => 0];
        foreach ($res as $id => $r) {
            $cnt[$r['結果']] = ($cnt[$r['結果']] ?? 0) + 1;
            $this->line(sprintf('  %-7s %-44s %s', $id, $r['項目'], $r['結果']));
        }
        $this->line('');
        $this->line('  確認できた: ' . $cnt['OK'] . '件　一部: ' . $cnt['一部']
            . '件　確認できない: ' . $cnt['NG'] . '件　'
            . 'この日は対象外: ' . $cnt['対象外'] . '件');
        $this->line('');
        $this->warn('※「この日は対象外」は、その記録が始まる前のレースなので');
        $this->warn('　この日では確認できないものです。10/17・18のレースで確認します。');
        $this->warn('※「一部」「確認できない」の理由は各項目に書いています。');

        $jp = storage_path('logs/evidence_check_' . str_replace('-', '', $date) . '.json');
        @file_put_contents($jp, json_encode(
            ['確認日時' => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))
                               ->format('Y-m-d H:i:s') . ' JST',
             'date' => $date, '集計' => $cnt, '項目' => $res],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @chmod($jp, 0666);
        $this->info(' 書き出しました: ' . $jp);
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261015-PROMPT-DUMP  送信文の全文を書き出す（--prompt-dump）
     * ═════════════════════════════════════════════════════════════════════════
     * 10月15日（2回目）02.txtへの対応です。
     *   「現在AIに送信しているプロンプト全文と最新の AiController.php を
     *     もらえれば、なるが作ったv0.4と照合して、
     *     変更・欠落・追加箇所を確認するよ」
     *
     * ハッシュは「変わっていないこと」は示せますが、「何が書いてあるか」は
     * 示せません。照合には本文そのものが必要なので、全文を書き出します。
     *
     * ■ 書き出すもの
     *   1st_system_prompt.txt   1st AIへのシステム指示（全文・そのまま）
     *   1st_user_prompt_<レース>.txt
     *                           1st AIへ送る本文（全文・実レースのもの）
     *   2nd_system_prompt.txt   2nd AIへのシステム指示（全文・そのまま）
     *   2nd_build_rules.txt     2nd AIへ送る本文の作り方（本番コードの該当行）
     *   _index.json             上のファイルのバイト数とMD5
     *
     * ■ 2nd AIの本文について（できることとできないことを分けて書きます）
     *   2nd AIへ送る本文は、1st AIへ送る本文から決まった箇所を外し、
     *   さらに条件つきの追記（出走履歴の未収録分・2nd専用の出力指示）を
     *   足して組み立てます。追記はレースごとに中身が変わるので、
     *   このコマンドで本番とまったく同じ本文を再現することはできません。
     *   できないことはできないと書きます。
     *
     *   代わりに、本番コードの該当行をそのまま抜き出して
     *   2nd_build_rules.txt に入れます。1st AIの本文と合わせて読めば、
     *   2nd AIへ何が渡っているかを行単位で照合できます。
     *
     *   そして【10月17日以降は本文そのものが残ります】。
     *   送信直前に本文をファイルへ書くようにしました
     *   （@ANCHOR-20261015-PROMPT-SENT）。
     *     storage/logs/prompt_sent/<日付>/<レースID>_1st.txt
     *     storage/logs/prompt_sent/<日付>/<レースID>_1st_system.txt
     *     storage/logs/prompt_sent/<日付>/<レースID>_2nd.txt
     *     storage/logs/prompt_sent/<日付>/<レースID>_2nd_system.txt
     *   これは実際に送った本文そのものなので、組み立て直す必要がありません。
     *
     * ■ 安全性
     *   AIへは1回も送りません（費用0円）。DBへは1件も書きません。
     *   お願い文の中身は1文字も変えていません。
     *   $dryRunNoPersist = true で動かすので、監査ログも汚しません。
     *
     * ⚠ 1stの本文は6分前オッズから組み立てます。
     *   洗い替え（土曜5:50）より前のレースを指定してください。
     */
    private function runPromptDump(string $date): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(" 送信文の全文の書き出し  対象日 {$date}");
        $this->info('  （AI呼び出し0回・費用0円・DBへの書き込みなし）');
        $this->info('══════════════════════════════════════════════════════════');

        $dir = storage_path('logs/prompt_full_' . str_replace('-', '', $date));
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); @chmod($dir, 0777); }

        $file = (new \ReflectionClass(AiController::class))->getFileName();
        $src  = (string) @file_get_contents($file);
        if ($src === '') {
            $this->error('AiController.php を読めません: ' . $file);
            return;
        }
        $index = ['書き出し日時' => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))
                                      ->format('Y-m-d H:i:s') . ' JST',
                  '対象日' => $date,
                  'AiController.php' => ['bytes' => strlen($src), 'md5' => md5($src),
                                         '行数' => substr_count($src, "\n") + 1],
                  'ファイル' => []];
        $put = function (string $name, string $text) use ($dir, &$index) {
            $path = $dir . '/' . $name;
            @file_put_contents($path, $text);
            @chmod($path, 0666);
            $index['ファイル'][$name] = ['bytes' => strlen($text), 'md5' => md5($text),
                                          '行数' => substr_count($text, "\n") + 1];
            $this->line(sprintf('  %-42s %8dバイト  md5 %s',
                $name, strlen($text), md5($text)));
            return $path;
        };

        // ── ① 1st AIのシステム指示 ────────────────────────────────────
        // 本番コードの文字列リテラルをそのまま取り出します。
        $sys1 = '';
        if (preg_match("/\\\$firstAiSystemPrompt = '((?:[^'\\\\\\\\]|\\\\\\\\.)*)';/u", $src, $m)) {
            $sys1 = str_replace(["\\\\'", '\\\\\\\\'], ["'", '\\\\'], $m[1]);
        }
        if ($sys1 === '') {
            $this->error('  1st AIのシステム指示を取り出せませんでした。');
        } else {
            $put('1st_system_prompt.txt', $sys1);
        }

        // ── ② 2nd AIのシステム指示（heredoc）──────────────────────────
        $sys2 = '';
        $hi = strpos($src, "\$systemPrompt = <<<'SYSTEM'");
        if ($hi !== false) {
            $bodyStart = strpos($src, "\n", $hi) + 1;
            $bodyEnd   = strpos($src, "\nSYSTEM", $bodyStart);
            if ($bodyEnd !== false) $sys2 = substr($src, $bodyStart, $bodyEnd - $bodyStart);
        }
        if ($sys2 === '') {
            $this->error('  2nd AIのシステム指示を取り出せませんでした。');
        } else {
            $put('2nd_system_prompt.txt', $sys2);
        }

        // ── ③ 2nd AIの本文の作り方（本番コードの該当行）────────────────
        $rules = [];
        $rules[] = '2nd AIへ送る本文の作り方（' . basename($file) . ' の該当行をそのまま抜き出したもの）';
        $rules[] = '書き出し日時: ' . $index['書き出し日時'];
        $rules[] = str_repeat('=', 78);
        $rules[] = '';
        $rules[] = '2nd AIへ送る本文は、1st AIへ送る本文（1st_user_prompt_*.txt）を';
        $rules[] = '出発点にして、下の処理を【この順番で】当てて作ります。';
        $rules[] = '追記（.=）はレースの状況で中身が変わるので、';
        $rules[] = 'このファイルでは本番コードの行をそのまま載せています。';
        $rules[] = '';
        $si = strpos($src, '$b2ndStrip = function (string $subject');
        $ei = ($si === false) ? false : strpos($src, '$dsPayload = [', $si);
        if ($si === false || $ei === false) {
            $rules[] = '★該当範囲を取り出せませんでした。コードの構造が変わった可能性があります。';
        } else {
            $base = substr_count(substr($src, 0, $si), "\n") + 1;
            $seg  = explode("\n", substr($src, $si, $ei - $si));
            foreach ($seg as $no => $l) {
                $t = trim($l);
                if ($t === '') continue;
                $isOp = (bool) preg_match('/^\$oddsData\s*\.?=/', $t)
                        || strpos($t, 'preg_replace') !== false
                        || strpos($t, '$b2ndStrip = function') === 0;
                $isNote = (strpos($t, '//') === 0);
                if (!$isOp && !$isNote) continue;
                $rules[] = sprintf('%6d  %s', $base + $no, $l);
            }
        }
        $put('2nd_build_rules.txt', implode("\n", $rules) . "\n");

        // ── ④ 1st AIへ送る本文（実レース）─────────────────────────────
        $merges = DB::table('t_horse_odds_finder_ai_merge_result')
            ->where('date', $date)
            ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
            ->get();
        if ($merges->isEmpty()) {
            $this->warn("  {$date} の統合結果がありません。1st AIの本文は書き出せません。");
        } else {
            $only = (string) ($this->option('race') ?: '');
            $ctl  = app(AiController::class);
            $ok = 0; $ng = 0;
            foreach ($merges as $mg) {
                if ($only !== '' && (string) $mg->race !== $only) continue;
                $label = "{$mg->kaisuu}回{$mg->basho_code}_{$mg->day}日目_"
                         . sprintf('%02d', $mg->race) . 'R';
                $before = AiController::$dryRunNoPersist;
                AiController::$dryRunNoPersist = true;
                $txt = null; $err = '';
                try {
                    $txt = $ctl->v04BuildPromptForCheck(
                        $date, $mg->kaisuu, $mg->basho_code, $mg->day, $mg->race);
                } catch (\Throwable $e) {
                    $err = mb_substr($e->getMessage(), 0, 60);
                } finally {
                    AiController::$dryRunNoPersist = $before;
                }
                if ($txt === null || $txt === '') {
                    $ng++;
                    $this->error(sprintf('  %-42s 組み立て不可 %s', $label,
                        ($err !== '') ? ('（' . $err . '）') : '（6分前オッズが無い可能性）'));
                    continue;
                }
                $put('1st_user_prompt_' . $label . '.txt', $txt);
                $ok++;
            }
            $this->line('');
            $this->line('  1st AIの本文: ' . $ok . 'レース書き出し／' . $ng . 'レース不可');
        }

        @file_put_contents($dir . '/_index.json', json_encode(
            $index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @chmod($dir . '/_index.json', 0666);

        $this->line('');
        $this->info('  書き出し先: ' . $dir);
        $this->info('  AiController.php: ' . $index['AiController.php']['行数'] . '行　md5 '
            . $index['AiController.php']['md5']);
        $this->line('');
        $this->warn('※1st AIのシステム指示と本文、2nd AIのシステム指示は【全文そのまま】です。');
        $this->warn('※2nd AIの本文は、1st AIの本文に 2nd_build_rules.txt の処理を');
        $this->warn('　当てて作ります。条件つきの追記があるので、このコマンドでは');
        $this->warn('　本番とまったく同じ本文を再現できません。できないと書いておきます。');
        $this->warn('※10月17日以降は、送信直前に本文そのものを');
        $this->warn('　storage/logs/prompt_sent/<日付>/ へ残します（1st・2nd両方）。');
        $this->warn('　そちらは実際に送った本文そのものなので、組み立て直す必要がありません。');
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261015-WAVE  波乱度判定の一致率と断層（--wave-accuracy）
     * ═════════════════════════════════════════════════════════════════════════
     * 10月15日（2回目）01.txt【3】①④への対応です。
     *   「① レースの事前波乱度判定と実際の結果の一致率」
     *   「④ 断層の位置・本数・変化とレース全体の波乱傾向」
     *
     * これまで断層は【1頭ごと】に見ていました（第44章 44-4・第45章 45-3）。
     * そこでは好走と凡走をほとんど分けていませんでした。
     * ご指摘のとおり、断層は【レース全体が荒れるか】の指標として見るべきなので、
     * レース単位で集計し直します。
     *
     * ■ 「荒れた」の定義（この集計で使うもの）
     *   3着内に7番人気以下が1頭以上入ったレースを「荒れた」とします。
     *   第41章以降の「荒れ率」と同じ定義です。
     *
     * ■ 出すもの
     *   ① 波乱度ごとの荒れ率と、事前判定の一致率（クロス表つき）
     *      「波乱度3以上を荒れる予想」とみなしたときの的中率・取りこぼし率
     *   ② 断層タイプ（A〜E）ごとの荒れ率
     *   ③ 6番人気以内の断層の本数ごとの荒れ率
     *   ④ レース全体の断層の本数ごとの荒れ率
     *   ⑤ 主断層（6番人気以内でいちばん比率が大きい箇所）の位置ごとの荒れ率
     *   ⑥ 主断層の時系列変化（縮小／拡大／横ばい）ごとの荒れ率
     *   ⑦ 厳選穴レース該当ごとの荒れ率
     *
     * 読み取りだけです。DBへの書き込みもAI呼び出しもありません。
     *
     * ⚠ 断層は6分前オッズから計算します。
     *   洗い替え（土曜5:50）より前に実行してください。
     */
    private function runWaveAccuracy(string $dateArg): void
    {
        $dates = array_values(array_filter(array_map('trim', explode(',', $dateArg))));
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' 波乱度判定の一致率と、断層とレース全体の波乱傾向');
        $this->info(' 対象日 ' . implode(' / ', $dates));
        $this->info('  （読み取りだけ・DBへの書き込みなし・AI呼び出し0回）');
        $this->info('══════════════════════════════════════════════════════════');
        $this->line(' 「荒れた」= 3着内に7番人気以下が1頭以上入ったレース');

        $races = [];   // レースごとの1行

        foreach ($dates as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->error("日付の書式が不正です: {$date}");
                continue;
            }
            $merges = DB::table('t_horse_odds_finder_ai_merge_result')
                ->where('date', $date)
                ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
                ->get();
            if ($merges->isEmpty()) { $this->warn("{$date} の統合結果がありません。"); continue; }

            $resMap = [];
            foreach (DB::table('t_horse_odds_finder_race_results')->where('date', $date)->get() as $r) {
                $resMap[$r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race][(int) $r->num]
                    = (int) $r->result;
            }
            $timings = [\App\Constants\Constants::ODDS_DB_FIRST, 21, 18, 15, 12, 9, 6];
            $series = [];
            foreach (DB::table('t_horse_odds_finder_odds')
                        ->where('date', $date)
                        ->whereIn('minutes_before_start', $timings)
                        ->get(['kaisuu', 'basho', 'day', 'race', 'num',
                               'minutes_before_start', 'odds', 'fuku_min']) as $r) {
                $k = $r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race;
                $t = (int) $r->minutes_before_start; $n = (int) $r->num;
                if (is_numeric($r->odds)     && (float) $r->odds     > 0) $series[$k][$n]['tan'][$t]  = (float) $r->odds;
                if (is_numeric($r->fuku_min) && (float) $r->fuku_min > 0) $series[$k][$n]['fuku'][$t] = (float) $r->fuku_min;
            }
            if (empty($series)) {
                $this->warn("{$date} の6分前オッズがありません（洗い替え済みの可能性）。この日は飛ばします。");
                continue;
            }

            foreach ($merges as $mg) {
                $dec = json_decode((string) ($mg->merge_result ?? ''), true);
                if (!is_array($dec)) continue;
                if ((string) ($dec['status'] ?? '') === 'undetermined') continue;
                $key = $mg->kaisuu . '|' . $mg->basho_code . '|' . $mg->day . '|' . $mg->race;
                $results = $resMap[$key] ?? [];
                $sr      = $series[$key] ?? [];
                if (empty($results) || empty($sr)) continue;
                $info = $this->longshotRaceInfo($sr);
                $pops = $info['pop'] ?? [];
                if (empty($pops)) continue;

                // 荒れたか
                $rough = false; $lsHit = 0;
                foreach ($results as $hn => $pos) {
                    if ($pos < 1 || $pos > 3) continue;
                    $pp = $pops[$hn] ?? null;
                    if ($pp !== null && $pp >= 7) { $rough = true; $lsHit++; }
                }

                // 主断層（6番人気以内でいちばん比率が大きい箇所）
                $mainK = null; $mainR = null;
                foreach (($info['ratio6'] ?? []) as $k2 => $r2) {
                    if ($k2 > 5) continue;                    // 6番人気以内の隣接だけ
                    if ($mainR === null || $r2 > $mainR) { $mainR = $r2; $mainK = $k2; }
                }
                // 主断層の時系列変化（その境界の比率を期間で追う）
                $trend = '断層なし';
                if ($mainK !== null && $mainR !== null && $mainR >= 2.00) {
                    $order = $info['order'];
                    $dn = $order[$mainK] ?? null;             // 断層の下側先頭の馬
                    if ($dn !== null) {
                        $g = $this->longshotGapFor($info, (int) $dn);
                        $trend = (string) $g['trend'];
                    } else {
                        $trend = '不明';
                    }
                }

                $races[] = [
                    '日付' => $date,
                    'レース' => "{$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R",
                    '波乱度' => ($mg->wave_level === null) ? 0 : (int) $mg->wave_level,
                    '下位進入度' => ($mg->lower_entry === null) ? null : (int) $mg->lower_entry,
                    '大穴進入度' => ($mg->big_gap_entry === null) ? null : (int) $mg->big_gap_entry,
                    '厳選穴レース' => ($mg->upset_race === null)
                                        ? null : ((int) $mg->upset_race === 1),
                    '断層タイプ' => (string) ($mg->gap_type ?? '－'),
                    '断層本数_6番人気以内' => (int) ($info['gapTop6'] ?? 0),
                    '断層本数_全体' => (int) ($info['gapAll'] ?? 0),
                    '主断層の位置' => ($mainK === null || $mainR === null || $mainR < 2.00)
                                        ? 'なし' : ($mainK . '-' . ($mainK + 1)),
                    '主断層の比率' => ($mainR === null) ? null : $mainR,
                    '主断層の時系列変化' => $trend,
                    '出走頭数' => (int) ($info['N'] ?? 0),
                    '荒れた' => $rough,
                    '人気薄の好走頭数' => $lsHit,
                ];
            }
        }

        if (empty($races)) {
            $this->error('集計できるレースがありません。');
            return;
        }
        $total = count($races);
        $roughTotal = count(array_filter($races, static fn($r) => $r['荒れた']));

        $this->line('');
        $this->info('  対象レース: ' . $total . '　うち荒れたレース: ' . $roughTotal
            . sprintf('（%.1f%%）', $roughTotal / $total * 100));

        // ── 集計の共通部品 ────────────────────────────────────────────
        $byKey = function (string $label, callable $f) use ($races, $total) {
            $g = [];
            foreach ($races as $r) {
                $k = (string) $f($r);
                if (!isset($g[$k])) $g[$k] = ['n' => 0, 'rough' => 0, 'ls' => 0];
                $g[$k]['n']++;
                if ($r['荒れた']) $g[$k]['rough']++;
                $g[$k]['ls'] += $r['人気薄の好走頭数'];
            }
            ksort($g, SORT_NATURAL);
            $this->line('');
            $this->info('── ' . $label . ' ──────────────────────────');
            $this->line(sprintf('    %-18s %7s %9s %9s %14s', '区分', 'レース', '荒れた',
                '荒れ率', '人気薄の好走'));
            foreach ($g as $k => $v) {
                $this->line(sprintf('    %-18s %7d %9d %8.1f%% %12d頭', $k,
                    $v['n'], $v['rough'], $v['rough'] / $v['n'] * 100, $v['ls']));
            }
            return $g;
        };

        // ── ① 波乱度ごとの荒れ率と事前判定の一致率 ────────────────────
        $byKey('① 波乱度ごとの荒れ率', static fn($r) => '波乱度' . ($r['波乱度'] > 0 ? $r['波乱度'] : '－'));

        $tp = 0; $fp = 0; $fn = 0; $tn = 0;   // 波乱度3以上を「荒れる予想」とみなす
        foreach ($races as $r) {
            $pred = ($r['波乱度'] >= 3);
            if ($pred && $r['荒れた'])        $tp++;
            elseif ($pred && !$r['荒れた'])   $fp++;
            elseif (!$pred && $r['荒れた'])   $fn++;
            else                               $tn++;
        }
        $this->line('');
        $this->info('── ①-2 「波乱度3以上を荒れる予想」とみなしたときの一致率 ─────');
        $this->line('                        実際に荒れた   荒れなかった');
        $this->line(sprintf('    波乱度3以上と判定 %10d %14d', $tp, $fp));
        $this->line(sprintf('    波乱度2以下と判定 %10d %14d', $fn, $tn));
        $this->line('');
        $this->line(sprintf('    全体の一致率（当たった割合）  : %.1f%%（%d／%d）',
            ($tp + $tn) / $total * 100, $tp + $tn, $total));
        $this->line(sprintf('    荒れると判定したうち実際に荒れた: %s',
            ($tp + $fp) > 0 ? sprintf('%.1f%%（%d／%d）', $tp / ($tp + $fp) * 100, $tp, $tp + $fp) : '－'));
        $this->line(sprintf('    実際に荒れたうち判定できていた  : %s',
            ($tp + $fn) > 0 ? sprintf('%.1f%%（%d／%d）', $tp / ($tp + $fn) * 100, $tp, $tp + $fn) : '－'));
        $this->line(sprintf('    荒れなかったのに荒れると判定    : %s',
            ($fp + $tn) > 0 ? sprintf('%.1f%%（%d／%d）', $fp / ($fp + $tn) * 100, $fp, $fp + $tn) : '－'));
        $this->line('');
        $this->warn('    ※この一致率は「荒れるかどうか」の2値に直した見方です。');
        $this->warn('    　波乱度はもともと1〜5の段階なので、段階ごとの荒れ率（①）と');
        $this->warn('    　あわせて見てください。');

        // ── ②〜⑦ ─────────────────────────────────────────────────────
        $byKey('② 断層タイプごとの荒れ率', static fn($r) => '断層' . $r['断層タイプ']);
        $byKey('③ 6番人気以内の断層の本数ごとの荒れ率',
               static fn($r) => $r['断層本数_6番人気以内'] . '本');
        $byKey('④ レース全体の断層の本数ごとの荒れ率',
               static fn($r) => $r['断層本数_全体'] . '本');
        $byKey('⑤ 主断層の位置ごとの荒れ率（何番人気の間か）',
               static fn($r) => $r['主断層の位置']);
        $byKey('⑥ 主断層の時系列変化ごとの荒れ率',
               static fn($r) => $r['主断層の時系列変化']);
        $byKey('⑦ 厳選穴レース該当ごとの荒れ率',
               static fn($r) => ($r['厳選穴レース'] === null)
                                  ? '不明' : ($r['厳選穴レース'] ? '該当' : '非該当'));
        $byKey('（参考）下位進入度ごとの荒れ率',
               static fn($r) => '下位進入度' . (($r['下位進入度'] === null) ? '－' : $r['下位進入度']));
        $byKey('（参考）大穴進入度ごとの荒れ率',
               static fn($r) => '大穴進入度' . (($r['大穴進入度'] === null) ? '－' : $r['大穴進入度']));

        // ── 書き出し ──────────────────────────────────────────────────
        $head = ['日付', 'レース', '波乱度', '下位進入度', '大穴進入度', '厳選穴レース',
                 '断層タイプ', '断層本数_6番人気以内', '断層本数_全体',
                 '主断層の位置', '主断層の比率', '主断層の時系列変化',
                 '出走頭数', '荒れた', '人気薄の好走頭数'];
        $name = 'wave_accuracy_' . str_replace(',', '_', str_replace('-', '', $dateArg)) . '.csv';
        $path = storage_path('logs/' . $name);
        $fh = @fopen($path, 'w');
        if ($fh === false) { $this->error('CSVを書けません: ' . $path); return; }
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $head);
        foreach ($races as $r) {
            fputcsv($fh, [$r['日付'], $r['レース'],
                          $r['波乱度'] > 0 ? $r['波乱度'] : '',
                          $r['下位進入度'], $r['大穴進入度'],
                          ($r['厳選穴レース'] === null) ? '' : ($r['厳選穴レース'] ? '該当' : '非該当'),
                          $r['断層タイプ'], $r['断層本数_6番人気以内'], $r['断層本数_全体'],
                          $r['主断層の位置'], $r['主断層の比率'], $r['主断層の時系列変化'],
                          $r['出走頭数'], $r['荒れた'] ? '荒れた' : '荒れなかった',
                          $r['人気薄の好走頭数']]);
        }
        fclose($fh);
        @chmod($path, 0666);
        $this->line('');
        $this->info('  書き出しました: ' . $path . '（' . $total . '行）');

        $jp = storage_path('logs/wave_accuracy_'
            . str_replace(',', '_', str_replace('-', '', $dateArg)) . '.json');
        @file_put_contents($jp, json_encode(
            ['対象日' => $dates, 'レース数' => $total, '荒れたレース' => $roughTotal,
             '一致率' => ['波乱度3以上と判定して荒れた' => $tp,
                          '波乱度3以上と判定して荒れなかった' => $fp,
                          '波乱度2以下と判定して荒れた' => $fn,
                          '波乱度2以下と判定して荒れなかった' => $tn],
             'レース別' => $races],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @chmod($jp, 0666);
        $this->info('  書き出しました: ' . $jp);
        $this->line('');
        $this->warn('※断層は6分前の単勝オッズを人気順に並べ、隣の比率が2.00以上の箇所です');
        $this->warn('　（本番の判定と同じ閾値）。主断層は6番人気以内でいちばん比率が大きい箇所です。');
        $this->warn('※時系列変化は、6分前が期間中の最大比率から10%以上下がっていれば「縮小」、');
        $this->warn('　計測開始前から10%以上上がっていれば「拡大」です（本番の断層縮小と同じ10%）。');
    }







    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261014-PRECHECK  切り替え前の確認（--switch-precheck）
     * ═════════════════════════════════════════════════════════════════════════
     * 10月14日（6回目）のご指摘①③への対応です。
     *   「切り替え前に、A方式への即時復帰、データ保全、異常入力時の動作を
     *     確認してください」
     *   「現行プロンプトは一切変更せず、比較基準として保存してください」
     *
     * 次を順に確認し、現行プロンプトを比較基準として保存します。
     *   ① いまの設定値（補充方式とv0.4の5スイッチ）
     *   ② A方式への即時復帰の手順と、戻したときに元の動きになること
     *   ③ 異常入力時の動作（受入試験のAB項目の結果）
     *   ④ データ保全（検証用コマンドがDBへ書き込まないこと）
     *   ⑤ 現行プロンプトの保存（全レース分のMD5一覧をファイルに残す）
     */
    private function runSwitchPrecheck(string $date): void
    {
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(" B方式へ切り替える前の確認  対象日 {$date}");
        $this->info('  （読み取りだけ・DBへの書き込みなし・AI呼び出し0回）');
        $this->info('══════════════════════════════════════════════════════════');

        // ── ① 設定値 ──────────────────────────────────────────────────
        $this->line('');
        $this->info('── ① いまの設定値 ──────────────────────────');
        $this->line('  補充方式（BAGANRIKI_FILL_MODE）: ' . AiController::currentFillMode()
            . '　既定 ' . AiController::FILL_MODE_DEFAULT);
        foreach (AiController::v04SwitchReport() as $k => $v) {
            $this->line(sprintf('  %-34s %s', $k, $v));
        }

        // ── ② 復帰 ────────────────────────────────────────────────────
        $this->line('');
        $this->info('── ② A方式への即時復帰 ──────────────────────');
        $this->line('  Bにする  : .env に BAGANRIKI_FILL_MODE=B を足して php artisan config:clear');
        $this->line('  Aへ戻す  : その行を A にする（または行を削除する）→ php artisan config:clear');
        $this->line('  既定値が A なので、行が無ければ現行の動きになります。');
        $this->line('  保存済みの統合結果は書き換えないので、戻しても記録は残ります。');
        $this->line('  どちらの方式で処理したレースかは、統合の監査ログの「補充方式」に残ります。');
        // 実際に切り替えて戻し、同じ結果になることをこのプロセス内で確かめる
        $before = AiController::currentFillMode();
        AiController::forceFillMode('B');
        $mid = AiController::currentFillMode();
        AiController::forceFillMode(null);
        $after = AiController::currentFillMode();
        if ($before === $after && $mid === 'B') {
            $this->info("  → 切り替え（{$before}→{$mid}）と復帰（{$mid}→{$after}）を確認しました");
        } else {
            $this->error("  → 復帰を確認できませんでした（{$before}→{$mid}→{$after}）");
        }

        // ── ③ 異常入力時の動作 ────────────────────────────────────────
        $this->line('');
        $this->info('── ③ 異常入力時の動作 ──────────────────────');
        $t = AiController::v04SelfTest();
        $ab = ['pass' => 0, 'fail' => 0];
        foreach ((array) ($t['results'] ?? []) as $r) {
            if (strpos((string) ($r['id'] ?? ''), 'AB-T') !== 0) continue;
            if (((string) ($r['結果'] ?? '')) === 'PASS') $ab['pass']++; else $ab['fail']++;
        }
        $this->line('  受入試験 全体: ' . (int) ($t['pass'] ?? 0) . ' 合格 / '
            . (int) ($t['fail'] ?? 0) . ' 不合格');
        $this->line('  うち異常入力（AB-T**）: ' . $ab['pass'] . ' 合格 / ' . $ab['fail'] . ' 不合格');
        if ((int) ($t['fail'] ?? 0) === 0 && $ab['fail'] === 0 && $ab['pass'] > 0) {
            $this->info('  → 壊れた入力でも例外で落ちず、安全側へ倒れることを確認しました');
        } else {
            $this->error('  → 不合格があります。切り替え前に直してください');
        }
        $this->line('  修正済みの異常入力の指摘: ' . count(AiController::V04_ABNORMAL_FIX_LOG)
            . '件（--abnormal-status で明細）');

        // ── ④ データ保全 ──────────────────────────────────────────────
        $this->line('');
        $this->info('── ④ データ保全 ──────────────────────────────');
        $this->line('  検証用のコマンド（--fill-compare／--fill-mode-proof／--weekend-report／');
        $this->line('  --missed-analysis／--v04-test／--abnormal-status／このコマンド）は');
        $this->line('  すべて読み取り専用です。DBへの書き込みは1件もしていません。');
        $this->line('  オッズ・着順・払戻・AI回答はいずれも読むだけで、書き換えていません。');
        $this->line('  補充方式を切り替えても、過去に保存した統合結果は書き換わりません。');

        // ── ⑤ 現行プロンプトの保存 ────────────────────────────────────
        $this->line('');
        $this->info('── ⑤ 現行プロンプトを比較基準として保存 ──────────');
        $merges = DB::table('t_horse_odds_finder_ai_merge_result')
            ->where('date', $date)
            ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
            ->get();
        if ($merges->isEmpty()) {
            $this->warn("  {$date} の統合結果がないため、プロンプトの保存は行いません。");
        } else {
            $dir = storage_path('logs/prompt_baseline_' . $date);
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); @chmod($dir, 0777); }
            $list = []; $ok = 0; $ng = 0;
            foreach ($merges as $mg) {
                $label = "{$mg->kaisuu}回{$mg->basho_code}_{$mg->day}日目_" . sprintf('%02d', $mg->race) . 'R';
                try {
                    AiController::forceFillMode(null);   // 既定（現行）で組み立てる
                    $ctl = app(AiController::class);
                    $txt = $ctl->v04BuildPromptForCheck(
                        $date, $mg->kaisuu, $mg->basho_code, $mg->day, $mg->race);
                    if ($txt === null || $txt === '') { $ng++; $list[$label] = '組み立て不可'; continue; }
                    $file = $dir . '/' . $label . '.txt';
                    @file_put_contents($file, $txt);
                    @chmod($file, 0666);
                    $list[$label] = ['bytes' => strlen($txt), 'md5' => md5($txt)];
                    $ok++;
                } catch (\Throwable $e) {
                    $ng++;
                    $list[$label] = '例外: ' . mb_substr($e->getMessage(), 0, 60);
                }
            }
            @file_put_contents($dir . '/_md5list.json', json_encode(
                ['date' => $date, '補充方式' => AiController::currentFillMode(), '一覧' => $list],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            $this->line("  保存先: {$dir}");
            $this->line("  保存できたレース: {$ok}　できなかったレース: {$ng}");
            $this->line('  MD5一覧: ' . $dir . '/_md5list.json');
            foreach ($list as $k => $v) {
                $this->line(sprintf('    %-24s %s', $k,
                    is_array($v) ? ($v['bytes'] . 'バイト　md5 ' . $v['md5']) : $v));
            }
            $this->info('  → この送信文が現行の比較基準です。AIへは送っていません（費用0円）。');
        }

        $this->line('');
        $this->warn('※このコマンドは設定を変更しません。切り替えは .env を手で直してください。');
    }


    /**
     * ═════════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261014-CSV  A/B/C比較のCSV（--compare-csv）
     * ═════════════════════════════════════════════════════════════════════════
     * 10月14日の確認依頼書⑤への対応です。
     *   「10/10〜12の72レースについて、A/B/C各方式の公開候補、3着内・5着内捕捉、
     *     人気薄捕捉、購入推奨対象、購入額、複勝回収率を同じ母集団で比較したCSV」
     *
     * 同じレース・同じスナップショット・同じAI回答から3方式を算出し、
     * 1レース1方式を1行としてCSVに書き出します。
     * --date=2026-10-10,2026-10-11,2026-10-12 のようにカンマ区切りで複数日を渡せます。
     * 読み取りだけで、AI呼び出しもDBへの書き込みもありません。
     *
     * 購入額は「1頭100円ずつ」の想定です（馬眼力には購入金額を決める処理がありません）。
     * 補充枠の馬は購入推奨に入れないため、購入推奨の列はA・B・Cで同じ値になります。
     */
    private function runCompareCsv(string $dateArg): void
    {
        $dates = array_values(array_filter(array_map('trim', explode(',', $dateArg))));
        $this->info('══════════════════════════════════════════════════════════');
        $this->info(' A/B/C比較のCSV書き出し  対象日 ' . implode('・', $dates));
        $this->info('  （読み取りだけ・DBへの書き込みなし・AI呼び出し0回）');
        $this->info('══════════════════════════════════════════════════════════');

        $head = ['日付', 'race_id', 'レース', '波乱度', '下位進入度', '大穴進入度',
                 '厳選穴レース', '断層タイプ', '配分モード', '目標配分', '目標数C',
                 '出走頭数', '方式', '公開頭数', '補充頭数',
                 '公開のうち1〜6番人気', '公開のうち7〜10番人気', '公開のうち11番人気以下',
                 '公開3着内', '公開5着内',
                 'レースの7番人気以下好走頭数', 'うち公開候補に入れていた頭数',
                 '購入推奨頭数', '購入推奨3着内', '購入額（円）', '複勝払戻（円）', '複勝回収率(%)',
                 'snapshot_id', '送信文md5(1st)', '送信文md5(2nd)'];
        $rows = [];
        $sum  = [];

        foreach ($dates as $date) {
            $merges = DB::table('t_horse_odds_finder_ai_merge_result')
                ->where('date', $date)
                ->orderBy('kaisuu')->orderBy('basho_code')->orderBy('day')->orderBy('race')
                ->get();
            if ($merges->isEmpty()) {
                $this->warn("  {$date}: 統合結果がありません（飛ばします）");
                continue;
            }

            $resMap = [];
            foreach (DB::table('t_horse_odds_finder_race_results')->where('date', $date)->get() as $r) {
                $resMap[$r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race][(int) $r->num]
                    = (int) $r->result;
            }
            $payMap = [];
            foreach (DB::table('t_horse_odds_finder_race_result_payout')->where('date', $date)->get() as $r) {
                $k = $r->kaisuu . '|' . $r->basho_code . '|' . $r->day . '|' . $r->race;
                foreach (explode('/', (string) ($r->fuku ?? '')) as $piece) {
                    $pair = explode('|', trim($piece));
                    if (count($pair) === 2 && ctype_digit(trim($pair[0]))) {
                        $payMap[$k][(int) trim($pair[0])] = (int) preg_replace('/\D/', '', $pair[1]);
                    }
                }
            }
            $popMap = []; $tmp = [];
            foreach (DB::table('t_horse_odds_finder_odds')
                         ->where('date', $date)->where('minutes_before_start', 6)->get() as $r) {
                if (is_numeric($r->odds) && (float) $r->odds > 0) {
                    $tmp[$r->kaisuu . '|' . $r->basho . '|' . $r->day . '|' . $r->race][(int) $r->num]
                        = (float) $r->odds;
                }
            }
            foreach ($tmp as $k => $byNum) {
                asort($byNum); $rank = 0;
                foreach (array_keys($byNum) as $num) { $rank++; $popMap[$k][$num] = $rank; }
            }

            foreach ($merges as $mg) {
                $dec = json_decode((string) ($mg->merge_result ?? ''), true);
                if (!is_array($dec)) continue;
                if ((string) ($dec['status'] ?? '') === 'undetermined') continue;
                $audit = is_array($dec['audit_merge'] ?? null) ? $dec['audit_merge'] : [];
                $zento = is_array($audit['全頭'] ?? null) ? $audit['全頭'] : [];
                $targetC = (int) ($audit['目標数C'] ?? 0);
                if (empty($zento) || $targetC <= 0) continue;

                $label  = "{$mg->kaisuu}回{$mg->basho_code} {$mg->day}日目 {$mg->race}R";
                $raceId = "{$date}_{$mg->kaisuu}_{$mg->basho_code}_{$mg->day}_{$mg->race}";
                $key    = $mg->kaisuu . '|' . $mg->basho_code . '|' . $mg->day . '|' . $mg->race;
                $results = $resMap[$key] ?? [];
                $pays    = $payMap[$key] ?? [];
                $pops    = $popMap[$key] ?? [];
                $sn      = is_array($dec['snapshot'] ?? null) ? $dec['snapshot'] : [];
                $ph      = is_array($audit['送信文ハッシュ'] ?? null) ? $audit['送信文ハッシュ'] : [];

                $pubNums = []; $flag = [];
                foreach ((is_array($dec['merged_horses'] ?? null) ? $dec['merged_horses'] : []) as $h) {
                    if (!isset($h['num'])) continue;
                    $hn = (int) $h['num'];
                    $pubNums[] = $hn;
                    $flag[$hn] = (!empty($h['below_min_score']) || !empty($h['buy_avoid_longshot'])
                                  || !empty($h['buy_avoid_low_payout']) || !empty($h['buy_avoid_recovery']));
                }
                sort($pubNums);

                // レース全体で3着内に来た7番人気以下
                $lsTop3 = [];
                foreach ($results as $hn => $pos) {
                    if ($pos < 1 || $pos > 3) continue;
                    $pp = $pops[$hn] ?? null;
                    if ($pp !== null && $pp >= 7) $lsTop3[] = (int) $hn;
                }

                $fill = AiController::v04FillCandidates($zento, $pubNums, $targetC, $results);
                $sets = ['A' => [[], $pubNums]];
                $bN = $pubNums; foreach ($fill['B'] as $v) $bN[] = (int) $v; sort($bN);
                $sets['B'] = [$fill['B'], $bN];
                if ($fill['C'] !== null) {
                    $cN = $pubNums; foreach ($fill['C'] as $v) $cN[] = (int) $v; sort($cN);
                    $sets['C'] = [$fill['C'], $cN];
                } else {
                    $sets['C'] = null;   // 独立根拠の記録が無い日は算出不可
                }

                foreach ($sets as $mode => $set) {
                    if ($set === null) {
                        $rows[] = [$date, $raceId, $label,
                            $mg->wave_level, $mg->lower_entry, $mg->big_gap_entry,
                            $mg->upset_race, $mg->gap_type,
                            $audit['配分モード'] ?? '', $audit['目標配分'] ?? '', $targetC,
                            count($zento), 'C', '算出不可（独立根拠の記録なし）', '',
                            '', '', '', '', '', count($lsTop3), '', '', '', '', '', '',
                            (string) ($sn['merge_snapshot_id'] ?? ''),
                            (string) ($ph['1st']['md5'] ?? ''), (string) ($ph['2nd']['md5'] ?? '')];
                        continue;
                    }
                    [$add, $nums] = $set;
                    $fav = 0; $mid = 0; $low = 0; $t3 = 0; $t5 = 0;
                    $reco = 0; $recoT3 = 0; $recoPay = 0; $caught = 0;
                    foreach ($nums as $hn) {
                        $row = $zento[(string) $hn] ?? ($zento[$hn] ?? []);
                        $pop = (int) ($row['人気'] ?? 0);
                        if ($pop >= 1 && $pop <= 6)      $fav++;
                        elseif ($pop >= 7 && $pop <= 10) $mid++;
                        elseif ($pop >= 11)              $low++;
                        $pos = $results[$hn] ?? null;
                        if ($pos !== null && $pos <= 3) $t3++;
                        if ($pos !== null && $pos <= 5) $t5++;
                        if (in_array($hn, $lsTop3, true)) $caught++;
                        if (in_array($hn, $add, true)) continue;          // 補充枠は購入推奨外
                        if ($flag[$hn] ?? false) continue;                // 購入回避フラグ
                        $reco++;
                        $recoPay += (int) ($pays[$hn] ?? 0);
                        if ($pos !== null && $pos <= 3) $recoT3++;
                    }
                    $buy = $reco * 100;
                    $rows[] = [$date, $raceId, $label,
                        $mg->wave_level, $mg->lower_entry, $mg->big_gap_entry,
                        $mg->upset_race, $mg->gap_type,
                        $audit['配分モード'] ?? '', $audit['目標配分'] ?? '', $targetC,
                        count($zento), $mode, count($nums), count($add),
                        $fav, $mid, $low, $t3, $t5,
                        count($lsTop3), $caught,
                        $reco, $recoT3, $buy, $recoPay,
                        $buy > 0 ? round($recoPay / $buy * 100, 1) : '',
                        (string) ($sn['merge_snapshot_id'] ?? ''),
                        (string) ($ph['1st']['md5'] ?? ''), (string) ($ph['2nd']['md5'] ?? '')];

                    if (!isset($sum[$mode])) {
                        $sum[$mode] = ['レース' => 0, '公開' => 0, '補充' => 0, '3着内' => 0,
                                       '5着内' => 0, '好走人気薄' => 0, '捕捉' => 0,
                                       '推奨' => 0, '推奨3着内' => 0, '購入額' => 0, '払戻' => 0];
                    }
                    $sum[$mode]['レース']++;
                    $sum[$mode]['公開']       += count($nums);
                    $sum[$mode]['補充']       += count($add);
                    $sum[$mode]['3着内']      += $t3;
                    $sum[$mode]['5着内']      += $t5;
                    $sum[$mode]['好走人気薄'] += count($lsTop3);
                    $sum[$mode]['捕捉']       += $caught;
                    $sum[$mode]['推奨']       += $reco;
                    $sum[$mode]['推奨3着内']  += $recoT3;
                    $sum[$mode]['購入額']     += $buy;
                    $sum[$mode]['払戻']       += $recoPay;
                }
            }
        }

        if (empty($rows)) {
            $this->error('書き出す行がありません。');
            return;
        }

        $name = 'compare_' . str_replace(',', '_', str_replace('-', '', $dateArg)) . '.csv';
        $path = storage_path('logs/' . $name);
        $fh = @fopen($path, 'w');
        if ($fh === false) {
            $this->error('CSVを書けません: ' . $path);
            return;
        }
        fwrite($fh, "\xEF\xBB\xBF");                 // ExcelでそのままUTF-8で開けるように
        fputcsv($fh, $head);
        foreach ($rows as $r) fputcsv($fh, $r);
        fclose($fh);
        @chmod($path, 0666);

        $this->line('');
        $this->info('── 方式ごとの合計 ──────────────────────────────');
        $this->line('  方式 レース 公開 補充 3着内 5着内 人気薄好走 捕捉 捕捉率 購入推奨 購入額 払戻 複勝回収率');
        foreach (['A', 'B', 'C'] as $m) {
            if (!isset($sum[$m])) { $this->line("  {$m}    算出できた行がありません"); continue; }
            $v = $sum[$m];
            $this->line(sprintf('  %s %5d %5d %4d %5d %5d %9d %5d %6s %8d %7d %6d %s',
                $m, $v['レース'], $v['公開'], $v['補充'], $v['3着内'], $v['5着内'],
                $v['好走人気薄'], $v['捕捉'],
                $v['好走人気薄'] > 0 ? sprintf('%.1f%%', $v['捕捉'] / $v['好走人気薄'] * 100) : '－',
                $v['推奨'], $v['購入額'], $v['払戻'],
                $v['購入額'] > 0 ? sprintf('%.1f%%', $v['払戻'] / $v['購入額'] * 100) : '－'));
        }
        $this->line('');
        $this->warn('※「人気薄好走」はレース全体で3着内に来た7番人気以下の頭数です（同じ母集団）。');
        $this->warn('※購入額は1頭100円ずつの想定です。購入金額を決める処理は馬眼力にありません。');
        $this->warn('※購入推奨・購入額・払戻・複勝回収率は、補充枠を購入推奨に入れないためA・B・Cで同じ値になります。');
        $this->info('CSVを書き出しました: ' . $path . '（' . count($rows) . '行）');
    }

}
