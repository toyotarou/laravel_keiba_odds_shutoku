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
    // 試験・確認用のコマンド（--prediction-○○-test など）は、下の3つのファイルに分けて置いています
    //   AiAnalysisCompensate/PredictionExpectedVersionChecks.php  E3・E5を使う版の確認（--prediction-expected-○○）
    //   AiAnalysisCompensate/PredictionPromptContractChecks.php   両AIの本文・入出力の決まりの確認
    //   AiAnalysisCompensate/PredictionAcceptanceTests.php        凍結・送信・検査・公開などの受入試験
    use \App\Console\Commands\AiAnalysisCompensate\PredictionExpectedVersionChecks;
    use \App\Console\Commands\AiAnalysisCompensate\PredictionPromptContractChecks;
    use \App\Console\Commands\AiAnalysisCompensate\PredictionAcceptanceTests;
    use \App\Console\Commands\AiAnalysisCompensate\PredictionUpperClassFactors;   // @ANCHOR-20261126-UPPER-CLASS-FACTORS

    // ══════════════════════════════════════════════════════════════════
    // @ANCHOR-20261020-PREDICTION-IDLECAP  旗が下りているときの空回しの上限（秒）
    // ══════════════════════════════════════════════════════════════════
    //   --prediction-sweep --prediction-sweep-watch=55 を毎分の cron に入れると、
    //   枠の段が off の間は<b>55秒眠るだけのPHPが毎分立ち続けます。</b>
    //   仕事は何もしません。ただ常駐するだけです。
    //   刻みを見せるのが目的なので、2回まわれば足ります。
    //   旗が shadow か 1 のときは本当に拾いに行くので、打ち切りません。
    private const SWEEP_IDLE_WATCH_MAX_SEC = 10;

    protected $signature   = 'keiba:ai-analysis-compensate'
                           . ' {--date= : 対象日付 YYYY-MM-DD（省略時は当日）}'
                           . ' {--quiet-skip : 補完対象が無かった回は通知もログ要約も出さない（毎分の先回り実行用）}'
                           // @ANCHOR-20261005-RACE-FILTER  試す前に1レースで確かめられるようにする
                           // @ANCHOR-20261006-ML-STATUS  学習データの蓄積状況を出す
                           . ' {--ml-status : 断層パターン学習の特徴量・正解ラベルの件数を集計する（読み取りだけ）}'
                           // @ANCHOR-20261013-V04-TEST  v0.4-X の受入試験と状態表示
                           // @ANCHOR-20261014  10/14のご依頼C・F・H
                           // @ANCHOR-20261014-FILL-COMPARE  10/14（2回目）ご依頼①の3方式比較
                           // @ANCHOR-20261014-FILL-PROOF  10/14（4回目）ご指摘①⑤の最終確認
                           // @ANCHOR-20261014-ABNORMAL-LOG  10/14（5回目）ご指摘の異常入力の修正状況
                           // @ANCHOR-20261014-MISSED  10/14（6回目）ご指摘⑤⑥
                           // @ANCHOR-20261014-CSV  10/14の確認依頼書⑤
                           // @ANCHOR-20261014-LONGSHOT  10/14（7回目）02.txt ②
                           // @ANCHOR-20261014-PLAN2  10/14（7回目）02.txt ③
                           // @ANCHOR-20261015-PROMPT-VERIFY  10/15（1回目）02.txt ⑤
                           // @ANCHOR-20261015-INTEGRITY  10/15（1回目）01.txt【6】
                           // @ANCHOR-20261015-EVIDENCE  10/15（1回目）02.txt ⑥
                           // @ANCHOR-20261015-PROMPT-DUMP  10/15（2回目）02.txt
                           // @ANCHOR-20261015-WAVE  10/15（2回目）01.txt【3】①④
                           // @ANCHOR-20261015-PROMPT-DIFF  10/15（3回目）02.txt
                           // @ANCHOR-20261015-AVOID  10/15（4回目）02.txt ②
                           // @ANCHOR-20261015-CODE-EVIDENCE  10/15（4回目）02.txt
                           // @ANCHOR-20261018-MODECHECK  10/18（005）ご指摘①
                           . ' {--prediction-cases= : 新方式の合成試験（mode_cases.json 729件・acquisition_boundary_cases.json 4件）を先方の期待値と突き合わせる。validationフォルダのパスを渡す（読み取りだけ・AI呼び出し0回）}'
                           . ' {--prediction-install : 新方式で使う新しいテーブル9本を作る（既にあれば何もしない。既存テーブルには触らない）}'
                           . ' {--prediction-status : 新方式のテーブルの有無と件数、版の設定値を表示する（読み取りだけ）}'
                           // @ANCHOR-20261110-PREDICTION-EXPECTED-CHECK  予想の版（E3・E5を使う版）の凍結と送る原文の準備だけを試す
                           . ' {--prediction-expected-check : 保存済みのレース（11/8の東京6R・京都8R・福島11R）で、E3・E5を付けた凍結と、両AIへ送る原文の準備を試す（AIへは送らない・費用0円・最後に必ずDBを元に戻す）}'
                           // @ANCHOR-20261110-PREDICTION-EXPECTED-PRIVATE  よっしー⑤の非公開2レース（実際にAIへ送る。お客様の画面には出ない世代900で保存）
                           . ' {--prediction-expected-measure : 非公開試験の世代900（11/8 東京6R）の1st原文を、締切を気にせずClaudeへ1回だけ送り、返事にかかる時間と長さを測る（料金は数円。予想の保存はしない）}'
                           // @ANCHOR-20261110-PREDICTION-EXPECTED-CHECKDAY  よっしー11/10「現在の稼働版とR15.24の差分」：その日の全レースで、凍結と両AIへ送る原文の準備が通るかを確かめる
                           . ' {--prediction-expected-jump-compare= : 指定した日の全レースで、障害レースの扱いを直す前と後の E3・E5 の材料を比べる（AIへは送らない・0円・レースごとにDBを元に戻す）}'
                           . ' {--prediction-expected-pool-strict-test= : 当日確認（--prediction-expected-check-day）が監査記録の pool_ids の欠落・型不正を不合格にし、正しい空配列は M=0 として通すかを、指定した日の最初の1レースで確かめる（AIへは送らない・0円・DBは元に戻す）}'
                           // @ANCHOR-20261126-UPPER-CLASS-FACTORS  なる11/26 第4稿：比較用の追加資料だけを作って書き出す（本番には触らない）
                           . ' {--prediction-upper-factors-day= : 指定した日（例 2026-11-28）の全レースで、第4稿の追加資料（①条件復帰 ②休み明け何走目 ③6分前人気順位別の3着内率 ④複勝の支持加速）を作り、storage/app/baganriki/upper_factors/日付.json に書き出す（DBは読むだけ・AIへは送らない・費用0円・レース後に実行）}'
                           . ' {--prediction-expected-check-day= : 指定した日（例 2026-11-08）の全レースで、E3・E5を付けた凍結と、両AIへ送る原文の準備（圧縮して元に戻せるかの照合を含む）を試す（AIへは送らない・費用0円・レースごとにDBを元に戻す）}'
                           // @ANCHOR-20261110-PREDICTION-EXPECTED-EXPORT  よっしー11/10「実際の1レース分の送信全文・両AIの回答・補正前後と検査結果」を書き出す
                           . ' {--prediction-expected-export : 非公開試験（世代900）のレースごとに、両AIへ送った全文・返事の原文・整えた後の返事・検査の結果・公開の記録・料金を ~/baganriki_export/ に書き出す（読むだけ・DBは変えない）}'
                           // @ANCHOR-20261110-PREDICTION-EXPECTED-FAKE  よっしー11/10夜 ③④：AIへは送らず、保存した返事を偽のAIとして返し、送り直し・検査・採否・公開・料金の記録・出せなかった記録・画面まで通す
                           . ' {--prediction-expected-fake : 非公開試験（世代900）の4レースを、AIへは送らずに通す（~/baganriki_export/ に書き出した返事を偽のAIが返す・費用0円・記録は世代900として残す）}'
                           . ' {--prediction-expected-private : 保存済みの2レース（11/8 東京6R・京都8R）を、E3・E5版で実際に両AIへ送り、検査・訂正・公開・画面用の返し方まで通す（費用がかかる。お客様の画面には出ない。記録は残す）}'
                           // @ANCHOR-20261020-PREDICTION-PROMPT  新方式の両AI本文（1st・2nd）の全文置換
                           . ' {--prediction-prompt-install= : 配布された両AI本文3本（prompt_1st.txt / prompt_2nd.txt / common_prompt.txt）をstorageへ設置し、マーカー・版・差し込み記号を確かめる。promptsフォルダのパスを渡す}'
                           . ' {--prediction-prompt-check= : 新方式の送信文（system全文＋INPUT_JSON）を組み立て、先方の参照入力14本・同梱スキーマと突き合わせる。送付資料のルート（prompts/schemas/validationを含む）を渡す（AIへは送らない・費用0円・DB書込なし）}'
                           . ' {--prediction-prompt-dump= : 組み立てた送信文（system本文とINPUT_JSON）をこのフォルダへ書き出す}'
                           . ' {--prediction-prompt-real : 本番DBの保存済みレースで送信文を組み立てられるか試す。--prediction-prompt-check と --date= を併用（読み取りだけ・AI呼び出し0回）}'
                           . ' {--prediction-prompt-assume : 取得監査の実時刻を予定時刻で仮置きして送信文の形まで確かめる（本番送信には使わない）。--prediction-prompt-real と併用}'
                           . ' {--prediction-prompt-sales-offset= : 投票締切を「発走のN秒前」として仮置きする（正本ではない。契約11.2のため本番送信はしない）。--prediction-prompt-real と併用}'
                           // @ANCHOR-20261020-PREDICTION-AUDIT  お願い②（A案）の取得監査
                           . ' {--prediction-audit-check : 取得監査の記録の経路を確かめる（テーブル・列・マイクロ秒・読み戻し）。試し書きは必ずロールバックするのでDBへ残る書き込みは0件。--date= と併用}'
                           // @ANCHOR-20261020-PREDICTION-INVALID  異常例32本（入力23・出力9）
                           . ' {--prediction-invalid-cases= : 異常例32本を Schema と 意味検証 に分けて判定し、先方の期待と突き合わせる。送付資料のルートを渡す（読み取りだけ・AI呼び出し0回）}'
                           // @ANCHOR-20261020-PREDICTION-FREEZE  凍結入力の保存
                           . ' {--prediction-freeze : 保存済みレースの凍結入力（snapshot）を作って保存する。--date= と併用。--prediction-freeze-dry で書かずに結果だけ見る}'
                           . ' {--prediction-freeze-dry : --prediction-freeze を1行も書かずに試す}'
                           // @ANCHOR-20261020-PREDICTION-ACQFIX  監査行からの通し試験
                           . ' {--prediction-acq-test : 取得側と同じ形の監査行を作り、採用バッチの選定→INPUT_JSON→凍結保存→読戻しまで本番の経路で通す。すべてロールバックするのでDBへ残る書き込みは0件。--date= と併用}'
                           // @ANCHOR-20261020-PREDICTION-FP  元値の指紋（切り捨ての起きない方法）
                           . ' {--prediction-fingerprint= : 元値の指紋を、主キー順の逐次SHA-256で出す（長さ付きの列表現・主キー集合も別hash・一貫した読取）。前回のJSONのパスを渡すと突き合わせる。読むだけ・書き込み0件}'
                           // @ANCHOR-20261020-PREDICTION-33R  33レースの候補比較
                           // @ANCHOR-20261020-PREDICTION-QUEUE  キューと永続予約
                           . ' {--prediction-queue-test : キューと永続予約（初回0・訂正1の2枠、重複送信の防止、再起動の後始末）が契約どおりか通しで確かめる。すべてロールバックするのでDBへ残る書き込みは0件}'
                           // @ANCHOR-20261020-PREDICTION-ONEBUILD  固定入力の通し試験（）
                           . ' {--prediction-onebuild-test : 固定した入力をそのまま両roleへ送れるか（作り直していないか）を通しで確かめる。--date= と併用。実AIは呼ばない。すべてロールバックする}'
                           // @ANCHOR-20261021-PREDICTION-TOKENS
                           . ' {--prediction-token-count= : トークン数を課金ゼロで実測する。count_tokens だけを使い、本文の生成は1回も呼ばない。送付資料ルートのパスを渡す。読むだけ}'
                           . ' {--prediction-token-out= : 実測した本文の書き出し先（DeepSeek側を公開トークナイザで数えるため）}'
                           . ' {--prediction-token-model= : 数えるモデルを1つに絞る（既定は claude-haiku-4-5 と claude-haiku-5-5 の両方）}'
                           // @ANCHOR-20261020-PREDICTION-WIRETEST  007のE（実起動経路）
                           . ' {--prediction-wire-test= : 送信口（Httpクライアント）を偽応答に差し替え、初回→検証→訂正→解決→公開を本番の関数で通す。片側失敗・通信不能・途中切れ・応答期限到達・公開期限到達・プロセス停止後の復旧・上限前呼出しも通す。送付資料ルートのパスを渡す。実AIは1回も呼ばない。すべてロールバックする}'
                           // @ANCHOR-20261021-PREDICTION-E2E  実凍結から一続きの通し試験（章末尾）
                           . ' {--prediction-e2e-test= : 実在レースの出馬表・オッズから監査行を作り、発走時刻を10分後へ寄せて、締切を渡さずに導出させ、キャッシュ後の入口が呼ぶ関数で実凍結し、期限の幅を1つずつ確かめ、送信口を偽応答に差し替えて初回→検証→訂正→採否→公開まで本番の関数で通す。3,600秒の決め打ちはしない。送付資料ルートのパスを渡す。実AIは1回も呼ばない。すべてロールバックする}'
                           // @ANCHOR-20261023-PREDICTION-LIVEONCE  実AIの18頭実測（AC05・003チェックリスト P-07）
                           . ' {--prediction-live-once= : 【課金あり】保存済みのレースを「いまから330秒後に発走」へ写して実凍結し、指定した role（1st か 2nd）の実AIへ初回を1回だけ送る。訂正が要れば1回だけ送る。受領までの秒数・complete・使用量・応答期限との差を出す。DBはすべてロールバックする。1st と 2nd を同時に測るときは2つ並べて起動する}'
                           . ' {--prediction-live-race= : --prediction-live-once の写し元 race_id（既定 20261018-4-04-2-10 ＝10/18新潟10R・18頭）}'
                           . ' {--prediction-live-yes : --prediction-live-once の課金に同意して確認を省く（並べて起動するときに使う）}'
                           // @ANCHOR-20261021-PREDICTION-TYPETEST  型保持の通し試験（章）
                           . ' {--prediction-type-test= : JSONの型（数値／文字列、オブジェクト／配列、整数／小数／指数）を、診断→訂正比較→保存→読戻しまで落とさずに通せることを確かめる。010の3-5の6例をすべて含む。型の地図を渡さない場合に何を見逃すかも並べて出す。送付資料ルートのパスを渡す。実AIは1回も呼ばない。すべてロールバックする}'
                           // @ANCHOR-20261021-PREDICTION-JOBTEST  Job構成の通し試験（章）
                           // @ANCHOR-20261023-NARU-WORKERTEST
                           . ' {--prediction-worker-test : 実 worker（別プロセス）に Job を処理させる受入試験。外部AIは呼ばない（試験用の場 90〜99 だけに効く偽AI）。約7分。最後に試験用のレースの行だけ消す}'
                           . ' {--prediction-worker-keep : --prediction-worker-test の行を消さずに残す}'
                           . ' {--prediction-worker-mat= : --prediction-worker-test の S8a／S8b に使う配布の材料の場所（既定 ~/r122_v23/mat）}'
                           . ' {--prediction-job-test : Job構成（凍結・role・集計・実状態の4本）が、どの待ち行列へどの引数で積まれるかをjobs表の行から確かめる。秒数の整合（Jobの上限・retry_after・HTTPの上限）、経路の固定、対象レースの限定、実状態の変更（取消は足すだけ・締切は前倒しだけ）も見る。workerは立てず、Jobは1本も実行しない。実AIは1回も呼ばない。すべてロールバックする}'
                           // @ANCHOR-20261020-PREDICTION-WIRE  再起動後のやり直し（）
                           . ' {--prediction-sweep : 原応答を保存したあと訂正・公開が始まらないまま止まったレースを拾い、訂正判定と公開だけやり直す。初回の送信はしない。1分おきのcronに入れてよい}'
                           // @ANCHOR-20261020-PREDICTION-SWEEPWATCH
                           . ' {--prediction-sweep-watch= : --prediction-sweep を、この秒数のあいだ繰り返す（既定55秒）。1分おきのcronでも、応答期限から公開期限までの30秒を取りこぼさないため}'
                           . ' {--prediction-sweep-interval= : --prediction-sweep-watch のときの間隔（秒。既定5）}'
                           // @ANCHOR-20261023-PREDICTION-SWEEPROLE
                           . ' {--prediction-sweep-role= : --prediction-sweep で受け持つAI（1st か 2nd）。cronに2行入れると1stと2ndを同時に回せる。省略すると両方を1本で回す（従来どおり）}'
                           // @ANCHOR-20261020-PREDICTION-RESP  応答検証と局所回復
                           . ' {--prediction-response-test= : 正常出力14本を本番の経路（extract→封筒→隔離→診断）へ通し、局所回復と訂正（最大1回）を確かめる。送付資料ルートのパスを渡す。すべてロールバックする}'
                           // @ANCHOR-20261020-PREDICTION-PUB  統合・公開・intent・revision
                           . ' {--prediction-budget-probe= : 予算の予約を1回だけ試す（2つ同時に走らせて、上限を超えないことを確かめる）。値は余裕のドル。外部へは送らない}'
                           . ' {--prediction-budget-probe-cleanup : --prediction-budget-probe の予約を消す}'
                           . ' {--prediction-race-lock-test : 発走時刻の更新と、送信・公開の最終確認が直列になることを2つの接続で確かめる。外部へは送らない}'
                           . ' {--prediction-publish-commit-test= : 公開本体が正本をロックした後に別プロセスの発走時刻UPDATEが待ち、公開の実COMMIT後に同じUPDATEが進むことを確かめる。送付資料ルートのパスを渡す。外部へは送らない。試験の行は最後に消す}'
                           . ' {--prediction-fixpolicy-compare= : 訂正方針 V1（contract）と V2（missing）を、診断別の固定ケース5つで比べ、結果を送付資料ルートの下にファイルで残す。送付資料ルートのパスを渡す。AIは呼ばない。DBはロールバック}'
                           . ' {--prediction-reprocess= : 保存した入力と原応答（*_input.json・*_attempt0.txt・*_attempt1.txt があるディレクトリ）を最新版で処理し直し、馬別の診断・順位の出どころ・公開結果を同じ場所へファイルで残す。AIは呼ばない。DBはロールバック}'
                           . ' {--prediction-compact-check= : 保存した入力JSONの出典一覧を詰めた形に変え、元へ戻せることとトークン数（count_tokens・無料）を確かめる。ファイルのパスを渡す。本文の生成は呼ばない。DBに書かない}'
                           . ' {--prediction-raw-odds-test= : 011再精査10版の追加139項目（原オッズの読込）を、統合後の本物の実装と MySQL で通す。値は写し元の race_id（省略は 1）。AIは呼ばない。すべてロールバックする}'
                           . ' {--prediction-publish-test= : 両roleの検証済み応答を統合して公開し、intentの再実行・版の見張り・期限・加点の証明を確かめる。送付資料ルートのパスを渡す。すべてロールバックする}'
                           // @ANCHOR-20261020-PREDICTION-API  読取API
                           . ' {--prediction-read-test= : 読取APIが返す中身（3つの版・ワーカーの状態・いまの取消を映した枠・表示状態）を確かめる。送付資料ルートのパスを渡す。すべてロールバックする}'
                           // @ANCHOR-20261020-PREDICTION-PAST  本番の経路で作った入力をSchemaに当てる
                           . ' {--prediction-prod-input= : 実レースについて本番と同じ経路でINPUT_JSONを作り、入力Schemaとsource_registry Schemaに当て、coverage7区分と過去走の件数を並べる。送付資料ルートのパスを渡す。読むだけ}'
                           // @ANCHOR-20261020-PREDICTION-MARKETTEST  OPIと推定確定オッズの通し試験
                           . ' {--prediction-market-test= : OPI・複勝OPI・推定確定オッズ・推定確定複勝最小・予測補正OPIを、実レースの実オッズで載せ、SQLから取り直した値と突き合わせる。載らない側（当日を含む集計）と載る側（期間が終わっている集計）の両方を通す。送付資料ルートのパスを渡す。読むだけ}'
                           . ' {--as-of= : --prediction-market-test で「仮の開催日」をこの日として呼ぶ（既定は集計期間の終わりの7日後）。動かすのは開催日と情報上限だけで、オッズ・集計表・式はそのまま}'
                           // @ANCHOR-20261020-PREDICTION-NATIVE  合成試験と取消の例を本体へ直接通す
                           . ' {--prediction-native-test= : 合成729件を本体 v04Classify へ、取得境界4件を本体 acceptBatch へ直接通す。取消で目標数Cが変わる例（16→15・14→13・9→8・全頭取消）と、正本の発走時刻との一致検査・日時の繰上がり検査も本体で確かめる。送付資料ルートのパスを渡す。読むだけ}';
    protected $description = '未実行のAI予想を補完実行する';

    public function handle(): void
    {

        // @ANCHOR-20261006-ML-STATUS  学習データの蓄積状況（読み取りだけ）
        if ((bool) $this->option('ml-status')) {
            $this->runMlStatus();
            return;
        }

        // @ANCHOR-20261020-PREDICTION-INSTALL  新方式のテーブルを作る／状態を見る
        if ((bool) $this->option('prediction-install')) {
            $this->runPredictionInstall();
            return;
        }
        if ((bool) $this->option('prediction-expected-measure')) {
            $this->runPredictionExpectedMeasure();
            return;
        }
        if ((bool) $this->option('prediction-expected-private')) {
            $this->runPredictionExpectedPrivate();
            return;
        }
        if ((bool) $this->option('prediction-expected-fake')) {
            $this->runPredictionExpectedFake();
            return;
        }
        if ((string) ($this->option('prediction-expected-jump-compare') ?? '') !== '') {
            $this->runPredictionExpectedJumpCompare((string) $this->option('prediction-expected-jump-compare'));
            return;
        }
        // @ANCHOR-20261111-CHECK-POOLSTRICT  なる 11/11 夜：当日確認の pool_ids の欠落・型不正を不合格にしたことの確認
        if ((string) ($this->option('prediction-expected-pool-strict-test') ?? '') !== '') {
            $this->runPredictionExpectedPoolStrictTest((string) $this->option('prediction-expected-pool-strict-test'));
            return;
        }
        // @ANCHOR-20261126-UPPER-CLASS-FACTORS
        if ((string) ($this->option('prediction-upper-factors-day') ?? '') !== '') {
            $this->runPredictionUpperFactorsDay((string) $this->option('prediction-upper-factors-day'));
            return;
        }
        if ((string) ($this->option('prediction-expected-check-day') ?? '') !== '') {
            $this->runPredictionExpectedCheckDay((string) $this->option('prediction-expected-check-day'));
            return;
        }
        if ((bool) $this->option('prediction-expected-export')) {
            $this->runPredictionExpectedExport();
            return;
        }
        if ((bool) $this->option('prediction-expected-check')) {
            $this->runPredictionExpectedCheck();
            return;
        }
        if ((bool) $this->option('prediction-status')) {
            $this->runPredictionStatus();
            return;
        }

        // @ANCHOR-20261020-PREDICTION-NATIVE  合成試験と取消の例を本体へ直接通す
        if ($this->hasOption('prediction-native-test')
            && $this->option('prediction-native-test') !== null) {
            $this->runNativeTest((string) $this->option('prediction-native-test'));
            return;
        }

        // @ANCHOR-20261020-PREDICTION-MARKETTEST  OPIと推定確定オッズの通し試験
        if ($this->hasOption('prediction-market-test')
            && $this->option('prediction-market-test') !== null) {
            $this->runMarketPriorTest((string) $this->option('prediction-market-test'));
            return;
        }

        // @ANCHOR-20261020-PREDICTION-PAST  本番の経路で作った入力をSchemaに当てる
        if ($this->hasOption('prediction-prod-input')
            && $this->option('prediction-prod-input') !== null) {
            $this->runProductionInputCheck((string) $this->option('prediction-prod-input'));
            return;
        }

        // @ANCHOR-20261020-PREDICTION-API  読取API
        if ($this->hasOption('prediction-read-test')
            && $this->option('prediction-read-test') !== null) {
            $this->runReadApiTest((string) $this->option('prediction-read-test'));
            return;
        }

        // @ANCHOR-20261023-NARU-BUDGET2  予算の2プロセス試験
        if ($this->hasOption('prediction-budget-probe-cleanup') && (bool) $this->option('prediction-budget-probe-cleanup')) {
            $this->runBudgetProbe(null, true);
            return;
        }
        if ($this->hasOption('prediction-budget-probe') && $this->option('prediction-budget-probe') !== null) {
            $this->runBudgetProbe((string) $this->option('prediction-budget-probe'), false);
            return;
        }
        // @ANCHOR-20261023-NARU-RACELOCK  発走時刻の更新と最終確認の直列化
        if ($this->hasOption('prediction-race-lock-test') && (bool) $this->option('prediction-race-lock-test')) {
            $this->runRaceLockTest();
            return;
        }
        // @ANCHOR-20261025-NARU-PUBCOMMIT  公開の実COMMIT後に待っていたUPDATEが進むこと（なる 10/25 再確認 3章）
        if ($this->hasOption('prediction-publish-commit-test') && $this->option('prediction-publish-commit-test') !== null) {
            $this->runPublishCommitTest((string) $this->option('prediction-publish-commit-test'));
            return;
        }

        // @ANCHOR-20261023-NARU-FIXCMP  訂正方針 V1／V2 の比較
        if ($this->hasOption('prediction-fixpolicy-compare')
            && $this->option('prediction-fixpolicy-compare') !== null) {
            $this->runFixPolicyCompare((string) $this->option('prediction-fixpolicy-compare'));
            return;
        }

        // @ANCHOR-20261023-NARU-REPROC  保存した原応答の再処理
        if ($this->hasOption('prediction-reprocess')
            && $this->option('prediction-reprocess') !== null) {
            $this->runReprocessSaved((string) $this->option('prediction-reprocess'));
            return;
        }

        // @ANCHOR-20261023-COMPACT  出典一覧を詰めた形の確認
        if ($this->hasOption('prediction-compact-check')
            && $this->option('prediction-compact-check') !== null) {
            $this->runCompactCheck((string) $this->option('prediction-compact-check'));
            return;
        }

        // @ANCHOR-20261023-NARU-AUDIT10  011再精査10版の追加139項目
        if ($this->hasOption('prediction-raw-odds-test')
            && $this->option('prediction-raw-odds-test') !== null) {
            $this->runRawOddsAuditTest((string) $this->option('prediction-raw-odds-test'));
            return;
        }

        // @ANCHOR-20261020-PREDICTION-PUB  統合・公開・intent・revision
        if ($this->hasOption('prediction-publish-test')
            && $this->option('prediction-publish-test') !== null) {
            // 訂正の試験なので、本番の設定（訂正は頼まない・帳簿の直し・記号の直し）を、この試験の間だけ外します
            $this->withEnv(['BAGANRIKI_PREDICTION_FIX_POLICY' => 'contract', 'BAGANRIKI_PREDICTION_CLERICAL_FIX' => '0', 'BAGANRIKI_PREDICTION_JSON_REPAIR' => '0'],
                fn() => $this->runPublishTest((string) $this->option('prediction-publish-test')));
            return;
        }

        // @ANCHOR-20261020-PREDICTION-RESP  応答検証と局所回復
        if ($this->hasOption('prediction-response-test')
            && $this->option('prediction-response-test') !== null) {
            // 訂正の試験なので、本番の設定（訂正は頼まない・帳簿の直し・記号の直し）を、この試験の間だけ外します
            $this->withEnv(['BAGANRIKI_PREDICTION_FIX_POLICY' => 'contract', 'BAGANRIKI_PREDICTION_CLERICAL_FIX' => '0', 'BAGANRIKI_PREDICTION_JSON_REPAIR' => '0'],
                fn() => $this->runResponseTest((string) $this->option('prediction-response-test')));
            return;
        }

        // @ANCHOR-20261020-PREDICTION-QUEUE  キューと永続予約
        if ((bool) $this->option('prediction-queue-test')) {
            $this->runQueueTest();
            return;
        }

        // @ANCHOR-20261020-PREDICTION-ONEBUILD  固定入力の通し試験（）
        // @ANCHOR-20261021-PREDICTION-TOKENS
        if ($this->hasOption('prediction-token-count')
            && $this->option('prediction-token-count') !== null) {
            $this->runTokenCount((string) $this->option('prediction-token-count'));
            return;
        }

        // @ANCHOR-20261020-PREDICTION-WIRETEST  007のE（実起動経路）
        // @ANCHOR-20261021-PREDICTION-E2E  実凍結から一続きの通し試験（章末尾）
        if ($this->hasOption('prediction-e2e-test')
            && $this->option('prediction-e2e-test') !== null) {
            $this->runEndToEndTest((string) $this->option('prediction-e2e-test'));
            return;
        }
        // @ANCHOR-20261023-PREDICTION-LIVEONCE  実AIの18頭実測（課金あり）
        if ($this->hasOption('prediction-live-once')
            && $this->option('prediction-live-once') !== null) {
            $this->runLiveAiOnce((string) $this->option('prediction-live-once'));
            return;
        }
        // @ANCHOR-20261021-PREDICTION-TYPETEST  型保持の通し試験（章）
        if ($this->hasOption('prediction-type-test')
            && $this->option('prediction-type-test') !== null) {
            $this->runTypePreserveTest((string) $this->option('prediction-type-test'));
            return;
        }
        // @ANCHOR-20261023-NARU-WORKERTEST  実 worker の受入試験
        if ($this->hasOption('prediction-worker-test')
            && (bool) $this->option('prediction-worker-test')) {
            $this->runWorkerAcceptanceTest();
            return;
        }
        // @ANCHOR-20261021-PREDICTION-JOBTEST  Job構成の通し試験（章）
        if ($this->hasOption('prediction-job-test')
            && (bool) $this->option('prediction-job-test')) {
            $this->runJobTest();
            return;
        }
        if ($this->hasOption('prediction-wire-test')
            && $this->option('prediction-wire-test') !== null) {
            // 訂正の試験なので、本番の設定（訂正は頼まない・帳簿の直し・記号の直し）を、この試験の間だけ外します
            $this->withEnv(['BAGANRIKI_PREDICTION_FIX_POLICY' => 'contract', 'BAGANRIKI_PREDICTION_CLERICAL_FIX' => '0', 'BAGANRIKI_PREDICTION_JSON_REPAIR' => '0'],
                fn() => $this->runWireTest((string) $this->option('prediction-wire-test')));
            return;
        }

        if ((bool) $this->option('prediction-onebuild-test')) {
            $this->runOneBuildTest();
            return;
        }

        // @ANCHOR-20261020-PREDICTION-WIRE  再起動後のやり直し（）
        if ((bool) $this->option('prediction-sweep')) {
            self::loadPredictionClasses();
            $this->info('════════════════════════════════════════════════════════');
            $this->info(' 新方式 止まったレースのやり直し（初回の送信はしません）');
            $this->info('════════════════════════════════════════════════════════');
            $this->line('  実行時刻 : ' . date('c'));
            // @ANCHOR-20261020-PREDICTION-WIRE  0件の理由が読めるようにします。
            //   旗が下りているだけなのか、拾う対象が無かったのかを
            //   区別できないと「動いていない」のと見分けが付きません。
            $swW = \App\Http\Controllers\Api\BaganrikiWorker::class;
            $this->line('  枠の段     : ' . $swW::mode()
                      . '（BAGANRIKI_PREDICTION_QUEUE）');
            $this->line('  必要な表   : ' . ($swW::ready() ? 'そろっています'
                                                           : 'ありません'));
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-SWEEPWATCH
            // ══════════════════════════════════════════════════════════
            //   【ご指摘のとおりでした】1分おきの巡回では、
            //   応答期限（発走150秒前）から公開期限（発走120秒前）までの
            //   <b>30秒を取りこぼします。</b>
            //   例：発走10:00 → 応答期限09:57:30／公開期限09:58:00。
            //       巡回が 09:57:01 → 09:58:01 なら、前は WAITING、
            //       後は公開期限の後です。
            //   【いま】1回の呼び出しの中で、決めた秒数のあいだ
            //   短い間隔で回せるようにしました。
            //   既定は 55秒を 5秒間隔（12回）です。1分おきの cron に
            //   入れれば、巡回の間隔は<b>最大5秒</b>になります。
            //   30秒の窓を取りこぼしません。
            $swWatch    = (int) ($this->option('prediction-sweep-watch') ?? 0);
            $swInterval = (int) ($this->option('prediction-sweep-interval') ?? 0);
            if ($swInterval <= 0) { $swInterval = 5; }
            if ($swWatch < 0)     { $swWatch = 0; }
            $ctrlSw = app(\App\Http\Controllers\Api\AiController::class);
            // @ANCHOR-20261023-PREDICTION-SWEEPROLE  受け持つAI（省略なら両方）
            $swRole = trim((string) ($this->option('prediction-sweep-role') ?? ''));
            if (!in_array($swRole, ['', '1st', '2nd'], true)) {
                $this->error('  × --prediction-sweep-role は 1st か 2nd です（指定：' . $swRole . '）');
                return;
            }
            $this->line('  受け持ち   : ' . ($swRole === '' ? '1st・2nd の両方' : $swRole . ' だけ'));
            $swEnd  = microtime(true) + $swWatch;
            $swRound = 0; $swPub = 0; $swScan = 0; $swRes = 0; $swFirst = 0;
            if ($swWatch > 0) {
                $this->line('  見張り     : ' . $swWatch . '秒を'
                          . $swInterval . '秒おき（最大'
                          . (int) floor($swWatch / max(1, $swInterval)) . '回）');
            }
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-SWEEPIDLE  旗が下りているときの見張り
            // ══════════════════════════════════════════════════════════
            //   枠の段が off のときは、拾う対象を見に行きません（従来どおり）。
            //   ただし <b>--prediction-sweep-watch を付けたときは、刻みだけは
            //   実際に回して見せます。</b>
            //   そうしないと「30秒の窓を取りこぼさない」という話が、
            //   off の環境では一度も動いていない状態で紙の上の主張に
            //   なってしまうためです（10月20日007のご指摘6-2）。
            //   回すのは時計だけで、DBには触りません。
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261020-PREDICTION-IDLECAP  空回しは10秒で打ち切ります
            // ══════════════════════════════════════════════════════════
            //   【10月20日に気づいたこと】
            //   これを毎分の cron に入れると、枠の段が off の間は
            //   <b>55秒眠るだけのPHPが、1分のうち55秒ずっと立ち続けます。</b>
            //   仕事は何もしません。ただ常駐するだけです。
            //   刻みを見せるのが目的なので、2回まわれば足ります。
            //   旗が下りているときは<b>10秒で打ち切ります。</b>
            //   旗が shadow か 1 のときは、指定どおりの秒数で回します
            //   （そちらは本当に拾いに行くので、打ち切りません）。
            if (!$swW::enabled()) {
                $this->warn('  枠の段が off なので、拾う対象は見ません'
                          . '（これは正常です。shadow か 1 で動きます）');
                if ($swWatch > 0) {
                    $idleMax = min($swWatch, self::SWEEP_IDLE_WATCH_MAX_SEC);
                    if ($idleMax < $swWatch) {
                        $this->line('  ※ 旗が下りているので、空回しは '
                                  . self::SWEEP_IDLE_WATCH_MAX_SEC
                                  . '秒で打ち切ります（指定は ' . $swWatch . '秒）。'
                                  . '毎分のcronに入れても常駐しません');
                    }
                    $t0 = microtime(true);
                    $idleRound = 0; $gapMax = 0.0; $prev = $t0;
                    $idleEnd = $t0 + $idleMax;
                    do {
                        $idleRound++;
                        $now = microtime(true);
                        $gap = $now - $prev;
                        if ($idleRound > 1 && $gap > $gapMax) { $gapMax = $gap; }
                        $prev = $now;
                        // @ANCHOR-20261021-PREDICTION-WATCHEND  終わりまで回します
                        $rest = $idleEnd - microtime(true);
                        if ($rest <= 0.05) { break; }
                        usleep((int) round(min((float) $swInterval, $rest) * 1000000));
                    } while (true);
                    $span = microtime(true) - $t0;
                    $this->line('  刻みの実測 : ' . $idleRound . ' 回／'
                              . number_format($span, 1) . ' 秒'
                              . '（最大の間隔 '
                              . number_format($gapMax, 1) . ' 秒）');
                    // ══════════════════════════════════════════════
                    // @ANCHOR-20261021-PREDICTION-WATCHDESC
                    // ══════════════════════════════════════════════
                    //   【書きすぎでした】「窓の取りこぼし：ありません」と
                    //   出していましたが、これは<b>何も処理していない
                    //   空回しの刻み</b>です。実際のレース処理の期限遵守を
                    //   証明するものではありません。書き方を直します。
                    $this->line('  確認したこと : 無負荷の巡回間隔だけです'
                              . '（実際のレース処理は含みません）');
                    $this->line('  実測の最大間隔 : '
                              . number_format($gapMax, 1) . ' 秒'
                              . ($gapMax > 0 ? '' : '（刻みが1回で終わりました）'));
                    $this->warn('  ※ 実処理では訂正の送信をこの中で待つので、'
                              . 'この間隔のまま回るとは限りません');
                }
                $this->line('');
                return;
            }
            do {
                $swRound++;
                $sw = $ctrlSw->sweepPendingPredictions($swRole === '' ? [] : ['role' => $swRole]);
                $swScan = (int) $sw['scanned'];
                $swRes += (int) $sw['resumed'];
                $swFirst += (int) ($sw['first_sent'] ?? 0);
                $swPub += (int) $sw['published'];
                if ((int) $sw['published'] > 0) {
                    foreach ((array) $sw['rows'] as $r) {
                        // @ANCHOR-20261023-PREDICTION-SWEEPROWS  対象外レースの行は文字列です
                        //   sweepPendingPredictions は対象外レースを「race_id:理由」の文字列で
                        //   rows へ入れます。配列として読むと PHP 8 で TypeError になり、
                        //   巡回そのものが止まります。文字列の行は表示だけ変えます。
                        if (!is_array($r)) {
                            $this->line(sprintf('    %2d回目 %s', $swRound, (string) $r));
                            continue;
                        }
                        $this->line(sprintf('    %2d回目 %-28s %s',
                            $swRound, (string) $r['race_id'], (string) $r['publish']));
                    }
                }
                if ($swWatch <= 0) { break; }
                // ══════════════════════════════════════════════════════
                // @ANCHOR-20261021-PREDICTION-WATCHEND
                // ══════════════════════════════════════════════════════
                //   【旧】`今 + 間隔 >= 終わり` で抜けていました。
                //     55秒・5秒間隔なら 0,5,…,50秒で終わり、
                //     <b>次の毎分起動まで約10秒空きます。</b>
                //     「最大5秒」という説明と合っていませんでした。
                //   【いま】終わりまで回します。最後の1回は<b>残り時間だけ</b>
                //     眠るので、窓の空きは次の起動までの差だけになります。
                $rest = $swEnd - microtime(true);
                if ($rest <= 0.05) { break; }
                $nap = min((float) $swInterval, $rest);
                usleep((int) round($nap * 1000000));
            } while (true);
            $this->line('  回した回数 : ' . $swRound . ' 回');
            $this->line('  見たレース : ' . $swScan . ' 件'
                      . '（投票締切が1時間前〜6時間後の凍結入力）');
            $this->line('  後始末     : ' . $swRes . ' 件'
                      . '（再起動をまたいだ送信を結果不明にした件数）');
            $this->line('  初回の送信 : ' . $swFirst . ' 件'
                      . '（固定入力があるのに初回の枠が無かったレース）');
            $this->line('  公開できた : ' . $swPub . ' 件');
            if ($swWatch <= 0) {
                foreach ((array) ($sw['rows'] ?? []) as $r) {
                    // @ANCHOR-20261023-PREDICTION-SWEEPROWS  対象外レースの行は文字列です
                    if (!is_array($r)) { $this->line('    ' . (string) $r); continue; }
                    $this->line(sprintf('    %-28s %s',
                        (string) $r['race_id'], (string) $r['publish']));
                }
            }
            $this->line('');
            $this->warn('  ※ 巡回は訂正の送信をこの中で同期で待ちます。'
                      . '複数レースが同時に期限へ来たときの保証はありません'
                      . '（10月21日009のご指摘3）');
            $this->line('');
            $this->line('  cron の例（1分おき）');
            $this->line('    * * * * * cd /var/www/horse_odds_finder && '
                      . 'php artisan keiba:ai-analysis-compensate '
                      . '--prediction-sweep --prediction-sweep-watch=55 --prediction-sweep-interval=5');
            $this->line('');
            return;
        }

        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-FP  元値の指紋
        // @ANCHOR-20261020-PREDICTION-FPFLAG  値なしで渡したときに素通りしていた
        // ══════════════════════════════════════════════════════════════
        //   【旧】option() が null でないことだけを見ていました。
        //     `--prediction-fingerprint=` は「値を省ける指定」なので、
        //     <b>値なしで渡すと null になり、付けていないのと見分けが
        //     付きません。</b>そのまま通常の補完処理へ進み、
        //     「[ガードA] レースが存在しません」で終わっていました。
        //     2026年10月20日に実機で踏みました。
        //   【いま】素の引数も見ます。`--prediction-fingerprint` だけでも動きます。
        $fpGiven = ($this->option('prediction-fingerprint') !== null);
        if (!$fpGiven) {
            foreach ((array) ($_SERVER['argv'] ?? []) as $av) {
                if ($av === '--prediction-fingerprint'
                    || strncmp((string) $av, '--prediction-fingerprint=', 19) === 0) {
                    $fpGiven = true;
                    break;
                }
            }
        }
        if ($this->hasOption('prediction-fingerprint') && $fpGiven) {
            $this->runFingerprintCheck($this->option('prediction-fingerprint'));
            return;
        }

        // @ANCHOR-20261020-PREDICTION-ACQFIX  監査行からの通し試験
        if ((bool) $this->option('prediction-acq-test')) {
            $this->runAcquisitionTest();
            return;
        }

        // @ANCHOR-20261020-PREDICTION-INVALID  異常例32本の突き合わせ
        if ($this->option('prediction-invalid-cases')) {
            $this->runInvalidResponseCases((string) $this->option('prediction-invalid-cases'));
            return;
        }

        // @ANCHOR-20261020-PREDICTION-FREEZE  凍結入力の保存
        if ((bool) $this->option('prediction-freeze') || (bool) $this->option('prediction-freeze-dry')) {
            $this->runFreezeInput((bool) $this->option('prediction-freeze-dry'));
            return;
        }

        // @ANCHOR-20261020-PREDICTION-AUDIT  取得監査の記録の確認
        if ((bool) $this->option('prediction-audit-check')) {
            $this->runAcquisitionAuditCheck();
            return;
        }

        // @ANCHOR-20261020-PREDICTION-PROMPT  新方式の両AI本文の設置と送信文の検査
        if ($this->option('prediction-prompt-install')) {
            $this->runPromptInstall((string) $this->option('prediction-prompt-install'));
            return;
        }
        if ($this->option('prediction-prompt-check')) {
            $this->runPromptCheck((string) $this->option('prediction-prompt-check'));
            return;
        }

        // @ANCHOR-20261020-R122CASES  新方式の合成試験（先方の期待値との突き合わせ）
        if ($this->option('prediction-cases')) {
            $this->runPredictionCases((string) $this->option('prediction-cases'));
            return;
        }

        // 以前の方式の先回り（毎分の cron『aiPrewarm』）は、新しい方式に切り替えたので何もしません。
        // cron の行を消すまでのあいだ、呼ばれてもすぐ終わるように残しています。
        if (!(bool) $this->option('quiet-skip')) {
            $this->line('以前の方式の先回りは使っていません（新しい方式の予想は --prediction-sweep が出します）');
        }
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

    /** @ANCHOR-20261018-MODECHECK  Sの値から mode を返す */
    private static function modeOfS(int $s): string
    {
        return ($s <= 1) ? 'FIRM' : (($s <= 3) ? 'MIXED' : 'VOLATILE');
    }

    /** @ANCHOR-20261018-MODECHECK  Sの値から星を返す（1〜5） */
    private static function starOfS(int $s): int
    {
        if ($s <= 0) return 1;
        if ($s === 1) return 2;
        if ($s <= 3) return 3;
        if ($s <= 5) return 4;
        return 5;
    }

    // ═════════════════════════════════════════════════════════════
    // @ANCHOR-20261020-PREDICTION  新方式（2026-10-20 受領の変更計画書v1.2・
    //   基本設計書v1.0・詳細設計書v1.0・server_contract_r12.2）の判定。
    //
    //   決まった3点をそのまま実装しています。
    //     ①X6はB案     … N>=7でnull、N<7でfalse。類似統計で代用しない
    //     ②取得猶予     … 6分前+30秒、21/18/15/12/9分前+120秒
    //     ③暫定配分     … 不明がX6だけで S0=1 ならMIXED、S0=3 ならVOLATILE
    //                      正式modeはUNKNOWNのまま、mode_status=PROVISIONAL
    //   根拠：server_contract_r12.2.txt 5章／詳細設計書 5.2・6章
    // ═════════════════════════════════════════════════════════════

    /** 信号の重み（S = 2X1 + X2 + X3 + X4 + X5 + X6 − 2Z） */
    private const MODE_SIGNAL_WEIGHTS = ['X1'=>2,'X2'=>1,'X3'=>1,'X4'=>1,'X5'=>1,'X6'=>1,'Z'=>-2];

    /**
     * 三値の信号から 新方式 の mode 一式を返します。
     * $sig は ['X1'=>true|false|null, … ,'Z'=>…]。X6を省くと null 扱いです。
     */
    private static function decideModeFromSignals(array $sig): array
    {
        $keys = array_keys(self::MODE_SIGNAL_WEIGHTS);
        $unknown = [];
        foreach ($keys as $k) {
            if (!array_key_exists($k, $sig)) { $sig[$k] = null; }
            if ($sig[$k] === null) { $unknown[] = $k; }
        }

        // 不明を 0/1 の全組合せで評価し、取り得る S を全部出します
        $svals = [];
        $u = count($unknown);
        for ($m = 0; $m < (1 << $u); $m++) {
            $sv = 0;
            foreach ($keys as $k) {
                $v = $sig[$k];
                if ($v === null) {
                    $i = array_search($k, $unknown, true);
                    $v = (bool) (($m >> $i) & 1);
                }
                if ($v) { $sv += self::MODE_SIGNAL_WEIGHTS[$k]; }
            }
            $svals[$sv] = true;
        }
        $svals = array_keys($svals);
        sort($svals);
        $sMin = $svals[0];
        $sMax = $svals[count($svals) - 1];

        $modes = []; $stars = [];
        foreach ($svals as $sv) {
            $modes[self::modeOfS($sv)] = true;
            $stars[self::starOfS($sv)] = true;
        }
        $modes = array_keys($modes);

        // S は不明が1つでもあれば null（S_min／S_max は残します）
        $S = empty($unknown) ? $sMin : null;

        // S0 = X6 を除いた重み付き合計。X6以外が全部確定しているときだけ出ます
        $s0 = 0; $s0ok = true;
        foreach ($keys as $k) {
            if ($k === 'X6') continue;
            if ($sig[$k] === null) { $s0ok = false; break; }
            if ($sig[$k]) { $s0 += self::MODE_SIGNAL_WEIGHTS[$k]; }
        }
        $S0 = $s0ok ? $s0 : null;

        $onlyX6 = (count($unknown) === 1 && $unknown[0] === 'X6');

        if (count($modes) === 1) {
            return [
                'mode'              => $modes[0],
                'mode_status'       => 'CONFIRMED',
                'allocation_mode'   => $modes[0],
                'mode_policy_id'    => 'BASE_TRI_STATE_V1',
                'selection_enabled' => true,
                'S' => $S, 'S_min' => $sMin, 'S_max' => $sMax, 'S0' => $S0,
                'display_rating'    => (count($stars) === 1) ? array_keys($stars)[0] : null,
                'unknown_keys'      => $unknown,
            ];
        }
        if ($onlyX6 && ($S0 === 1 || $S0 === 3)) {
            return [
                'mode'              => 'UNKNOWN',
                'mode_status'       => 'PROVISIONAL',
                'allocation_mode'   => ($S0 === 1) ? 'MIXED' : 'VOLATILE',
                'mode_policy_id'    => 'X6_ONLY_UPPER_MODE_V1',
                'selection_enabled' => true,
                'S' => $S, 'S_min' => $sMin, 'S_max' => $sMax, 'S0' => $S0,
                'display_rating'    => null,
                'unknown_keys'      => $unknown,
            ];
        }
        return [
            'mode'              => 'UNKNOWN',
            'mode_status'       => 'UNRESOLVED',
            'allocation_mode'   => null,
            'mode_policy_id'    => 'BASE_TRI_STATE_V1',
            'selection_enabled' => false,
            'S' => $S, 'S_min' => $sMin, 'S_max' => $sMax, 'S0' => $S0,
            'display_rating'    => null,
            'unknown_keys'      => $unknown,
        ];
    }

    // ── 取得時刻（server_contract_r12.2 4章／詳細設計書 4.3）────────

    /** スロットごとの取得猶予（ミリ秒）。ここに無い時点は予想入力に使いません */
    private const ACQUISITION_GRACE_MS = [21=>120000, 18=>120000, 15=>120000,
                                   12=>120000, 9=>120000, 6=>30000];

    /**
     * RFC3339（秒必須・小数秒0〜6桁・タイムゾーン必須）をマイクロ秒の整数へ。
     * 7桁以上や独自形式は切り捨てず null を返します（契約12章）。
     */
    private static function parseTimeUs(?string $t): ?int
    {
        if ($t === null) { return null; }
        $re = '/^(\d{4}-\d{2}-\d{2})[Tt](\d{2}:\d{2}:\d{2})(\.\d{1,6})?([Zz]|[+\-]\d{2}:\d{2})$/';
        if (!preg_match($re, $t, $m)) { return null; }
        $frac = isset($m[3]) ? str_pad(substr($m[3], 1), 6, '0') : '000000';
        $tz   = (strtoupper($m[4]) === 'Z') ? '+00:00' : $m[4];
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:sP', $m[1] . ' ' . $m[2] . $tz);
        if ($d === false) { return null; }
        return $d->getTimestamp() * 1000000 + (int) $frac;
    }

    /** 予定時刻（anchor_kind=POST_TIME。発走時刻からスロット分を引く） */
    private static function slotScheduledUs(int $anchorUs, int $slotMin): int
    { return $anchorUs - $slotMin * 60 * 1000000; }

    /**
     * 1バッチの採用可否。期限ちょうどは許容、1マイクロ秒でも後なら不採用。
     * 返りは ['ok'=>bool, 'reason'=>string|null]。
     */
    private static function acceptAcquisitionBatch(int $slot, array $b): array
    {
        if (!isset(self::ACQUISITION_GRACE_MS[$slot])) {
            return ['ok'=>false, 'reason'=>'予想に使わないスロット（21/18/15/12/9/6分前だけ）'];
        }
        $v = [];
        foreach (['slot_scheduled_at','request_started_at','response_received_at',
                  'available_at','input_freeze_at'] as $k) {
            $v[$k] = self::parseTimeUs($b[$k] ?? null);
            if ($v[$k] === null) {
                return ['ok'=>false, 'reason'=>$k . ' が無いか日時として読めない'];
            }
        }
        $deadline = $v['slot_scheduled_at'] + self::ACQUISITION_GRACE_MS[$slot] * 1000;
        if ($v['request_started_at']   < $v['slot_scheduled_at'])    return ['ok'=>false, 'reason'=>'要求開始が予定時刻より前'];
        if ($v['response_received_at'] < $v['request_started_at'])   return ['ok'=>false, 'reason'=>'受信完了が要求開始より前（時計逆行）'];
        if ($v['available_at']         < $v['response_received_at']) return ['ok'=>false, 'reason'=>'利用可能が受信完了より前（時計逆行）'];
        if ($v['available_at'] > $deadline)                          return ['ok'=>false, 'reason'=>'ACQUISITION_DEADLINE_EXCEEDED（取得猶予を超えた）'];
        if ($v['available_at'] > $v['input_freeze_at'])              return ['ok'=>false, 'reason'=>'情報上限 input_freeze_at を超えた'];
        if (($b['source_updated_at'] ?? null) !== null) {
            $su = self::parseTimeUs($b['source_updated_at']);
            if ($su === null)                      return ['ok'=>false, 'reason'=>'source_updated_at が日時として読めない'];
            if ($su > $v['response_received_at'])  return ['ok'=>false, 'reason'=>'取得元の更新時刻が受信完了より未来'];
        }
        return ['ok'=>true, 'reason'=>null];
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-INSTALL  新方式 の新規テーブルを作る
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：詳細設計書9章「MySQLの保存設計」
     *
     *   ・CREATE TABLE IF NOT EXISTS なので、既にあれば何もしません。
     *   ・既存テーブルには一切触れません（元値を更新しません）。
     *   ・原入力・原応答は LONGTEXT で原文のまま保持します。
     *     MySQLのJSON変換後だけを正本にしません。
     *   ・日時はマイクロ秒まで保持します（DATETIME(6)）。
     *   ・race_id は既存正本と同じ照合規則にそろえるため VARCHAR(64) utf8mb4_bin。
     */
    private function runPredictionInstall(): void
    {
        $this->line('');
        $this->line('══════════════════════════════════════════════════════════');
        $this->line(' 新方式 の新しいテーブルを作ります（既にあれば何もしません）');
        $this->line('  既存テーブルには触れません');
        $this->line('══════════════════════════════════════════════════════════');
        $this->line('');

        foreach (self::predictionTableDdl() as $name => $ddl) {
            $existed = \Schema::hasTable($name);
            try {
                \DB::statement($ddl);
            } catch (\Throwable $e) {
                $this->error(sprintf('  %-34s 失敗: %s', $name, $e->getMessage()));
                continue;
            }
            $this->line(sprintf('  %-34s %s', $name, $existed ? '既にありました' : '作りました'));
        }
        // @ANCHOR-20261110-PREDICTION-EXPECTED-COLUMNS  予想の版（E3・E5を使う版）で使う列を足します（あれば何もしません）
        //   baganriki_ai_attempts.send_proof   … 予約した枠と、実際に送る原文を結び付けた印
        //   baganriki_ai_attempts.sent_request … 実際に送った system・user・形式・ハッシュ（訂正のときに初回と照合する）
        //   baganriki_snapshots.expected_proof … 凍結のときに計算した E3・E5 の計算原文と SHA-256
        //   どれも NULL を許すので、今の10/20版の行には影響しません。
        foreach ([['baganriki_ai_attempts', 'send_proof', 'CHAR(65) NULL'],
                  ['baganriki_ai_attempts', 'sent_request', 'LONGTEXT NULL'],
                  ['baganriki_snapshots', 'expected_proof', 'LONGTEXT NULL']] as [$tb, $col, $type]) {
            if (!\Schema::hasTable($tb)) { continue; }
            if (\Schema::hasColumn($tb, $col)) {
                $this->line(sprintf('  %-34s %s', $tb . '.' . $col, '既にありました'));
                continue;
            }
            try {
                \DB::statement("ALTER TABLE `{$tb}` ADD COLUMN `{$col}` {$type}");
                $this->line(sprintf('  %-34s %s', $tb . '.' . $col, '足しました'));
            } catch (\Throwable $e) {
                $this->error(sprintf('  %-34s 失敗: %s', $tb . '.' . $col, $e->getMessage()));
            }
        }
        $this->line('');
        $this->info('  完了しました。--prediction-status で確認できます。');
        $this->line('');
        $this->warn('※テーブルを作っただけです。凍結・AI呼出し・公開の処理は別です。');
        $this->warn('※取得監査（実時刻）は取得処理側に記録を足す必要があります。');
        $this->line('');
    }

    /** @ANCHOR-20261020-PREDICTION-INSTALL  新方式 のテーブルと設定の状態 */
    private function runPredictionStatus(): void
    {
        $this->line('');
        $this->line('══════════════════════════════════════════════════════════');
        $this->line(' 新方式 の状態（読み取りだけ）');
        $this->line('══════════════════════════════════════════════════════════');
        $this->line('');
        $this->info(' 1. テーブル');
        foreach (array_keys(self::predictionTableDdl()) as $name) {
            if (!\Schema::hasTable($name)) {
                $this->line(sprintf('    %-34s %s', $name, 'まだありません'));
                continue;
            }
            $n = \DB::table($name)->count();
            $this->line(sprintf('    %-34s %8d 行', $name, $n));
        }
        $this->line('');
        $this->info(' 2. 版と設定');
        foreach ([
            'implementation_version'  => 'baganriki-v041-r12-2-20261020',
            'prompt_version'          => 'baganriki-v041-r12-2-20261020',
            'schema_version'          => 'baganriki-v041-r12-2',
            'time_contract.version'   => 'baganriki-post-slot-grace-v1',
            'anchor_kind'             => 'POST_TIME',
            'sampling_policy_id'      => 'SLOT_FIRST_VALID_COMMITTED_V1',
            '通常の mode_policy_id'   => 'BASE_TRI_STATE_V1',
            '暫定の mode_policy_id'   => 'X6_ONLY_UPPER_MODE_V1',
            'learning_stage'          => 'COLLECT_ONLY',
            'model_version'           => '(null)',
            'buy_recommended'         => 'false（PENDING_VALIDATION）',
        ] as $k => $v) {
            $this->line(sprintf('    %-26s %s', $k, $v));
        }
        $this->line('');
        $this->info(' 3. 取得猶予（acquisition_grace_ms）');
        $this->line('    6分前                       30,000 ミリ秒');
        $this->line('    21・18・15・12・9分前      120,000 ミリ秒');
        $this->line('    cutoff_at                  発走 − 360秒');
        $this->line('    input_freeze_at            発走 − 330秒');
        $this->line('    publish_deadline           投票締切 − 60秒');
        $this->line('    reply_deadline             公開期限 − 30秒');
        $this->line('');
        $this->info(' 4. まだ足りていないもの');
        $this->warn('    ・投票締切（sales_close_at）の正本がDBにありません。');
        $this->warn('      races には start_time しかなく、締切の列は全テーブルにありません。');
        $this->warn('      契約11.2「正本が特定できないレースは公開しない」。');
        $this->warn('    ・取得監査の実時刻（要求開始・受信完了・利用可能・batch_id）が');
        $this->warn('      既存DBにありません。取得処理側に記録を足す必要があります。');
        $this->warn('      既存の get_datetime は保存完了時刻で、流用しません。');
        $this->line('');
    }

    /** @ANCHOR-20261020-PREDICTION-INSTALL  9本のDDL（詳細設計書9章の表のとおり） */
    private static function predictionTableDdl(): array
    {
        $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $rid = "VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL";
        return [
            'baganriki_race_runs' => "
CREATE TABLE IF NOT EXISTS baganriki_race_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id VARCHAR(64) NOT NULL COMMENT '処理の一意ID',
  race_id {$rid},
  generation INT NOT NULL DEFAULT 0 COMMENT '固定入力の世代。期限延長のために増やさない',
  run_kind ENUM('PRODUCTION','REPLAY') NOT NULL DEFAULT 'PRODUCTION',
  status VARCHAR(32) NOT NULL DEFAULT 'STARTED',
  entry_count INT NULL, acquired_count INT NULL,
  frozen_count INT NULL, active_count INT NULL,
  invalid_code VARCHAR(64) NULL, invalid_reason TEXT NULL,
  release_version VARCHAR(64) NULL,
  created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_run (run_id), KEY ix_race (race_id, run_kind)
) {$opt} COMMENT='r12.2 レース単位の処理記録。snapshotを作れない入力不正でも残す'",

            'baganriki_acquisition_batches' => "
CREATE TABLE IF NOT EXISTS baganriki_acquisition_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  race_id {$rid},
  slot INT NOT NULL COMMENT '21/18/15/12/9/6。3と0は取消監視のみ',
  batch_id VARCHAR(128) NOT NULL COMMENT '同一応答バッチの一意ID',
  slot_scheduled_at DATETIME(6) NULL COMMENT '発走時刻からslot分を引いた予定時刻',
  source_updated_at DATETIME(6) NULL COMMENT '取得元の更新時刻。取れなければNULL',
  request_started_at DATETIME(6) NULL COMMENT '要求を開始した実サーバ時刻',
  response_received_at DATETIME(6) NULL COMMENT '受信し終えた実サーバ時刻（=observed_at）',
  available_at DATETIME(6) NULL COMMENT 'commit後に読めると確認した実時刻。予定で埋めない',
  raw_payload LONGTEXT NULL COMMENT '原値のまま',
  payload_hash CHAR(64) NULL COMMENT '原ペイロードのSHA-256',
  validation_result VARCHAR(32) NULL COMMENT 'ADOPTED / REJECTED',
  rejection_reason TEXT NULL,
  committed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '未確定の監査行は採用しない',
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_batch (batch_id),
  KEY ix_race_slot (race_id, slot, available_at)
) {$opt} COMMENT='r12.2 取得監査。実時刻はここへ'",

            'baganriki_snapshots' => "
CREATE TABLE IF NOT EXISTS baganriki_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  race_id {$rid},
  generation INT NOT NULL DEFAULT 0,
  run_id VARCHAR(64) NULL,
  release_version VARCHAR(64) NULL,
  common_json LONGTEXT NULL COMMENT '両roleへ渡す共通JSONの原文',
  input_json_1st LONGTEXT NULL, input_json_2nd LONGTEXT NULL,
  snapshot_hash CHAR(64) NULL COMMENT '共通JSON本文のSHA-256',
  frozen_nums TEXT NULL COMMENT '凍結出走集合の馬番',
  frozen_rank TEXT NULL COMMENT '馬番=>凍結順位',
  mode VARCHAR(16) NULL, mode_status VARCHAR(16) NULL,
  allocation_mode VARCHAR(16) NULL, mode_policy_id VARCHAR(32) NULL,
  display_rating INT NULL,
  signals TEXT NULL, s_min INT NULL, s_max INT NULL, s0 INT NULL,
  target_count INT NULL, quota_1st TEXT NULL, quota_2nd TEXT NULL,
  quota_adjustments TEXT NULL,
  collection_audit LONGTEXT NULL, coverage LONGTEXT NULL,
  anchor_at DATETIME(6) NULL COMMENT '正本の発走時刻',
  cutoff_at DATETIME(6) NULL, input_freeze_at DATETIME(6) NULL,
  frozen_at DATETIME(6) NULL COMMENT '実際に保存した時刻。input_freeze_atとは別',
  sales_close_at DATETIME(6) NULL COMMENT '投票締切の正本。無ければ公開しない',
  input_invalid_code VARCHAR(64) NULL, input_invalid_reason TEXT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_snap (race_id, generation)
) {$opt} COMMENT='r12.2 凍結入力。固定後は変えない'",

            'baganriki_live_states' => "
CREATE TABLE IF NOT EXISTS baganriki_live_states (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  snapshot_id BIGINT UNSIGNED NOT NULL,
  cancellations TEXT NULL COMMENT '累積の取消馬番。追加のみ',
  active_count INT NULL COMMENT '集合矛盾時はNULL',
  live_target_count INT NULL, live_quota TEXT NULL,
  live_close DATETIME(6) NULL COMMENT '締切の前倒し。延長はしない',
  state_revision BIGINT NOT NULL DEFAULT 0,
  response_revision BIGINT NOT NULL DEFAULT 0,
  publication_revision BIGINT NOT NULL DEFAULT 0,
  latest_publication_id VARCHAR(64) NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_live (snapshot_id)
) {$opt} COMMENT='r12.2 ライブ状態。競合の共通ロック行'",

            'baganriki_ai_attempts' => "
CREATE TABLE IF NOT EXISTS baganriki_ai_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  snapshot_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(8) NOT NULL COMMENT '1st / 2nd',
  attempt_no TINYINT NOT NULL COMMENT '初回0・訂正1のみ',
  token VARCHAR(64) NOT NULL COMMENT '内部専用。公開APIへ出さない',
  status VARCHAR(16) NOT NULL DEFAULT 'RESERVED' COMMENT 'RESERVED/RECEIVED/FAILED',
  reserved_at DATETIME(6) NULL, received_at DATETIME(6) NULL,
  complete TINYINT(1) NOT NULL DEFAULT 0,
  raw_response LONGTEXT NULL COMMENT '原文のまま',
  response_hash CHAR(64) NULL, failure_reason TEXT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_attempt (snapshot_id, role, attempt_no),
  UNIQUE KEY uq_token (token)
) {$opt} COMMENT='r12.2 AI呼出枠。予約してから1回だけ送る'",

            'baganriki_validation_results' => "
CREATE TABLE IF NOT EXISTS baganriki_validation_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  validator_version VARCHAR(32) NOT NULL,
  normal_part LONGTEXT NULL, diagnostics LONGTEXT NULL,
  repair_scope TEXT NULL, field_sources LONGTEXT NULL, rank_sources LONGTEXT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_val (attempt_id, validator_version)
) {$opt} COMMENT='r12.2 応答の検証結果と局所回復'",

            'baganriki_publication_intents' => "
CREATE TABLE IF NOT EXISTS baganriki_publication_intents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  snapshot_id BIGINT UNSIGNED NOT NULL,
  job_id VARCHAR(64) NOT NULL,
  intent_hash CHAR(64) NOT NULL,
  read_state_revision BIGINT NULL, read_response_revision BIGINT NULL,
  read_publication_revision BIGINT NULL,
  state VARCHAR(24) NOT NULL DEFAULT 'OPEN' COMMENT 'OPEN/PUBLISHED/STALE_INTENT',
  publication_id VARCHAR(64) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_job (job_id),
  UNIQUE KEY uq_intent (snapshot_id, intent_hash)
) {$opt} COMMENT='r12.2 公開intent。同じintentの再実行は同じ公開ID'",

            'baganriki_publications' => "
CREATE TABLE IF NOT EXISTS baganriki_publications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  publication_id VARCHAR(64) NOT NULL,
  snapshot_id BIGINT UNSIGNED NOT NULL,
  intent_id BIGINT UNSIGNED NOT NULL,
  published_at DATETIME(6) NOT NULL,
  prediction_target_count INT NULL, prediction_quota TEXT NULL,
  publication_target_count INT NULL, publication_quota TEXT NULL,
  active_count INT NULL,
  candidates LONGTEXT NULL, evidence LONGTEXT NULL, shortfalls TEXT NULL,
  used_state_revision BIGINT NULL, used_response_revision BIGINT NULL,
  public_proof_hash TEXT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_pub_intent (intent_id),
  KEY ix_pub (publication_id), KEY ix_snap (snapshot_id, published_at)
) {$opt} COMMENT='r12.2 公開履歴。追記型'",

            'baganriki_audit_events' => "
CREATE TABLE IF NOT EXISTS baganriki_audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  race_id {$rid},
  snapshot_id BIGINT UNSIGNED NULL,
  kind VARCHAR(48) NOT NULL,
  happened_at DATETIME(6) NOT NULL,
  old_revision BIGINT NULL, new_revision BIGINT NULL,
  diagnostics LONGTEXT NULL, related_id VARCHAR(64) NULL,
  PRIMARY KEY (id), KEY ix_race_time (race_id, happened_at)
) {$opt} COMMENT='r12.2 監査イベント'",
        ];
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-PROMPT  同じファイルの中のクラスを読ませる一手間
     * ═══════════════════════════════════════════════════════════════════════
     * PSR-4 の自動読み込みは「クラス名＝ファイル名」で探すので、
     * AiController.php の<b>中に</b>置いた BaganrikiPrompt などは、
     * 外から名前で呼んでも見つかりません
     * （Class "App\Http\Controllers\Api\BaganrikiPrompt" not found）。
     * 先に AiController を読ませると、同じファイルにある全クラスが入ります。
     *
     * これは「新しいファイルを作らない」という決めごとの副作用です。
     * 10月19日の compareWithProduction() で同じ手当てをしていましたが、
     * 今回足した入口（--prediction-prompt-install / --prediction-prompt-check）に
     * 入れ忘れていて、サーバで同じエラーが出ました。
     */
    private static function loadPredictionClasses(): void
    {
        if (!class_exists(\App\Http\Controllers\Api\BaganrikiPrompt::class, false)) {
            class_exists(\App\Http\Controllers\Api\AiController::class);
        }
    }

    /**
     * 試験の間だけ .env の値を差し替えて $fn を動かし、終わったら元に戻します（本番の設定は変えません）
     * @param array<string,string> $vars
     */
    private function withEnv(array $vars, callable $fn)
    {
        $keep = [];
        foreach ($vars as $k => $v) {
            $keep[$k] = [getenv($k), $_ENV[$k] ?? null, $_SERVER[$k] ?? null];
            putenv($k . '=' . $v); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        try {
            return $fn();
        } finally {
            foreach ($keep as $k => [$g, $e, $sv]) {
                if ($g === false) { putenv($k); } else { putenv($k . '=' . $g); }
                if ($e === null) { unset($_ENV[$k]); } else { $_ENV[$k] = $e; }
                if ($sv === null) { unset($_SERVER[$k]); } else { $_SERVER[$k] = $sv; }
            }
        }
    }

}
