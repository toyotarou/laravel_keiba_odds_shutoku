<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

// @ANCHOR-20261023-NARU-011  PHP 8.0 には array_is_list が無いので、この名前空間に用意します
//   （011再精査版の直しが array_is_list を使うため。本番は PHP 8.0.30）
if (!function_exists(__NAMESPACE__ . '\\array_is_list')) {
    function array_is_list(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) { return false; }
        }
        return true;
    }
}

// ##############################################################################
// ##  AiController.php の目次
// ##############################################################################
// ##
// ##  馬眼力の予想を作って、アプリへ返すところです。予想は「新しい方式」（よっしーの
// ##  プロンプト 新方式 を 1st＝Claude・2nd＝DeepSeek に送る方式）だけで作ります。
// ##  以前の方式（11/6 まで）のAI呼び出しはありません。過去レースの表示のために、
// ##  以前の方式で保存した予想を読み出す部分だけ残しています。
// ##
// ##  ── 流れ（1レース） ───────────────────────────────────────────────
// ##   1. 巡回（cron：keiba:ai-analysis-compensate --prediction-sweep）が、発走の
// ##      300秒前に入力を固める（凍結）  … AiController::sweepFreezeTargetRaces
// ##   2. 同じ巡回が 1st・2nd を送る       … sendInitialAiRequest → callPredictionAi
// ##   3. 応答を検査して採否を決め、公開する … inspectAiResponse → acceptedHorsesOf → publishPrediction
// ##   4. アプリの取得口が、公開した予想を返す（公開が無ければ理由の文言。11/10 から補充はしません） … predictionAnswerFor
// ##
// ##  ── AiController の中（見出しの文字で検索してください） ─────────────────
// ##   定数と設定
// ##   アプリの取得口（routes/api.php から呼ばれる）
// ##   以前の方式で保存した過去の予想の読み出し
// ##   新しい予想を、取得口の形にして返す
// ##   入力を集めて固める（凍結）
// ##   AI枠の予約・応答の検査・採否・公開
// ##   AIへの送信・訂正・巡回・Job・実状態の反映
// ##   ほかのクラスへの窓口（ほかのファイルからの呼び方を変えないための1行の転送）
// ##
// ##  ── 同じファイルの中のクラス ─────────────────────────────────────────
// ##   BaganrikiAnswer       予想を画面の形にする部品（理由文・状態。補充の部品は 11/10 から使っていません）
// ##   BaganrikiInput        AIへ渡す入力の材料（出馬表・オッズ・過去走・市場の事前分布）
// ##   BaganrikiRaceState    レースごとの状態（役割の完了・処理経路・対象レース・発走時刻と取消）
// ##   BaganrikiUsage        AIの使用量・料金・1日の予算
// ##   BaganrikiSendConfig   送るときの設定（送信形式・3指標・プロンプトの保存・訂正の方針・偽AI・読取権限）
// ##   BaganrikiSignals      レース傾向の信号判定
// ##   BaganrikiAllocation   人気帯ごとの頭数の配分
// ##   BaganrikiTime         時刻の決まり（凍結・応答期限・公開期限）
// ##   BaganrikiAcquisition  オッズ取得バッチの採用
// ##   BaganrikiSnapshot     凍結した集合・頭数・共通JSON・hash
// ##   BaganrikiRanking      順位・統合・公開候補
// ##   BaganrikiResponse     AIの応答の取り出し・帳簿の直し・JSONの記号の直し
// ##   BaganrikiPublicView   読取API用の表示
// ##   BaganrikiPrompt       両AIの本文（storage/app/baganriki/prompts/r12_2）と INPUT_JSON
// ##   BaganrikiWorker       AI枠の予約とキュー
// ##   BaganrikiDiagnose     応答の診断
// ##   BaganrikiDecimal / BaganrikiEmptyObject / BaganrikiJson   JSONの厳密な読み書き
// ##
// ##   ── E3・E5を使う版（11/10〜）で足したクラス ──（20261112 目次に追記）
// ##   BaganrikiExpectedFiles        この版が読むデータファイルの置き場所（storage/app/baganriki/prompts/expected）
// ##   BaganrikiText                 構造の検査と組み直しで共通に使う文字の扱い（元の文字を変えない）
// ##   BaganrikiDerivedValue         保存した数値の文字から、r12 の派生値を厳密に計算し直す
// ##   BaganrikiMarketClaim          市場の主張（支持加速など）を型で確かめる
// ##   BaganrikiGapClaim             断層（gap）の根拠が、固定隣接ペアの比 2.00以上を引用しているか確かめる
// ##   BaganrikiOutputSchema         両AIの応答を、同梱の schema で検査する
// ##   BaganrikiExpectedPacket       凍結済みのデータから、両AI共通の E3・E5 パケットを作る
// ##   BaganrikiFrozenInput          保存した入力を読み直して確かめる（fromStored だけが組み立てられる）
// ##   BaganrikiAttemptWindow        AI枠の記録（DBの完了フラグなど）を読むときの決まり
// ##   BaganrikiRecovery             保存済みの応答どうしを比べ、組み直しに使えるものを集める
// ##   BaganrikiResponseFrame        応答の外側の行（区切りの印）だけを読む
// ##   BaganrikiResponseInspection   応答の検査（訂正の提案。公開を許すのは保存入力と原文による独立の検査だけ）
// ##   BaganrikiScoreMaterials       H/V の点に必要な最低限の材料
// ##   BaganrikiSourceEvidence       根拠の出典（入力のどの値を引用したか）を確かめる
// ##   BaganrikiMarketState          凍結した市場の分類と根拠を、同じレースから作り直す
// ##   BaganrikiReturnStats          発走前のDB集計（回収率など）を、観測値の組にする
// ##   BaganrikiRoleJournal          役割ごとの要約を、受領・採用の行と一緒に記録する
// ##   BaganrikiDispatch             実際に送った2つの本文（system・user）。サーバーに残し、あとで再現できる
// ##   BaganrikiExpectedBridge       共通の入力に E3・E5 の部分をつなぐ（凍結・送信の前に版を確かめる）
// ##   BaganrikiPublishedRead        公開した予想と、取消などの実状態を読む
// ##   BaganrikiReplyTidy            応答の「書き方だけのミス」を、機械的に一つに決まるものだけ直す
// ##   BaganrikiRaceMetrics          レース指標（波乱度・下位進入度・大穴進入度）の検査
// ##   BaganrikiCandidateSelection   検証済みの基礎順位から、候補を一度だけ選ぶ
// ##
// ##  ── 設定（.env） ─────────────────────────────────────────────────────
// ##   BAGANRIKI_PREDICTION_PUBLIC=1   新しい予想をアプリへ出す
// ##   BAGANRIKI_PREDICTION_PROMPT / _QUEUE / _TARGETS / _BUDGET_USD_DAY / _1ST_MODEL など
// ##   ほかに BAGANRIKI_PREDICTION_METRICS（3指標）・_JSON_REPAIR（記号の直し）・_CLERICAL_FIX（帳簿の直し）
// ##
// ##############################################################################

class AiController extends Controller
{
    /** Snapshot and effective deadlines used only while this controller inspects a E3・E5版の publication. */
    private ?array $publicationReadWindow = null;
    // @ANCHOR-20261110-PREDICTION-RESEND-VERIFY  送り直しの比べ方（よっしー11/10夜 ③）：まだ保存していない返事を、本番と同じ検査に通して数えるときだけ入れます（保存はしません）
    private ?string $dryInspectRaw = null;


    // ##########################################################################
    // ## 定数と設定
    // ##########################################################################

    /**
     * シャドー検証中だけの内部値。Flutter へ返す候補からは必ず除去する。
     *
     * 【仕様】受入チェック #36・#51 および【シャドー表示制御】
     *   「シャドー検証中の強化指標・馬券判定は内部計算とログ保存だけに使用し、
     *     Flutter の候補・順位・選出理由へ影響しない」
     *
     * 【なぜ定数にしたか】同じ一覧を2か所（AI実行直後の応答と、保存済み統合結果の
     *   読み出し）で使うため。片方だけ直して食い違う事故を防ぐ。
     * ★本番有効化が決まるまで、このキー一覧を減らしてはいけない。
     */
    private const SHADOW_ONLY_KEYS = [
        // ── 20261004 追加 ───────────────────────────────────────────────
        //   全頭採点（内部用JSON）から取り込んだ信頼度小計。
        //   仕様「内訳・小計は公開出力へ追加しない」に従い Flutter へは返さない。
        //   merge_result には保存され、監査・タイブレークの追跡に使える。
        //   @ANCHOR-20261004-AUDIT-ALLSCORE
        'conf_1st',
        'conf_2nd',
        'fake_inflow_warning',   // B-8 偽流入警戒
        'fake_inflow_true_cnt',  // B-8 成立条件数
        'high_payout_score',     // B-10 高配当総合点
        'market_score',          // B-10 市場妙味基礎点
        'ability_grade_merged',  // B-10 統合後の能力適性グレード
        'ability_corr_merged',   // B-10 統合後の能力適性補正点
        'mismatch_category',     // B-10 不一致馬A〜D分類
        'betting_judgment',      // B-10 馬券判定
        // ── 20260926 追加（よっしー指摘 01.txt）──────────────────────────
        //   Block 12 / Block 13a を「候補除外」から「購入回避フラグ」へ変更した際に
        //   各馬へ付与する内部値。馬券購入判定の材料であり、
        //   仕様 #36・#51「シャドー検証中の馬券判定はFlutterへ影響させない」に従い
        //   Flutter へは返さない（merge_result にはそのまま保存され、追跡に使える）。
        //   ※「見送り」「監視」の画面表示を行う場合は、
        //     馬券判定をシャドーから外す判断とあわせて、ここから除く必要がある。
        'buy_avoid_recovery',    // B-12 回収率による購入回避フラグ
        'recovery_detail',       // B-12 3種類の回収率・サンプル数・閾値未満の個数
        'buy_avoid_low_payout',  // B-13a 低配当による購入回避フラグ
        'low_payout',            // B-13a 低配当条件に該当したか
        'low_payout_released',   // B-13a 例外3条件で解除されたか
        'low_payout_fuku_min',   // B-13a 判定に使った推定確定複勝最小
        'low_payout_tan_odds',   // B-13a 判定に使った推定確定単勝
        // ── 20260927 追加（よっしー20260927指摘）────────────────────────────
        //   Block 13b（最低基準点）と大穴選出条件を「候補除外」から
        //   「優先目安＋購入回避フラグ」へ変更した際に各馬へ付与する内部値。
        //   目標数C頭を目指すため候補からは削除せず、
        //   順位づけの優先材料と馬券購入判断にだけ使う。
        //   @ANCHOR-20260927-NO-HARD-EXCLUDE
        'below_min_score',            // B-13b 最低基準点未満か
        'min_score_required',         // B-13b 人気帯から決まる必要点（70/60/55）
        'longshot_cond_ok',           // 大穴条件の成立（10番人気以内は null）
        'longshot_cond_detail',       // 大穴条件 c2〜c5 の3値判定
        'buy_avoid_longshot',         // 大穴条件不成立による購入回避フラグ
        'longshot_bc_special',        // タイプB・C特例に該当したか
        'longshot_bc_special_rank',   // タイプB・C特例馬の序列（旧1頭制限の代替）
    ];

    /**
     * 可変配分（20261004仕様）が有効かどうか。
     * .env の KEIBA_ALLOC_20261004 があればそれを優先し、無ければ定数を使う。
     * 不正な値・未設定は定数の値とみなす（配分を勝手に止めないため）。
     */

    public const AUDIT_JSON_BEGIN = '<<<BAGANRIKI_ALLSCORE_BEGIN>>>';
    public const AUDIT_JSON_END   = '<<<BAGANRIKI_ALLSCORE_END>>>';

    

    /** 付録D signal_thresholds_research_only。検証済みの本番値ではない */
    public const V04_THRESHOLDS = [
        'win_wall'                       => 2.00,
        'place_wall'                     => 1.50,
        'win_wall_shrink_fraction'       => 0.20,
        'middle_close_upper_bound'       => 1.50,
        'place_shorten_21_to_6_fraction' => 0.05,
        'place_shorten_9_to_6_fraction'  => 0.03,
        'similar_min_races'              => 50,
        'similar_3in_delta_pp'           => 10.0,
    ];

    /** S = 2*X1 + X2 + X3 + X4 + X5 + X6 - 2*Z */
    public const V04_SIGNAL_WEIGHTS = [
        'X1' => 2, 'X2' => 1, 'X3' => 1, 'X4' => 1, 'X5' => 1, 'X6' => 1, 'Z' => -2,
    ];
    public const V04_MODE_FIRM_MAX   = 1;   // S<=1 → FIRM
    public const V04_MODE_MIXED_MAX  = 3;   // 2<=S<=3 → MIXED、S>=4 → VOLATILE

    /**
     * 付録D quota_by_target。[A, B, D]
     *
     * @ANCHOR-20261020-PREDICTION-QUOTA  2026-10-20 受領の 新方式 で C7 混戦が変わりました。
     *   変更計画書v1.2 第3章／詳細設計書6章／config/r122_contract.json の base_quota は
     *   いずれも C7 MIXED = 3/3/1 です（「C7混戦の人気側3頭＋穴側4頭を維持する」）。
     *   【旧】7 => [... 'MIXED' => [4, 2, 1] ...]  ← 人気側4頭。新方式で使いません。
     *   他の行（C4〜C6、C7のFIRM・VOLATILE）は 新方式 と同じ値でした。
     */
    public const V04_QUOTA_BY_TARGET = [
        7 => ['FIRM' => [5, 2, 0], 'MIXED' => [3, 3, 1], 'VOLATILE' => [3, 3, 1]],
        6 => ['FIRM' => [4, 2, 0], 'MIXED' => [3, 2, 1], 'VOLATILE' => [3, 2, 1]],
        5 => ['FIRM' => [4, 1, 0], 'MIXED' => [3, 2, 0], 'VOLATILE' => [2, 2, 1]],
        4 => ['FIRM' => [3, 1, 0], 'MIXED' => [2, 2, 0], 'VOLATILE' => [2, 1, 1]],
    ];

    /** 07章B未決。基準率が決まるまで null（X6は必ずUNKNOWN） */
    public const V04_X6_BASELINE = null;

    // ═════════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261013-V04-CORE  第二世代 v0.4-X 統一仕様の構造判定（記録だけ）
    // ═════════════════════════════════════════════════════════════════════════
    // よっしー20261013提出「馬眼力 第二世代・統一版v0.4-X」
    //   config_version = v04-x-unified-20261013
    //   scoring_version = hv10-v04 / allocation_version = abd-x-v04
    //
    // 【v0.3（20261012）との関係】
    //   20261012の提出パックには X1〜X6＋Z 方式と Q1〜Q9 方式の2系列があり、
    //   判定式・UNKNOWN時の扱い・4〜6頭の配分が食い違っていた。v0.4-X は
    //   そのうち X 方式を正式系列として一本化したもの。
    //   採用理由は「10/12に AUDIT_V03_SHADOW がこの計算法を記録しているので
    //   比較しやすい」であり、X方式の予測精度が実証されたからではない（付録G）。
    //   Q1〜Q9 は research_variant=Q_V03 として凍結し、ここには混ぜない。
    //
    // 【v0.3シャドー実装（@ANCHOR-20261012-V03-SHADOW）との差分】
    //   衝突する箇所は新しい v0.4 を優先する。具体的な差分は以下の6点。
    //     ①早期FALSE短絡：上側の値が有効で閾値を満たさないと分かる時点でFALSE。
    //       v0.3実装は片方でも欠損なら不明にしていたため、不明が過剰に出ていた。
    //     ②X3は N<7 を「構造的非該当＝FALSE」とする（v0.3実装は不明）。
    //     ③X5の対象境界が存在しない場合は FALSE（v0.3実装は不明）。
    //     ④件数条件（X4・Z）は tri_at_least に統一。
    //       成立数>=2→TRUE／成立数+不明数<2→FALSE／それ以外→UNKNOWN。
    //       v0.3実装は「不明が1つでもあれば不明」としていたため、
    //       成立0・不明1（＝どう転んでも2件に届かない）を不明にしていた。
    //     ⑤不明がある場合 S は null（確定値を出さない）。S_min/S_max だけを出す。
    //     ⑥mode=UNKNOWN のときの配分ラベルを allocation_mode=MIXED_PROVISIONAL
    //       （@ANCHOR-20261020-PREDICTION-MODE  新方式 で廃止。UNKNOWNでMIXEDを流用しません）
    //       として明示する（配分自体はMIXEDを流用するが、別物として記録する）。
    //
    // 【この一式は本番に出さない】
    //   提出資料に「この一式をそのまま本番にデプロイすることは禁止」
    //   「スイッチの既定値はOFF」と明記されている。したがって
    //   BAGANRIKI_V04_ENABLED=false の間は選出・表示・DBに一切影響しない。
    // ═════════════════════════════════════════════════════════════════════════

    // /**
    // * getHorseOddsFinderAiRecoverySummary
    // *
    // * 【K-2】直近 100 / 300 / 600 レースの累積回収率
    // *   単勝回収率 = SUM(tan_bet) ÷ (SUM(ai_pick_count) × 100) × 100
    // *   複勝回収率 = SUM(fuku_bet) ÷ (SUM(ai_pick_count) × 100) × 100
    // *
    // * 【K-3】最大連敗数・最大ドローダウン（直近100レース・全期間）
    // *   連敗: tan_hit == 0 が何レース連続したかの最大値
    // *   ドローダウン: 累積損益がピークから最大いくら落ちたか（円単位・正の値）
    // *
    // * 【K-3補足】人気帯別回収率（1〜3番人気 / 4〜6番人気 / 7番人気以下）
    // *   t_horse_odds_finder_race_result_history.popularity_rank で分類
    // *
    // * @return \Illuminate\Http\JsonResponse
    // */
    // public function getHorseOddsFinderAiRecoverySummary()
    // {
    // // ─── 全レコードを新しい順に取得 ──────────────────────────────────
    // // ※ t_horse_odds_finder_ai_recovery は現在未使用のためコメントアウト中
    // // ※ SummaryAiRecoveryRate を再有効化する際にコメントを外すこと
    // // $allRows = DB::table('t_horse_odds_finder_ai_recovery')
    // //     ->orderBy('date',       'desc')
    // //     ->orderBy('kaisuu',     'desc')
    // //     ->orderBy('basho_code', 'desc')
    // //     ->orderBy('day',        'desc')
    // //     ->orderBy('race',       'desc')
    // //     ->get(['ai_pick_count', 'tan_bet', 'fuku_bet', 'tan_hit', 'fuku_hit']);
    // $allRows = collect([]);

    // // ─── K-2: 累積回収率を計算するクロージャ ──────────────────────
    // $calcCumulative = function (int $limit) use ($allRows): array {
    // $slice      = $allRows->take($limit);
    // $totalPicks = $slice->sum('ai_pick_count');
    // $totalBet   = $totalPicks * 100;
    // if ($totalBet <= 0) {
    // return [
    // 'race_count'     => $slice->count(),
    // 'tan_rate'       => null,
    // 'fuku_rate'      => null,
    // 'tan_total_bet'  => 0,
    // 'fuku_total_bet' => 0,
    // 'total_invest'   => 0,
    // ];
    // }
    // return [
    // 'race_count'     => $slice->count(),
    // 'tan_rate'       => round($slice->sum('tan_bet')  / $totalBet * 100, 1),
    // 'fuku_rate'      => round($slice->sum('fuku_bet') / $totalBet * 100, 1),
    // 'tan_total_bet'  => (int) $slice->sum('tan_bet'),
    // 'fuku_total_bet' => (int) $slice->sum('fuku_bet'),
    // 'total_invest'   => (int) $totalBet,
    // ];
    // };

    // // ─── K-3: 最大連敗数を計算するクロージャ ──────────────────────
    // // tan_hit == 0 なら単勝ハズレ、fuku_hit == 0 なら複勝ハズレ
    // // 連敗数の最大値を求めるだけなので昇降順どちらでも結果は同じ
    // $calcMaxConsecutiveLosses = function ($rows): array {
    // $tanMax  = 0; $tanCur  = 0;
    // $fukuMax = 0; $fukuCur = 0;
    // foreach ($rows as $r) {
    // if ((int) $r->tan_hit === 0) {
    // $tanCur++;
    // if ($tanCur > $tanMax) $tanMax = $tanCur;
    // } else {
    // $tanCur = 0;
    // }
    // if ((int) $r->fuku_hit === 0) {
    // $fukuCur++;
    // if ($fukuCur > $fukuMax) $fukuMax = $fukuCur;
    // } else {
    // $fukuCur = 0;
    // }
    // }
    // return ['tan' => $tanMax, 'fuku' => $fukuMax];
    // };

    // // ─── K-3: 最大ドローダウンを計算するクロージャ ────────────────
    // // ドローダウン = 累積損益がピークから最大いくら落ちたか（正の値・円単位）
    // // 例: ピーク +5,000円 → 谷 -3,000円 → ドローダウン = 8,000円
    // // rows は新→古の降順なので reverse() して古→新の時系列順で処理する
    // $calcMaxDrawdown = function ($rows): array {
    // $chronological = $rows->reverse()->values();

    // $tanRunning  = 0; $tanPeak  = 0; $tanMaxDD  = 0;
    // $fukuRunning = 0; $fukuPeak = 0; $fukuMaxDD = 0;

    // foreach ($chronological as $r) {
    // $invest = (int) $r->ai_pick_count * 100;

    // // 単勝
    // $tanRunning += ((int) $r->tan_bet - $invest);
    // if ($tanRunning > $tanPeak) $tanPeak = $tanRunning;
    // $dd = $tanPeak - $tanRunning;
    // if ($dd > $tanMaxDD) $tanMaxDD = $dd;

    // // 複勝
    // $fukuRunning += ((int) $r->fuku_bet - $invest);
    // if ($fukuRunning > $fukuPeak) $fukuPeak = $fukuRunning;
    // $dd = $fukuPeak - $fukuRunning;
    // if ($dd > $fukuMaxDD) $fukuMaxDD = $dd;
    // }

    // return ['tan' => (int) $tanMaxDD, 'fuku' => (int) $fukuMaxDD];
    // };

    // $rows100 = $allRows->take(100);

    // // ─── K-3補足: 人気帯別回収率 ────────────────────────────────────
    // $popularityStats = $this->_calcAiRecoveryByPopularity();

    // return response()->json(['data' => [
    // // K-2: 累積回収率
    // 'cumulative' => [
    // '100' => $calcCumulative(100),
    // '300' => $calcCumulative(300),
    // '600' => $calcCumulative(600),
    // ],
    // // K-3: 最大連敗数
    // 'max_consecutive_losses' => [
    // 'last_100' => $calcMaxConsecutiveLosses($rows100),
    // 'all_time' => $calcMaxConsecutiveLosses($allRows),
    // ],
    // // K-3: 最大ドローダウン（円単位）
    // 'max_drawdown' => [
    // 'last_100' => $calcMaxDrawdown($rows100),
    // 'all_time' => $calcMaxDrawdown($allRows),
    // ],
    // // K-3補足: 人気帯別回収率
    // 'by_popularity' => $popularityStats,
    // // メタ情報
    // 'total_race_count' => $allRows->count(),
    // ]]);
    // }

    // ════════════════════════════════════════════════════════════════════════════
    // getHorseOddsFinderSecondAiOpinion() から抽出した private メソッド群
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261102-PREDICTION-PUBLIC  新しい予想を今の画面に出す（11/7 から）
     * ═══════════════════════════════════════════════════════════════════════
     *   【何をするか】
     *     BAGANRIKI_PREDICTION_PUBLIC=1 のとき、新しい予想の対象レースでは、
     *     今のアプリが使っている取得口
     *       ・getHorseOddsFinderAiAnalysis（1st）
     *       ・getHorseOddsFinderSecondAiOpinion（統合結果 merged_horses）
     *       ・HistoryController::getHorseOddsFinderAiAnalysisByDate（1日分）
     *     から、新しい予想の公開結果を<b>今と同じ形</b>で返し、旧方式のAIは呼びません。
     *     アプリに鍵を入れる必要はなく、お客様の見方も変わりません。
     *
     *   【効く条件（全部そろったときだけ。1つでも欠ければ今までどおり旧方式）】
     *     BAGANRIKI_PREDICTION_PUBLIC=1、BAGANRIKI_PREDICTION_PROMPT=1、BAGANRIKI_PREDICTION_QUEUE=1、
     *     そのレースが BAGANRIKI_PREDICTION_TARGETS の対象（ALL なら全レース）。
     *     さらに、そのレースに新しい予想の凍結入力があるか、当日の発走前であること。
     *     凍結入力の無い過去のレース（旧方式の時代のレース）は、旧方式の保存済みを返します。
     *
     *   【返すもの】
     *     1. 公開済み            … 公開候補（取消になった馬は除く）を、馬名・人気・6分前オッズ・
     *                              おすすめ度・選出理由の形で返す
     *     2. 予想を作っている途中 … not_ready（画面は「まだ」のまま。再読込みでAIは呼ばない）
     *     3. 出せなかった         … 旧方式の保存済みがあればそれ、無ければ status=undetermined
     *                              （画面は「AIの回答を受け取れなかったため…」）
     *     4. 凍結できなかった     … 発走まで時間があり、予算も足りるときだけ、旧方式に切り替える
     *                              （PREDICTION_FALLBACK_LEGACY を1回だけ記録。予算は先に押さえる）
     *
     *   【選出理由】AIが書いた文章（explanation）は検証なしで出さない決まりなので使いません。
     *     凍結した入力の値（6分前・21分前の単勝、複勝、近走の着順）と、
     *     サーバが確かめた根拠の系統（family_review が SUPPORTED のもの）だけで、決まった形の文を作ります。
     *   【おすすめ度】統合点（0〜100、両AIの公開候補の加点 +5 を含む）を四捨五入し、100 で止めます。
     */
    public const PREDICTION_FALLBACK_KIND = 'PREDICTION_FALLBACK_LEGACY';

    /** 根拠の系統の画面用の名前 */
    public const PREDICTION_FAMILY_LABEL = [
        'market'        => 'オッズの動き',
        'ability'       => '能力と適性',
        'gap'           => 'オッズの断層',
        'win_place'     => '単勝と複勝の差',
        'similar_value' => '似たレースの統計',
    ];

    /** 同じリクエストの中で同じレースを何度も読まないための覚え書き */
    private static array $predictionAnswerMemo = [];
    // @ANCHOR-20261110-PREDICTION-ANSWER-TESTGEN  試験の世代（900）で、画面に返す中身を確かめるときだけ入れます（本番は null のまま。対象レースの見張りも本番どおり）
    public static ?int $answerTestGeneration = null;

    /**
     * @ANCHOR-20261020-PREDICTION-SALVAGE  採点まわりだけの指摘コード
     *   この範囲の指摘しか無い馬は、採点欄だけを落として行は残します。
     *   根拠・5系統・帯・順位・資格の指摘が混ざっていたら残しません。
     */
    public const SCORE_ONLY_ERROR_CODES = [
        'SCORES_TYPE', 'SCORES_UNKNOWN_KEY', 'SCORES_MISSING', 'SCORES_RANGE',
        'OBSERVED_SUM', 'OBSERVED_CAP', 'TOTAL_SCORE',
        'SCORE_EVIDENCE_TYPE', 'SCORE_EVIDENCE_UNKNOWN_KEY',
        'SCORE_EVIDENCE_MISSING', 'SCORE_WITHOUT_EVIDENCE',
        'SCORE_NULL_WITHOUT_REASON', 'SCORE_EVIDENCE_REF_UNKNOWN',
        'SCORE_EVIDENCE_REF_NOT_OWNED', 'EXPECTED_SCORE_MATERIAL', 'EXPECTED_SCORE_PROOF_FIELDS',
    ];

    /** このレースで使う経路（JOB／SYNC）を記録する監査の種別 */
    public const PATH_FIXED_AUDIT_KIND = 'R122_PATH_FIXED';

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261021-PREDICTION-TARGET12  10月24日の非公開12レースだけに限る
     * ═══════════════════════════════════════════════════════════════════════
     *
     * ■ 決め方
     *   .env の BAGANRIKI_PREDICTION_TARGETS に race_id を読点（,）で並べます。
     *     BAGANRIKI_PREDICTION_TARGETS=20261024-5-05-1-1,20261024-5-05-1-2,…
     *   <b>空のときは1つも送りません。</b>「指定が無ければ全部送る」に
     *   しません。逆にしておくと、設定を入れ忘れた日に全レースへ
     *   送ってしまいます。
     *
     * ■ どこで見るか
     *   ・sendInitialAiRequest（初回の送信許可の直前）
     *   ・sendCorrectionRequest（訂正の送信許可の直前）
     *   ・sweepPendingPredictions（巡回が拾ったとき）
     *   ・afterCachedResponse（キャッシュ後の後始末）
     *   ・PredictionRunRoleJob（待ち行列から出たとき。積まれてから時間が経つため）
     *   同じ関数を全部から呼びます。判定を書き写しません。
     *
     * @return array ['ok','code','reason','count','targets']
     */
    // ══════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261023-PREDICTION-TARGETSHORT  対象レースを「日付-場-R」でも書けます
    // ══════════════════════════════════════════════════════════════════════
    //   race_id（20261018-4-04-2-10）の「回」「日」は、当日朝6時の取り込みまで
    //   DBに入らないので、前夜には書けません。そこで
    //     BAGANRIKI_PREDICTION_TARGETS=20261024-05-11,20261024-05-12
    //   （日付8桁-場コード2桁-レース番号）でも書けるようにしました。
    //   取り込み後に t_horse_odds_finder_races から本来の race_id へ読み替えます。
    //   まだ取り込み前なら、その行は一覧に出しません（＝1レースも送りません）。
    //   本来の race_id で書いた行は、いままでどおりそのまま使います。
    public static array $shortTargetIdCache = [];

    /**
     * @ANCHOR-20261020-PREDICTION-WIRE  訂正に使う時間の決めごと
     *
     *   時刻の並びは 発走T → 投票締切 T-60 → 公開期限 T-120
     *   → 応答期限 T-150 → 入力凍結 T-330 → cutoff T-360 です。
     *   訂正は<b>応答期限までに受け取れなければ意味がありません</b>
     *   （過ぎて届いたものは遅着で捨てます）。
     *   残り時間がこれ未満なら、枠を取らずに初回の正常部分で進みます。
     */
    public const CORRECTION_MIN_SEC      = 25;   // これ未満なら訂正を送りません
    public const CORRECTION_MAX_WAIT_SEC     = 60;   // 訂正の待ち時間の上限（秒）
    public const SWEEP_RECEIVE_GRACE_SEC  = 30;
    // @ANCHOR-20261021-PREDICTION-SENDFIRST  初回のやり直しに必要な残り時間
    public const FIRST_REQUEST_MIN_SEC   = 40;   // これ未満なら初回を送りません
    public const FIRST_REQUEST_MAX_WAIT_SEC  = 170;  // 初回の待ち時間の上限（秒）   // 受領直後はやり直しで触りません

    // ── @ANCHOR-20261023-NARU-BUDGET  旧版（なる 10/23 4通目のご指摘で下の BUDGET2 に置き換え。消さずに残します）──
    //     /**
    //      * ═══════════════════════════════════════════════════════════════════════
    //      * @ANCHOR-20261023-NARU-BUDGET  送信前の予算制御（なる 10/23 2通目 6章）
    //      * ═══════════════════════════════════════════════════════════════════════
    //      *   BAGANRIKI_PREDICTION_BUDGET_USD_DAY に1日（日本時間）の上限をドルで書くと、送る前に
    //      *     その日の使用額（実際の usage から計算）＋予約中の額＋この呼び出しの最悪額
    //      *   が上限を超えるなら<b>送りません</b>（BUDGET_EXCEEDED）。書かなければ制御しません。
    //      *   最悪額は、入力を「本文のバイト数＝トークン数」（実際より多めに出ます）・高い単価帯・
    //      *   キャッシュ書込み（入力の1.25倍）、出力を max_tokens いっぱい・高い単価帯で見積ります。
    //      *   DeepSeek はピーク時間の単価で見積ります。複数の worker が同時に判断しても上限を
    //      *   超えないよう、判断と予約は MySQL の名前付きロックの中で行います。
    //      *   予約は R122_BUDGET_HOLD、実際の額は R122_USAGE の cost_usd に残します。
    //      *   単価は 2026年10月23日に公式の料金表で確認した値です（変わったらここを直します）。
    //      */
    //     public const PRICE_USD_PER_MTOK = [
    //         // [入力10万以下: 入力, 出力] [入力10万超: 入力, 出力]
    //         'claude-haiku-5-5' => ['low' => [0.10, 0.50], 'high' => [0.50, 2.50], 'tier' => 100000],
    //         'claude-haiku-4-5' => ['low' => [1.00, 5.00], 'high' => [1.00, 5.00], 'tier' => null],
    //         // DeepSeek：入力（キャッシュなし）・出力・入力（キャッシュあり）。ピーク時間の単価
    //         'deepseek-flash'   => ['peak' => [0.30, 1.20, 0.006], 'offpeak' => [0.15, 0.60, 0.003]],
    //     ];

    //     public static function budgetUsdDay(): ?float
    //     {
    //         $v = trim((string) env('BAGANRIKI_PREDICTION_BUDGET_USD_DAY', ''));
    //         return ($v !== '' && is_numeric($v) && (float) $v > 0) ? (float) $v : null;
    //     }

    //     /** usage から実際の額（ドル）。モデルが分からなければ高い側で数えます */
    //     public static function costFromUsage(string $role, ?string $model, ?array $u): ?float
    //     {
    //         if (!is_array($u)) { return null; }
    //         if ($role === '2nd') {
    //             $p = self::PRICE_USD_PER_MTOK['deepseek-flash']['peak'];
    //             $hit  = (int) ($u['prompt_cache_hit_tokens'] ?? 0);
    //             $miss = (int) ($u['prompt_cache_miss_tokens'] ?? max(0, (int) ($u['prompt_tokens'] ?? 0) - $hit));
    //             $out  = (int) ($u['completion_tokens'] ?? 0);
    //             return ($miss * $p[0] + $out * $p[1] + $hit * $p[2]) / 1000000;
    //         }
    //         $m = (string) $model;
    //         $tab = null;
    //         foreach (self::PRICE_USD_PER_MTOK as $k => $t) { if ($k !== 'deepseek-flash' && strpos($m, $k) === 0) { $tab = $t; } }
    //         if ($tab === null) { $tab = self::PRICE_USD_PER_MTOK['claude-haiku-4-5']; }
    //         $in   = (int) ($u['input_tokens'] ?? 0);
    //         $cw   = (int) ($u['cache_creation_input_tokens'] ?? 0);
    //         $cr   = (int) ($u['cache_read_input_tokens'] ?? 0);
    //         $out  = (int) ($u['output_tokens'] ?? 0);
    //         $all  = $in + $cw + $cr;      // 単価帯の判定はキャッシュの読込・書込みも含めた入力
    //         $p = ($tab['tier'] !== null && $all <= $tab['tier']) ? $tab['low'] : $tab['high'];
    //         return ($in * $p[0] + $cw * $p[0] * 1.25 + $cr * $p[0] * 0.1 + $out * $p[1]) / 1000000;
    //     }

    //     /** この呼び出しの最悪額（ドル） */
    //     public static function worstCostUsd(string $role, string $model, int $bytes, int $maxTokens): float
    //     {
    //         if ($role === '2nd') {
    //             $p = self::PRICE_USD_PER_MTOK['deepseek-flash']['peak'];
    //             return ($bytes * $p[0] + $maxTokens * $p[1]) / 1000000;
    //         }
    //         $tab = self::PRICE_USD_PER_MTOK['claude-haiku-4-5'];
    //         foreach (self::PRICE_USD_PER_MTOK as $k => $t) { if ($k !== 'deepseek-flash' && strpos($model, $k) === 0) { $tab = $t; } }
    //         $p = $tab['high'];
    //         return ($bytes * $p[0] * 1.25 + $maxTokens * $p[1]) / 1000000;
    //     }

    //     /** その日の使用額＋予約中の額（ドル） */
    //     public static function spentTodayUsd(int $nowUs): float
    //     {
    //         $day = (new \DateTimeImmutable('@' . intdiv($nowUs, 1000000)))
    //                  ->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d');
    //         $rows = \DB::table('baganriki_audit_events')
    //                   ->whereIn('kind', ['R122_USAGE', 'R122_BUDGET_HOLD'])
    //                   ->where('happened_at', '>=', $day . ' 00:00:00')
    //                   ->get(['kind', 'diagnostics', 'related_id']);
    //         $used = 0.0; $holds = []; $settled = [];
    //         foreach ($rows as $r) {
    //             $d = json_decode((string) $r->diagnostics, true);
    //             if (!is_array($d)) { continue; }
    //             if ($r->kind === 'R122_BUDGET_HOLD') {
    //                 $holds[(string) $r->related_id] = (float) ($d['amount_usd'] ?? 0);
    //             } else {
    //                 $c = $d['cost_usd'] ?? null;
    //                 $h = (string) ($d['budget_hold'] ?? '');
    //                 if ($h !== '') { $settled[$h] = true; }
    //                 // usage が無い（結果不明など）ときは、予約した最悪額のまま数えます
    //                 if ($c !== null) { $used += (float) $c; }
    //                 elseif ($h !== '') { unset($settled[$h]); }
    //             }
    //         }
    //         foreach ($holds as $h => $amt) { if (!isset($settled[$h])) { $used += $amt; } }
    //         return $used;
    //     }

    //     /**
    //      * 送る前の判断と予約。@return array ['ok','hold','reason','spent','worst','budget']
    //      */
    //     public static function budgetReserve(string $role, string $model, string $raceId, int $bytes, int $maxTokens): array
    //     {
    //         $budget = self::budgetUsdDay();
    //         $worst = self::worstCostUsd($role, $model, $bytes, $maxTokens);
    //         if ($budget === null) {
    //             return ['ok' => true, 'hold' => null, 'reason' => '予算の上限が設定されていません',
    //                     'spent' => null, 'worst' => $worst, 'budget' => null];
    //         }
    //         $got = \DB::select("SELECT GET_LOCK('baganriki_r122_budget', 10) AS l");
    //         if ((int) ($got[0]->l ?? 0) !== 1) {
    //             return ['ok' => false, 'hold' => null, 'reason' => '予算のロックを取れないので送りません',
    //                     'spent' => null, 'worst' => $worst, 'budget' => $budget];
    //         }
    //         try {
    //             $now = BaganrikiWorker::nowUs();
    //             $spent = self::spentTodayUsd($now);
    //             if ($spent + $worst > $budget) {
    //                 return ['ok' => false, 'hold' => null,
    //                         'reason' => sprintf('予算を超えるので送りません（本日 %.4f＋最悪 %.4f ＞ 上限 %.4f ドル）', $spent, $worst, $budget),
    //                         'spent' => $spent, 'worst' => $worst, 'budget' => $budget];
    //             }
    //             $hold = 'B' . substr(hash('sha256', $raceId . $role . $now . random_bytes(4)), 0, 30);
    //             BaganrikiWorker::event($raceId, null, 'R122_BUDGET_HOLD', null, null,
    //                 ['amount_usd' => round($worst, 6), 'role' => $role, 'model' => $model,
    //                  'bytes' => $bytes, 'max_tokens' => $maxTokens, 'spent_before' => round($spent, 6),
    //                  'budget_usd' => $budget], $hold, $now);
    //             return ['ok' => true, 'hold' => $hold, 'reason' => null,
    //                     'spent' => $spent, 'worst' => $worst, 'budget' => $budget];
    //         } finally {
    //             \DB::select("SELECT RELEASE_LOCK('baganriki_r122_budget') AS l");
    //         }
    //     }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261023-NARU-BUDGET2  送信前の予算制御（なる 10/23 4通目 3章・6章で直した版）
     * ═══════════════════════════════════════════════════════════════════════
     *   BAGANRIKI_PREDICTION_BUDGET_USD_DAY に1日（日本時間）の上限をドルで書くと、送る前に
     *     その日の保守的見積り額（usage から・高い側の単価）＋予約中の額＋この呼び出しの最悪額
     *   が上限を超えるなら送りません（BUDGET_EXCEEDED）。書かなければ制御しません。
     *
     *   【直したこと】
     *   ・usage は役割ごとに必要な項目を検証します。空・欠け・null・負・整数でない・内訳の不整合は
     *     「費用不明」にして、予約した最悪額のまま数えます（0ドル扱いにしません）。
     *   ・予約は<b>専用の別接続</b>で書いて確定（自動コミット）してから、名前付きロックを外します。
     *     呼び出し側がトランザクションの中でも、予約は先に確定するので、別の worker から必ず見えます。
     *     集計も同じ別接続で読むので、確定した行だけを数えます。
     *   ・料金表に無いモデルは、予算を設定しているときは送りません（MODEL_NOT_PRICED）。
     *   ・額は2つに分けて残します。
     *       cost_usd_conservative … 予算に使う保守的見積り（DeepSeek はいつもピーク単価）
     *       cost_usd_estimate     … 請求見込み（DeepSeek は送信時刻のピーク／オフピークで計算）
     *     どちらも usage からの計算で、請求書の実費ではありません（税・為替は含みません）。
     *   ・送らなかった予約（期限の直前で止めた等）は R122_BUDGET_RELEASE で0ドルに精算します。
     *   単価は 2026年10月23日に公式の料金表で確認した値です。
     */
    public const PRICE_USD_PER_MTOK = [
        // [入力10万以下: 入力, 出力] [入力10万超: 入力, 出力]
        'claude-haiku-5-5' => ['low' => [0.10, 0.50], 'high' => [0.50, 2.50], 'tier' => 100000],
        'claude-haiku-4-5' => ['low' => [1.00, 5.00], 'high' => [1.00, 5.00], 'tier' => null],
        // DeepSeek：入力（キャッシュなし）・出力・入力（キャッシュあり）
        'deepseek-flash'   => ['peak' => [0.30, 1.20, 0.006], 'offpeak' => [0.15, 0.60, 0.003]],
    ];
    public const BUDGET_CONN = 'baganriki_budget';
    /** 送る前に止めたときのコード（外部へは何も送っていない） */
    public const NOT_SENT_CODES = ['BUDGET_EXCEEDED', 'MODEL_NOT_PRICED', 'BUDGET_CONN_IN_TX',
                                   'BUDGET_LOCK_TIMEOUT', 'DEADLINE_AFTER_WAIT', 'NO_SNAPSHOT',
                                   'TIME_UNCOMPUTABLE', 'START_TIME_UNREADABLE',
                                   'RACE_STARTED', 'RACE_ROW_MISSING',   // 20261025 NARU-STARTED で2つ追加
                                   'RACE_STATUS_UNAVAILABLE', 'ALL_CANCELLED', 'LIVE_STATE_UNAVAILABLE',
                                   'SNAPSHOT_MISMATCH', 'OUTBOUND_MISMATCH'];

    /**
     * @ANCHOR-20261023-COMPACT  送信形式 SOURCE_TABLE_V1 の説明（指示文の末尾に足します）
     *   配布された指示文の本文は1文字も変えません。詰めた形で送るときだけ、この説明を後ろに足します。
     *   prompt_version は変えません。送った形は R122_USAGE の wire_encoding に残します。
     */
    // @ANCHOR-20261023-NARU-ADDENDUM2  【旧】下の説明は、列が rows に残る場合の読み方が無く、実装と合っていませんでした
    //   （なる 10/23 4通目 5章）。消さずに残し、V2 に置き換えました。
    //     public const COMPACT_PROMPT_ADDENDUM =
    //         "【送信形式 SOURCE_TABLE_V1】\n"
    //       . "この入力では source_registry を source_table に詰めて送る。中身は source_registry と同じで、値・出典は1件も省いていない。\n"
    //       . "source_table.rows の1行が出典1件。列の並びは source_table.columns のとおり。\n"
    //       . "race_id は全件 source_table.race_id。observed_at・available_at・computed_at の整数は source_table.time_dict の番号（null は null）。\n"
    //       . "unit・dependency_role・source_kind・scope・formula_id・formula_version は source_table.field_defaults[field] の値。\n"
    //       . "dependency_key は、null なら source_ref と同じ、整数 n なら source_ref の先頭 n 文字、文字列ならその文字列。\n"
    //       . "parents の整数は rows の行番号（0始まり）の source_ref を指す。文字列はその名前のまま。\n"
    //       . "input_indices は送らない（source_table から用途別に引ける）。coverage の source_fields は、その field の出典すべてを指す。\n"
    //       . "根拠を引用するときは rows にある source_ref の文字列をそのまま使う。source_registry と書かれている指示は source_table に読み替える。";
    public const COMPACT_ADDENDUM_VERSION = 'SOURCE_TABLE_V1_ADDENDUM_V2';
    public const COMPACT_PROMPT_ADDENDUM =
        "【送信形式 SOURCE_TABLE_V1】\n"
      . "この入力では source_registry を source_table に詰めて送る。中身は source_registry と同じで、値・出典は1件も省いていない。\n"
      . "source_table.rows の1行が出典1件。各行の値の並びは source_table.columns の並びと同じ。\n"
      . "race_id は全件 source_table.race_id。\n"
      . "observed_at・available_at・computed_at の値が整数なら source_table.time_dict の番号（0始まり）。null は null のまま。\n"
      . "unit・dependency_role・source_kind・scope・formula_id・formula_version は、columns にその項目があれば、その行の値を使う（null も行の値として扱い、field_defaults で置き換えない）。columns に無い項目だけ source_table.field_defaults[field] の値を使う。\n"
      . "dependency_key は、null なら source_ref と同じ、整数 n なら source_ref の先頭 n 文字、文字列ならその文字列。\n"
      . "parents の要素は、整数なら rows の行番号（0始まり）の source_ref、文字列ならその名前のまま。\n"
      . "input_indices は送らない（source_table から用途別に引ける）。coverage の source_fields は、その field の出典すべてを指す。\n"
      . "根拠を引用するときは rows にある source_ref の文字列をそのまま使う。source_registry と書かれている指示は source_table に読み替える。";

    // @ANCHOR-20261102-PREDICTION-DICT  送信形式 SOURCE_DICT_V1 の説明（指示文の末尾に足します）
    public const COMPACT_DICT_ADDENDUM_VERSION = 'SOURCE_DICT_V1_ADDENDUM_V1';
    public const COMPACT_DICT_PROMPT_ADDENDUM =
        "【送信形式 SOURCE_DICT_V1】\n"
      . "この入力では source_registry を source_dict に詰めて送る。中身は source_registry と同じで、値・出典は1件も省いていない。\n"
      . "source_dict.entries のキーが source_ref で、値がその出典1件。各馬の値は h{馬番}_{field}（時点があれば _{分前}）のキーで引ける。\n"
      . "race_id は全件 source_dict.race_id。\n"
      . "observed_at・available_at・computed_at の値は source_dict.time_dict の番号（0始まり）。項目が無ければ null。\n"
      . "horse_nos・applicable_horse_nos・parents は、項目が無ければ []。slot_offset は、項目が無ければ null。dependency_key は、項目が無ければ source_ref と同じ。\n"
      . "unit・dependency_role・source_kind・scope・formula_id・formula_version は、その件にあればその値、無ければ source_dict.field_defaults[field] の値。\n"
      . "input_indices は送らない（source_dict から用途別に引ける）。coverage の source_fields は、その field の出典すべてを指す。\n"
      . "根拠を引用するときは entries のキー（source_ref）の文字列をそのまま使い、facts の value にはその件の value をそのまま書く。source_registry と書かれている指示は source_dict に読み替える。";

    // @ANCHOR-20261102-PREDICTION-DICT2  送信形式 SOURCE_DICT_V2 の説明（指示文の末尾に足します）
    public const COMPACT_DICT2_ADDENDUM_VERSION = 'SOURCE_DICT_V2_ADDENDUM_V1';
    public const COMPACT_DICT2_PROMPT_ADDENDUM =
        "【送信形式 SOURCE_DICT_V2】\n"
      . "この入力では source_registry を source_dict に詰めて送る。中身は source_registry と同じで、値・出典は1件も省いていない。\n"
      . "source_dict.entries のキーが source_ref で、値がその出典1件。各馬の値は h{馬番}_{field}（時点があれば _{分前}）のキーで引ける。\n"
      . "各件の項目名は短くしてある：v=value、f=field、h=horse_nos、ah=applicable_horse_nos、p=parents、s=slot_offset、o=observed_at、a=available_at、c=computed_at、d=dependency_key。\n"
      . "f が無ければ、source_ref から先頭の「h{馬番}_」と末尾の「_{s}」を除いた残りが field。\n"
      . "h が無ければ、source_ref が h{馬番}_ で始まるなら [馬番]、それ以外は []。ah・p が無ければ []。s・c が無ければ null。\n"
      . "o・a・c は source_dict.time_dict の番号（0始まり）。a が無ければ o と同じ。o が無ければ null。\n"
      . "d が整数 n なら source_ref の先頭 n 文字、d が無ければ source_ref と同じ。\n"
      . "race_id は全件 source_dict.race_id。unit・dependency_role・source_kind・scope・formula_id・formula_version は、その件にあればその値、無ければ source_dict.field_defaults[field] の値。\n"
      . "input_indices は送らない（source_dict から用途別に引ける）。coverage の source_fields は、その field の出典すべてを指す。\n"
      . "根拠を引用するときは entries のキー（source_ref）の文字列をそのまま使い、facts の value にはその件の v をそのまま書く。source_registry と書かれている指示は source_dict に読み替える。";

    // @ANCHOR-20261102-PREDICTION-DICT3  送信形式 SOURCE_DICT_V3 の説明（指示文の末尾に足します）
    public const COMPACT_DICT3_ADDENDUM_VERSION = 'SOURCE_DICT_V3_ADDENDUM_V1';
    public const COMPACT_DICT3_PROMPT_ADDENDUM =
        "【送信形式 SOURCE_DICT_V3】\n"
      . "この入力では source_registry を source_dict に詰めて送る。中身は source_registry と同じで、値・出典は1件も省いていない。\n"
      . "source_dict.entries のキーが source_ref で、値がその出典1件。各馬の値は h{馬番}_{field}（時点があれば _{分前}）のキーで引ける。\n"
      . "各件の項目名は短くしてある：v=value、f=field、h=horse_nos、ah=applicable_horse_nos、p=parents、s=slot_offset、o=observed_at、a=available_at、c=computed_at、d=dependency_key。\n"
      . "f が無ければ、source_ref から先頭の「h{馬番}_」と末尾の「_{s}」を除いた残りが field。\n"
      . "h が無ければ、source_ref が h{馬番}_ で始まるなら [馬番]、それ以外は []。ah・p が無ければ []。s・c が無ければ null。\n"
      . "o・a・c は source_dict.time_dict の番号（0始まり）。o が無ければ source_dict.field_auto[field].os[s]（s があるとき）、無ければ field_auto[field].o、それも無ければ null。a が無ければ field_auto[field].as[s]（s があるとき）、無ければその件の o と同じ。\n"
      . "d が整数 n なら source_ref の先頭 n 文字。d が無ければ source_dict.field_auto[field].d（{h} は馬番）、それも無ければ source_ref と同じ。\n"
      . "race_id は全件 source_dict.race_id。unit・dependency_role・source_kind・scope・formula_id・formula_version は、その件にあればその値、無ければ source_dict.field_defaults[field] の値。\n"
      . "field_catalog の各項目で、display_label が無ければ項目名と同じ、maximum・minimum が無ければ null、missing_allowed が無ければ true。\n"
      . "input_indices は送らない（source_dict から用途別に引ける）。coverage の source_fields は、その field の出典すべてを指す。\n"
      . "根拠を引用するときは entries のキー（source_ref）の文字列をそのまま使い、facts の value にはその件の v をそのまま書く。source_registry と書かれている指示は source_dict に読み替える。";

    // ══════════════════════════════════════════════════════════════
    // @ANCHOR-20261108-PREDICTION-METRICS  波乱度・下位進入度・大穴進入度を 1st に答えてもらう（11/8 豊田さんの判断）
    // ══════════════════════════════════════════════════════════════
    //   新方式の本文（先方の prompt_1st.txt）は変えず、送るときに末尾へこの頼み書きを足します。
    //   定義は旧方式の1stの本文と同じです。選出・枠・点数には使いません（画面に出すだけ）。
    //   答えが無い・形が違うときは、3つとも出さないだけで、予想の公開は止めません。
    //   BAGANRIKI_PREDICTION_METRICS=0 で足すのを止められます（既定は足す）。
    public const PREDICTION_METRICS_ADDENDUM_VERSION = 'RACE_METRICS_ADDENDUM_V1';
    public const PREDICTION_METRICS_ADDENDUM =
        "【レース指標（画面表示用・この項目だけ追加キーを許可）】\n"
      . "応答のJSONの最上位に、次の1キーだけを追加してよい（これ以外の追加キー禁止はそのまま）。\n"
      . "\"race_metrics\":{\"wave_level\":整数,\"lower_entry\":整数,\"big_gap_entry\":整数}\n"
      . "各値は1〜5の整数。判定に必要な入力が不足して決められない項目は null とし、推測値・0・固定値を入れない。\n"
      . "・wave_level（波乱度）：レース全体の予測困難度。1=堅い、2=やや堅い、3=中波乱、4=波乱、5=大波乱。\n"
      . "　legacy_gap_type の目安：A（二重断層・上位完結型）1〜2、B（上位断層型）2〜3、C（中間断層型）3、D（断層なし・混戦型）4〜5、E（判定困難型）4〜5。\n"
      . "　1段階上げる条件（複数該当で2段階まで）：断層が6分前に向けて急縮小／断層より下の複数馬に複勝流入／7〜10番人気の複勝流入が継続上昇／単勝と複勝の断層位置が不一致／1番人気の複勝支持が継続低下。\n"
      . "　1段階下げる条件（複数該当で2段階まで）：断層が複数時点で継続維持／単勝と複勝の断層位置が一致／断層が6分前に向けて拡大／6番人気以内に二重断層／断層より下の馬に明確な複勝流入がない。\n"
      . "・lower_entry（下位進入度）：7〜10番人気が5着以内へ入る可能性。断層下への複勝流入・断層縮小・複勝流入ランク上昇で判定。\n"
      . "・big_gap_entry（大穴進入度）：11番人気以下が5着以内へ入る可能性。継続的な複勝流入が確認できない限り1〜2にとどめる。\n"
      . "波乱度が高くても下位進入度・大穴進入度が低い場合は、1〜6番人気の中での順番入れ替えが主因と判断する。\n"
      . "この3指標は画面に表示するだけで、帯・枠・順位・点数・公開候補の判断には使わない。horses 以下の出力はこれまでどおり。";

    /** @ANCHOR-20261025-NARU-NOWC  試験用の差し込み口（期限を読んだ直後に呼ぶ）。本番では常に null */
    public static ?\Closure $testAfterDeadlineRead = null;

    // ══════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261023-NARU-JOBTIME  Job 全体の残り時間（なる 10/23 重要6）
    // ══════════════════════════════════════════════════════════════════════
    //   Laravel 8 では Job の $timeout が worker の --timeout より優先されます。
    //   PredictionRunRoleJob が handle の最初に「この Job が打ち切られる時刻」を入れ、
    //   初回と訂正の送信直前に、応答期限とこの残り時間の両方で待ち時間を決めます。
    //   保存・診断・採否確定のぶんとして JOB_SAVE_MARGIN_SEC を残します。
    //   Job の外（巡回の同期送信など）では null のままで、制限しません。
    public const JOB_SAVE_MARGIN_SEC = 15;
    public static ?int $jobDeadlineUs = null;

    /**
     * @ANCHOR-20261023-PREDICTION-SWEEPFREEZE  対象レースの凍結を起こします（巡回から呼ぶ）
     *
     *   ・対象レースの限定が効いていないときは何もしません（全レースを凍結しない）
     *   ・発走時刻は正本（t_horse_odds_finder_races.start_time）から読みます
     *   ・情報上限より前は何もしません（TOO_EARLY の空振りを毎回作らない）
     *   ・同じプロセスの中では、1レースにつき30秒に1回までしか起こしません
     *     （--prediction-sweep-watch で5秒ごとに呼ばれるため）
     *
     * @return array ['targets','started','skipped'=>[race_id=>理由]]
     */
    private static array $sweepFreezeTriedAt = [];
    private static array $sweepFreezeEarlyTriedAt = [];   // @ANCHOR-20261116-PREDICTION-EARLYFREEZE

    /**
     * @ANCHOR-20261116-PREDICTION-EARLYFREEZE  6分前の取得バッチが、今の時刻までに利用可能になっているか（早期凍結を試す前の軽い確認）
     *   採用できるかどうか（全頭そろっているか等）は、材料を集める処理（acceptBatch・pick）で今までどおり確かめます。
     */
    private static function predictionSlot6Ready(string $raceId, int $nowUs): bool
    {
        try {
            if (!\Schema::hasTable('baganriki_acquisition_batches')) { return false; }
            foreach (\DB::table('baganriki_acquisition_batches')->where('race_id', $raceId)->where('slot', 6)
                        ->where('committed', 1)->whereNotNull('available_at')->get(['available_at']) as $b) {
                $at = BaganrikiTime::us((string) AiController::dbTimeToRfc3339($b->available_at));
                if ($at !== null && $at <= $nowUs) { return true; }
            }
        } catch (\Throwable $e) {
            \Log::warning('[PREDICTION-EARLYFREEZE] 6分前のバッチを確かめられません（早期凍結はしません）', ['race_id' => $raceId, 'message' => $e->getMessage()]);
        }
        return false;
    }

    // ##########################################################################
    // ## アプリの取得口（routes/api.php から呼ばれる）
    // ##########################################################################

    public function __construct(private AnthropicService $anthropic)
    {
    }

    /**
     * 1st の予想を返す（アプリの「AI予想」）
     *
     *   1. 新しい方式の予想（公開・補充・予想中・予想なし）があれば、それを返す
     *   2. 以前の方式で保存した過去の予想があれば、それを返す（11/6 までのレースの表示用）
     *   3. どちらも無ければ、オッズがそろっていなければ「まだ」、そろっていれば「予想なし」を返す
     *   AI はここでは呼びません（新しい方式の AI は、巡回 keiba:ai-analysis-compensate --prediction-sweep が呼びます）。
     *
     *   テーブル間のカラム対応：t_horse_odds_finder_races.basho（場コード）＝ t_horse_odds_finder_ai_analysis.basho_code
     *
     * @param  Request $request  date, kaisuu, basho（場コード）, day, race, cacheOnly
     * @return \Illuminate\Http\JsonResponse
     */
    public function getHorseOddsFinderAiAnalysis(Request $request)
    {
        $date   = $request->query('date');
        $kaisuu = $request->query('kaisuu');
        $basho  = $request->query('basho');
        $day    = $request->query('day');
        $race   = $request->query('race');

        $predictionAnswer = $this->predictionAnswerFor($date, $kaisuu, $basho, $day, $race);
        if ($predictionAnswer !== null && $predictionAnswer['first'] !== null) {
            return response()->json(['data' => $predictionAnswer['first']]);
        }

        $base = ['date' => $date, 'kaisuu' => $kaisuu, 'basho_code' => $basho, 'day' => $day, 'race' => $race];
        $saved = DB::table('t_horse_odds_finder_ai_analysis')
            ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho_code', $basho)
            ->where('day', $day)->where('race', $race)
            ->first();
        if ($saved) {
            return response()->json(['data' => $base + [
                'analysis_text' => trim(self::publicPart((string) $saved->analysis_text)),
            ]]);
        }
        if (((string) $request->query('cacheOnly', '')) === '1' || !$this->oddsReadyForRace($date, $kaisuu, $basho, $day, $race)) {
            return response()->json(['data' => $base + ['analysis_text' => '', 'not_ready' => true]]);
        }
        if (!$this->raceExists($date, $kaisuu, $basho, $day, $race)) {
            return response()->json(['error' => 'レースが見つかりません'], 404);
        }
        return response()->json(['data' => $base + [
            'analysis_text' => '', 'prediction_method' => 'new', 'prediction_state' => 'UNAVAILABLE',
        ]]);
    }

    /**
     * 2nd の予想と、画面に出す統合結果を返す（アプリの「AI予想」の2つ目・予想総括）
     *
     *   1. 新しい方式の予想があれば、それを返す（merged_horses・race_metrics を含む）
     *   2. 以前の方式で保存した過去の予想があれば、保存済みの統合結果と合わせて返す
     *   3. どちらも無ければ「まだ」／「予想なし」を返す。AI はここでは呼びません。
     *
     * @param  Request $request  date, kaisuu, basho（場コード）, day, race, cacheOnly
     * @return \Illuminate\Http\JsonResponse
     */
    public function getHorseOddsFinderSecondAiOpinion(Request $request)
    {
        $date   = $request->query('date');
        $kaisuu = $request->query('kaisuu');
        $basho  = $request->query('basho');
        $day    = $request->query('day');
        $race   = $request->query('race');

        $predictionAnswer = $this->predictionAnswerFor($date, $kaisuu, $basho, $day, $race);
        if ($predictionAnswer !== null && $predictionAnswer['second'] !== null) {
            return response()->json(['data' => $predictionAnswer['second']]);
        }

        $base = ['date' => $date, 'kaisuu' => $kaisuu, 'basho_code' => $basho, 'day' => $day, 'race' => $race];
        $saved = DB::table('t_horse_odds_finder_ai_analysis2')
            ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho_code', $basho)
            ->where('day', $day)->where('race', $race)
            ->first();
        if ($saved) {
            return response()->json(['data' => array_merge($base + [
                'analysis_text' => trim(self::publicPart((string) $saved->analysis_text)),
            ], $this->_loadSavedMergeResult($date, $kaisuu, $basho, $day, $race))]);
        }
        if (((string) $request->query('cacheOnly', '')) === '1') {
            return response()->json(['data' => $base + ['analysis_text' => '', 'not_ready' => true]]);
        }
        if (!$this->raceExists($date, $kaisuu, $basho, $day, $race)) {
            return response()->json(['error' => 'レースが見つかりません'], 404);
        }
        $hasFirst = DB::table('t_horse_odds_finder_ai_analysis')
            ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho_code', $basho)
            ->where('day', $day)->where('race', $race)
            ->exists();
        if (!$hasFirst) {
            return response()->json(['error' => '1st AIの分析が完了していません。しばらくしてから再度お試しください'], 409);
        }
        return response()->json(['data' => $base + [
            'analysis_text' => '', 'merged_horses' => [], 'upset_race' => null, 'upset_race_status' => '不明',
            'race_metrics' => null, 'status' => 'undetermined',
            'status_reason' => 'このレースの予想はありません',
            'prediction_method' => 'new', 'prediction_state' => 'UNAVAILABLE',
        ]]);
    }

    /**
     * 馬眼力指数を返す
     *
     * 引数: date, kaisuu, basho_code (int), day, race
     * 返値: [{num, name, uma_ganryoku_index}, ...]
     */
    public function getHorseOddsFinderBaganrikiIndex(Request $request)
    {
        $date      = $request->query('date');
        $kaisuu    = $request->query('kaisuu');
        $bashoCode = intval($request->query('basho_code'));
        $day       = $request->query('day');
        $race      = intval($request->query('race'));

        // ─── レース存在確認 ───────────────────────────────────────────────
        $raceRow = DB::table('t_horse_odds_finder_races')
            ->where('date',   $date)
            ->where('kaisuu', $kaisuu)
            ->where('basho',  $bashoCode)
            ->where('day',    $day)
            ->where('race',   $race)
            ->first();

        if (!$raceRow) {
            return response()->json(['data' => []]);
        }

        // ─── 出走馬情報の取得 ─────────────────────────────────────────────
        $horses = DB::table('t_horse_odds_finder_horses')
            ->where('date',   $date)
            ->where('kaisuu', $raceRow->kaisuu)
            ->where('basho',  $raceRow->basho)
            ->where('day',    $raceRow->day)
            ->where('race',   $raceRow->race)
            ->orderBy('num')
            ->get()
            ->keyBy('num');

        // ─── オッズ取得（999=計測前, 21分前, 6分前） ─────────────────────
        $oddsRows = DB::table('t_horse_odds_finder_odds')
            ->where('date',   $date)
            ->where('kaisuu', $raceRow->kaisuu)
            ->where('basho',  $raceRow->basho)
            ->where('day',    $raceRow->day)
            ->where('race',   $raceRow->race)
            ->whereIn('minutes_before_start', [999, 21, 6])
            ->get();

        $oddsByNum = [];
        foreach ($oddsRows as $row) {
            if (!is_numeric($row->odds)) continue;   // 【20260924】「取消」等は人気順計算から除外
            $num    = $row->num;
            $timing = $row->minutes_before_start;
            if (!isset($oddsByNum[$num])) {
                $oddsByNum[$num] = ['tan' => [], 'fuku_min' => []];
            }
            $oddsByNum[$num]['tan'][$timing]      = floatval($row->odds);
            $oddsByNum[$num]['fuku_min'][$timing] = floatval($row->fuku_min);
        }

        // ─── プロンプト用データの組み立て ────────────────────────────────
        $promptHorses = [];
        foreach ($oddsByNum as $num => $o) {
            $tanBase = $o['tan'][999] ?? null;
            $tan6    = $o['tan'][6]   ?? null;
            if ($tanBase === null || $tan6 === null || $tanBase == 0) continue;

            $name = isset($horses[$num]) ? $horses[$num]->name : '馬' . $num;

            $promptHorses[] = [
                'num'             => $num,
                'name'            => $name,
                'tan_series'      => $o['tan'],
                'fuku_min_series' => $o['fuku_min'],
                'odds_6'          => $tan6,
                'fuku_min_6'      => $o['fuku_min'][6] ?? null,
            ];
        }

        if (empty($promptHorses)) {
            return response()->json(['data' => []]);
        }

        // ─── 人気順の決定（6分前の単勝オッズ昇順） ───────────────────────
        usort($promptHorses, function ($a, $b) {
            if ($a['odds_6'] !== $b['odds_6']) {
                return $a['odds_6'] <=> $b['odds_6'];
            }
            return $a['num'] <=> $b['num'];
        });
        foreach ($promptHorses as $i => &$h) {
            $h['popularity'] = $i + 1;
        }
        unset($h);

        // ─── 人気順別過去平均単勝オッズ（OPI用） ─────────────────────────
        $popularityAvgRows = DB::table('t_horse_odds_finder_popularity_rank_average')->get();
        $popularityAvgMap  = [];
        foreach ($popularityAvgRows as $row) {
            $popularityAvgMap[(int)$row->popularity_rank] = floatval($row->odds_average);
        }

        // ─── OPI 計算（人気順別過去平均単勝オッズ ÷ 6分前単勝オッズ） ────
        foreach ($promptHorses as &$h) {
            $avgOdds  = $popularityAvgMap[$h['popularity']] ?? null;
            $h['opi'] = ($avgOdds && $h['odds_6'] > 0)
                ? round($avgOdds / $h['odds_6'], 2)
                : null;
        }
        unset($h);

        // ─── 複勝人気順マップの作成（fuku_min_6 昇順） ───────────────────
        $earlyFukuTemp = array_values(
            array_filter($promptHorses, fn($h) => isset($h['fuku_min_6']) && $h['fuku_min_6'] > 0)
        );
        usort($earlyFukuTemp, fn($a, $b) => $a['fuku_min_6'] <=> $b['fuku_min_6']);
        $fukuPopularityMap = [];
        foreach ($earlyFukuTemp as $pos => $fh) {
            $fukuPopularityMap[$fh['num']] = $pos + 1;
        }

        // ─── 単勝断層の事前検出 ───────────────────────────────────────────
        $earlyTanGapAll    = [];
        $earlyTanGapTop6   = [];
        $earlyTanGapStrong = [];
        for ($i = 0; $i < count($promptHorses) - 1; $i++) {
            $eu = $promptHorses[$i];
            $el = $promptHorses[$i + 1];
            if (($eu['odds_6'] ?? 0) > 0 && ($el['odds_6'] ?? 0) > 0) {
                $er = round($el['odds_6'] / $eu['odds_6'], 2);
                if ($er >= 2.0) {
                    $ee = ['upper_pop' => $eu['popularity'], 'lower_pop' => $el['popularity'], 'ratio' => $er];
                    $earlyTanGapAll[] = $ee;
                    if ($eu['popularity'] <= 6) {
                        $earlyTanGapTop6[] = $ee;
                        if ($er >= 2.5) $earlyTanGapStrong[] = $ee;
                    }
                }
            }
        }

        // ─── 複勝断層の事前検出 ───────────────────────────────────────────
        $earlyFukuGapDets = [];
        for ($i = 0; $i < count($earlyFukuTemp) - 1; $i++) {
            $eu = $earlyFukuTemp[$i];
            $el = $earlyFukuTemp[$i + 1];
            if (($eu['fuku_min_6'] ?? 0) > 0) {
                $er = round($el['fuku_min_6'] / $eu['fuku_min_6'], 2);
                if ($er >= 2.0) {
                    $earlyFukuGapDets[] = $er;
                }
            }
        }

        // ─── 断層タイプの判定 ─────────────────────────────────────────────
        $earlyTanHasGap  = count($earlyTanGapAll) > 0;
        $earlyFukuHasGap = count($earlyFukuGapDets) > 0;

        if (count($earlyTanGapTop6) >= 2) { // 【20260924】仕様どおり二重断層（2か所以上）でタイプA
            $earlyGapType = 'A';
        } elseif ($earlyTanHasGap !== $earlyFukuHasGap) {
            $earlyGapType = 'E';
        } elseif (count($earlyTanGapTop6) === 1) {
            $earlyGapType = 'B';
        } elseif ($earlyTanHasGap) {
            $earlyGapType = 'C';
        } else {
            $earlyGapType = 'D';
        }

        // 断層補正の基準人気順（上側グループ境界）。D・E は null → 補正係数 1.00
        $earlyPrimaryGapUpperPop = null;
        if (in_array($earlyGapType, ['A', 'B']) && !empty($earlyTanGapTop6)) {
            $earlyPrimaryGapUpperPop = $earlyTanGapTop6[0]['upper_pop'];
        } elseif ($earlyGapType === 'C' && !empty($earlyTanGapAll)) {
            $earlyPrimaryGapUpperPop = $earlyTanGapAll[0]['upper_pop'];
        }

        // ─── 馬眼力指数の算出 ─────────────────────────────────────────────
        // 馬眼力指数 = OPI × オッズ上昇率 × 複勝支持率 × 断層補正 × 100
        $result = [];
        foreach ($promptHorses as $h) {
            $umaOpi = $h['opi'];

            // ② オッズ上昇率: 21分前単勝 ÷ 6分前単勝（21分前データなければ 1.0）
            $odds21 = isset($h['tan_series'][21]) && floatval($h['tan_series'][21]) > 0
                ? floatval($h['tan_series'][21])
                : null;
            $odds6  = $h['odds_6'] ?? 0;
            $umaOddsRiseRate = ($odds21 !== null && $odds6 > 0)
                ? round($odds21 / $odds6, 4)
                : 1.0;

            // ③ 複勝支持率: 単勝人気順 ÷ 複勝人気順
            $fukuPop = $fukuPopularityMap[$h['num']] ?? null;
            $umaFukuSupportRate = ($fukuPop !== null && $fukuPop > 0)
                ? round($h['popularity'] / $fukuPop, 4)
                : null;

            // ④ 断層補正: 内側=1.10 / 外側=0.90 / D・E=1.00
            if ($earlyPrimaryGapUpperPop !== null) {
                $umaDansouCorr = ($h['popularity'] <= $earlyPrimaryGapUpperPop) ? 1.10 : 0.90;
            } else {
                $umaDansouCorr = 1.00;
            }

            // 馬眼力指数（OPI または 複勝支持率 が null なら null）
            $umaGanryokuIndex = ($umaOpi !== null && $umaFukuSupportRate !== null)
                ? round($umaOpi * $umaOddsRiseRate * $umaFukuSupportRate * $umaDansouCorr * 100, 1)
                : null;

            $result[] = [
                'num'                => $h['num'],
                'name'               => $h['name'],
                'baganriki_index' => $umaGanryokuIndex,
            ];
        }

        // 馬番順にソートして返す
        usort($result, fn($a, $b) => $a['num'] <=> $b['num']);

        return response()->json(['data' => $result]);
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-API  新方式 の読取API
     * ═══════════════════════════════════════════════════════════════════════
     * GET /api/baganriki/r12-2/races/{race_id}
     *
     * 根拠：server_contract_r12.2.txt 8章・11章／詳細設計書 12.2
     *
     *   ・読み取りだけです。AIへの送信も再選出も起こしません。
     *   ・正式公開（publication）と現在のライブ状態（live_state）を分けて返します。
     *   ・原AI応答・予約token・内部認証情報は返しません。
     *   ・数値は十進数文字列のまま返します（Flutter側で一律doubleにしないため）。
     *
     * race_id の形は `YYYYMMDD-回-場-日-R` です（例 20261018-4-08-7-9）。
     * 既存の正本テーブルと列の対応は、詳細設計書14章の対応表に記録します。
     */
    public function getBaganrikiPublishedPrediction(string $race_id)
    {
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-R122-READGATE  非公開の読取経路（010の7章⑤）
        // ══════════════════════════════════════════════════════════════
        //   【見つかったこと】この読取APIには<b>認証が1つも掛かって
        //   いませんでした。</b>さらに画面側（Flutter）の
        //   race_content_page に、<b>誰にでも見える「新方式」のボタン</b>が
        //   出ています（管理者判定も debug 判定もありません）。
        //
        //   いまは記録が1件もないので空が返るだけです。ですが
        //   <b>10月24日に旗を上げた瞬間、その12レースの予想が
        //   一般の利用者に見えます。</b>「非公開の検証」になりません。
        //   race_id は日付と場と番号なので、総当たりも簡単です。
        //
        //   【いま】既定で<b>閉じます。</b>.env の BAGANRIKI_PREDICTION_READ が
        //   open のときだけ誰でも見られます。既定（key）では、
        //   BAGANRIKI_PREDICTION_READ_KEY と一致する鍵を
        //     ヘッダ  X-R122-Key: <鍵>
        //     クエリ  ?r122_key=<鍵>
        //   のどちらかで渡したときだけ中身を返します。
        //   <b>鍵が未設定なら、誰も中身を見られません。</b>
        //   対象レースの限定（TARGETS が空なら1レースも送らない）と
        //   同じ考え方です。設定を入れ忘れた側が安全に倒れます。
        //
        //   <b>記録が在るかどうかは漏らしません。</b>race_id が何であれ
        //   同じものを返します。
        //
        //   ══════════════════════════════════════════════════════════
        //   @ANCHOR-20261021-R122-READGATE200  HTTPは 200 で返します
        //   ══════════════════════════════════════════════════════════
        //   【画面（Flutter）側の作りを見て決めました】
        //   lib/data/http/client.dart は 2xx 以外を<b>すべて例外</b>に
        //   します（49〜51行）。画面はそれを受けて
        //     「読めませんでした  Exception: http 404 [url=https://…]」
        //   と<b>生のエラー文字列をそのまま出します</b>
        //   （baganriki_published_prediction_display_alert.dart 98行）。
        //   利用者に意味のない文字列とURLを見せることになります。
        //
        //   なので 404 ではなく <b>200</b> で、いつもの形のまま
        //   state=UNAVAILABLE と理由を返します。
        //   画面は diagnostics をそのまま並べるので（同380行）、
        //   「この記録はまだ公開していません」と読める形で出ます。
        //   <b>Flutter は1行も直していません。</b>作り直し不要です。
        //
        //   artisan（コマンド）からは素通りします。外から叩けないので
        //   止める意味がなく、止めると試験が全部落ちます。
        $headers = ['Cache-Control' => 'private, no-store'];
        $gate = self::checkPredictionReadAccess();
        if (!$gate['ok']) {
            return response()->json(BaganrikiPublicView::build([
                'race_id'     => $race_id,
                'state'       => 'UNAVAILABLE',
                'diagnostics' => [['code' => (string) $gate['code'],
                                   'message' => (string) $gate['reason']]],
            ]), 200, $headers);
        }

        $parts = explode('-', $race_id);
        if (count($parts) !== 5) {
            return response()->json([
                'schema_version' => 'baganriki-v041-r12-2',
                'race_id'        => $race_id,
                'state'          => 'UNAVAILABLE',
                'diagnostics'    => [['code' => 'ENTRY_SET_MISMATCH',
                                      'message' => 'race_id の形が違います（YYYYMMDD-回-場-日-R）']],
                'buy_recommended' => false,
            ], 400, $headers);
        }
        [$ymd, $kaisuu, $basho, $day, $race] = $parts;
        $date = substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);

        // ── 正本のレース情報 ────────────────────────────────────────────
        $r = \DB::table('t_horse_odds_finder_races')
            ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho', $basho)
            ->where('day', $day)->where('race', (int) $race)->first();
        if ($r === null) {
            return response()->json(BaganrikiPublicView::build([
                'race_id'     => $race_id,
                'state'       => 'UNAVAILABLE',
                'diagnostics' => [['code' => 'ENTRY_MISSING',
                                   'message' => 'このレースは正本に存在しません']],
            ]), 404, $headers);
        }

        // ── 保存済みの 新方式 の記録を読む ───────────────────────────────
        //   まだ凍結・公開の保存を実装していない期間は、
        //   snapshot が無い状態として PENDING / UNAVAILABLE を返します。
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-R122-API  本番の世代（generation=0）だけを読みます
        // ══════════════════════════════════════════════════════════════
        // 【直した中身】
        //   これまで orderByDesc('generation') で読んでいたので、
        //   試験で保存した REPLAY（generation=900）があると
        //   <b>公開APIがその試験用の記録を返してしまいました。</b>
        //   読取APIは本番の世代だけを見ます。
        //
        //   3つの版と公開履歴は<b>ひとつの読取取引</b>でそろえて取ります。
        //   書込ロックは持ちません（契約8章）。
        $snap = null; $live = null; $pub = null; $slots = null; $w2 = null; $closeSrc = null;
        if (\Schema::hasTable('baganriki_snapshots')) {
            [$snap, $live, $pub, $slots, $w2, $closeSrc] = \DB::transaction(function () use ($race_id) {
                $s = \DB::table('baganriki_snapshots')->where('race_id', $race_id)
                       ->where('generation', 0)->first();
                $l = null; $p = null; $w = null; $view = null; $source = null;
                if ($s !== null && \Schema::hasTable('baganriki_live_states')) {
                    $l = \DB::table('baganriki_live_states')
                           ->where('snapshot_id', $s->id)->first();
                }
                if ($s !== null && BaganrikiWorker::isExpectedSnapshot($s)) {
                    try {
                        $view = BaganrikiPublishedRead::load($s, $l);
                        $p = $view['pub'];
                    } catch (\InvalidArgumentException $error) {
                        $view = ['error' => $error->getMessage()];
                    }
                } elseif ($s !== null && \Schema::hasTable('baganriki_publications')) {
                    $p = \DB::table('baganriki_publications')
                           ->where('snapshot_id', $s->id)
                           ->orderByDesc('published_at')->first();
                }
                if ($s !== null && \Schema::hasTable('baganriki_ai_attempts')) {
                    $w = BaganrikiWorker::slots((int) $s->id);
                }
                if (\Schema::hasTable('baganriki_audit_events') && $s !== null) {
                    $ce = \DB::table('baganriki_audit_events')
                            ->where('snapshot_id', $s->id)
                            ->where('kind', 'SALES_CLOSE_SOURCE')
                            ->orderByDesc('id')->first();
                    if ($ce !== null) {
                        $cd = json_decode((string) $ce->diagnostics, true);
                        if (is_array($cd)) { $source = $cd; }
                    }
                }
                return [$s, $l, $p, $w, $view, $source];
            });
        }

        if (isset($w2['error'])) {
            $code = $w2['error'];
            $reasons = [
                'LIVE_STATE_UNAVAILABLE' => '取消馬と残りの頭数を確認できないため、現在の候補は表示を保留しています',
                'EXPECTED_LIVE_REVISION' => '公開に使った記録の版を確認しています',
                'PUBLICATION_REFERENCE_MISMATCH' => '最新の公開記録を確認しています',
                'PUBLICATION_DATA_INVALID' => '公開した候補の記録を確認しています',
                'TIME_UNCOMPUTABLE' => '凍結時の締切と正式な変更履歴を確認しています',
                'PUBLICATION_CLOCK_UNAVAILABLE' => '正式な発走時刻と締切を確認しています',
            ];
            $dto = BaganrikiPublicView::build(['race_id' => $race_id, 'state' => 'UNAVAILABLE',
                'diagnostics' => [['code' => $code, 'message' => $reasons[$code] ?? '公開記録を確認しています']]]);
            $dto['live_state']['available_candidate_nos'] = null;
            return response()->json($dto, 200, $headers);
        }
        $cancellations = $w2 !== null ? $w2['roster']['withdrawn'] : (($live !== null && $live->cancellations !== null)
            ? (json_decode((string) $live->cancellations, true) ?: []) : []);
        $activeCount   = $w2 !== null ? $w2['allocation']['active_count'] : ($live->active_count ?? null);
        $allCancelled  = ($activeCount !== null && (int) $activeCount === 0);

        // 実効公開期限。投票締切の正本が無ければ期限を作らず、推測もしません。
        $effPublish = null; $pastDeadline = false;
        $salesClose = $snap->sales_close_at ?? null;
        if ($w2 !== null) {
            $effPublish = BaganrikiTime::toJst($w2['clock']['publish']);
            $pastDeadline = $w2['clock']['race_started'] || BaganrikiWorker::nowUs() > $w2['clock']['publish'];
        } elseif ($salesClose !== null) {
            // @ANCHOR-20261020-R122-API  DBの日時はDBの日時として読みます
            $sc = BaganrikiWorker::dbUs($salesClose);
            if ($sc !== null) {
                $lc = ($live !== null && $live->live_close !== null)
                    ? BaganrikiWorker::dbUs($live->live_close) : null;
                $eff = BaganrikiTime::effectiveDeadlines($sc, $lc);
                $effPublish   = BaganrikiTime::toJst($eff['publish']);
                $pastDeadline = (BaganrikiTime::us(date('c')) ?? 0) > $eff['publish'];
            }
        }

        $diag = [];
        if ($salesClose === null) {
            $diag[] = ['code' => 'PUBLICATION_DEADLINE_EXCEEDED',
                       'message' => '投票締切の正本が特定できていないため公開しません'];
        }
        $inputInvalid = ($snap === null)
            ? false
            : (($snap->input_invalid_code ?? null) !== null);
        if ($inputInvalid) {
            $diag[] = ['code' => (string) $snap->input_invalid_code,
                       'message' => (string) ($snap->input_invalid_reason ?? '')];
        }

        if ($w2 !== null && $inputInvalid) { $pub = null; }
        $state = BaganrikiPublicView::state(
            $pub !== null, $allCancelled, $inputInvalid, $pastDeadline);
        if ($snap === null) { $state = 'PENDING'; }

        $pubDto = null;
        if ($pub !== null) {
            $pubDto = [
                'publication_id'           => $pub->publication_id,
                'published_at'             => $pub->published_at,
                'prediction_target_count'  => $pub->prediction_target_count ?? null,
                'prediction_quota'         => json_decode((string) ($pub->prediction_quota ?? 'null'), true),
                'publication_target_count' => $pub->publication_target_count ?? null,
                'publication_quota'        => json_decode((string) ($pub->publication_quota ?? 'null'), true),
                // @ANCHOR-20261023-R122-COUNTS  正式公開したときの頭数（変更計画書8章「正式公開時の頭数と現在頭数を区別」）
                //   公開時に baganriki_publications.active_count へ保存してある値です。いまの頭数は live_state.active_count。
                'active_count'             => isset($pub->active_count) ? (int) $pub->active_count : null,
                'candidates'               => $w2['candidates'] ?? json_decode((string) ($pub->candidates ?? '[]'), true),
                'evidence'                 => json_decode((string) ($pub->evidence ?? '[]'), true),
            ];
        }

        // @ANCHOR-20261020-R122-API  いまの取消を映した枠と候補数
        //   正式公開のリストは動かしません（取消だけで再選出しません）。
        //   取消になった馬を除いた数だけを、別の欄として返します。
        $actualQuota = null; $availableCount = null;
        if ($w2 !== null && $pubDto !== null) {
            $actualQuota = $w2['quota'];
            $availableCount = count($w2['available']);
        } elseif ($pubDto !== null) {
            $cans = (array) ($pubDto['candidates'] ?? []);
            $actualQuota = ['A' => 0, 'B' => 0, 'D' => 0];
            $availableCount = 0;
            foreach ($cans as $c) {
                $no = (int) ($c['horse_no'] ?? 0);
                if (in_array($no, array_map('intval', $cancellations), true)) { continue; }
                $availableCount++;
                $b = (string) ($c['band'] ?? '');
                if (isset($actualQuota[$b])) { $actualQuota[$b]++; }
            }
        }
        // @ANCHOR-20261020-R122-CLOSE  締切の出どころ（凍結のときの監査から）
        $effReply = null;
        if ($w2 !== null) {
            $effReply = BaganrikiTime::toJst($w2['clock']['reply']);
        } elseif ($salesClose !== null) {
            $sc2 = BaganrikiWorker::dbUs($salesClose);
            if ($sc2 !== null) {
                $lc2 = ($live !== null && $live->live_close !== null)
                    ? BaganrikiWorker::dbUs($live->live_close) : null;
                $eff2 = BaganrikiTime::effectiveDeadlines($sc2, $lc2);
                $effReply = BaganrikiTime::toJst($eff2['reply']);
            }
        }

        $dto = BaganrikiPublicView::build([
            'race_id'         => $race_id,
            'state'           => $state,
            'response_revision'     => $live->response_revision ?? null,
            'publication_revision'  => $live->publication_revision ?? null,
            'latest_publication_id' => $live->latest_publication_id ?? null,
            'actual_quota'              => $actualQuota,
            'available_candidate_count' => $availableCount,
            'live_close'              => $live->live_close ?? null,
            'effective_reply_deadline' => $effReply,
            'worker_state'    => $slots,
            'sales_close_at'  => $snap->sales_close_at ?? null,
            'sales_close_source' => $closeSrc,
            'mode'            => $snap->mode            ?? null,
            'mode_status'     => $snap->mode_status     ?? null,
            'allocation_mode' => $snap->allocation_mode ?? null,
            'mode_policy_id'  => $snap->mode_policy_id  ?? null,
            'display_rating'  => $snap->display_rating  ?? null,
            'publication'     => $pubDto,
            'state_revision'  => $live->state_revision ?? null,
            'cancellations'   => $cancellations,
            'active_count'    => $activeCount,
            'live_target_count' => $w2 !== null ? $w2['allocation']['target_count'] : ($live->live_target_count ?? null),
            'live_quota'      => $w2 !== null ? $w2['allocation']['quota']
                                 : (($live !== null && ($live->live_quota ?? null) !== null)
                                    ? json_decode((string) $live->live_quota, true) : null),
            'effective_publish_deadline' => $effPublish,
            'collection_audit' => ($snap !== null && ($snap->collection_audit ?? null) !== null)
                                  ? json_decode((string) $snap->collection_audit, true) : null,
            'input_freeze_at' => $snap->input_freeze_at ?? null,
            // @ANCHOR-20261023-R122-COUNTS  凍結したときの頭数（N）。凍結集合の馬番の数です
            'frozen_count'    => ($snap !== null && ($snap->frozen_nums ?? null) !== null)
                                 ? count((array) json_decode((string) $snap->frozen_nums, true)) : null,
            'coverage'        => ($snap !== null && ($snap->coverage ?? null) !== null)
                                 ? json_decode((string) $snap->coverage, true) : null,
            'diagnostics'     => $diag,
            'shortfalls'      => ($pub !== null && ($pub->shortfalls ?? null) !== null)
                                 ? json_decode((string) $pub->shortfalls, true) : [],
        ]);
        if ($w2 !== null) {
            $dto['live_state']['available_candidate_nos'] = $pubDto !== null ? $w2['available'] : null;
            $dto['race_metrics'] = $pubDto !== null && ($dto['state']??null)==='PUBLISHED' ? $w2['race_metrics'] : null;
            if ($pubDto !== null) {
                $dto['publication']['race_metrics']=$dto['race_metrics'];
                unset($dto['publication']['evidence']['race_metrics']);
            }
        }
        return response()->json($dto, 200, $headers);
    }

    public function getHorseOddsFinderAiAnalysisRecord()
    {
        $result = DB::table('t_horse_odds_finder_ai_analysis')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho_code')
            ->orderBy('day')
            ->orderBy('race')
            ->get();

        // @ANCHOR-20261014-ALLSCORE-KEEP  画面へ内部監査用JSONを出さない
        return response()->json(['data' => self::stripAuditJsonRows($result)]);
    }

    public function getHorseOddsFinderAiAnalysisRecord2()
    {
        $result = DB::table('t_horse_odds_finder_ai_analysis2')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho_code')
            ->orderBy('day')
            ->orderBy('race')
            ->get();

        // @ANCHOR-20261014-ALLSCORE-KEEP  画面へ内部監査用JSONを出さない
        //   DBには全頭採点を残すようにしたので、応答でだけ落とします。
        //   レース単位のAPIは以前から publicPart() を通しています。
        return response()->json(['data' => self::stripAuditJsonRows($result)]);
    }

    /** 基準オッズ（999）と6分前のオッズが、どちらも取り込まれているか */
    private function oddsReadyForRace($date, $kaisuu, $basho, $day, $race): bool
    {
        $q = fn(int $min) => DB::table('t_horse_odds_finder_odds')
            ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho', $basho)
            ->where('day', $day)->where('race', $race)
            ->where('minutes_before_start', $min)->exists();
        return $q(999) && $q(6);
    }

    /** 出馬表（t_horse_odds_finder_races）にそのレースがあるか */
    private function raceExists($date, $kaisuu, $basho, $day, $race): bool
    {
        return DB::table('t_horse_odds_finder_races')
            ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho', $basho)
            ->where('day', $day)->where('race', intval($race))
            ->exists();
    }

    // ##########################################################################
    // ## 以前の方式で保存した過去の予想の読み出し（11/6 までのレースの表示用）
    // ##########################################################################

    /**
     * AI回答を「公開部分」と「内部監査用JSON」に分ける。
     *
     * マーカーが無い回答（旧形式・AIが出さなかった場合）は
     * 公開部分だけが返り、json は null になる。
     *
     * @return array{public: string, json: ?string}
     */
    private static function splitAuditJson(string $text): array
    {
        $b = self::AUDIT_JSON_BEGIN;
        $e = self::AUDIT_JSON_END;
        $bp = mb_strpos($text, $b);
        if ($bp === false) {
            return ['public' => $text, 'json' => null];
        }
        $public = mb_substr($text, 0, $bp);
        $rest   = mb_substr($text, $bp + mb_strlen($b));
        $ep     = mb_strpos($rest, $e);
        if ($ep === false) {
            // 終了マーカーが無い＝途中で切れた。JSONは採用しない（推測で補わない）。
            return ['public' => $public, 'json' => null];
        }
        $json = mb_substr($rest, 0, $ep);
        // 終了マーカーより後ろに本文が続く場合も公開部分として残す
        $public .= mb_substr($rest, $ep + mb_strlen($e));
        return ['public' => trim($public), 'json' => trim($json)];
    }

    /**
     * AI回答の公開部分だけを返す（内部監査用JSONを落とす）。
     * Flutterへ返す文字列・候補行のパース・回答判定はすべてこれを通す。
     */
    // 20261026: HistoryController::getHorseOddsFinderAiAnalysisByDate からも使うため public にした（中身は変えていない）
    public static function publicPart(string $text): string
    {
        return self::splitAuditJson($text)['public'];
    }

    /**
     * @ANCHOR-20261014-ALLSCORE-KEEP  一覧の analysis_text から内部JSONを落とす
     *
     * 【ついでに直したこと】
     *   1st AI の一覧API（getHorseOddsFinderAiAnalysisRecord）は、
     *   生の回答をそのまま返していたため、内部監査用JSONが画面側へ
     *   流れていました（Flutterは候補行だけを正規表現で読むので表示は
     *   壊れていませんが、出さない約束のデータが出ていました）。
     *   2nd と同じようにここで落とします。
     */
    private static function stripAuditJsonRows($rows)
    {
        return collect($rows)->map(static function ($r) {
            if (is_object($r) && property_exists($r, 'analysis_text')) {
                $r->analysis_text = trim(self::publicPart((string) $r->analysis_text));
            }
            return $r;
        })->values();
    }

    /**
     * 【20260924 追加】2nd AI のキャッシュをそのまま返してよいか。
     *   統合結果（ai_merge_result）が保存済み、または過去日のレースなら true。
     *   過去日は再処理しない（再処理すると当日以降に更新された回収率等で計算し直すことになり、
     *   予測時点のデータではなくなるため）。
     */
    // 20261026: HistoryController::getHorseOddsFinderAiAnalysisByDate からも使うため public にした（中身は変えていない）
    public function _canReturnSecondAiCache($date, $kaisuu, $basho, $day, $race): bool
    {
        if ((string) $date < date('Y-m-d')) {
            return true;
        }
        try {
            return DB::table('t_horse_odds_finder_ai_merge_result')
                ->where('date',       $date)
                ->where('kaisuu',     (int) $kaisuu)
                ->where('basho_code', $basho)
                ->where('day',        (int) $day)
                ->where('race',       (int) $race)
                ->exists();
        } catch (\Throwable $e) {
            return true;   // 判定できない場合は従来どおりキャッシュを返す（AIを呼び直さない側に倒す）
        }
    }

    /**
     * 保存済みの統合結果（Block 14 が t_horse_odds_finder_ai_merge_result に書いたもの）を読み出す。
     *
     * 【なぜ必要か】
     *   getHorseOddsFinderSecondAiOpinion は、統合結果（merged_horses / upset_race）を
     *   「AIを実際に呼んだ回」の応答にしか載せていなかった。
     *   ところが getHorseOddsFinderAiAnalysis が app()->terminating() で 2nd AI を
     *   先読み実行してキャッシュを作るため、ユーザーが 2nd AI ボタンを押す頃には
     *   必ずキャッシュヒットする。つまり実運用では統合結果が一度も Flutter へ届かず、
     *   アプリは常に「1st AI の生リスト」フォールバック表示のままだった。
     *   統合結果は Block 14 が DB へ保存済みなので、キャッシュ応答ではそれを読んで返す。
     *
     * 【シャドー値について】
     *   Block 14 は B-8 / B-10 がシャドー値を付与する「前」に保存しているため
     *   保存済み JSON にシャドー値は入っていない。それでも将来の取り違えを防ぐため、
     *   読み出し時にも SHADOW_ONLY_KEYS を必ず落とす。
     *
     * @return array 応答へマージする配列。レコードが無い・壊れている場合は空配列。
     */
    // 20261026: HistoryController::getHorseOddsFinderAiAnalysisByDate からも使うため public にした（中身は変えていない）
    public function _loadSavedMergeResult($date, $kaisuu, $basho, $day, $race): array
    {
        try {
            $row = DB::table('t_horse_odds_finder_ai_merge_result')
                ->where('date',       $date)
                ->where('kaisuu',     (int) $kaisuu)
                ->where('basho_code', $basho)
                ->where('day',        (int) $day)
                ->where('race',       (int) $race)
                ->first();
        } catch (\Throwable $e) {
//             \Log::warning('[Block14] 統合結果の読み出しに失敗（応答には含めない）', [
//                 'date' => $date, 'kaisuu' => $kaisuu, 'basho_code' => $basho,
//                 'day'  => $day,  'race'   => $race,   'error' => $e->getMessage(),
//             ]);
            return [];
        }

        if (!$row) {
            return [];
        }

        $decoded = json_decode((string) ($row->merge_result ?? ''), true);
        $horses  = (is_array($decoded) && isset($decoded['merged_horses']) && is_array($decoded['merged_horses']))
            ? $decoded['merged_horses']
            : [];

        $horses = array_values(array_map(function ($h) {
            if (!is_array($h)) {
                return $h;
            }
            foreach (self::SHADOW_ONLY_KEYS as $k) {
                unset($h[$k]);
            }
            return $h;
        }, $horses));

        // 【20260928】upset_race は 1 / 0 / null（不明）の三値。
        //   null をそのまま返す（(int) でキャストすると 不明 が 0 に化ける）。
        //   Flutter は (data['upset_race'] as num?)?.toInt() で読むため null 安全。
        //   @ANCHOR-20260928-UPSET-TRISTATE
        $out = [
            'merged_horses'     => $horses,
            'upset_race'        => ($row->upset_race === null) ? null : (int) $row->upset_race,
            'upset_race_status' => ($row->upset_race === null) ? '不明' : (string) ((int) $row->upset_race),
        ];
        // @ANCHOR-20261025-MERGE-STATUS  未確定（採用できるAI回答が無かった）を画面が区別できるように返します
        //   保存直後の応答には status が入っていましたが、保存済みから返すときは落ちていました。
        if (is_array($decoded) && isset($decoded['status']) && is_string($decoded['status'])) {
            $out['status'] = $decoded['status'];
            if (isset($decoded['status_reason']) && is_string($decoded['status_reason'])) {
                $out['status_reason'] = $decoded['status_reason'];
            }
        }

        if ($row->wave_level !== null && $row->lower_entry !== null && $row->big_gap_entry !== null) {
            $out['race_metrics'] = [
                '波乱度'     => (int) $row->wave_level,
                '下位進入度' => (int) $row->lower_entry,
                '大穴進入度' => (int) $row->big_gap_entry,
            ];
        }

        return $out;
    }

    private static function v04Classify(array $flags) : array
    { return BaganrikiSignals::v04Classify($flags); }

    private static function v04Allocation(int $N, array $classification) : array
    { return BaganrikiAllocation::v04Allocation($N, $classification); }

    private static function v04Band(int $pop) : string
    { return BaganrikiAllocation::v04Band($pop); }

    // ##########################################################################
    // ## 新しい予想を、取得口の形にして返す
    // ##########################################################################

    public static function predictionPublicEnabled(): bool
    {
        $v = strtolower(trim((string) env('BAGANRIKI_PREDICTION_PUBLIC', '0')));
        if (!in_array($v, ['1', 'true', 'on', 'yes'], true)) { return false; }
        if (!(BaganrikiPrompt::sending() && BaganrikiWorker::blocking() && BaganrikiWorker::ready())) { return false; }
        foreach (['baganriki_race_runs', 'baganriki_publications'] as $t) {
            if (!\Schema::hasTable($t)) { return false; }
        }
        return true;
    }

    /**
     * そのレースを新しい予想で返すなら、1st・2nd の応答の data を返します。
     * 旧方式に任せるなら null を返します（呼び出し側は今までどおりの処理を続けます）。
     * @return array|null ['first' => data, 'second' => data, 'state' => …]
     */
    public function predictionAnswerFor($date, $kaisuu, $basho, $day, $race): ?array
    {
        if (!self::predictionPublicEnabled()) { return null; }
        try {
            $date = (string) $date;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return null; }
            $row = \DB::table('t_horse_odds_finder_races')
                     ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho', $basho)
                     ->where('day', $day)->where('race', (int) $race)
                     ->first(['date', 'kaisuu', 'basho', 'day', 'race', 'start_time']);
            if ($row === null) { return null; }
            $raceId = self::buildPredictionRaceId($row->date, $row->kaisuu, $row->basho, $row->day, (int) $row->race);
            if (array_key_exists($raceId, self::$predictionAnswerMemo)) { return self::$predictionAnswerMemo[$raceId]; }
            $ans = $this->predictionAnswerForRace($raceId, $row, $date, $kaisuu, $basho, $day, $race);
            self::$predictionAnswerMemo[$raceId] = $ans;
            return $ans;
        } catch (\Throwable $e) {
            // 読めないときは旧方式に任せず「まだ」で返します（勝手に旧方式のAIを呼ばないため）
            \Log::error('[PREDICTION-PUBLIC] 新しい予想を読めません（not_ready で返します）', [
                'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
                'message' => $e->getMessage(),
            ]);
            return self::predictionNotReady($date, $kaisuu, $basho, $day, $race, 'READ_ERROR');
        }
    }

    private function predictionAnswerForRace(string $raceId, object $row, string $date,
                                             $kaisuu, $basho, $day, $race): ?array
    {
        if (self::$answerTestGeneration === null) {   // @ANCHOR-20261110-PREDICTION-ANSWER-TESTGEN（本番は今までどおり）
        $tg = self::checkPredictionTarget($raceId);
        if (!($tg['ok'] ?? false) || ($tg['code'] ?? '') !== 'TARGET') { return null; }
        }

        $now = BaganrikiWorker::nowUs();
        $anchor = self::predictionAnchorUs($date, (string) ($row->start_time ?? ''));

        // 旧方式へ切り替えると決めたレースは、最後まで旧方式（あとから凍結できても、こちらは使わない）
        if (\DB::table('baganriki_audit_events')->where('race_id', $raceId)
                ->where('kind', self::PREDICTION_FALLBACK_KIND)->exists()) {
            return null;
        }

        // $snap = \DB::table('baganriki_snapshots')->where('race_id', $raceId)->where('generation', 0)   // 旧
        $snap = \DB::table('baganriki_snapshots')->where('race_id', $raceId)->where('generation', self::$answerTestGeneration ?? 0)
                  // ->first(['id', 'input_invalid_code', 'sales_close_at', 'mode', 'mode_status',
                  //          'allocation_mode', 'display_rating']);   // 旧
                  // @ANCHOR-20261115-PREDICTION-METRICS-VERSION  release_version も読みます
                  //   【本物の不具合でした】この行に release_version が無かったため、下の predictionPublishedAnswer で
                  //   isExpectedSnapshot が「新しい版ではない」と判定し、3指標を旧方式の読み方（数字だけ）で読んでいました。
                  //   新しい版のAIは {value, source_refs, reason} の形で返すので、検査を通った値もすべて「未算出」になっていました
                  //   （11/14・15：検査を通った値があるレースでも、画面は3つとも未算出）。
                  ->first(['id', 'input_invalid_code', 'sales_close_at', 'mode', 'mode_status',
                           'allocation_mode', 'display_rating', 'release_version']);
        $valid = ($snap !== null && $snap->input_invalid_code === null);

        if (!$valid) {
            // 発走前：凍結を待つ（凍結できないまま時間が来たら、予算の範囲で旧方式へ切り替える）
            if ($anchor !== null && $now < $anchor) {
                // @ANCHOR-20261107-PREDICTION-ALWAYSNEW  旧方式へは切り替えない（豊田さん指示 11/7）
                // if ($this->predictionLegacyFallback($raceId, $anchor, $now)) { return null; }
                return self::predictionNotReady($date, $kaisuu, $basho, $day, $race, 'FREEZING');
            }
            // 発走後・発走時刻が読めない：旧方式の保存済みがあれば、それを見せるだけ（旧方式のAIは呼ばない）
            $tried = \DB::table('baganriki_race_runs')->where('race_id', $raceId)->exists();
            return self::predictionLegacySavedOrUndetermined($date, $kaisuu, $basho, $day, $race, $tried
                       ? '新しい予想の入力を固められなかったため、このレースの予想は出せませんでした'
                       : 'このレースの予想はありません');
        }

        $live = \DB::table('baganriki_live_states')->where('snapshot_id', $snap->id)->first();
        $pub  = \DB::table('baganriki_publications')->where('snapshot_id', $snap->id)
                  ->orderByDesc('published_at')->orderByDesc('id')->first();
        $cancelled = array_map('intval', (array) json_decode((string) ($live->cancellations ?? '[]'), true));

        if ($pub !== null) {
            // @ANCHOR-20261110-PREDICTION-ZEROCAND  よっしー11/10夜：評価は検査を通ったが、根拠を確かめられた候補が0頭で公開したレースは、
            //   馬0頭の画面を文面なしで出さず、次の文面を出します（「AIの評価を確認できなかった」とは別の文面）
            // $pubCands = json_decode((string) ($pub->candidates ?? ''), true);
            // if (is_array($pubCands) && $pubCands === []) {
            //     return self::predictionLegacySavedOrUndetermined($date, $kaisuu, $basho, $day, $race,
            //                'AIの評価で根拠を確かめられた馬がいなかったため、予想は出せませんでした');
            // }   // 旧（11/10〜11/15）
            // @ANCHOR-20261115-PREDICTION-REFILL  よっしー11/14「B：頭数分を表示」。候補0頭の公開も、頭数分を参考の補充として出します
            //   （下の predictionPublishedAnswer で、全頭が「補充（参考）：AIの評価を確認できなかったため…」になります）
            // $key = 'prediction_answer:v7:' . (int) $snap->id . ':' . $pub->publication_id . ':' . (int) ($live->state_revision ?? 0);   // 旧（20261116 指標反映の印を足したので v8）
            $key = 'prediction_answer:v8:' . (int) $snap->id . ':' . $pub->publication_id . ':' . (int) ($live->state_revision ?? 0);
            $build = function () use ($snap, $pub, $cancelled, $date, $kaisuu, $basho, $day, $race) {
                return $this->predictionPublishedAnswer($snap, $pub, $cancelled, $date, $kaisuu, $basho, $day, $race);
            };
            // try {
            //     return \Cache::remember($key, 86400, $build);
            // } catch (\Throwable $e) {
            //     return $build();
            // }   // 旧（20261118）
            // @ANCHOR-20261118-PREDICTION-ANSWER-CACHE  画面を開いたときの「30秒を超えました」（laravel.log 11/15〜11/18）への対応
            //   ① 組み立ては重いので、30秒で止めず最後まで組み立ててキャッシュへ残します（2人目からはすぐ返ります）
            //   ② キャッシュへ書けなかったとき、もう一度組み立て直していました（時間が倍）。組み立て済みならそれを返します
            //   ③ キーに公開IDと状態の版が入っているので、中身が変わればキーも変わります。1日で捨てず30日持ちます
            @set_time_limit(120);
            $built = null;
            try {
                return \Cache::remember($key, 2592000, function () use ($build, &$built) { return $built = $build(); });
            } catch (\Throwable $e) {
                return $built ?? $build();
            }
        }

        // 公開がまだ：期限の前なら「まだ」、過ぎていれば出せなかった
        $pastDeadline = false;
        $dl = BaganrikiWorker::deadlines(
                  \DB::table('baganriki_snapshots')->where('id', $snap->id)->first(), $live);
        if (($dl['ok'] ?? false) === true) {
            $pastDeadline = $now > (int) $dl['publish'];
        } else {
            // 期限を出せない（発走済み・締切が読めない等）ときは、もう公開できないので「出せなかった」
            $pastDeadline = true;
        }
        // @ANCHOR-20261108-PREDICTION-EARLYFILL  両方のAIが使える馬を返せなかったと決まったら、期限を待たずに補充を出す
        //   11/7 福島3R・11/8 東京6R は、両AIの失敗が分かってからも公開期限まで「予想中」のままでした（11/7 豊田さんの指示で変更）。
        if (!$pastDeadline && !self::predictionBothRolesFailed((int) $snap->id)) {
            return self::predictionNotReady($date, $kaisuu, $basho, $day, $race, 'PREDICTING');
        }
        $allCancelled = ($live !== null && $live->active_count !== null && (int) $live->active_count === 0);
        // @ANCHOR-20261104-PREDICTION-HISTORY  旧方式の保存済みがあるレースは、それを見せる（補充より先）
        //   10/24・10/25 などは、新しい予想の凍結だけが動き（公開はしていない）、お客様には旧方式の予想を見せていました。
        //   そのレースを今開いたときに補充の予想へ変わらないよう、旧方式の保存済みがあればそれを返します。
        //   11/7 からのレースは旧方式を動かさないので、ここには当たりません（旧方式へ切り替えたレースを除く）。
        $legacy = self::predictionLegacySavedOrUndetermined($date, $kaisuu, $basho, $day, $race, '');
        if ($legacy['first'] === null) { return $legacy; }
        // @ANCHOR-20261102-PREDICTION-FILL  公開できなかったレースも、凍結した出走馬から頭数をそろえて出す
        if (!$allCancelled) {
            // $key = 'prediction_answer:v7:fill:' . (int) $snap->id . ':' . (int) ($live->state_revision ?? 0);   // 旧（20261116 v8）
            $key = 'prediction_answer:v8:fill:' . (int) $snap->id . ':' . (int) ($live->state_revision ?? 0);
            $build = function () use ($snap, $cancelled, $date, $kaisuu, $basho, $day, $race) {
                return $this->predictionPublishedAnswer($snap, null, $cancelled, $date, $kaisuu, $basho, $day, $race);
            };
            // try { $fa = \Cache::remember($key, 86400, $build); } catch (\Throwable $e) { $fa = $build(); }   // 旧（20261118）
            // @ANCHOR-20261118-PREDICTION-ANSWER-CACHE  上と同じ（30秒で止めない・二度組み立てない・30日持つ）
            @set_time_limit(120);
            $built = null;
            try { $fa = \Cache::remember($key, 2592000, function () use ($build, &$built) { return $built = $build(); }); }
            catch (\Throwable $e) { $fa = $built ?? $build(); }
            if ((array) ($fa['second']['merged_horses'] ?? []) !== []) { return $fa; }
        }
        return self::predictionLegacySavedOrUndetermined($date, $kaisuu, $basho, $day, $race, $allCancelled
                   ? '全頭が取消になったため、このレースの予想はありません'
                   // : '発走前の締切までにAIの回答を確かめ終えられなかったため、このレースの予想は出せませんでした');   // 旧
                   : 'AIの評価を確認できなかったため、予想は出せませんでした');   // よっしー11/10 の文面
    }

    /** 公開済みの予想から、今の画面の形を作ります */
    private function predictionPublishedAnswer(object $snap, ?object $pub, array $cancelled,
                                               $date, $kaisuu, $basho, $day, $race): array
    {
        $full = \DB::table('baganriki_snapshots')->where('id', $snap->id)->first(['input_json_1st', 'quota_1st']);
        $in = json_decode((string) ($full->input_json_1st ?? ''), true);
        $in = is_array($in) ? $in : [];
        $reg = (array) ($in['source_registry'] ?? []);
        $pop = [];
        foreach ((array) ($in['runners'] ?? []) as $r) {
            if (isset($r['horse_no'])) { $pop[(int) $r['horse_no']] = $r['win_rank'] ?? null; }
        }
        $names = [];
        foreach (\DB::table('t_horse_odds_finder_horses')->where('date', $date)->where('kaisuu', $kaisuu)
                    ->where('basho', $basho)->where('day', $day)->where('race', (int) $race)
                    ->get(['num', 'name']) as $h) {
            $names[(int) $h->num] = (string) $h->name;
        }
        // 検証を通った応答の根拠の系統（両roleを合わせる）
        $fams = [];
        $okRoles = [];
        foreach (['1st', '2nd'] as $role) {
            try {
                $a = $this->acceptedHorsesOf((int) $snap->id, $role);
            } catch (\Throwable $e) {
                continue;
            }
            if (!($a['ok'] ?? false)) { continue; }
            // $okRoles[$role] = true;   // @ANCHOR-20261115-PREDICTION-REFILL  補充の順位に使ってよい（検査を通った）role   （旧）
            $okRoles[$role] = $a;   // @ANCHOR-20261115-PREDICTION-FILLRANK  検査を通った結果そのもの（補充の順位を、検証済みの順位から取るため）
            foreach ((array) ($a['merged'] ?? []) as $no => $h) {
                foreach ((array) ($h['family_review'] ?? []) as $fam => $fr) {
                    if (is_array($fr) && ($fr['status'] ?? '') === 'SUPPORTED'
                        && isset(self::PREDICTION_FAMILY_LABEL[$fam])) {
                        $fams[(int) $no][$fam] = true;
                    }
                }
            }
        }

        // @ANCHOR-20261116-PREDICTION-METRICSSLOT  3指標の選出連動（RACE_METRICS_SELECTION_V1）で入った馬に印を付ける（なる11/15 仕様9節）
        //   公開記録の metrics_selection が APPLIED のときだけ。説明は実際に変更したときだけ出し、補充とは別のラベルにします。
        //   おすすめ度のかさ上げ・購入推奨はしません（buy_recommended=false のまま）。
        $msel = null;
        if ($pub !== null) {
            $evMs = json_decode((string) ($pub->evidence ?? ''), true);
            $m0 = is_array($evMs) ? ($evMs['metrics_selection'] ?? null) : null;
            if (is_array($m0) && ($m0['status'] ?? null) === 'APPLIED' && is_int($m0['added'] ?? null) && is_int($m0['removed'] ?? null)
                && in_array($m0['target_band'] ?? null, ['B', 'D'], true) && in_array($m0['source_band'] ?? null, ['A', 'B', 'D'], true)) {
                $msel = $m0;
            }
        }
        $mselLabel = ($msel === null) ? null
            : (($msel['target_band'] === 'D' ? '大穴進入度' : '下位進入度') . 'を反映：' . $msel['source_band'] . '帯から' . $msel['target_band'] . '帯へ1頭変更');
        $horses = [];
        foreach ((array) json_decode((string) ($pub->candidates ?? '[]'), true) as $c) {   // $pub が null なら0頭
            $no = (int) ($c['horse_no'] ?? 0);
            if ($no <= 0 || in_array($no, $cancelled, true)) { continue; }
            $odds6 = self::predictionRegNum($reg, 'h' . $no . '_win_odds_6');
            $score = is_numeric($c['score'] ?? null) ? (int) round((float) $c['score']) : 0;
            $horses[] = [
                'num'        => $no,
                'name'       => $names[$no] ?? '',
                'score'      => min(100, max(0, $score)),
                'odds_6'     => $odds6,
                'popularity' => is_numeric($pop[$no] ?? null) ? (int) $pop[$no] : null,
                'reason'     => self::predictionReason($reg, $no, array_keys($fams[$no] ?? [])),
                'category'   => 'matched',
                'score_1st'  => min(100, max(0, $score)),   // 旧方式の matched と同じく値を入れる（両AIの統合点）
                'score_2nd'  => min(100, max(0, $score)),
                'reason_2nd' => null,
                'band'       => (string) ($c['band'] ?? ''),
            ];
            if ($msel !== null && $no === $msel['added']) {   // @ANCHOR-20261116-PREDICTION-METRICSSLOT
                $last = count($horses) - 1;
                $horses[$last]['metrics_slot'] = true;
                $horses[$last]['reason'] = '指標反映（' . $mselLabel . '）：' . $horses[$last]['reason'];
            }
        }
        usort($horses, static function (array $x, array $y): int {
            if ($x['score'] !== $y['score']) { return $y['score'] <=> $x['score']; }
            return ($x['popularity'] ?? 99) <=> ($y['popularity'] ?? 99);
        });

        // @ANCHOR-20261102-PREDICTION-FILL  頭数に足りなければ補う（「結果なし」で終わらせない。11/2 豊田の判断）
        //   枠（A・B・D の頭数）は、公開があれば公開したときの枠、無ければ凍結したときの枠です。
        //   補うのは、AIの根拠を確かめきれなかった分だけです。同じ人気帯の6分前の人気順で取り、
        //   帯に馬がいなければ、D の不足は B から、B の不足は D から、A の不足は B→D の順で取ります
        //   （公開の不足の埋め方と同じ順）。補った馬は、おすすめ度を付けず、理由に「補充」と書きます。
        $quota = self::predictionQuotaOf($pub !== null ? ($pub->publication_quota ?? null) : null,
                                         $full->quota_1st ?? null);
        // 補う順番は、AIが付けた帯の中の順位があればそれを先に（1st→2nd の順で、受け取れた応答から）、無ければ人気順
        $aiPos = [];
        foreach (['1st', '2nd'] as $role) {
            // @ANCHOR-20261115-PREDICTION-REFILL  なる11/14「無効なAI回答の順位を、そのまま正常な順位として流用しない」
            //   検査を通らなかった role の順位は使いません（その帯は6分前の人気順で補います）。
            if (!isset($okRoles[$role])) { continue; }
            // foreach (\DB::table('baganriki_ai_attempts')->where('snapshot_id', $snap->id)->where('role', $role)
            //             ->where('status', BaganrikiWorker::RECEIVED)->orderBy('attempt_no')->get(['raw_response']) as $at) {
            //     $ex = BaganrikiResponse::extract((string) $at->raw_response);
            //     if (!$ex['ok'] || !is_array($ex['json']['band_ranks'] ?? null)) { continue; }
            //     foreach ((array) $ex['json']['band_ranks'] as $b => $list) {
            //         foreach (array_values((array) $list) as $i => $no) {
            //             if (is_int($no) && !isset($aiPos[$no])) { $aiPos[$no] = $i; }
            //         }
            //     }
            //     break 2;
            // }   // 旧（11/15：検査を通った role でも、最初に届いた返事の順位をそのまま読んでいた。訂正で直った順位を使えない）
            // @ANCHOR-20261115-PREDICTION-FILLRANK  なる11/15「補充の順位は、実際に採用・検証された順位に結び付ける」
            //   公開と同じ markResponseUsed（読み取りだけ）で、検証済みの帯の順位（訂正・予備根拠順位・明示縮退を含む）を取ります。
            //   E3・E5の入れ替えで外れた馬は、検証済みの順位の中で入れ替え相手より上にいるので、残りの馬の並びは入れ替え後と同じです。
            //   評価が最後まで終わっていない（complete でない）・順位を取れないときは、その role を使わず、6分前の人気順に戻します。
            $acc = $okRoles[$role];
            if (($acc['complete'] ?? null) !== true) { continue; }
            try { $rk = $this->markResponseUsed((int) $snap->id, $role, $acc); }
            catch (\Throwable $e) { continue; }
            $br = $rk['band_ranks'] ?? null;
            if (!is_array($br) || $br === []) { continue; }
            foreach ($br as $b => $list) {
                foreach (array_values((array) $list) as $i => $no) {
                    if (is_int($no) && !isset($aiPos[$no])) { $aiPos[$no] = $i; }
                }
            }
            break;
        }
        // $fill = self::predictionFillRows($in, $quota, $horses, $cancelled, $aiPos);   // 旧（11/2 豊田の判断：根拠を確かめきれない枠も人気順などで埋める）
        // @ANCHOR-20261110-PREDICTION-NOFILL  よっしー11/10「B：根拠で選べた馬だけ表示」。根拠を確かめられない馬で枠を埋めません。
        //   画面に出すのは公開した候補（両AIの検証済みの根拠で選んだ馬。公開のときに別の帯から根拠のある馬で補った分を含む）だけです。
        //   1頭も公開できなかったレースは、呼び出し側で「予想は出せませんでした」になります。
        // $fill = [];   // 旧（11/10〜11/15：補充しない）
        // @ANCHOR-20261115-PREDICTION-REFILL  よっしー11/14「B：頭数分を表示し、自信のある馬から並べる」（なる11/14の整理）
        //   通常候補（公開した、少なくとも片方のAIの検証済みの根拠で選んだ馬）を先に、おすすめ度の順で並べます（上の usort）。
        //   頭数（公開の枠）に足りない分だけ、同じ人気帯の中で補います（B・Dの不足をAでは埋めません）。
        //   補った馬は後ろに並べ、理由の先頭に「補充」と書き、おすすめ度は0（画面には出しません）。購入推奨にも入れません。
        $regularCount = count($horses);
        if ($regularCount === 0) { $aiPos = []; }   // 参考の表示は、6分前の人気順だけで並べる（理由の文と合わせる）
        $fill = self::predictionFillRows($in, $quota, $horses, $cancelled, $aiPos);
        foreach ($fill as $f) {
            $no = (int) $f['num'];
            $horses[] = [
                'num'        => $no,
                'name'       => $names[$no] ?? '',
                'score'      => 0,
                'odds_6'     => self::predictionRegNum($reg, 'h' . $no . '_win_odds_6'),
                'popularity' => is_numeric($pop[$no] ?? null) ? (int) $pop[$no] : null,
                // 'reason'     => '補充：AIの根拠を確かめきれなかったため、同じ人気帯の中でAIが付けた順位（無ければ6分前の人気順）で補いました。'
                //               . self::predictionReason($reg, $no, []),   // 旧
                // @ANCHOR-20261115-PREDICTION-REFILL  通常候補が0頭のとき（AIの評価が成り立たなかった）は、参考の表示だと分かる文にする
                'reason'     => ($regularCount === 0
                                   ? '補充（参考）：AIの評価を確認できなかったため、同じ人気帯の中で6分前の人気順に並べた参考の表示です。'
                                   : '補充：根拠を確かめられた馬だけでは頭数に足りないため、同じ人気帯の中でAIが付けた順位（無ければ6分前の人気順）で補いました。')
                              . self::predictionReason($reg, $no, []),
                'category'   => 'matched',
                'score_1st'  => 0,
                'score_2nd'  => 0,
                'reason_2nd' => null,
                'band'       => (string) $f['band'],
                'fill_slot'  => true,
            ];
        }

        $lines = [];
        foreach ($horses as $h) {
            // 旧方式と同じ1行の形（PUBLIC_ROW_REGEX・アプリの parseAnalysisText のどちらでも読める）
            $lines[] = '馬番：' . $h['num']
                     . '、馬名：' . str_replace(['、', ',', "\n"], ' ', $h['name'])
                     . '、人気順: ' . (int) ($h['popularity'] ?? 0)
                     . '、6分前オッズ: ' . sprintf('%.1f', (float) ($h['odds_6'] ?? 0))
                     . '、おすすめ度: ' . $h['score']
                     . '、選出理由：' . str_replace("\n", ' ', $h['reason']);
        }
        $text = implode("\n", $lines);
        $meta = [
            'prediction_method' => 'new',
            'prediction_state'  => 'PUBLISHED',
            'prediction' => [
                'publication_id'  => ($pub === null) ? null : (string) $pub->publication_id,
                'published_at'    => ($pub === null) ? null : (string) $pub->published_at,
                'filled'          => count($fill),
                // @ANCHOR-20261115-PREDICTION-REFILL  なる11/14「同じ成功として数えない」ための区分
                //   REGULAR_FULL（通常候補だけで頭数どおり）／WITH_FILL（補充を含めて頭数どおり）／REFERENCE_ONLY（AIの評価が成り立たず、参考の表示だけ）／EMPTY（出せる馬なし）
                'regular_count'   => $regularCount,
                // 'fill_status'     => ($regularCount > 0 && count($fill) === 0) ? 'REGULAR_FULL'
                //                      : (($regularCount > 0) ? 'WITH_FILL' : ((count($fill) > 0) ? 'REFERENCE_ONLY' : 'EMPTY')),   // 旧（目標の頭数と比べていなかった）
                // @ANCHOR-20261115-PREDICTION-FILLSTATUS  なる11/15「目標・通常・補充・表示を別に記録し、充足を実際に比べる」
                //   SHORT＝補充しても目標の頭数に届かない（取消などで、その帯に馬が残っていない）
                'target_count'    => array_sum(array_map('intval', (array) $quota)),
                'shown_count'     => count($horses),
                'fill_status'     => self::predictionFillStatus($regularCount, count($fill), array_sum(array_map('intval', (array) $quota))),
                'mode'            => $snap->mode ?? null,
                'mode_status'     => $snap->mode_status ?? null,
                'allocation_mode' => $snap->allocation_mode ?? null,
                'display_rating'  => (($snap->mode_status ?? null) === 'PROVISIONAL') ? null : ($snap->display_rating ?? null),
                'provisional_label' => (($snap->mode_status ?? null) === 'PROVISIONAL')
                                       ? BaganrikiPublicView::provisionalLabel($snap->allocation_mode ?? null) : null,
                'cancelled'       => array_values($cancelled),
                'buy_recommended' => false,
                // @ANCHOR-20261116-PREDICTION-METRICSSLOT  3指標で実際に1頭変更したときだけ中身が入る（それ以外は null）
                'metrics_selection' => ($msel === null) ? null : [
                    'label'       => $mselLabel,
                    'added'       => $msel['added'],
                    'removed'     => $msel['removed'],
                    'source_band' => $msel['source_band'],
                    'target_band' => $msel['target_band'],
                    'shown'       => !in_array($msel['added'], $cancelled, true),
                ],
            ],
        ];
        // @ANCHOR-20261108-PREDICTION-METRICS  1st が答えた3指標（旧方式と同じ race_metrics の形。読めなければ null）
        $metrics = null;
        if (BaganrikiWorker::isExpectedSnapshot($snap)) {
            // @ANCHOR-20261110-PREDICTION-EXPECTED-METRICS  予想の版：検査済みで公開記録に保存した3指標だけを返す（よっしーR15.20）
            //   AIの最新応答を別に読んで混ぜない。保存値が壊れていれば null（何も出さない）。項目ごとの null は「未算出」。
            if ($pub !== null) {
                try {
                    $ev = json_decode((string) ($pub->evidence ?? ''), true);
                    $in1 = $in;   // この関数の最初で読んだ 1st の凍結入力
                    if (is_array($in1) && BaganrikiRaceMetrics::enabled($in1)) {
                        $metrics = BaganrikiRaceMetrics::publicValues($in1, is_array($ev) ? ($ev['race_metrics'] ?? null) : null);
                    }
                } catch (\Throwable $e) {
                    $metrics = null;
                }
            }
        } else {
            foreach (\DB::table('baganriki_ai_attempts')->where('snapshot_id', $snap->id)->where('role', '1st')
                        ->where('status', BaganrikiWorker::RECEIVED)->orderBy('attempt_no')->get(['raw_response']) as $at) {
                $metrics = self::predictionMetricsFromRaw((string) $at->raw_response);
                if ($metrics !== null) { break; }
            }
        }
        // @ANCHOR-20261115-PREDICTION-METRICS-NA  なる11/14「人気帯そのものが存在しない場合は『対象外』と区別」
        //   凍結した出走馬に 7〜10番人気がいなければ下位進入度、11番人気以下がいなければ大穴進入度を「対象外」にします。
        //   （AIが値を返していても、その人気帯の馬がいないので使いません。11/14・15 は10頭以下のレースでAIが別の帯の馬を引いて検査で外れていました）
        //   値は文字の「対象外」で返し、数字（1〜5）や0では埋めません。
        if ($metrics !== null) {
            $rk = array_map(static fn($r) => (int) ($r['win_rank'] ?? 0), (array) ($in['runners'] ?? []));
            if (array_filter($rk, static fn($k) => $k >= 7 && $k <= 10) === []) { $metrics['下位進入度'] = '対象外'; }
            if (array_filter($rk, static fn($k) => $k >= 11) === []) { $metrics['大穴進入度'] = '対象外'; }
        }
        $meta['race_metrics'] = $metrics;
        // 1st の本文にも旧方式と同じ「レース指標|…」の行を先頭に置く（アプリが analysis_text からも読むため）
        if ($metrics !== null) {
            // $mv = static fn($v) => ($v === null) ? '未算出' : (string) $v;   // 未算出の項目は文字で（20261110）   // 旧
            $mv = static fn($v) => ($v === null) ? '未算出' : (string) $v;   // 未算出の項目は文字で（20261110）。「対象外」はそのまま文字で出る（20261115）
            $text = 'レース指標|波乱度: ' . $mv($metrics['波乱度']) . '|下位進入度: ' . $mv($metrics['下位進入度'])
                  . '|大穴進入度: ' . $mv($metrics['大穴進入度']) . ($text !== '' ? "\n" . $text : '');
        }
        $base = ['date' => $date, 'kaisuu' => $kaisuu, 'basho_code' => $basho, 'day' => $day, 'race' => $race];
        if ($pub === null) { $meta['prediction_state'] = 'FILLED'; }
        return [
            'state'  => ($pub === null) ? 'FILLED' : 'PUBLISHED',
            'first'  => $base + ['analysis_text' => $text] + $meta,
            'second' => $base + ['analysis_text' => $text,
                                 'merged_horses' => $horses,
                                 'upset_race' => null, 'upset_race_status' => '不明',
                                 'status' => 'ok'] + $meta,
        ];
    }

    // ##########################################################################
    // ## 入力を集めて固める（凍結）
    // ##########################################################################

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-PROMPT  送信文（system＋INPUT_JSON）を1組にする
     * @ANCHOR-20261020-PREDICTION-ONEBUILD
     * ═══════════════════════════════════════════════════════════════════════
     * 失敗したら ok=false を返します。<b>旧本文へ落ちません。</b>
     *
     * ■ ご指摘のとおりでした
     *   【旧】送信用と保存用で gatherPredictionContext を<b>2回</b>呼んでいました。
     *     ① assemblePredictionRequest が本文Aを作る
     *     ② reserveAiSlot が freezePredictionInput を呼ぶ
     *     ③ freezePredictionInput が<b>もう一度</b>組み立てて本文Bを保存する
     *     ④ 保存した snapshot_hash と本文Aの snapshot_hash を比べる
     *   派生の computed_at を実計算時刻へ直した（007の4-2）ので、
     *   ①と③の時刻が違えば共通hashも違います。④で SNAPSHOT_MISMATCH に
     *   なり、<b>自分で自分の送信を拒否します。</b>DBが動かなくても起きます。
     *   log/02_凍結.txt に出ていた2つのhashの食い違いを、前回
     *   「組み立てが別だから」と説明しましたが、無害ではありませんでした。
     *
     * ■ いまの形
     *   <b>組み立ては1回だけです。</b>
     *     ① 固定入力（generation=0）があるか見る
     *     ② 無ければ freezePredictionInput で<b>1回だけ</b>作って固定する
     *     ③ <b>保存した本文をそのまま読んで返す</b>（作り直しません）
     *   1st も 2nd も 訂正も、同じ保存物を読みます。
     *   詳細設計書7.2「両roleのJSONは保存した本文をそのまま送信する」です。
     *
     *   hash検査を外す・computed_at を予定時刻へ戻す・時刻をhash対象から
     *   外す、という回避はしていません。
     *
     * ■ 作り直す側（試験・調査用）
     *   保存を見ずに組み立てたいときは assembleFreshPredictionRequest() を呼びます。
     *   <b>送信には使いません。</b>
     */
    public function assemblePredictionRequest($date, $kaisuu, $basho, $day, $race,
                                     string $role, array $override = []): array
    {
        $raceId = self::buildPredictionRaceId($date, $kaisuu, $basho, $day, $race);
        $fail = static function (string $code, string $reason)
                use ($role, $raceId): array {
            return ['ok' => false, 'role' => $role, 'race_id' => $raceId,
                    'code' => $code, 'reason' => $reason,
                    'system' => null, 'system_sha256' => null, 'user' => null,
                    'user_bytes' => 0, 'input' => null, 'snapshot_hash' => null,
                    'issues' => [], 'derived' => [], 'notes' => []];
        };
        if ($role !== '1st' && $role !== '2nd') {
            return $fail('ROLE_INVALID', 'role は 1st か 2nd だけです（' . $role . '）');
        }
        if (!\Schema::hasTable('baganriki_snapshots')) {
            return $fail('TABLE_MISSING',
                'r12.2 のテーブルがありません（--prediction-install で作ります）');
        }

        // ── ① 固定入力を探します（本番は generation=0 だけ）──────────
        $snap = \DB::table('baganriki_snapshots')
                  ->where('race_id', $raceId)->where('generation', 0)->first();

        // ── ② 無ければ1回だけ作って固定します ────────────────────────
        $notes = [];
        if ($snap === null) {
            $fz = $this->freezePredictionInput($date, $kaisuu, $basho, $day, $race,
                                    $override, 'PRODUCTION', false);
            if (!($fz['ok'] ?? false)) {
                return $fail((string) ($fz['code'] ?? 'FREEZE_FAILED'),
                             (string) ($fz['reason'] ?? '固定入力を作れません'));
            }
            $notes[] = 'このレースの固定入力をいま作りました（1回だけ）';
            $snap = \DB::table('baganriki_snapshots')
                      ->where('race_id', $raceId)->where('generation', 0)->first();
        } else {
            $notes[] = '固定済みの入力をそのまま使いました（作り直していません）';
        }
        if ($snap === null) {
            return $fail('NO_SNAPSHOT', '固定入力を読み戻せません');
        }

        // ── ③ 保存した本文をそのまま読みます ─────────────────────────
        $col  = ($role === '2nd') ? 'input_json_2nd' : 'input_json_1st';
        $user = (string) ($snap->{$col} ?? '');
        if ($user === '') {
            return $fail('NO_SAVED_INPUT',
                '固定入力に ' . $role . ' の本文が入っていません（' . $col . '）');
        }
        $in = json_decode($user, true);
        if (!is_array($in)) {
            return $fail('SAVED_INPUT_BROKEN',
                '固定入力の ' . $role . ' 本文を読めません');
        }
        // 保存本文の共通hashと、snapshot_hash 列が合っているか
        if ((string) ($in['snapshot_hash'] ?? '') !== (string) $snap->snapshot_hash) {
            return $fail('SAVED_HASH_MISMATCH',
                '保存本文の snapshot_hash と列の値が違います（保存が壊れています）');
        }

        // ── ④ system は配布された本文ファイルから（中身は決まっています）──
        $sys = BaganrikiPrompt::systemBody($role);
        if (!$sys['ok']) {
            return $fail('PROMPT_BODY_INVALID', (string) $sys['reason']);
        }

        $asm = ['ok' => true, 'role' => $role, 'race_id' => $raceId,
                'system' => $sys['body'], 'system_sha256' => $sys['sha256'],
                'user' => $user, 'user_bytes' => strlen($user),
                'input' => $in, 'snapshot_hash' => (string) $snap->snapshot_hash,
                'code' => null, 'reason' => null, 'issues' => [],
                'derived' => [], 'notes' => $notes,
                // @ANCHOR-20261020-PREDICTION-ONEBUILD  保存物をそのまま送った印
                'from_saved' => true];

        // ── ⑤ 保存物でも、送る前の検査は同じように通します ───────────
        $asm['issues'] = BaganrikiPrompt::selfCheck($asm);
        if ($asm['issues'] !== []) {
            $asm['ok']     = false;
            $asm['code']   = 'SEND_SELF_CHECK_FAILED';
            $asm['reason'] = implode('／', array_column($asm['issues'], 'reason'));
        }
        return $asm;
    }

    /**
     * @ANCHOR-20261020-PREDICTION-ONEBUILD  保存を見ずに組み立てます（試験・調査用）
     *
     *   <b>送信には使いません。</b>実レースの入力がどう組み上がるかを
     *   見るための関数です（--prediction-prod-input や市場試験など）。
     *   呼ぶたびに派生の計算時刻が変わるので、2回呼べば共通hashは変わります。
     *   それが普通です。送信経路はこの関数を通りません。
     */
    public function assembleFreshPredictionRequest($date, $kaisuu, $basho, $day, $race,
                                      string $role, array $override = []): array
    {
        $g = self::gatherPredictionContext($date, $kaisuu, $basho, $day, $race, $override);
        if (!$g['ok']) {
            return ['ok' => false, 'role' => $role, 'race_id' => $g['race_id'],
                    'code' => $g['code'], 'reason' => $g['reason'],
                    'system' => null, 'system_sha256' => null, 'user' => null,
                    'user_bytes' => 0, 'input' => null, 'snapshot_hash' => null,
                    'issues' => [], 'derived' => [], 'notes' => $g['notes']];
        }
        $asm = BaganrikiPrompt::assemble($g['ctx'], $role);
        $asm['race_id']    = $g['race_id'];
        $asm['notes']      = $g['notes'];
        $asm['from_saved'] = false;
        return $asm;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-FREEZE  凍結入力の保存（snapshot）
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：server_contract_r12.2.txt 2章・3章・5章・11章／詳細設計書 4.4・7.2
     *
     * ■ 何を保存するか
     *   両roleへ渡す共通JSONの原文と SHA-256、1st・2nd それぞれの INPUT_JSON、
     *   凍結出走集合と凍結順位、信号・S・mode一式、配分、取得監査、coverage、
     *   発走時刻・cutoff・input_freeze・投票締切、そして実際に保存した時刻。
     *
     * ■ 決めごと
     *   ・<b>一度固定したら変えません。</b>同じ race_id・generation が既にあれば
     *     何もせず「既存」を返します。上書きしません。
     *   ・generation は期限の延長では増やしません（契約5章）。
     *   ・入力が作れないときは snapshot を作りません。
     *     代わりに baganriki_race_runs へ理由コードつきで1行残します
     *     （「何も起きなかった」と「入力が不正だった」を区別するため）。
     *   ・frozen_at は実際に保存した時刻です。input_freeze_at とは別物で、
     *     予定時刻で埋めません。
     *   ・4つの頭数（entry / acquired / frozen / active）は混ぜずに別々に出します。
     *
     * @param array  $override  投票締切などの差し込み（検証用）
     * @param string $runKind   'PRODUCTION' か 'REPLAY'
     * @param bool   $dryRun    true なら1行も書かずに結果だけ返す
     * @return array ['ok','race_id','code','reason','skipped','snapshot_hash','counts']
     */
    public function freezePredictionInput($date, $kaisuu, $basho, $day, $race,
                               array $override = [], string $runKind = 'PRODUCTION',
                               bool $dryRun = false): array
    {
        $raceId = self::buildPredictionRaceId($date, $kaisuu, $basho, $day, $race);
        $now    = BaganrikiPrompt::rfc3339((int) BaganrikiTime::us(date('c')));
        $nowDb  = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');
        if (BaganrikiWorker::$testNowUs !== null && app()->runningInConsole()) {   // 試験の時計（本番では通らない）
            $now   = BaganrikiPrompt::rfc3339(intdiv(BaganrikiWorker::nowUs(), 1000000) * 1000000);
            $nowDb = BaganrikiWorker::toDb(BaganrikiWorker::nowUs());
        }
        $runId  = 'run-' . $raceId . '-' . date('YmdHis') . '-'
                . substr(bin2hex(random_bytes(3)), 0, 6);
        $out = ['ok' => false, 'race_id' => $raceId, 'code' => null, 'reason' => null,
                'skipped' => false, 'snapshot_hash' => null, 'counts' => []];
        // @ANCHOR-20261115-PREDICTION-FREEZE-TIMING  凍結の段階ごとの時間と、そのときのサーバの混み具合をログに残します（動きは変えません）
        //   11/15：凍結は単独なら4.5〜7秒なのに、本番では25〜47秒かかっていました（毎分のオッズ取り込みと重なる時刻）。
        //   どの段階が延びているか（集める／組み立てる／E3・E5／保存）と、CPUの負荷・メモリを、11/21（土）の本番で確かめるためです。
        $tm = ['start' => microtime(true)];
        $earlyTry = false;   // @ANCHOR-20261116-PREDICTION-EARLYFREEZE  T−300秒より前の早期凍結の試し
        $logTiming = static function (array $tm, string $raceId, string $result): void {
            try {
                $sec = []; $prev = $tm['start'];
                foreach ($tm as $k => $t) { if ($k === 'start') { continue; } $sec[$k] = round($t - $prev, 2); $prev = $t; }
                $sec['total'] = round(microtime(true) - $tm['start'], 2);
                $load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
                $mem = null;
                if (is_readable('/proc/meminfo') && preg_match('/MemAvailable:\s+(\d+)/', (string) @file_get_contents('/proc/meminfo'), $mm) === 1) {
                    $mem = (int) round(((int) $mm[1]) / 1024);
                }
                \Log::error('[PREDICTION-FREEZE-TIMING] 凍結の段階ごとの秒数', [
                    'race_id' => $raceId, 'result' => $result, 'sec' => $sec,
                    'load' => $load, 'mem_available_mb' => $mem,
                    'php_peak_mb' => (int) round(memory_get_peak_usage(true) / 1048576),
                ]);
            } catch (\Throwable $e) { /* 記録できなくても凍結には影響させません */ }
        };

        if (!\Schema::hasTable('baganriki_snapshots')
            || !\Schema::hasTable('baganriki_race_runs')) {
            $out['code']   = 'TABLE_MISSING';
            $out['reason'] = 'r12.2 のテーブルがありません（--prediction-install で作ります）';
            return $out;
        }

        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-SEP  REPLAY と PRODUCTION を混ぜません
        // ══════════════════════════════════════════════════════════════
        // 【10月20日005のご指摘9。そのとおりでした】
        //   snapshot の検索も一意キーも race_id＋generation だけだったので、
        //   仮の監査・締切で保存した REPLAY を、本番の PRODUCTION が
        //   「既存」と見て成功扱いする経路がありました。
        //   <b>REPLAY は generation=900 の別世代に保存</b>し、
        //   PRODUCTION は generation=0 だけを見ます。
        //   仮置き（assume_audit／sales_close_at の差し込み）で作った入力は
        //   PRODUCTION として保存できないようにもしました。
        $isReplay = ($runKind === 'REPLAY');
        $assumed  = (bool) ($override['assume_audit'] ?? false)
                 || array_key_exists('sales_close_at', $override);
        if ($assumed && !$isReplay) {
            $out['code']   = 'REPLAY_ONLY';
            $out['reason'] = '仮の監査・締切で作った入力は PRODUCTION として保存しません';
            return $out;
        }
        $generation = $isReplay ? 900 : 0;

        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-EARLY
        // ══════════════════════════════════════════════════════════════
        //   実行時刻は取っていましたが、<b>情報上限に達したかを見ずに
        //   保存していました。</b>6分前のデータがすぐ届いて T−358秒 で
        //   呼ばれると、上限 T−330秒 の前に固定できてしまいます。
        //   そのあと T−340秒 に利用可能になった正式取消は、本来なら
        //   凍結集合へ反映する対象なのに「既存」で取り逃します。
        //   上限に達する前の凍結は、ここで断ります（待つのは呼び出し側です）。
        //   REPLAY（再生）は過去のレースを扱うので、この検査をしません。
        if (!$isReplay) {
            $st = (string) ($override['start_time'] ?? '');
            $anchorAt = null;
            if ($st !== '') {
                $st = BaganrikiTime::normalizeRaceStartClock($st);
                $anchorAt = substr((string) $date, 0, 10) . 'T' . $st . '+09:00';
            } else {
                $rowR = \DB::table('t_horse_odds_finder_races')
                          ->where('date', $date)->where('kaisuu', $kaisuu)
                          ->where('basho', $basho)->where('day', $day)
                          ->where('race', (int) $race)->first();
                if ($rowR !== null && ($rowR->start_time ?? '') !== '') {
                    $s2 = (string) $rowR->start_time;
                    $s2 = BaganrikiTime::normalizeRaceStartClock($s2);
                    $anchorAt = substr((string) $rowR->date, 0, 10) . 'T' . $s2 . '+09:00';
                }
            }
            $anchorUs0 = ($anchorAt === null) ? null : BaganrikiTime::us($anchorAt);
            if ($anchorUs0 !== null) {
                $freezeUs0 = BaganrikiTime::inputFreeze($anchorUs0);
                $nowUs0    = BaganrikiWorker::nowUs();
                // @ANCHOR-20261116-PREDICTION-EARLYFREEZE  早期凍結の版なら、6分前のバッチが利用可能になっていれば T−300秒を待たずに凍結します
                //   情報上限はこの時刻（freezeFor）。材料を集める処理にも同じ値を渡します（両AIに同じ凍結入力）。
                if (BaganrikiTime::earlyFreezeActive() && $nowUs0 < $freezeUs0
                    && $nowUs0 >= BaganrikiTime::earliestFreeze($anchorUs0)
                    && self::predictionSlot6Ready($raceId, $nowUs0)) {
                    $earlyTry = true;
                    $override['input_freeze_us'] = BaganrikiTime::freezeFor($anchorUs0, $nowUs0, BaganrikiTime::TIME_CONTRACT_V2);
                } elseif ($nowUs0 < $freezeUs0) {
                    $out['code']   = 'TOO_EARLY';
                    $out['reason'] = '情報上限に達していません（いま '
                                   . BaganrikiTime::toJst($nowUs0) . '／情報上限 '
                                   . BaganrikiTime::toJst($freezeUs0)
                                   . '）。上限到達後に固定します';
                    return $out;
                }
            }
        }

        // ── すでに固定済みなら触りません（同じ世代だけを見ます）──────
        $exists = \DB::table('baganriki_snapshots')
                     ->where('race_id', $raceId)->where('generation', $generation)->first();
        if ($exists !== null) {
            $out['ok'] = true; $out['skipped'] = true;
            $out['snapshot_hash'] = $exists->snapshot_hash;
            $out['reason'] = 'すでに固定済みです（上書きしません）';
            return $out;
        }

        // ── 材料を集めて両roleの入力を作ります ───────────────────────
        $tm['before_gather'] = microtime(true);   // @ANCHOR-20261115-PREDICTION-FREEZE-TIMING
        $g = self::gatherPredictionContext($date, $kaisuu, $basho, $day, $race, $override);
        $tm['gather'] = microtime(true);   // @ANCHOR-20261115-PREDICTION-FREEZE-TIMING
        $a1 = null; $a2 = null;
        if ($g['ok']) {
            $a1 = BaganrikiPrompt::assemble($g['ctx'], '1st');
            $a2 = BaganrikiPrompt::assemble($g['ctx'], '2nd');
        }
        $tm['assemble'] = microtime(true);   // @ANCHOR-20261115-PREDICTION-FREEZE-TIMING
        $bad = null;
        if (!$g['ok'])                       $bad = [$g['code'], $g['reason']];
        elseif (!$a1['ok'])                  $bad = [$a1['code'], $a1['reason']];
        elseif (!$a2['ok'])                  $bad = [$a2['code'], $a2['reason']];
        elseif ($a1['snapshot_hash'] !== $a2['snapshot_hash'])
            $bad = ['SNAPSHOT_HASH_MISMATCH', '1stと2ndの共通hashが違います'];
        // @ANCHOR-20261110-PREDICTION-EXPECTED-FREEZE  予想の版なら、ここで E3・E5 を付けた入力に作り直します
        $ex = null;
        if ($bad === null && self::expectedEnabled()) {
            $ex = self::expectedAssemble($a1, $a2, $g['ctx']);
            if (!$ex['ok']) { $bad = [$ex['code'], $ex['reason']]; }
        }
        $tm['expected'] = microtime(true);   // @ANCHOR-20261115-PREDICTION-FREEZE-TIMING

        // ── 4つの頭数 ────────────────────────────────────────────────
        $entryCount = ($g['ok'] && isset($g['ctx']['entry_nums']))
                    ? count($g['ctx']['entry_nums']) : null;
        $acquired = \DB::table('t_horse_odds_finder_odds')
            ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho', $basho)
            ->where('day', $day)->where('race', (int) $race)
            ->whereIn('minutes_before_start', BaganrikiTime::INPUT_SLOTS)
            ->distinct()->count('num');
        $frozenCount = ($bad === null) ? (int) $a1['input']['N'] : null;
        $activeCount = null;
        if ($bad === null) {
            $post = (array) ($g['ctx']['cancelled_post'] ?? []);
            $activeCount = max(0, $frozenCount - count($post));
        }
        $out['counts'] = ['entry' => $entryCount, 'acquired' => (int) $acquired,
                          'frozen' => $frozenCount, 'active' => $activeCount];

        if ($dryRun) {
            $out['ok'] = ($bad === null);
            if ($bad !== null) { $out['code'] = $bad[0]; $out['reason'] = $bad[1]; }
            else { $out['snapshot_hash'] = $a1['snapshot_hash']; }
            return $out;
        }

        // @ANCHOR-20261116-PREDICTION-EARLYFREEZE  早期凍結の試しで入力が作れなかったときは記録しません
        //   （6分前のレース全体がそろっていない等。次の巡回でもう一度試し、T−300秒からは今までどおり凍結・記録します）
        if ($bad !== null && $earlyTry) {
            $out['code']   = 'EARLY_NOT_READY';
            $out['reason'] = '早期凍結の入力がまだそろいません（' . $bad[0] . '）。次の巡回か T−300秒で凍結します';
            return $out;
        }
        // ── 入力が作れないとき：race_runs だけ残します ────────────────
        if ($bad !== null) {
            \DB::table('baganriki_race_runs')->insert([
                'run_id' => $runId, 'race_id' => $raceId, 'generation' => $generation,
                'run_kind' => $runKind, 'status' => 'INPUT_INVALID',
                'entry_count' => $entryCount, 'acquired_count' => (int) $acquired,
                'frozen_count' => null, 'active_count' => null,
                'invalid_code' => $bad[0], 'invalid_reason' => $bad[1],
                'release_version' => BaganrikiPrompt::PROMPT_VERSION,
                'created_at' => $nowDb, 'updated_at' => $nowDb,
            ]);
            $out['code'] = $bad[0]; $out['reason'] = $bad[1];
            if (!$dryRun && $runKind === 'PRODUCTION') { $logTiming($tm, $raceId, 'NG:' . (string) $bad[0]); }   // @ANCHOR-20261115-PREDICTION-FREEZE-TIMING
            return $out;
        }

        // ── 固定して保存します ───────────────────────────────────────
        $in   = $a1['input'];
        $hash = BaganrikiPrompt::commonHash($in);
        $release = BaganrikiPrompt::PROMPT_VERSION;
        if ($ex !== null) {
            // 予想の版：保存する原文・共通hash・版を E3・E5 付きのものに替えます（選出の設定・頭数・時刻は同じ）
            $hash = ['ok' => true, 'json' => $ex['json'], 'hash' => $ex['hash']];
            $in['snapshot_hash'] = $ex['hash'];
            $a1['user'] = $ex['user1']; $a2['user'] = $ex['user2'];
            $release = BaganrikiCandidateSelection::VERSION;
        }
        $frozenRank = [];
        foreach ((array) $in['runners'] as $r) {
            $frozenRank[(string) $r['horse_no']] = (int) $r['win_rank'];
        }
        $toDb = static function ($rfc) {
            $us = BaganrikiTime::us($rfc);
            if ($us === null) return null;
            return (new \DateTimeImmutable('@' . intdiv($us, 1000000)))
                ->setTimezone(new \DateTimeZone('Asia/Tokyo'))
                ->format('Y-m-d H:i:s') . sprintf('.%06d', $us % 1000000);
        };
        $j = static fn($v) => json_encode($v,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        $closeSrc = (string) ($g['sales_close_source'] ?? 'UNKNOWN');
        \DB::transaction(function () use (
            $raceId, $runId, $runKind, $in, $a1, $a2, $hash, $frozenRank, $nowDb,
            $entryCount, $acquired, $frozenCount, $activeCount, $toDb, $j, $generation,
            $closeSrc, $release, $ex
        ) {
            \DB::table('baganriki_race_runs')->insert([
                'run_id' => $runId, 'race_id' => $raceId, 'generation' => $generation,
                'run_kind' => $runKind, 'status' => 'FROZEN',
                'entry_count' => $entryCount, 'acquired_count' => (int) $acquired,
                'frozen_count' => $frozenCount, 'active_count' => $activeCount,
                'invalid_code' => null, 'invalid_reason' => null,
                'release_version' => $release,
                'created_at' => $nowDb, 'updated_at' => $nowDb,
            ]);
            $snapId = \DB::table('baganriki_snapshots')->insertGetId([
                'race_id' => $raceId, 'generation' => $generation, 'run_id' => $runId,
                'release_version'  => $release,
                'common_json'      => $hash['json'],
                'input_json_1st'   => $a1['user'],
                'input_json_2nd'   => $a2['user'],
                'snapshot_hash'    => $in['snapshot_hash'],
                'frozen_nums'      => $j(array_map(
                                        static fn($r) => (int) $r['horse_no'],
                                        (array) $in['runners'])),
                'frozen_rank'      => $j($frozenRank),
                'mode'             => $in['mode'],
                'mode_status'      => $in['mode_status'],
                'allocation_mode'  => $in['allocation_mode'],
                'mode_policy_id'   => $in['mode_policy_id'],
                'display_rating'   => $in['display_rating'],
                'signals'          => $j($in['signals']),
                's_min'            => $in['S_min'],
                's_max'            => $in['S_max'],
                's0'               => $in['S'],
                'target_count'     => $in['target_count'],
                'quota_1st'        => $j($a1['input']['role_quota']),
                'quota_2nd'        => $j($a2['input']['role_quota']),
                'quota_adjustments' => $j($in['quota_adjustments']),
                'collection_audit' => $j($in['collection_audit']),
                'coverage'         => $j($in['coverage']),
                'anchor_at'        => $toDb($in['time_contract']['anchor_at']),
                'cutoff_at'        => $toDb($in['cutoff_at']),
                'input_freeze_at'  => $toDb($in['input_freeze_at']),
                'frozen_at'        => $nowDb,
                'sales_close_at'   => $toDb($in['time_contract']['sales_close_at']),
                'input_invalid_code'   => null,
                'input_invalid_reason' => null,
                'created_at'       => $nowDb,
            ] + ($ex === null ? [] : ['expected_proof' => $j($ex['proof'])]));
            // @ANCHOR-20261110-PREDICTION-EXPECTED-FREEZE  E3・E5 の計算の監査（過去レースの一覧・除外理由など）はDBだけに残します（AIには送りません）
            if ($ex !== null && \Schema::hasTable('baganriki_audit_events')) {
                $au = $ex['audit']; unset($au['expected_proof']);
                $au['saved_history_audit'] = AiController::$expectedSavedHistoryAudit;   // @ANCHOR-20261116-EXPECTED-SAVED-CONFIRM
                \DB::table('baganriki_audit_events')->insert([
                    'race_id' => $raceId, 'snapshot_id' => $snapId, 'kind' => 'PREDICTION_EXPECTED_AUDIT',
                    'happened_at' => $nowDb, 'old_revision' => null, 'new_revision' => null,
                    'diagnostics' => $j($au), 'related_id' => null,
                ]);
            }
            // @ANCHOR-20261116-METRICS-SPEC-FREEZE  なる11/16 仕上げ②：3指標の選出仕様（ID・有効/無効・パラメータの照合値）を凍結のときに記録します。
            //   公開のときは、この記録と今のコードの仕様が一致するときだけ選出を補正します（AIには送りません。凍結入力も変えません）。
            if (\Schema::hasTable('baganriki_audit_events')) {
                $revSpec = ($ex !== null) ? ($ex['common']['prompt_text_revision'] ?? null) : ($in['prompt_text_revision'] ?? null);
                \DB::table('baganriki_audit_events')->insert([
                    'race_id' => $raceId, 'snapshot_id' => $snapId, 'kind' => BaganrikiMetricsSelection::SPEC_EVENT,
                    'happened_at' => $nowDb, 'old_revision' => null, 'new_revision' => null,
                    'diagnostics' => $j(BaganrikiMetricsSelection::specFor($revSpec)), 'related_id' => null,
                ]);
            }
            // @ANCHOR-20261020-PREDICTION-CLOSE  投票締切の出どころを監査へ残します。
            //   INPUT_JSON の time_contract は閉じた形なので入れられません。
            //   どの規則で締切を決めたかは、ここで必ず追えるようにします。
            if (\Schema::hasTable('baganriki_audit_events')) {
                \DB::table('baganriki_audit_events')->insert([
                    'race_id'     => $raceId,
                    'snapshot_id' => $snapId,
                    'kind'        => 'SALES_CLOSE_SOURCE',
                    'happened_at' => $nowDb,
                    'old_revision' => null, 'new_revision' => null,
                    'diagnostics' => $j([
                        'source'         => $closeSrc,
                        'sales_close_at' => $in['time_contract']['sales_close_at'],
                        'anchor_at'      => $in['time_contract']['anchor_at'],
                        'offset_sec'     => BaganrikiTime::salesCloseOffsetSec(),
                        'rule'           => 'JRA公式 発売時間・発売締切時刻'
                                          . '（当日のインターネット投票は'
                                          . '各レース発走時刻の1分前）',
                        'rule_url'       => 'https://jra.jp/dento/a/instructions'
                                          . '/hatsubai/jra.html',
                    ]),
                    'related_id'  => null,
                ]);
            }
            // @ANCHOR-20261020-PREDICTION-QUEUE  ライブ状態の行をここで作ります。
            //   state_revision / response_revision / publication_revision の
            //   置き場所です。キューの予約・終端はここを数えます。
            if (\Schema::hasTable('baganriki_live_states')) {
                \DB::table('baganriki_live_states')->insertOrIgnore([
                    'snapshot_id'           => $snapId,
                    'cancellations'         => $j([]),
                    'active_count'          => $activeCount,
                    'live_target_count'     => $in['target_count'],
                    'live_quota'            => $j($a1['input']['role_quota']),
                    'live_close'            => null,
                    'state_revision'        => 0,
                    'response_revision'     => 0,
                    'publication_revision'  => 0,
                    'latest_publication_id' => null,
                    'updated_at'            => $nowDb,
                ]);
            }
        });

        $out['ok'] = true;
        $out['snapshot_hash'] = $in['snapshot_hash'];
        $out['generation']    = $generation;
        $out['run_kind']      = $runKind;
        $tm['save'] = microtime(true);   // @ANCHOR-20261115-PREDICTION-FREEZE-TIMING
        if ($runKind === 'PRODUCTION') { $logTiming($tm, $raceId, 'OK'); }
        return $out;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-RESUME  上限到達後にもう一度固定する（）
     * ═══════════════════════════════════════════════════════════════════════
     *
     * そのとおりでした。TOO_EARLY で断ったあと、誰も呼び直していませんでした。
     *
     * ■ いまの形
     *   ・キャッシュを返すときも、<b>固定入力が無ければここで作り直します。</b>
     *     応答を返したあと（terminating）に動かすので、画面は遅くなりません。
     *   ・情報上限に達していなければ、今回も作りません（TOO_EARLY のまま）。
     *     次のアクセスでまた試します。
     *   ・固定済みなら何もしません。
     *   ・TOO_EARLY で止まったときは<b>空回答をキャッシュしません。</b>
     *     空回答を残すと、上のキャッシュ返却で終わってしまい、
     *     上限到達後に作り直せなくなるためです。
     *
     * @return array ['ran','code','reason','snapshot_hash']
     */
    public function resumePredictionFreeze($date, $kaisuu, $basho, $day, $race): array
    {
        $out = ['ran' => false, 'code' => null, 'reason' => null,
                'snapshot_hash' => null];
        try {
            if (!BaganrikiPrompt::enabled()) {
                $out['code'] = 'FLAG_OFF';
                $out['reason'] = '本文の段が 0 なので何もしません';
                return $out;
            }
            if (!\Schema::hasTable('baganriki_snapshots')) {
                $out['code'] = 'TABLE_MISSING';
                return $out;
            }
            $raceId = self::buildPredictionRaceId($date, $kaisuu, $basho, $day, $race);
            $snap = \DB::table('baganriki_snapshots')
                      ->where('race_id', $raceId)->where('generation', 0)->first();
            if ($snap !== null) {
                $out['code'] = 'ALREADY';
                $out['reason'] = 'すでに固定済みです（作り直しません）';
                $out['snapshot_hash'] = (string) $snap->snapshot_hash;
                return $out;
            }
            $out['ran'] = true;
            $fz = $this->freezePredictionInput($date, $kaisuu, $basho, $day, $race,
                                    [], 'PRODUCTION', false);
            $out['code']   = (string) ($fz['code'] ?? ($fz['ok'] ? 'OK' : 'NG'));
            $out['reason'] = (string) ($fz['reason'] ?? '');
            $out['snapshot_hash'] = $fz['snapshot_hash'] ?? null;
            \Log::error('[PREDICTION-RESUME] 固定入力をやり直しました', [
                'race_id' => $raceId,
                'ok'      => $fz['ok'] ?? false,
                'code'    => $out['code'],
                'reason'  => $out['reason'],
            ]);
            return $out;
        } catch (\Throwable $e) {
            // ここで落ちても商品は止めません
            \Log::error('[PREDICTION-RESUME] やり直しで例外', ['message' => $e->getMessage()]);
            $out['code'] = 'EXCEPTION'; $out['reason'] = $e->getMessage();
            return $out;
        }
    }

    public static function sweepFreezeTargetRaces(self $ctrl, int $now): array
    {
        $out = ['targets' => 0, 'started' => [], 'skipped' => []];
        try {
            if (!BaganrikiWorker::targetsEnforced()) { return $out; }
            if (!BaganrikiPrompt::enabled()) { return $out; }
            // @ANCHOR-20261023-NARU-FAKEAI  「TEST」は当日の試験用レース（場 90〜99）へ広げます
            $tgList = [];
            foreach (self::predictionTargets() as $t0) {
                // @ANCHOR-20261023-PREDICTION-ALL  「ALL」は当日の実在の全レース（場 01〜10）へ広げます
                if ($t0 === 'ALL') {
                    $todayA = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
                    foreach (\DB::table('t_horse_odds_finder_races')->where('date', $todayA)
                                ->whereIn('basho', ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10'])
                                ->get(['date', 'kaisuu', 'basho', 'day', 'race']) as $tr) {
                        $tgList[] = self::buildPredictionRaceId($tr->date, $tr->kaisuu, $tr->basho,
                                                                $tr->day, (int) $tr->race);
                    }
                    continue;
                }
                if ($t0 !== 'TEST') { $tgList[] = $t0; continue; }
                $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
                foreach (\DB::table('t_horse_odds_finder_races')->where('date', $today)
                            ->whereIn('basho', array_map(static fn($i) => (string) $i, range(90, 99)))
                            ->get(['date', 'kaisuu', 'basho', 'day', 'race']) as $tr) {
                    $tgList[] = self::buildPredictionRaceId($tr->date, $tr->kaisuu, $tr->basho,
                                                            $tr->day, (int) $tr->race);
                }
            }
            foreach ($tgList as $rid) {
                $out['targets']++;
                $p = explode('-', (string) $rid);
                if (count($p) !== 5 || !preg_match('/^\d{8}$/', $p[0])) {
                    $out['skipped'][$rid] = 'race_id の形が違います';
                    continue;
                }
                $has = \DB::table('baganriki_snapshots')
                         ->where('race_id', $rid)->where('generation', 0)->exists();
                if ($has) { continue; }
                $d = substr($p[0], 0, 4) . '-' . substr($p[0], 4, 2) . '-' . substr($p[0], 6, 2);
                $r = \DB::table('t_horse_odds_finder_races')
                        ->where('date', $d)->where('kaisuu', $p[1])
                        ->where('basho', $p[2])->where('day', $p[3])
                        ->where('race', (int) $p[4])->first(['start_time']);
                $st = trim((string) ($r->start_time ?? ''));
                if (!preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $st)) {
                    $out['skipped'][$rid] = '発走時刻を読めません';
                    continue;
                }
                $st = BaganrikiTime::normalizeRaceStartClock($st);
                $anchorUs = BaganrikiTime::us($d . 'T' . $st . '+09:00');
                if ($anchorUs === null) {
                    $out['skipped'][$rid] = '発走時刻を組み立てられません';
                    continue;
                }
                // if ($now < BaganrikiTime::inputFreeze($anchorUs)) {
                //     $out['skipped'][$rid] = 'まだ情報上限の前です';
                //     continue;
                // }   // 旧（T−300秒まで待つ）
                // @ANCHOR-20261116-PREDICTION-EARLYFREEZE  早期凍結の版なら、6分前のバッチが利用可能になった時点で凍結を起こします
                $early = false;
                if ($now < BaganrikiTime::inputFreeze($anchorUs)) {
                    $early = BaganrikiTime::earlyFreezeActive() && $now >= BaganrikiTime::earliestFreeze($anchorUs)
                           && self::predictionSlot6Ready($rid, $now);
                    if (!$early) {
                        $out['skipped'][$rid] = 'まだ情報上限の前です';
                        continue;
                    }
                }
                if ($now >= $anchorUs) {
                    $out['skipped'][$rid] = '発走時刻を過ぎています';
                    continue;
                }
                // $last = self::$sweepFreezeTriedAt[$rid] ?? null;
                // if ($last !== null && ($now - $last) < 30 * 1000000) { continue; }
                // self::$sweepFreezeTriedAt[$rid] = $now;   // 旧
                // @ANCHOR-20261116-PREDICTION-EARLYFREEZE  早期凍結の試しは10秒に1回。T−300秒からの試しは今までどおり30秒に1回（早期の試しとは別に数える）
                if ($early) {
                    $last = self::$sweepFreezeEarlyTriedAt[$rid] ?? null;
                    if ($last !== null && ($now - $last) < 10 * 1000000) { continue; }
                    self::$sweepFreezeEarlyTriedAt[$rid] = $now;
                } else {
                    $last = self::$sweepFreezeTriedAt[$rid] ?? null;
                    if ($last !== null && ($now - $last) < 30 * 1000000) { continue; }
                    self::$sweepFreezeTriedAt[$rid] = $now;
                }
                if (BaganrikiWorker::jobs()) {
                    $dp = $ctrl->dispatchPredictionJobs($d, $p[1], $p[2], $p[3], (int) $p[4]);
                    $out['started'][$rid] = 'JOB:' . (string) $dp['code'];
                } else {
                    $rf = $ctrl->resumePredictionFreeze($d, $p[1], $p[2], $p[3], (int) $p[4]);
                    $out['started'][$rid] = 'SYNC:' . (string) $rf['code'];
                }
                \Log::error('[PREDICTION-SWEEPFREEZE] 対象レースの凍結を起こしました', [
                    'race_id' => $rid, 'result' => $out['started'][$rid],
                ]);
            }
        } catch (\Throwable $e) {
            // @ANCHOR-20261005-AUX-NEVER-BREAKS  巡回を止めません
            \Log::error('[PREDICTION-SWEEPFREEZE] 例外（巡回は続けます）',
                ['message' => $e->getMessage()]);
        }
        return $out;
    }

    // ##########################################################################
    // ## AI枠の予約・応答の検査・採否・公開
    // ##########################################################################

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-QUEUE  送信の前に枠を予約します
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：server_contract_r12.2.txt 10章
     *   「予約commit後に送信し、原応答とcompleteをcommitしてから訂正を開始する」
     *
     * ■ 流れ
     *   ① 凍結入力（snapshot）を正本にします。無ければここで固定します。
     *   ② 送る本文の共通hashが、固定したものと同じかを確かめます。
     *      違えば送りません（固定後は変えない、が契約8章です）。
     *   ③ 枠（初回=0）を予約します。すでにあれば<b>送りません</b>。
     *   ④ 送信許可をもらいます（1回だけ）。
     *
     * ■ .env の BAGANRIKI_PREDICTION_QUEUE が 0（既定）のときは素通りします。
     *   そのときは skip=true を返し、呼出側は今までどおり送ります。
     *   1バイトも挙動が変わりません。
     *
     * ■ shadow のときは、ここで失敗しても<b>送信を止めません</b>。
     *   shadow=true を返すので、呼出側は記録だけ諦めて送ります。
     *   初めて実物で動かす日に、いまの商品を落とさないための段です。
     *
     * @return array ['skip'=>bool, 'ok'=>bool, 'code'=>?string, 'reason'=>?string,
     *                'token'=>?string, 'snapshot_id'=>?int]
     */
    public function reserveAiSlot($date, $kaisuu, $basho, $day, $race,
                                     string $role, array $asm): array
    {
        $out = ['skip' => true, 'ok' => false, 'code' => null, 'reason' => null,
                'token' => null, 'snapshot_id' => null, 'role' => $role,
                // @ANCHOR-20261020-PREDICTION-SHADOW  shadow のときは止めません
                'shadow' => false, 'mode' => BaganrikiWorker::mode()];
        if (!BaganrikiWorker::enabled()) { return $out; }
        $out['skip'] = false;
        $out['shadow'] = !BaganrikiWorker::blocking();
        if (!BaganrikiWorker::ready()) {
            $out['code']   = 'TABLE_MISSING';
            $out['reason'] = 'キューの表がありません（--prediction-install で作ります）';
            return $out;
        }
        $raceId = self::buildPredictionRaceId($date, $kaisuu, $basho, $day, $race);
        // @ANCHOR-20261023-NARU-ENTRY  枠を取る前にも対象レースを見ます（なる 10/23 重要1）
        $tgQ = self::checkPredictionTarget($raceId);
        if (!$tgQ['ok']) {
            $out['code']   = (string) $tgQ['code'];
            $out['reason'] = (string) $tgQ['reason'];
            return $out;
        }

        try {
            // ① 凍結入力。本番は generation=0 だけを見ます（REPLAY は 900）
            $snap = \DB::table('baganriki_snapshots')
                      ->where('race_id', $raceId)->where('generation', 0)->first();
            if ($snap === null) {
                $fz = $this->freezePredictionInput($date, $kaisuu, $basho, $day, $race,
                                        [], 'PRODUCTION', false);
                if (!($fz['ok'] ?? false)) {
                    $out['code']   = (string) ($fz['code'] ?? 'FREEZE_FAILED');
                    $out['reason'] = (string) ($fz['reason'] ?? '凍結入力を作れません');
                    return $out;
                }
                $snap = \DB::table('baganriki_snapshots')
                          ->where('race_id', $raceId)->where('generation', 0)->first();
            }
            if ($snap === null) {
                $out['code']   = 'NO_SNAPSHOT';
                $out['reason'] = '凍結入力を読み戻せません';
                return $out;
            }

            // ② 固定した入力と送信文が同じものか
            if ((string) $snap->snapshot_hash !== (string) ($asm['snapshot_hash'] ?? '')) {
                $out['code']   = 'SNAPSHOT_MISMATCH';
                $out['reason'] = '固定した入力と送信文の共通hashが違います'
                               . '（固定後は変えません）';
                return $out;
            }

            // ③ 枠を予約（ライブと公開の版は見張ります。応答の版は
            //    予約そのもので増えるので見張りに入れません）
            $live = \DB::table('baganriki_live_states')
                      ->where('snapshot_id', $snap->id)->first();
            $expect = ($live === null) ? [] : [
                'state_revision'       => (int) $live->state_revision,
                'publication_revision' => (int) $live->publication_revision,
            ];
            $r = BaganrikiWorker::reserve((int) $snap->id, $role,
                                          BaganrikiWorker::FIRST,
                                          BaganrikiWorker::nowUs(), $expect);
            if (!$r['ok']) {
                $out['code']   = (string) $r['code'];
                $out['reason'] = (string) $r['reason'];
                $out['snapshot_id'] = (int) $snap->id;
                return $out;
            }

            // ④ 送信許可（1回だけ）
            $s = BaganrikiWorker::allowSend((string) $r['token'],
                                            BaganrikiWorker::nowUs());
            if (!$s['ok']) {
                $out['code']   = (string) $s['code'];
                $out['reason'] = (string) $s['reason'];
                $out['snapshot_id'] = (int) $snap->id;
                return $out;
            }

            $out['ok']    = true;
            $out['code']  = 'OK';
            $out['token'] = (string) $r['token'];
            $out['snapshot_id'] = (int) $snap->id;
            $out['reply_deadline'] = $r['reply_deadline'] ?? null;
            return $out;
        } catch (\Throwable $e) {
            $out['code']   = 'QUEUE_EXCEPTION';
            $out['reason'] = $e->getMessage();
            \Log::error('[PREDICTION-QUEUE] 予約で例外', [
                'race_id' => $raceId, 'role' => $role,
                'message' => $e->getMessage(),
                'at'      => $e->getFile() . ':' . $e->getLine(),
            ]);
            return $out;
        }
    }

    /**
     * @ANCHOR-20261020-PREDICTION-QUEUE  受け取った原応答を枠へ保存します。
     *   同じ応答を2回保存しても冪等です。別の応答は保存しません。
     *   complete=false の本文は、そのまま残して選出には使いません。
     */
    public function receiveAiSlotResponse(array $q, string $raw, bool $complete): array
    {
        if (($q['skip'] ?? true) || !($q['ok'] ?? false)) {
            return ['skip' => true, 'ok' => false];
        }
        try {
            $r = BaganrikiWorker::receive((string) $q['token'], $raw, $complete,
                                          BaganrikiWorker::nowUs());
            if (!$r['ok']) {
                \Log::error('[PREDICTION-QUEUE] 原応答を保存できません', [
                    'role' => $q['role'] ?? null, 'snapshot_id' => $q['snapshot_id'] ?? null,
                    'code' => $r['code'], 'reason' => $r['reason'],
                ]);
            } else {
                \Log::info('[PREDICTION-QUEUE] 原応答を保存しました', [
                    'role' => $q['role'] ?? null, 'snapshot_id' => $q['snapshot_id'] ?? null,
                    'code' => $r['code'], 'complete' => $complete,
                    'response_hash' => $r['response_hash'] ?? null,
                ]);
            }
            return $r;
        } catch (\Throwable $e) {
            \Log::error('[PREDICTION-QUEUE] 受領で例外', ['message' => $e->getMessage()]);
            return ['skip' => false, 'ok' => false, 'code' => 'QUEUE_EXCEPTION',
                    'reason' => $e->getMessage()];
        }
    }

    /**
     * @ANCHOR-20261020-PREDICTION-QUEUE  枠を終端にします。
     *   $unknown=true は「結果が分からない」です。再送しないための印です。
     *   通信が切れた・待ち時間で切れた場合はこちらを使います
     *   （向こうに届いているかどうかが分からないためです）。
     */
    public function failAiSlot(array $q, string $reason, bool $unknown = false): array
    {
        if (($q['skip'] ?? true) || !($q['ok'] ?? false)) {
            return ['skip' => true, 'ok' => false];
        }
        try {
            $r = BaganrikiWorker::fail((string) $q['token'], $reason,
                                       BaganrikiWorker::nowUs(), $unknown);
            \Log::error('[PREDICTION-QUEUE] 枠を終端にしました', [
                'role' => $q['role'] ?? null, 'snapshot_id' => $q['snapshot_id'] ?? null,
                'status' => $r['status'] ?? null, 'unknown' => $unknown,
                'reason' => $reason,
            ]);
            return $r;
        } catch (\Throwable $e) {
            \Log::error('[PREDICTION-QUEUE] 終端で例外', ['message' => $e->getMessage()]);
            return ['skip' => false, 'ok' => false, 'code' => 'QUEUE_EXCEPTION',
                    'reason' => $e->getMessage()];
        }
    }

    public function inspectAiResponse(int $snapshotId, string $role,
                                        int $attemptNo = 0): array
    {
        $out = ['ok' => false, 'stage' => 'start', 'code' => null, 'reason' => null,
                'accepted' => false, 'needs_fix' => false,
                'repair_scope' => null, 'normal' => [], 'row_valid' => [],
                'diag' => null, 'attempt_id' => null,
                // @ANCHOR-20261020-PREDICTION-COMPLETE  既定は「未完了あつかい」
                'complete' => false, 'normal_kept' => [], 'salvaged' => []];

        $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
        if ($snap === null) {
            $out['stage'] = 'snapshot'; $out['code'] = 'NO_SNAPSHOT';
            $out['reason'] = '凍結入力がありません';
            return $out;
        }
        $a = \DB::table('baganriki_ai_attempts')
               ->where('snapshot_id', $snapshotId)->where('role', $role)
               ->where('attempt_no', $attemptNo)->first();
        if ($a === null) {
            $out['stage'] = 'attempt'; $out['code'] = 'NO_ATTEMPT';
            $out['reason'] = 'その枠がありません';
            return $out;
        }
        $out['attempt_id'] = (int) $a->id;
        $out['validator_version'] = BaganrikiDiagnose::revision($snap->release_version ?? null);
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-COMPLETE
        // ══════════════════════════════════════════════════════════════
        //   status=RECEIVED は見ていましたが、<b>complete を見ていませんでした。</b>
        //   契約10章は「未完了の本文は保持するが、選出へは使わない」です。
        //   「加点しない」だけでは足りず、順位点にも候補にも入れてはいけません。
        //   ここでは本文の検証は続け（保持するため）、
        //   選出に使ってよいかの印だけを別に返します。
        $current = $out['validator_version'] === BaganrikiResponseInspection::REVISION;
        $out['complete'] = $current ? BaganrikiAttemptWindow::isComplete($a->complete ?? null)
            : ((int) ($a->complete ?? 0) === 1);
        // @ANCHOR-20261110-PREDICTION-RESEND-VERIFY  試しの検査（保存前の返事）：最後まで返ってきた返事だけを渡すので、完了あつかいにします
        $dry = ($this->dryInspectRaw !== null);
        if ($dry) { $out['complete'] = true; $out['dry'] = true; }
        if (!$dry && (string) $a->status !== BaganrikiWorker::RECEIVED) {
            $out['stage'] = 'attempt'; $out['code'] = 'NOT_RECEIVED';
            $out['reason'] = '原応答を受け取っていません（' . $a->status . '）';
            return $out;
        }

        $inputTypes = null;
        try {
            $src = BaganrikiFrozenInput::forInspection($snap, $role, $inputTypes);
        } catch (\InvalidArgumentException $error) {
            $out['stage'] = 'snapshot'; $out['code'] = 'INVALID_SAVED_INPUT';
            $out['reason'] = '保存入力を照合できません ' . $error->getMessage();
            $this->saveValidationResult((int) $a->id, null, $out, null, null, null);
            return $out;
        }
        if (!is_array($src)) {
            $out['stage'] = 'snapshot'; $out['code'] = 'NO_INPUT';
            $out['reason'] = '固定入力（' . ($role === '1st' ? 'input_json_1st' : 'input_json_2nd') . '）を読めません';
            return $out;
        }

        if (!$dry && $current && (!is_string($a->response_hash ?? null) || !is_string($a->raw_response ?? null)
                || !hash_equals($a->response_hash, hash('sha256', $a->raw_response)))) {
            $out['stage'] = 'attempt'; $out['code'] = 'RESPONSE_HASH_MISMATCH';
            $out['reason'] = '受領時に保存した応答原文とSHA-256が一致しません';
            $this->saveValidationResult((int) $a->id, null, $out, null, null, null);
            return $out;
        }

        $out['response_hash'] = $a->response_hash ?? null;

        // ── ① 原文から封筒を取り出す ────────────────────────────────
        // $ex = BaganrikiResponseFrame::supportsVersion($src['prompt_version'] ?? null)
        //         ? BaganrikiResponseFrame::extract((string) $a->raw_response)
        //         : BaganrikiResponse::extract((string) $a->raw_response);   // 旧
        $rawIn = $dry ? (string) $this->dryInspectRaw : (string) $a->raw_response;
        $ex = BaganrikiResponseFrame::supportsVersion($src['prompt_version'] ?? null)
                ? BaganrikiResponseFrame::extract($rawIn)
                : BaganrikiResponse::extract($rawIn);
        if (!$ex['ok']) {
            $out['stage'] = 'extract';
            $out['code']  = (string) ($ex['code'] ?? 'AI_FAILURE');
            $out['reason'] = (string) ($ex['reason'] ?? '');
            $this->saveValidationResult((int) $a->id, null, $out, null, null, null);
            return $out;
        }
        $res = (array) $ex['json'];
        // @ANCHOR-20261109-PREDICTION-REPLY-TIDY  E3・E5版だけ、書き方のミスを検査の前に整えます（中身は変えません）
        $out['tidied'] = [];
        if ($current) {
            [$res, $tidyMap, $out['tidied']] = BaganrikiReplyTidy::apply($res, $ex['map'] ?? null);
            if ($out['tidied'] !== []) { $ex['json'] = $res; $ex['map'] = $tidyMap; }
            $out['reply_json'] = $res;   // 整えた後の返事（表示指標の読み取りで、地図と同じ中身を使うため）
            // @ANCHOR-20261110-PREDICTION-STRAYQUOTE  読み直しのために原文から取り除いたものも「整えた」として記録します
            //   （原文そのままではないので、一致加点の証明は付けません。中身の検査はこのあと全部通します。よっしー11/10 ③）
            $fixNotes = [];
            if ((int) ($ex['stray_quotes_removed'] ?? 0) > 0) { $fixNotes[] = '原文の余計な " を ' . (int) $ex['stray_quotes_removed'] . '個取り除いて読み直し'; }
            if ((int) ($ex['metric_braces_removed'] ?? 0) > 0) { $fixNotes[] = '原文の race_metrics の余計な } を ' . (int) $ex['metric_braces_removed'] . '個取り除いて読み直し'; }
            if ((int) ($ex['score_evidence_closed'] ?? 0) > 0) { $fixNotes[] = '原文の score_evidence の閉じ忘れを ' . (int) $ex['score_evidence_closed'] . 'か所閉じて読み直し'; }
            foreach ((array) ($ex['duplicate_keys_removed'] ?? []) as $dk) { $fixNotes[] = '原文で2回書かれた欄 ' . (string) $dk . ' の2回目を外して読み直し'; }
            if ($fixNotes !== []) { $out['tidied'] = array_merge($fixNotes, $out['tidied']); }
        }

        // @ANCHOR-20261110-PREDICTION-ECHO-NULL  【使わない】11/10 東京6R の 1st は、写すだけの欄を null にしたのではなく、自分で「選出停止」と判断して
        //   role_cap=0・quota=null に書き換えていた（AIの判断の誤り）。null だけを戻すこの処理では直らず、直すべきでもないので、コメントにして残します
        // //   （値を写し間違えたとき＝null 以外の違う値は、今までどおり不採用です。11/10 東京6R で 1st が両方 null を返して全体が使えなかった）
        // if ($current) {
        //     $echoNotes = [];
        //     foreach (['role_cap', 'role_quota'] as $ek) {
        //         if (array_key_exists($ek, $res) && $res[$ek] === null && ($src[$ek] ?? null) !== null) { $res[$ek] = $src[$ek]; $echoNotes[] = $ek . ' null→凍結入力の値'; }
        //     }
        //     if ($echoNotes !== []) {
        //         $reparsed = BaganrikiJson::decode(BaganrikiJson::encode($res, $ex['map'] ?? BaganrikiJson::emptyMap()));
        //         if ($reparsed['ok']) {
        //             $res = $reparsed['value']; $ex['json'] = $res; $ex['map'] = $reparsed['map'];
        //             $out['reply_json'] = $res; $out['tidied'] = array_merge($out['tidied'], $echoNotes);
        //         }
        //     }
        // }

        // ── ② 封筒照合（予約した role の固定入力だけが相手）──────────
        $env = BaganrikiResponse::checkEnvelope($res, [
            'prompt_version'  => $src['prompt_version']  ?? null,
            'schema_version'  => $src['schema_version']  ?? null,
            'race_id'         => $src['race_id']         ?? null,
            'ai_role'         => $src['ai_role']         ?? null,
            'snapshot_hash'   => $src['snapshot_hash']   ?? null,
            'mode'            => $src['mode']            ?? null,
            'mode_status'     => $src['mode_status']     ?? null,
            'allocation_mode' => $src['allocation_mode'] ?? null,
            'mode_policy_id'  => $src['mode_policy_id']  ?? null,
            'role_cap'        => $src['role_cap']        ?? null,
            'role_quota'      => $src['role_quota']      ?? null,
        ]);
        if (!$env['ok']) {
            $out['stage'] = 'envelope';
            $out['code']  = (string) ($env['code'] ?? 'ENVELOPE');
            $out['reason'] = (string) ($env['reason'] ?? '');
            $this->saveValidationResult((int) $a->id, null, $out, null, null, null);
            return $out;
        }

        // ── ③ 馬ごとの隔離（正常な他馬を残す）───────────────────────
        $frozen = [];
        foreach ((array) ($src['runners'] ?? []) as $r) {
            $frozen[] = (int) ($r['horse_no'] ?? 0);
        }
        $iso = BaganrikiResponse::isolateHorses((array) ($res['horses'] ?? []), $frozen);

        // ── ④ 診断（inspect と resolve で同じ関数）──────────────────
        $d = BaganrikiDiagnose::run($res, $src, $ex['map'] ?? null, $inputTypes);

        // 正常部分＝隔離に残り、かつ row_valid が true の行
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-SALVAGE
        // ══════════════════════════════════════════════════════════════
        //   【直した中身】row_valid=false の行を<b>まるごと</b>外していました。
        //   そのため、採点の集計だけがおかしい馬（observed_sum が合わない等）も
        //   正常な根拠ごと落ち、候補から消えていました。
        //   契約は「不正な得点項目だけを null にして、正常根拠は保持する」です。
        //
        //   そこで、その馬の指摘が<b>採点まわりだけ</b>のときは、
        //   採点欄だけを落として行は残します（score_status は partial）。
        //   根拠・5系統・帯・順位の指摘がある行は、これまでどおり外します。
        $bad = [];                       // 馬番 => その馬に付いた指摘コード
        foreach ((array) $d['findings'] as $fd) {
            // A presentation error still needs its own fix but cannot invalidate independently salvageable scores.
            if (($src['prompt_version'] ?? null) === BaganrikiCandidateSelection::VERSION &&
                ($fd['scope'] ?? null) === 'presentation_horses') { continue; }
            $t = $fd['target'] ?? null;
            if (!is_int($t) && !(is_string($t) && ctype_digit($t))) { continue; }
            $bad[(int) $t][] = (string) ($fd['code'] ?? '');
        }
        $salvaged = []; $salvageDetail = []; $marketSalvageMaps = [];
        $normal = [];
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-TYPEMAP  並べ替えに型の地図を追従させます
        // ══════════════════════════════════════════════════════════════
        //   応答の馬の行は ["horses", 3] の位置にあります。
        //   normal は<b>馬番をキーにしたオブジェクト</b>に組み替えるので、
        //   位置が ["7"] に変わります。地図の経路もそのとおりに
        //   付け替えないと、再保存で {} が [] に化けたり、
        //   数値が文字列に化けたりします。
        //   先に「馬番 → 応答での位置」を控えておきます。
        $idxOf = [];
        foreach (array_values((array) ($res['horses'] ?? [])) as $iH => $rowH) {
            $nH = (int) (((array) $rowH)['horse_no'] ?? 0);
            if ($nH > 0 && !isset($idxOf[$nH])) { $idxOf[$nH] = (int) $iH; }
        }
        $resMap = (array) ($ex['map'] ?? BaganrikiJson::emptyMap());
        foreach ((array) $iso['kept'] as $k => $h) {
            $no = is_int($k) ? $k : (int) ($h['horse_no'] ?? 0);
            if ($no <= 0) { continue; }
            $rowValid = ($d['row_valid'][$no] ?? false) === true;
            $badKeys = (array) ($d['bad_scores'][$no] ?? []);
            if (!$rowValid || $badKeys !== []) {
                $codes = array_values(array_unique($bad[$no] ?? []));
                $only  = ($codes !== [])
                      && array_diff($codes, array_merge(self::SCORE_ONLY_ERROR_CODES,
                          ['EVIDENCE_REF_UNKNOWN', 'EVIDENCE_REF_NOT_OWNED'])) === [];
                if (($src['prompt_version'] ?? null) === BaganrikiCandidateSelection::VERSION &&
                    $codes === ['EXPECTED_FALSE_ACCELERATION'] && isset($idxOf[$no])) {
                    $marketSalvage = BaganrikiResponseInspection::salvageMarket(
                        $src, $h, $idxOf[$no], $inputTypes, $resMap);
                    if ($marketSalvage !== null) {
                        $normal[$no] = $marketSalvage['row'];
                        $marketSalvageMaps[$no] = $marketSalvage['map'];
                        $salvageDetail[$no] = ['removed_evidence_ids' => $marketSalvage['removed_evidence_ids']];
                        $salvaged[] = $no;
                        continue;
                    }
                }
                // @ANCHOR-20261109-PREDICTION-EVIDENCE-SALVAGE  E3・E5版：根拠の一部だけが不正な行は、正しい根拠だけで組み直して残します
                if (!$rowValid && ($src['prompt_version'] ?? null) === BaganrikiCandidateSelection::VERSION && isset($idxOf[$no])) {
                    $evSalvage = BaganrikiResponseInspection::salvageEvidence($src, $h, $idxOf[$no], $inputTypes, $resMap);
                    if ($evSalvage !== null) {
                        $normal[$no] = $evSalvage['row'];
                        $marketSalvageMaps[$no] = $evSalvage['map'];
                        $salvageDetail[$no] = ['removed_evidence_ids' => $evSalvage['removed_evidence_ids'], 'rebuilt' => true];
                        $salvaged[] = $no;
                        continue;
                    }
                }
                // 自分のSIGNALが1系統も無い馬は、採点を外しても資格が立ちません
                $own = (int) (($d['ref_stats'][$no]['own_signal'] ?? 0));
                // Valid own-horse evidence can coexist with an invalid score reference.
                // Keep the eligible row, but always remove every diagnosed bad score.
                if (!$rowValid && (!$only || $own <= 0)) { continue; }
                // ══════════════════════════════════════════════════
                // @ANCHOR-20261020-PREDICTION-SCOREKEY
                // ══════════════════════════════════════════════════
                //   【旧】observed_sum だけの誤りでも H1〜V5 を<b>全部</b>
                //     null にし、observed_sum・observed_cap を 0 にして
                //     いました。正常な採点項目まで消えます。
                //     契約6章は「不正項目だけ null、合計等を再計算」です。
                //   【いま】診断が印を付けた項目だけ null にし、
                //     <b>残った項目から合計を計算し直します。</b>
                //     集計欄だけの誤り（observed_sum が合わない等）なら、
                //     採点は1項目も消えません。計算し直すだけです。
                if (!is_array($h['scores'] ?? null)) { $h['scores'] = []; }
                if (BaganrikiScoreMaterials::handlesFamilies($src['prompt_version'] ?? null) && is_array($h['score_evidence'] ?? null)) {
                    foreach ($h['score_evidence'] as &$proofItem) {
                        if (is_array($proofItem)) {
                            $proofItem = array_intersect_key($proofItem, ['reason' => true, 'source_refs' => true]);
                        }
                    }
                    unset($proofItem);
                }
                // 採点辞書に無い項目は落とします（残すと再計算が狂います）
                foreach (array_keys($h['scores']) as $sk) {
                    if (!array_key_exists($sk, BaganrikiDiagnose::SCORE_CAP)) {
                        unset($h['scores'][$sk]);
                    }
                }
                foreach ($badKeys as $sk) {
                    if (array_key_exists($sk, BaganrikiDiagnose::SCORE_CAP)) {
                        $h['scores'][$sk] = null;
                        if (BaganrikiScoreMaterials::handlesVersion($src['prompt_version'] ?? null)) {
                            $refs = is_array($h['score_evidence'][$sk] ?? null) ? ($h['score_evidence'][$sk]['source_refs'] ?? []) : [];
                            if (!is_array($h['score_evidence'] ?? null)) { $h['score_evidence'] = []; }
                            $h['score_evidence'][$sk] = ['reason' => $sk . 'は出典または採点内容を検証できず未算出',
                                'source_refs' => BaganrikiSourceEvidence::retainedRefs($src['source_registry'], $no, $refs)];
                        }
                    }
                }
                // 残った項目から合計を出し直します
                $sumS = 0; $capS = 0; $nNull = 0;
                foreach (BaganrikiDiagnose::SCORE_CAP as $sk => $mxS) {
                    $vS = $h['scores'][$sk] ?? null;
                    if (!array_key_exists($sk, $h['scores'])) {
                        $h['scores'][$sk] = null;      // 欄は残します
                        $nNull++;
                        continue;
                    }
                    if ($vS === null) { $nNull++; continue; }
                    if (!is_int($vS) || $vS < 0 || $vS > $mxS) {
                        $h['scores'][$sk] = null; $nNull++; continue;
                    }
                    $sumS += $vS; $capS += $mxS;
                }
                $h['observed_sum'] = $sumS;
                $h['observed_cap'] = $capS;
                $h['total_score']  = ($nNull === 0) ? $sumS : null;
                $h['score_status'] = ($nNull === 0) ? 'complete' : 'partial';
                // ══════════════════════════════════════════════════════
                // @ANCHOR-20261021-PREDICTION-METASPLIT
                // ══════════════════════════════════════════════════════
                //   【本物の不具合でした】
                //   直した中身を <b>馬の行の中に</b> r122_salvage として
                //   足していました。これは内部の監査項目で、AIの業務項目
                //   ではありません。ところが、この行がそのまま
                //   $first['normal'] に入り、acceptCorrection の
                //   「範囲外の項目を変えていないか」の比較対象になります。
                //   正しい全頭訂正に r122_salvage は入っていないので、
                //     初回 = 配列 ／ 訂正 = 無い
                //   となり「その範囲で直してよい項目の外を変えている」と
                //   判定され、<b>正しい訂正が FIX_REJECTED になります。</b>
                //   つまり「採点の救済」と「全頭訂正」を両方通すと落ちます。
                //   別々に試していたので気づきませんでした。
                //
                //   【いま】馬の行には入れません。<b>別の入れ物</b>に出します。
                //   AIが返すべき項目だけが訂正の比較対象になります。
                //   AIに r122_salvage を返させる解決はしません（それは
                //   内部の監査項目をAIに書かせることになります）。
                $salvageDetail[$no] = [
                    'nulled_scores' => array_values($badKeys),
                    'recomputed'    => ['observed_sum' => $sumS,
                                        'observed_cap' => $capS,
                                        'total_score'  => $h['total_score'],
                                        'score_status' => $h['score_status']],
                ];
                $salvaged[] = $no;
            }
            if (($src['prompt_version'] ?? null) === BaganrikiCandidateSelection::VERSION)
                $h = array_intersect_key($h, array_flip(BaganrikiDiagnose::HORSE_KEYS));
            $normal[$no] = $h;
        }
        ksort($normal);
        sort($salvaged);

        $out['ok']           = true;
        $out['stage']        = 'ok';
        $out['code']         = $d['ok'] ? 'OK' : 'REPAIRABLE';
        $out['reason']       = BaganrikiDiagnose::summary($d);
        $out['accepted']     = ($normal !== []);
        $out['needs_fix']    = !$d['ok'];
        $out['repair_scope'] = $d['repair_scope'];
        // @ANCHOR-20261020-PREDICTION-COMPLETE  未完了は選出に使いません（本文は残します）
        $out['normal']       = ($out['complete'] === true) ? $normal : [];
        $out['normal_kept']  = $normal;      // 保持はします（監査・訂正の材料）
        // @ANCHOR-20261021-PREDICTION-METASPLIT  内部の監査項目はここに出します
        //   （馬の行には入れません。訂正の比較対象にしないためです）
        $out['salvage_detail'] = $salvageDetail;
        // @ANCHOR-20261110-PREDICTION-RESEND-VERIFY  試しの検査はここまで（検査を通った馬が分かれば足ります。期限の確認と保存はしません）
        if ($dry) { return $out; }
        if ($out['complete'] !== true) {
            $out['reason'] = '未完了（complete=false）なので選出に使いません。'
                           . '本文は保持しています。／' . $out['reason'];
        }
        // A confirmed earlier live close can move the reply deadline behind an
        // already RECEIVED response. Recheck on every inspection/publication.
        $window = $this->publicationReadWindow ?? null;
        if ($current && $window !== null && $window['snapshot_id'] === $snapshotId) {
            $deadline = $window['deadlines'];
        } else {
            $live = \DB::table('baganriki_live_states')->where('snapshot_id',$snapshotId)->first();
            $deadline = BaganrikiWorker::deadlines($snap,$live);
        }
        $receivedAt = BaganrikiWorker::dbUs($a->received_at ?? null);
        $timeIssue = null;
        if (!$deadline['ok']) $timeIssue = $deadline['code'];
        elseif ($receivedAt === null) $timeIssue = 'RECEIVE_TIME_INVALID';
        elseif ($receivedAt > $deadline['reply']) $timeIssue = 'REPLY_DEADLINE_EXCEEDED';
        elseif ($current) {
            try {
                $got = BaganrikiExpectedPacket::instant(BaganrikiTime::toJst($receivedAt));
                $now = BaganrikiExpectedPacket::instant(BaganrikiTime::toJst(BaganrikiWorker::nowUs()));
                $ready = BaganrikiSourceEvidence::readyAt($src);
                $frozenLimit = BaganrikiExpectedPacket::instant($src['time_contract']['reply_deadline']);
                $sent = $this->inspectionSentAt($a,$snapshotId,$src['race_id'],$role,$attemptNo);
                $timeIssue = BaganrikiAttemptWindow::receiveTimeIssue($sent,$got,$ready,$now,$frozenLimit);
                if ($timeIssue===null && $attemptNo===BaganrikiWorker::FIX) {
                    $prior = \DB::table('baganriki_ai_attempts')->where('snapshot_id',$snapshotId)->where('role',$role)
                        ->where('attempt_no',BaganrikiWorker::FIRST)->where('status',BaganrikiWorker::RECEIVED)->first();
                    $priorTime = self::dbTimeToRfc3339($prior->received_at ?? null);
                    if ($priorTime===null || !BaganrikiAttemptWindow::isComplete($prior->complete ?? null))
                        $timeIssue = 'CORRECTION_SOURCE_TIME_INVALID';
                    elseif ($sent < BaganrikiExpectedPacket::instant($priorTime))
                        $timeIssue = 'CORRECTION_SENT_BEFORE_FIRST_RECEIVED';
                }
            } catch (\InvalidArgumentException $e) {
                $timeIssue = $e->getMessage();
            }
        }
        $timely = $timeIssue === null;
        $out['within_reply_deadline'] = $timely;
        if (!$timely) {
            $out['ok'] = false;
            $out['accepted'] = false;
            $out['normal'] = [];
            $out['stage'] = 'deadline';
            $out['code'] = $timeIssue;
            $out['reason'] = '入力確定から現在時刻・応答期限までの受領を確認できません。本文・正常部分を監査用に保持します。';
        }
        if ($timely && !$out['complete'] && $current) {
            $out['ok'] = $out['accepted'] = $out['needs_fix'] = false;
            $out['stage'] = 'attempt'; $out['code'] = 'INCOMPLETE_RESPONSE';
        }
        $out['row_valid']    = $d['row_valid'];
        // Locally recomputed rows may be retained, but are not fully validated fixes.
        foreach ($salvaged as $no) { $out['row_valid'][$no] = false; }
        $out['salvaged']     = $salvaged;
        $out['diag']         = [
            'findings'   => $d['findings'],
            'missing'    => $d['missing'],
            'duplicated' => $d['duplicated'],
            'unknown'    => $d['unknown'],
            'isolated'   => array_keys((array) $iso['isolated']),
            'iso_missing' => (array) $iso['missing'],
            // @ANCHOR-20261020-PREDICTION-REFCHECK  馬ごとの参照の内訳も外へ出します
            //   （どの馬に自分のSIGNALが何件あったかを、後から数えられるように）
            'ref_stats'  => (array) ($d['ref_stats'] ?? []),
            // @ANCHOR-20261020-PREDICTION-SALVAGE  採点欄だけ落として残した馬
            'salvaged'   => $salvaged,
            // @ANCHOR-20261020-PREDICTION-SCOREKEY  馬ごとの「おかしい採点項目」
            'bad_scores' => (array) ($d['bad_scores'] ?? []),
        ];
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-TYPEMAP  normal の型の地図（・3-4）
        // ══════════════════════════════════════════════════════════════
        //   normal は馬番をキーにしたオブジェクトです。
        //   最上位を obj と印し、各馬の枝を応答の位置から付け替えます。
        //   救済で計算し直した欄（observed_sum など）は、こちらで
        //   作ったPHPの整数です。地図に載っていた古い印をそのまま使うと
        //   「原トークンを書き戻す」ことになって値が戻ってしまうので、
        //   encode は<b>値が地図と食い違えばいまの値を書きます</b>
        //   （@ANCHOR-20261021-PREDICTION-TYPEMAP の sameNumberValue）。
        $normalMap = BaganrikiJson::mapWithKind(
            BaganrikiJson::emptyMap(), [], 'obj');
        foreach (array_keys($normal) as $noN) {
            if (!isset($idxOf[(int) $noN])) { continue; }
            $normalMap = BaganrikiJson::mapMerge($normalMap,
                isset($marketSalvageMaps[$noN]) ? BaganrikiJson::mapMove($marketSalvageMaps[$noN], [], [(string) $noN]) :
                BaganrikiJson::mapMove($resMap,
                    ['horses', (int) $idxOf[(int) $noN]], [(string) $noN]));
        }
        $out['map']        = $resMap;        // 応答そのものの地図
        $out['normal_map'] = $normalMap;     // 正常部分の地図（並べ替え後）

        $this->saveValidationResult((int) $a->id, $normal, $out,
            $d['repair_scope'], self::fieldSourcesOf($normal),
            self::rankSourcesOf($res), $normalMap);
        return $out;
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-R122-RESP  応答検証と局所回復（本番接続）
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：server_contract_r12.2.txt 6章・7章・10章
     *
     * ■ 通す順番（本番と同じ）
     *   extract → checkEnvelope → isolateHorses → BaganrikiDiagnose::run
     *
     * ■ 何を正とするか
     *   凍結入力（snapshot）と、予約済みの role を正とします。
     *   <b>応答が名乗る役は照合相手を選ぶのに使いません。</b>
     *   封筒照合は、予約した role の固定入力に対してだけ行います。
     *
     * ■ 局所回復
     *   馬番欠落・重複・未知馬・局所の項目不正は、その範囲だけ隔離します。
     *   正常な他馬はそのまま残します。
     *   直してよい範囲は repair_scope として baganriki_validation_results に残します。
     *
     * @return array ['ok','stage','code','reason','accepted','needs_fix',
     *                'repair_scope','normal','row_valid','diag']
     */
    /** Actual SDK send time for one role and slot. Earlier storage uses the submitted R122_USAGE event. */
    private function inspectionSentAt(object $attempt,int $snapshotId,string $race,string $role,int $slot): \DateTimeImmutable
    {
        $value=$attempt->sent_at ?? null;
        if ($value===null) {
            if (!\Schema::hasTable('baganriki_audit_events')) throw new \InvalidArgumentException('SEND_TIME_MISSING');
            $rows=\DB::table('baganriki_audit_events')->where('snapshot_id',$snapshotId)->where('race_id',$race)
                ->where('kind','R122_USAGE')->where('related_id',$role.'#'.$slot)->get();
            $found=false;
            foreach ($rows as $row) {
                $d=json_decode((string)($row->diagnostics ?? ''),true);
                if ($found || !is_array($d) || ($d['role'] ?? null)!==$role || ($d['attempt_no'] ?? null)!==$slot)
                    throw new \InvalidArgumentException('SEND_TIME_AMBIGUOUS');
                $found=true;$value=$d['sent_at'] ?? null;
            }
        }
        if ($value===null) throw new \InvalidArgumentException('SEND_TIME_MISSING');
        if (!is_string($value)) throw new \InvalidArgumentException('SEND_TIME_INVALID');
        try {
            return BaganrikiExpectedPacket::instant(self::dbTimeToRfc3339($value) ?? $value);
        } catch (\InvalidArgumentException $error) {
            throw new \InvalidArgumentException('SEND_TIME_INVALID',0,$error);
        }
    }

    /**
     * @ANCHOR-20261020-PREDICTION-RESP  訂正を解決します（最大1回）
     *   初回の原応答と complete を保存してからでないと動きません。
     *   直してよい範囲は<b>初回の診断</b>から取ります（同じ診断関数です）。
     *   採用できない訂正では、初回の正常部分をそのまま残します。
     */
    public function resolveCorrection(int $snapshotId, string $role): array
    {
        $out = ['ok' => false, 'code' => null, 'reason' => null,
                'merged' => [], 'applied' => [], 'rescued' => [], 'skipped' => []];

        $first = $this->inspectAiResponse($snapshotId, $role, BaganrikiWorker::FIRST);
        if (!$first['ok']) {
            $out['code'] = 'FIRST_NOT_USABLE';
            $out['reason'] = '初回が採用できません（' . $first['stage'] . '／'
                           . $first['code'] . '）';
            return $out;
        }
        $current = ($first['validator_version'] ?? null) === BaganrikiResponseInspection::REVISION;
        // Correction results carry the completeness of the inspected initial receipt.
        // A rejected or incomplete correction can only leave its previously verified rows in use.
        if ($current) $out['complete'] = ($first['complete'] ?? false) === true;
        $out['merged_map'] = $first['normal_map'] ?? BaganrikiJson::emptyMap();
        $out['merged'] = $first['normal'];        // 何があっても初回の正常部分は残します
        if (!$first['needs_fix']) {
            $out['ok'] = true; $out['code'] = 'NO_FIX_NEEDED';
            $out['reason'] = '初回に直すところがないので訂正しません';
            return $out;
        }

        $fix = $this->inspectAiResponse($snapshotId, $role, BaganrikiWorker::FIX);
        if (!$fix['ok']) {
            $out['ok'] = true; $out['code'] = 'FIX_NOT_USABLE';
            $out['reason'] = '訂正が採用できないので初回の正常部分を残します（'
                           . $fix['stage'] . '／' . $fix['code'] . '）';
            return $out;
        }

        $rowsA = \DB::table('baganriki_ai_attempts')
                   ->where('snapshot_id', $snapshotId)->where('role', $role)
                   ->whereIn('attempt_no', [BaganrikiWorker::FIRST, BaganrikiWorker::FIX])
                   ->get()->keyBy('attempt_no');
        $firstAt = self::dbTimeToRfc3339($rowsA[BaganrikiWorker::FIRST]->received_at ?? null);
        $fixAt   = self::dbTimeToRfc3339($rowsA[BaganrikiWorker::FIX]->received_at ?? null);

        $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
        $frozen = [];
        foreach ((array) json_decode((string) ($snap->frozen_nums ?? '[]'), true) as $n) {
            $frozen[] = (int) $n;
        }

        // @ANCHOR-20261021-PREDICTION-TYPEMAP  型の地図も渡します（）
        //   どちらも「馬番をキーにした入れ物」の地図なので、
        //   経路の先頭が馬番でそろいます。
        $validRows = $fix['row_valid'];
        if ($current && $fix['needs_fix']) {
            // A presentation finding does not invalidate its initial scores, but this corrected row is still unresolved.
            foreach (['horses','summary_horses','presentation_horses'] as $scope) {
                foreach ($fix['repair_scope'][$scope] as $no) $validRows[$no] = false;
            }
        }
        $compareScope = $first['repair_scope'];
        if ($current) {
            if ($snap === null || !isset($rowsA[BaganrikiWorker::FIRST])
                || ($rowsA[BaganrikiWorker::FIRST]->response_hash ?? null) !== $first['response_hash']) {
                $out['code'] = 'FIRST_NOT_USABLE'; $out['merged'] = []; $out['merged_map'] = BaganrikiJson::emptyMap();
                $out['reason'] = '初回の受領記録が診断時と一致しません'; return $out;
            }
            if (!isset($rowsA[BaganrikiWorker::FIX]) || ($rowsA[BaganrikiWorker::FIX]->response_hash ?? null) !== $fix['response_hash']) {
                $out['ok'] = true; $out['code'] = 'FIX_NOT_USABLE'; $out['reason'] = '訂正の受領記録が診断時と一致しません'; return $out;
            }
            try {
                $types = null; $bound = BaganrikiFrozenInput::forInspection($snap,$role,$types);
                if ($bound === null || $types === null) throw new \InvalidArgumentException('EXPECTED_STORED_VERSION');
            } catch (\InvalidArgumentException $ex) {
                $out['code'] = 'FIRST_NOT_USABLE'; $out['merged'] = []; $out['merged_map'] = BaganrikiJson::emptyMap();
                $out['reason'] = '凍結した役割の原文を照合できません '.$ex->getMessage(); return $out;
            }
            $receipt = static fn($row) => ['raw'=>$row->raw_response,'hash'=>$row->response_hash];
            $from = $receipt($rowsA[BaganrikiWorker::FIRST]); $to = $receipt($rowsA[BaganrikiWorker::FIX]);
            $missing = array_diff(array_keys(array_filter($validRows)),array_keys($first['normal']),$compareScope['horses']);
            if ($missing) {
                try {
                    $checked = BaganrikiRecovery::scopedRescues($bound,$from,$to,$compareScope,$missing);
                    // Local comparator only. The persisted grant and the final original-response check keep the narrow field scope.
                    $compareScope['horses'] = array_merge($compareScope['horses'],$checked);
                } catch (\InvalidArgumentException $e) {
                    $out['ok'] = true; $out['code'] = 'FIX_REJECTED';
                    $out['reason'] = '初回で除外した馬の訂正が許可範囲と一致しません '.$e->getMessage();
                    return $out;
                }
            }
        }
        $acc = BaganrikiResponse::acceptCorrection(
            $first['normal'], $fix['normal'], $firstAt, $fixAt,
            $frozen, $compareScope, $validRows,
            (array) ($first['normal_map'] ?? BaganrikiJson::emptyMap()),
            (array) ($fix['normal_map']   ?? BaganrikiJson::emptyMap()));
        if (($acc['ok'] ?? false) !== true) {
            $out['ok'] = true; $out['code'] = 'FIX_REJECTED';
            $out['reason'] = '訂正を採用しません（' . (string) ($acc['reason'] ?? '')
                           . '）。初回の正常部分を残します';
            return $out;
        }
        $rankRejected = false; $rankReceipts = null;
        if ($current && !$fix['needs_fix'] && empty($acc['skipped'])) {
            try {
                BaganrikiRecovery::inspectCorrection($bound,$types,$from,$to,$first['repair_scope']);
                $rankReceipts = [$from['hash'],$to['hash']];
            } catch (\InvalidArgumentException $ex) {
                if ($ex->getMessage() !== 'EXPECTED_REPAIR_RANK_SCOPE') {
                    $out['ok'] = true; $out['code'] = 'FIX_REJECTED';
                    $out['reason'] = '保存した初回と訂正を照合できません '.$ex->getMessage();
                    return $out;
                }
                // A valid row can still be recovered. An unauthorized order cannot supply raw candidates or a proof.
                $rankRejected = true;
            }
        }
        $out['merged']  = BaganrikiResponse::applyCorrection(
                              $first['normal'], $fix['normal'], $acc);
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-TYPEMAP  当てた行だけ地図も差し替えます
        // ══════════════════════════════════════════════════════════════
        //   merged は「初回の行＋当てた行」です。地図も同じ組み方に
        //   しないと、当てた行の型が初回のものに戻ってしまいます。
        $mergedMap = (array) ($first['normal_map'] ?? BaganrikiJson::emptyMap());
        $fixMapAll = (array) ($fix['normal_map']   ?? BaganrikiJson::emptyMap());
        foreach (array_merge((array) ($acc['applied'] ?? []),
                             (array) ($acc['rescued'] ?? [])) as $noM) {
            $mergedMap = BaganrikiJson::mapMerge($mergedMap,
                BaganrikiJson::mapMove($fixMapAll,
                    [(string) (int) $noM], [(string) (int) $noM]));
        }
        $out['merged_map'] = $mergedMap;
        $out['applied'] = (array) ($acc['applied'] ?? []);
        $out['rescued'] = (array) ($acc['rescued'] ?? []);
        $out['skipped'] = (array) ($acc['skipped'] ?? []);
        $out['ok']   = true;
        if ($current && ($fix['needs_fix'] || $out['skipped'] || $rankRejected)) {
            $changed = $out['rescued'] !== [];
            // Display-only corrections do not need a rescued horse to count as a partial repair.
            try {
                $firstParsed=BaganrikiCandidateSelection::parseOriginal($from['raw'],$from['hash']);
                $fixParsed=BaganrikiCandidateSelection::parseOriginal($to['raw'],$to['hash']);
                $out['metric_repairs']=BaganrikiRaceMetrics::repairedKeys(
                    $bound,$firstParsed['json'],$fixParsed['json'],$firstParsed['map'],$fixParsed['map']);
                if ($out['metric_repairs']!==[]) $out['metric_receipts']=[$from['hash'],$to['hash']];
                $changed=$changed || $out['metric_repairs']!==[];
            } catch (\InvalidArgumentException $e) { $out['metric_repairs']=[]; }
            foreach ($out['applied'] as $no) {
                if (!isset($first['normal'][$no]) || !BaganrikiResponse::sameHorseRow($first['normal'][$no], $fix['normal'][$no],
                     $first['normal_map'], $fix['normal_map'], [(string)$no])) { $changed = true; break; }
            }
            if (!$changed) {
                $out['merged'] = $first['normal']; $out['merged_map'] = $first['normal_map'];
                $out['applied'] = [];
            }
            if ($rankRejected) $out['rank_issue'] = 'EXPECTED_REPAIR_RANK_SCOPE';
            $out['code'] = $changed ? 'FIX_PARTIAL' : 'FIX_REJECTED';
            $out['reason'] = $changed ? '訂正を検証できた馬・表示指標だけ反映しました。順位と候補を再計算します'
                                      : '訂正に指摘が残ったため、初回の正常部分を残します';
            return $out;
        }
        if ($current) $out['rank_receipts'] = $rankReceipts;
        $out['code'] = 'FIX_APPLIED';
        $out['reason'] = '訂正を反映しました（適用 ' . count($out['applied'])
                       . '頭・救済 ' . count($out['rescued'])
                       . '頭・飛ばし ' . count($out['skipped']) . '頭）';
        return $out;
    }

    /**
     * @ANCHOR-20261020-PREDICTION-RESP  選出に渡す馬（訂正があればそれを使う）
     */
    public function acceptedHorsesOf(int $snapshotId, string $role): array
    {
        $has = \DB::table('baganriki_ai_attempts')
                 ->where('snapshot_id', $snapshotId)->where('role', $role)
                 ->where('attempt_no', BaganrikiWorker::FIX)
                 ->where('status', BaganrikiWorker::RECEIVED)->exists();
        if ($has) { return $this->resolveCorrection($snapshotId, $role); }
        $f = $this->inspectAiResponse($snapshotId, $role, BaganrikiWorker::FIRST);
        return ['ok' => $f['ok'], 'code' => $f['code'], 'reason' => $f['reason'],
                // @ANCHOR-20261020-PREDICTION-COMPLETE  未完了なら選出へ渡しません
                'merged' => $f['normal'], 'applied' => [], 'rescued' => [],
                'skipped' => [], 'needs_fix' => $f['needs_fix'],
                'complete' => ($f['complete'] ?? null),
                'repair_scope' => $f['repair_scope']];
    }

    /** @ANCHOR-20261020-PREDICTION-RESP  検証の結果を残します（表が無ければ何もしません） */
    private function saveValidationResult(int $attemptId, ?array $normal, array $out,
                                        ?array $scope, ?array $fieldSources,
                                        ?array $rankSources,
                                        ?array $normalMap = null): void
    {
        if (!\Schema::hasTable('baganriki_validation_results')) { return; }
        if ($this->dryInspectRaw !== null) { return; }   // @ANCHOR-20261110-PREDICTION-RESEND-VERIFY  試しの検査は保存しません
        $validator = $out['validator_version'] ?? BaganrikiDiagnose::VERSION;
        $j = static fn($v) => ($v === null) ? null : (string) json_encode($v,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-TYPEMAP  正常部分は専用シリアライザで書きます
        // ══════════════════════════════════════════════════════════════
        //   そのとおりでした。ここが通常の json_encode のままだと、
        //   丸まる数値（文字列で持っているもの）が<b>引用符付きで
        //   保存されて、読み戻すと文字列になります。</b>
        //   【いま】型の地図があるときは encode で書きます。
        //   数値だった位置は引用符なし、{} と [] の種類もそのままです。
        //   JSON_NUMERIC_CHECK も float への変換も使っていません。
        //   読み戻しは BaganrikiJson::decode です（normalizedPartOf）。
        $jn = static function ($v) use ($normalMap, $j) {
            if ($v === null) { return null; }
            if ($normalMap === null) { return $j($v); }
            return BaganrikiJson::encode($v, $normalMap);
        };
        try {
            // @ANCHOR-20261023-PREDICTION-VALDUP  同じ応答の検証結果は1回だけ残します
            //   10/23 の実測で、同じ応答を何度も検証する流れ（採否確定・公開判定）で
            //   一意キー（attempt_id＋validator_version）の重複エラーが出ていました。
            //   検証は同じ入力なら同じ結果なので、最初の1件を正とし、2回目以降は書きません。
            if (\DB::table('baganriki_validation_results')
                    ->where('attempt_id', $attemptId)
                    ->where('validator_version', $validator)->exists()) {
                return;
            }
            \DB::table('baganriki_validation_results')->insert([
                'attempt_id'        => $attemptId,
                'validator_version' => $validator,
                'normal_part'       => $jn($normal),
                'diagnostics'       => $j(['stage' => $out['stage'], 'code' => $out['code'],
                                           'reason' => $out['reason'],
                                           'diag' => $out['diag'] ?? null]),
                'repair_scope'      => $j($scope),
                'field_sources'     => $j($fieldSources),
                'rank_sources'      => $j($rankSources),
                'created_at'        => BaganrikiWorker::toDb(BaganrikiWorker::nowUs()),
            ]);
        } catch (\Throwable $e) {
            \Log::error('[PREDICTION-RESP] 検証結果を残せません', ['message' => $e->getMessage()]);
            throw $e; // Do not mark role processing as settled after a persistence error.
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261021-PREDICTION-TYPEREAD  保存した正常部分を読み戻します（）
     * ═══════════════════════════════════════════════════════════════════════
     *
     *   そのとおりです。保存を専用シリアライザにしても、読むほうが
     *   json_decode なら、そこで型が落ちます。
     *   <b>読み戻しも専用パーサ（BaganrikiJson::decode）に統一します。</b>
     *   返すのは「値」と「型の地図」の両方です。
     *
     * @return array ['ok','code','reason','value','map','raw']
     */
    public function normalizedPartOf(int $attemptId, ?string $validator = null): array
    {
        $out = ['ok' => false, 'code' => null, 'reason' => null,
                'value' => null, 'map' => BaganrikiJson::emptyMap(), 'raw' => null];
        if (!\Schema::hasTable('baganriki_validation_results')) {
            $out['code'] = 'TABLE_MISSING';
            return $out;
        }
        if ($validator === null) {
            $release = \DB::table('baganriki_ai_attempts as a')
                ->join('baganriki_snapshots as s','s.id','=','a.snapshot_id')
                ->where('a.id',$attemptId)->value('s.release_version');
            $validator = BaganrikiDiagnose::revision($release);
        }
        $r = \DB::table('baganriki_validation_results')
               ->where('attempt_id', $attemptId)
               ->where('validator_version', $validator)
               ->orderByDesc('id')->first(['normal_part']);
        if ($r === null) {
            $out['code'] = 'NO_ROW';
            $out['reason'] = 'その枠の検証結果がありません';
            return $out;
        }
        $raw = $r->normal_part;
        if ($raw === null || (string) $raw === '') {
            $out['code'] = 'NO_NORMAL';
            $out['reason'] = '正常部分が保存されていません';
            return $out;
        }
        $out['raw'] = (string) $raw;
        // @ANCHOR-20261021-PREDICTION-TYPEREAD  json_decode は使いません
        $d = BaganrikiJson::decode((string) $raw);
        if (!$d['ok']) {
            $out['code'] = (string) $d['code'];
            $out['reason'] = (string) $d['reason'];
            return $out;
        }
        $out['ok'] = true;
        $out['code'] = 'OK';
        $out['value'] = $d['value'];
        $out['map'] = (array) $d['map'];
        return $out;
    }

    /**
     * @ANCHOR-20261021-PREDICTION-TYPEREAD  保存した正常部分が、いまの値と同じか
     *
     *   保存 → 読戻し で型も値も落ちていないことを、その場で確かめます。
     *   「公開用の一致確認」までこの比較1本で通します（010の3-3）。
     *
     * @return array ['ok','same','where','reason']
     */
    public function checkNormalizedRoundTrip(int $attemptId, array $normal,
                                        array $normalMap, ?string $validator = null): array
    {
        $rd = $this->normalizedPartOf($attemptId, $validator);
        if (!$rd['ok']) {
            return ['ok' => false, 'same' => false, 'where' => null,
                    'reason' => '読み戻せません（' . (string) $rd['code'] . '）'];
        }
        $r = BaganrikiJson::same($normal, $normalMap,
                                 $rd['value'], $rd['map'], true);
        return ['ok' => true, 'same' => (bool) $r['same'],
                'where' => $r['where'], 'reason' => $r['reason']];
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * @ANCHOR-20261020-PREDICTION-PUB  統合・公開・intent・revision（本番接続）
     * ═══════════════════════════════════════════════════════════════════════
     * 根拠：server_contract_r12.2.txt 7章・8章・10章・11章
     *
     * ■ 通す順番
     *   ① 読取（書込ロックを持ちません）
     *      3つの版（state／response／publication）と、両roleの検証済み全頭評価。
     *      ここで「検証開始時刻」を取ります。
     *   ② 統合（BaganrikiRanking::merge）
     *      rank_score=100×(K−r+1)/K の max。同点は 両AIの原公開一致あり →
     *      独立根拠群数の多い順 → 馬番昇順。
     *      <b>両AIの検証済み原公開候補だと証明できたときだけ +5。</b>
     *   ③ 枠を先に全帯ぶん予約し、D不足はB、B不足はD、A不足はB→D で埋めます。
     *      馬番は重複させません。
     *   ④ 「検証完了時刻」を取り、intent_hash を作ります。
     *   ⑤ <b>短いトランザクション</b>で live_state と intent をロックし、
     *      3つの版・現在時刻・期限をもう一度見ます。
     *      古い処理は拒否。同じ intent の再実行は同じ publication_id。
     *   ⑥ 「commit直前時刻」を取り、
     *      検証開始 <= 検証完了 <= commit直前 を確かめます。
     *
     * ■ 加点の証明について
     *   訂正版または初回の<b>完全な原応答</b>が、公開に使う全頭の実効内容・
     *   帯順位・公開リスト・馬別フラグ・非選出理由と一致すると確かめられた
     *   ときだけ、その role の証明として使います。
     *   初回と訂正が混ざった行は証明できないので加点しません。
     *
     * @param array $opt ['now_us'=>int, 'job_id'=>string, 'handed'=>array]
     * @return array
     */
    public function publishPrediction(int $snapshotId, array $opt = []): array
    {
        $out = ['ok' => false, 'code' => null, 'reason' => null,
                'publication_id' => null, 'reused' => false,
                'candidates' => [], 'quota' => null, 'shortfalls' => [],
                'intent_hash' => null, 'job_id' => null,
                'used' => [], 'proof' => [],
                // @ANCHOR-20261020-R122-BANDKEEP  どの帯の順位をどこから採ったか
                'rank_source' => [], 'rank_fallback' => [], 'rank_notes' => []];

        $t0 = (int) ($opt['now_us'] ?? BaganrikiWorker::nowUs());   // 検証開始時刻

        $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
        if ($snap === null) {
            $out['code'] = 'NO_SNAPSHOT'; $out['reason'] = '凍結入力がありません';
            return $out;
        }
        // 原応答手渡しは、ジャーナルがあるレースでは受け付けません（契約10章）
        if (!empty($opt['handed'])) {
            $hasJournal = \DB::table('baganriki_ai_attempts')
                            ->where('snapshot_id', $snapshotId)->exists();
            if ($hasJournal) {
                $out['code'] = 'HANDED_REFUSED';
                $out['reason'] = 'ジャーナルがあるレースでは原応答の手渡しを受け付けません';
                return $out;
            }
        }

        BaganrikiWorker::ensureLive($snapshotId, $t0);
        $live = \DB::table('baganriki_live_states')
                  ->where('snapshot_id', $snapshotId)->first();      // ロックしません
        $readRev = [
            'state'       => ($live === null) ? 0 : (int) $live->state_revision,
            'response'    => ($live === null) ? 0 : (int) $live->response_revision,
            'publication' => ($live === null) ? 0 : (int) $live->publication_revision,
        ];
        // 呼出側が整合した読取取引で3つの版を取っているときは、その値を使います。
        //   （読取→検証→保存を分けて行う呼び方のためです。保存の直前に
        //     もう一度見比べて、古ければ保存しません）
        if (BaganrikiWorker::isExpectedSnapshot($snap)) {
            // A caller's expected revision may check the read, never substitute for the observed state.
            $expected=$opt['read_revisions']??[];
            if (!is_array($expected) || array_diff_key($expected,$readRev)) {
                return array_replace($out,['code'=>'STALE','reason'=>'指定された読取版を確認できません']);
            }
            foreach ($readRev as $k=>$observed) {
                try {
                    $column=$k.'_revision';
                    $actual=BaganrikiAttemptWindow::revision($live->$column??null);
                    if ($actual!==(string)$observed || (array_key_exists($k,$expected)
                        && BaganrikiAttemptWindow::revision($expected[$k])!==$actual)) {
                        return array_replace($out,['code'=>'STALE','reason'=>'指定された版と実際に読んだ版が一致しません']);
                    }
                } catch (\InvalidArgumentException $error) {
                    return array_replace($out,['code'=>'STALE','reason'=>'公開に使う版を照合できません']);
                }
            }
        } else if (isset($opt['read_revisions']) && is_array($opt['read_revisions'])) {
            foreach (['state', 'response', 'publication'] as $k) {
                if (array_key_exists($k, $opt['read_revisions'])) {
                    $readRev[$k] = (int) $opt['read_revisions'][$k];
                }
            }
        }
        $out['used'] = $readRev;

        // ── 期限（実効）──────────────────────────────────────────────
        $dl = BaganrikiWorker::deadlines($snap, $live);
        if (!$dl['ok']) {
            $out['code'] = (string) $dl['code']; $out['reason'] = (string) $dl['reason'];
            return $out;
        }
        if ($t0 > $dl['publish']) {
            $out['code'] = 'DEADLINE_PASSED';
            $out['reason'] = '実効の公開期限を過ぎています';
            return $out;
        }

        if (BaganrikiWorker::isExpectedSnapshot($snap)) {
            $roster=BaganrikiWorker::verifiedLiveRoster($snap,$live,true,$issue);
            if ($roster===null) return array_replace($out,BaganrikiWorker::liveStateFailure($issue));
            $cancelled=$roster['withdrawn'];
            $activeNums=array_values(array_diff($roster['roster'],$cancelled));
            if (!$activeNums) return array_replace($out,['code'=>'ALL_CANCELLED','reason'=>'全頭取消です']);
            if (($opt['require_settled_expected'] ?? false) === true) {
                $waiting = $this->pendingPublicationRoles($snap, $dl, $t0);
                if ($waiting !== null) { return array_replace($out, $waiting); }
            }
            // Both roles and any corrections must be inspected against the same effective window.
            $previousWindow = $this->publicationReadWindow;
            $this->publicationReadWindow = ['snapshot_id' => $snapshotId, 'deadlines' => $dl];
            try {
                $selection=BaganrikiCandidateSelection::submittedPublication($snap,$activeNums,function($role)use($snapshotId){
                    $accepted=$this->acceptedHorsesOf($snapshotId,$role);
                    $rank=(($accepted['ok']??false) && ($accepted['complete']??false)===true)
                        ? $this->markResponseUsed($snapshotId,$role,$accepted) : null;
                    return [$accepted,$rank];
                });
            } catch (\InvalidArgumentException $error) {
                $code=['EXPECTED_NO_VERIFIED_ROLE'=>'NO_RESPONSE','EXPECTED_SELECTION_DISABLED'=>'SELECTION_DISABLED'][$error->getMessage()]??'INVALID_SAVED_INPUT';
                return array_replace($out,['code'=>$code,'reason'=>'E3・E5版の選出を確認できません '.$error->getMessage()
                    .(BaganrikiCandidateSelection::$lastRoleIssues ? ' '.json_encode(BaganrikiCandidateSelection::$lastRoleIssues,JSON_UNESCAPED_UNICODE) : '')]);
            } finally {
                $this->publicationReadWindow = $previousWindow;
            }
            $cands=$selection['candidates'];$quota=$selection['quota'];
        } else {
            // ── 全頭取消 ────────────────────────────────────────────────
            $cancelled = (array) json_decode((string) ($live->cancellations ?? '[]'), true);
            $cancelled = array_map('intval', $cancelled);
            $frozenBand = [];
            $src1 = json_decode((string) $snap->input_json_1st, true);
            foreach ((array) ($src1['runners'] ?? []) as $r) {
                $frozenBand[(int) $r['horse_no']] = (string) ($r['band'] ?? '');
            }
            $activeNums = array_values(array_diff(array_keys($frozenBand), $cancelled));
            if ($activeNums === []) {
                $out['code'] = 'ALL_CANCELLED'; $out['reason'] = '全頭取消です';
                return $out;
            }

            // ── ① 両roleの検証済み全頭評価（読取だけ）────────────────────
            $per = []; $proofHash = []; $accepted = [];
            foreach (['1st', '2nd'] as $role) {
                $a = $this->acceptedHorsesOf($snapshotId, $role);
                if (!($a['ok'] ?? false) || ($a['merged'] ?? []) === []) { continue; }
                // @ANCHOR-20261020-R122-COMPLETE  未完了は選出に使いません（契約10章）
                if (($a['complete'] ?? null) === false) { continue; }
                $accepted[$role] = $a;
                $srcCol = ($role === '2nd') ? 'input_json_2nd' : 'input_json_1st';
                $src = json_decode((string) $snap->{$srcCol}, true);
                $resUsed = $this->markResponseUsed($snapshotId, $role, $a);
                if ($resUsed['proof'] !== null) { $proofHash[$role] = $resUsed['proof']; }
                // @ANCHOR-20261020-R122-BANDKEEP  帯ごとの順位の出どころを残します
                $out['rank_source'][$role] = (array) ($resUsed['rank_source'] ?? []);
                if ((array) ($resUsed['fallback'] ?? []) !== []) {
                    $out['rank_fallback'][$role] = (array) $resUsed['fallback'];
                }
                foreach ((array) ($resUsed['rank_notes'] ?? []) as $rn) {
                    $out['rank_notes'][] = $role . '：' . (string) $rn;
                }
                $rows = [];
                foreach ((array) $a['merged'] as $num => $h) {
                    $num = (int) $num;
                    if (in_array($num, $cancelled, true)) { continue; }   // 取消は入れません
                    // ══════════════════════════════════════════════════════
                    // @ANCHOR-20261020-R122-ELIG  10月20日006のご指摘2-2
                    // ══════════════════════════════════════════════════════
                    //   【本物の不具合でした】取消は除いていたのに、
                    //   <b>資格の検査がありませんでした。</b>
                    //   NO_EVIDENCE や UNVERIFIED の馬も band_ranks に馬番さえ
                    //   あれば順位点に入り、「適格馬の数」として不足判定にも
                    //   使われていました。全頭 NO_EVIDENCE のレースで
                    //   候補7頭が出てしまいます（本来は候補0です）。
                    //
                    //   ここで見るのは<b>意味検証を通ったあとの資格</b>です。
                    //   診断が eligibility と family_review・自分のSIGNALの整合を
                    //   見たうえで残した行なので、この値が実効資格になります。
                    //   採点が partial でも、個別SIGNALが1系統あれば ELIGIBLE が
                    //   立つので落ちません（新しい下限や複数系統必須は足しません）。
                    $elig = (string) ($h['eligibility'] ?? '');
                    if ($elig !== 'ELIGIBLE') { continue; }
                    $band = $frozenBand[$num] ?? ($h['band'] ?? '');
                    // ══════════════════════════════════════════════════════
                    // @ANCHOR-20261020-R122-FROZENK  10月20日006のご指摘3-4
                    // ══════════════════════════════════════════════════════
                    //   【本物の不具合でした】Kを残存頭数で数え直し、
                    //   順位配列からも取消馬を抜いて r を詰めていました。
                    //   凍結A帯6頭の2位は 100×5/6＝83.33点ですが、
                    //   上位が取消になると5頭の1位＝100点に変わっていました。
                    //   契約7章は「凍結帯頭数Kと帯内順位rで統合し、
                    //   統合後に取消馬を除き、最新頭数で公開枠を調整する」です。
                    //   <b>Kとrは凍結時のまま。</b>取消で動かすのは公開枠だけです。
                    $K = 0;
                    foreach ($frozenBand as $n2 => $b2) {
                        if ($b2 === $band) { $K++; }          // 取消で減らしません
                    }
                    $order = array_map('intval',
                        (array) ($resUsed['band_ranks'][$band] ?? []));
                    // 取消馬を抜いて詰め直すことはしません（rは凍結時の位置）
                    $r = array_search($num, $order, true);
                    if ($r === false) { continue; }    // 順位の分からない馬は入れません
                    $sup = 0;
                    foreach ((array) ($h['family_review'] ?? []) as $fam) {
                        if (($fam['status'] ?? '') === 'SUPPORTED') { $sup++; }
                    }
                    $rows[$num] = [
                        'r' => (int) $r + 1, 'K' => max(1, $K),
                        'public' => in_array($num,
                            array_map('intval', (array) ($resUsed['public_candidates'] ?? [])), true),
                        'groups' => $sup,
                    ];
                }
                if ($rows !== []) { $per[$role] = $rows; }
            }
            if ($per === []) {
                $out['code'] = 'NO_RESPONSE';
                $out['reason'] = '検証を通った応答がありません';
                return $out;
            }

            // ── ② 統合 ─────────────────────────────────────────────────
            $merged = BaganrikiRanking::merge($per, $proofHash);
            $out['proof'] = array_keys($proofHash);

            // ── ③ 枠（先に全帯ぶん予約し、不足を規則どおりに移す）─────────
            //   構造上の不足（取消で実在しなくなった帯）と、
            //   評価後の適格馬不足は別の規則なので、2段に分けます。
            $q0 = (array) json_decode((string) $snap->quota_1st, true);
            $quota1st = isset($q0[0])
                ? ['A' => (int) $q0[0], 'B' => (int) $q0[1], 'D' => (int) $q0[2]]
                : ['A' => (int) ($q0['A'] ?? 0), 'B' => (int) ($q0['B'] ?? 0),
                   'D' => (int) ($q0['D'] ?? 0)];
            $afterC = BaganrikiRanking::afterCancellation($frozenBand, $cancelled,
                          $quota1st, $snap->allocation_mode);
            if (($afterC['all_cancelled'] ?? false) === true) {
                $out['code'] = 'ALL_CANCELLED'; $out['reason'] = '全頭取消です';
                return $out;
            }
            $quota = (array) ($afterC['live_quota'] ?? $quota1st);
            if ($quota === []) { $quota = ['A' => 0, 'B' => 0, 'D' => 0]; }
            if (isset($quota[0])) {
                $quota = ['A' => (int) $quota[0], 'B' => (int) $quota[1], 'D' => (int) $quota[2]];
            }
            // 評価後の適格馬（統合に乗った馬）の帯ごとの数
            $avail = ['A' => 0, 'B' => 0, 'D' => 0];
            foreach ($merged as $m) {
                $b = $frozenBand[$m['num']] ?? '';
                if (isset($avail[$b])) { $avail[$b]++; }
            }
            $fill = BaganrikiRanking::fillShortfall($quota, $avail);
            $quota = (array) $fill['quota'];
            $out['shortfalls'] = array_merge((array) ($afterC['shortfalls'] ?? []),
                                             (array) $fill['shortfalls']);

            $taken = []; $cands = [];
            foreach (['A', 'B', 'D'] as $b) {
                $want = (int) ($quota[$b] ?? 0);
                $got = 0;
                foreach ($merged as $m) {
                    if ($got >= $want) { break; }
                    $num = (int) $m['num'];
                    if (isset($taken[$num])) { continue; }          // 馬番は重複させません
                    if (($frozenBand[$num] ?? '') !== $b) { continue; }
                    $taken[$num] = true; $got++;
                    $cands[] = [
                        'horse_no' => $num, 'band' => $b,
                        'score' => $m['score'], 'bonus' => $m['bonus'],
                        'both_public' => $m['both_public'],
                        'bonus_proven' => $m['bonus_proven'],
                        'independent_groups' => $m['groups'],
                        'public_proof_hash' => $m['public_proof_hash'],
                    ];
                }
            }
            $out['candidates'] = $cands;
            $out['quota'] = $quota;
        }

        // ── ④ 検証完了時刻と intent ─────────────────────────────────
        $t1 = BaganrikiWorker::nowUs();                     // 検証完了時刻
        $intentData = [
            'snapshot_hash' => (string) $snap->snapshot_hash,
            'quota'         => $quota,
            'candidates'    => array_map(static fn($c) => [
                                   'horse_no' => $c['horse_no'],
                                   'band'     => $c['band'],
                                   'bonus'    => $c['bonus'],
                               ], $cands),
            // @ANCHOR-20261020-R122-PUB  intentに入れるのは「読んだもの」だけです。
            //   publication_revision は公開した<b>結果</b>なので入れません。
            //   入れると、同じ計算の再実行が毎回別のintentになり、
            //   「同じintentの再実行は同じpublication_id」が守れません。
            'revisions'     => ['state'    => $readRev['state'],
                                'response' => $readRev['response']],
        ];
        if (isset($selection) && array_key_exists('race_metrics',$selection)) $intentData['race_metrics']=$selection['race_metrics'];
        $canon=BaganrikiPrompt::canonical($intentData);
        if ($canon === null) {
            $out['code'] = 'CANON_FAILED'; $out['reason'] = 'intentの正本を作れません';
            return $out;
        }
        $intentHash = hash('sha256', $canon);
        $jobId = (string) ($opt['job_id'] ?? ('job-' . $snapshotId . '-' . $t1 . '-'
                 . substr(bin2hex(random_bytes(3)), 0, 6)));
        $out['intent_hash'] = $intentHash;
        $out['job_id'] = $jobId;

        // @ANCHOR-20261023-NARU-RACE  競合の試験口（なる 10/23 5章D）
        //   重い計算が終わり、保存の短いトランザクションに入る<b>直前</b>に、別の処理が
        //   取消・締切の前倒しを入れた場面を、試験から再現するための口です。
        //   本番の呼び出しはこれを渡しません。artisan からの実行のときだけ効きます。
        if (isset($opt['test_before_commit']) && is_callable($opt['test_before_commit'])
            && app()->runningInConsole()) {
            ($opt['test_before_commit'])($snapshotId);
        }

        // ── ⑤ 短いトランザクション（ここだけロックします）─────────────
        $readDeadlines = $dl;
        $publicationData = $selection ?? $out;
        try {
            $res = \DB::transaction(function () use ($snapshotId, $snap, $intentHash,
                    $jobId, $readRev, $cands, $quota, $publicationData, $t0, $t1, $cancelled,
                    $activeNums, $readDeadlines, $opt) {
                $live = \DB::table('baganriki_live_states')
                          ->where('snapshot_id', $snapshotId)->lockForUpdate()->first();
                $now = $readRev;
                if ($live !== null) {
                    $now = ['state' => (int) $live->state_revision,
                            'response' => (int) $live->response_revision,
                            'publication' => (int) $live->publication_revision];
                }
                // 同じ intent の再実行は同じ publication_id を返します
                $same = \DB::table('baganriki_publication_intents')
                          ->where('snapshot_id', $snapshotId)
                          ->where('intent_hash', $intentHash)->lockForUpdate()->first();
                if ($same !== null && BaganrikiWorker::isExpectedSnapshot($snap)) {
                    try {
                        $stored = BaganrikiPublishedRead::replay($snap,$live,$same,$intentHash,$readRev);
                    } catch (\InvalidArgumentException $error) {
                        return ['ok'=>false,'code'=>$error->getMessage(),'reason'=>'保存済みの公開記録を照合できません',
                            'stored'=>['candidates'=>[],'proof'=>[]]];
                    }
                    return ['ok'=>true,'code'=>'ALREADY','reused'=>true,'publication_id'=>(string)$same->publication_id,'stored'=>$stored];
                }
                if ($same !== null && $same->publication_id !== null) {
                    return ['ok' => true, 'code' => 'ALREADY', 'reused' => true,
                            'publication_id' => (string) $same->publication_id];
                }
                if (BaganrikiWorker::isExpectedSnapshot($snap)) {
                    $saved = BaganrikiWorker::verifiedLiveRoster($snap, $live, true, $issue);
                    if ($saved === null) {
                        return BaganrikiWorker::liveStateFailure($issue);
                    }
                    $currentActive = array_values(array_diff($saved['roster'], $saved['withdrawn']));
                    if ($currentActive === []) {
                        return ['ok' => false, 'code' => 'ALL_CANCELLED', 'reason' => '全頭取消です'];
                    }
                    $usedActive = $activeNums;
                    sort($usedActive, SORT_NUMERIC);
                    if ($currentActive !== $usedActive) {
                        return ['ok' => false, 'code' => 'STALE', 'reason' => '公開候補を計算した後に出走馬が変わりました'];
                    }
                }
                // 読んだあとに版が変わっていたら、古い計算は保存しません
                if ($now !== $readRev) {
                    return ['ok' => false, 'code' => 'STALE',
                            'reason' => '読取のあとに版が変わりました（state '
                                      . $readRev['state'] . '→' . $now['state']
                                      . '／response ' . $readRev['response'] . '→'
                                      . $now['response'] . '／publication '
                                      . $readRev['publication'] . '→'
                                      . $now['publication'] . '）'];
                }
                // 期限と時刻をもう一度
                // @ANCHOR-20261023-NARU-RACELOCK  ロックした実状態の中で、レース正本の発走時刻も FOR UPDATE で読みます（なる 10/23 4通目 4章）
                $dl = BaganrikiWorker::deadlines($snap, $live, true, true);
                if (!$dl['ok']) {
                    return ['ok' => false, 'code' => (string) $dl['code'],
                            'reason' => (string) $dl['reason']];
                }
                $t2 = BaganrikiWorker::nowUs();              // commit直前時刻
                if ($t2 > $dl['publish']) {
                    return ['ok' => false, 'code' => 'DEADLINE_PASSED',
                            'reason' => 'commit直前の検査で公開期限を過ぎています'];
                }
                // @ANCHOR-20261025-NARU-PUBRACE  競合の試験口（なる 10/23 5通目 8章）
                //   レース正本を FOR UPDATE で読み、公開してよいと判定した「あと」に、別の接続が発走時刻を
                //   書き換えようとする場面を、試験から再現するための口です。本番の呼び出しはこれを渡しません。
                //   artisan からの実行のときだけ効きます。
                if (isset($opt['test_after_race_lock']) && is_callable($opt['test_after_race_lock'])
                    && app()->runningInConsole()) {
                    ($opt['test_after_race_lock'])($snapshotId);
                }
                // 検証開始 <= 検証完了 <= commit直前（1マイクロ秒でも逆行なら拒否）
                if (!($t0 <= $t1 && $t1 <= $t2)) {
                    return ['ok' => false, 'code' => 'CLOCK_BACKWARD',
                            'reason' => '時刻が逆行しています'];
                }

                if (BaganrikiWorker::isExpectedSnapshot($snap)) {
                    // The official start can change before the live-state revision is updated.
                    if ($readDeadlines['reply'] !== $dl['reply'] || $readDeadlines['publish'] !== $dl['publish']) {
                        return ['ok' => false, 'code' => 'STALE',
                                'window_changed' => true, 'reason' => '計算中に実効期限が変わりました。最新の締切で保存済みの回答を再判定してください'];
                    }
                    try { BaganrikiPublishedRead::checkPrevious($snap,$live); }
                    catch (\InvalidArgumentException $error) {
                        return ['ok'=>false,'code'=>$error->getMessage(),'reason'=>'直前の公開記録を照合できません',
                            'stored'=>['candidates'=>[],'proof'=>[]]];
                    }
                }
                $pubId = 'pub-' . $snap->race_id . '-' . $snap->generation . '-'
                       . substr($intentHash, 0, 12);
                $intentId = \DB::table('baganriki_publication_intents')->insertGetId([
                    'snapshot_id' => $snapshotId, 'job_id' => $jobId,
                    'intent_hash' => $intentHash,
                    'read_state_revision'       => $readRev['state'],
                    'read_response_revision'    => $readRev['response'],
                    'read_publication_revision' => $readRev['publication'],
                    'state' => 'COMMITTED', 'publication_id' => $pubId,
                    'created_at' => BaganrikiWorker::toDb($t2),
                ]);
                $j = static fn($v) => (string) json_encode($v,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION);
                $proofs = [];
                foreach ($cands as $c) {
                    if ($c['public_proof_hash'] !== null) {
                        $proofs[(string) $c['horse_no']] = $c['public_proof_hash'];
                    }
                }
                \DB::table('baganriki_publications')->insert([
                    'publication_id' => $pubId, 'snapshot_id' => $snapshotId,
                    'intent_id' => $intentId,
                    'published_at' => BaganrikiWorker::toDb($t2),
                    'prediction_target_count'  => array_sum($publicationData['prediction_quota'] ?? $quota),
                    'prediction_quota'         => $j($publicationData['prediction_quota'] ?? $quota),
                    'publication_target_count' => array_sum($quota),
                    'publication_quota'        => $j($quota),
                    'active_count'             => count($activeNums),
                    'candidates' => $j($cands),
                    'evidence'   => $j(['shortfalls' => $publicationData['shortfalls'],
                                        'proof_roles' => $publicationData['proof'],
                                        'cancelled' => $cancelled] + array_intersect_key($publicationData,array_flip(BaganrikiWorker::isExpectedSnapshot($snap)
                                            ? ['actual_quota','fill','role_issues','rank_source','rank_fallback','rank_notes','race_metrics','metrics_selection']   // @ANCHOR-20261116-METRICS-SELECTION
                                            : ['actual_quota','fill','role_issues']))),
                    'shortfalls' => $j($publicationData['shortfalls']),
                    'used_state_revision'    => $readRev['state'],
                    'used_response_revision' => $readRev['response'],
                    'public_proof_hash'      => $j($proofs),
                ]);
                \DB::table('baganriki_live_states')->where('snapshot_id', $snapshotId)
                  ->update(['publication_revision' => $readRev['publication'] + 1,
                            'latest_publication_id' => $pubId,
                            'updated_at' => BaganrikiWorker::toDb($t2)]);
                BaganrikiWorker::event((string) $snap->race_id, $snapshotId,
                    'PUBLISHED', $readRev['publication'], $readRev['publication'] + 1,
                    ['publication_id' => $pubId, 'intent_hash' => $intentHash,
                     'job_id' => $jobId, 'candidates' => array_column($cands, 'horse_no'),
                     'verify_started_at'  => BaganrikiTime::toJst($t0),
                     'verify_finished_at' => BaganrikiTime::toJst($t1),
                     'committed_at'       => BaganrikiTime::toJst($t2)],
                    $pubId, $t2);
                return ['ok' => true, 'code' => 'PUBLISHED', 'reused' => false,
                        'publication_id' => $pubId];
            });
        } catch (\Throwable $e) {
            if (BaganrikiWorker::isDup($e)) {
                // 別のintentが先に入った＝こちらは stale
                $out['code'] = 'STALE';
                $out['reason'] = 'ほかの処理が先に保存しました';
                return $out;
            }
            $out['code'] = 'PUBLISH_EXCEPTION'; $out['reason'] = $e->getMessage();
            \Log::error('[PREDICTION-PUB] 公開で例外', ['message' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine()]);
            return $out;
        }

        $out['ok']     = (bool) ($res['ok'] ?? false);
        $out['code']   = (string) ($res['code'] ?? '');
        $out['reason'] = $res['reason'] ?? null;
        $out['reused'] = (bool) ($res['reused'] ?? false);
        $out['publication_id'] = $res['publication_id'] ?? null;
        if ($res['window_changed'] ?? false) { $out['window_changed'] = true; }
        if ($out['ok'] && isset($selection)) {
            $out = array_replace($out, $selection);
        }
        return array_replace($out,$res['stored'] ?? []);
    }

    /**
     * @ANCHOR-20261020-PREDICTION-PUB  公開に使った原応答を特定します
     *   加点の証明は、<b>1本の完全な原応答</b>が公開に使う全頭と
     *   そろっているときだけ出します。初回と訂正が混ざった行は証明しません。
     *
     * @return array ['band_ranks','public_candidates','proof'=>[馬番=>hash]|null,
     *                'attempt_no'=>?int]
     */
    public function markResponseUsed(int $snapshotId, string $role, array $accepted): array
    {
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261020-PREDICTION-BANDKEEP
        // ══════════════════════════════════════════════════════════════
        //   【旧】`if (!$inspO['ok'] || $inspO['needs_fix']) { continue; }`
        //     1頭の observed_sum が誤っているだけで、<b>そのAIの全帯の
        //     順位をまとめて捨てて</b>いました。publishPrediction は帯順位に
        //     居ない馬を外すので、正常な馬も候補から消えます。
        //     片側しか応答が無ければ NO_RESPONSE、両側に小さな不備が
        //     あれば両側とも消えます。「normalへ残った」と「候補に使える」は
        //     別だ、というご指摘のとおりでした。
        //
        //   【いま】<b>帯ごとに</b>見ます。詳細設計書10.2の縮退の順です。
        //     ① その帯に順位の指摘が無く、馬の集合が凍結入力と合っていれば
        //        応答の順位をそのまま使う（RESPONSE）
        //     ② 使えないときは、同じroleの<b>初回の順位</b>を使う（PREVIOUS）
        //     ③ それも使えないときは、<b>検証済みの予備根拠順位</b>
        //        （evidence_band_ranks）を使う（EVIDENCE_RANK）
        //     ④ それも無ければ<b>明示縮退</b>。独立根拠群数の多い順 →
        //        馬番昇順（EXPLICIT_FALLBACK）。RANK_RECOVERY_FALLBACK を付けます
        //   非適格馬を戻したり、点数の下限を足したりはしていません。
        //   加点の証明（proof）は、これまでどおり<b>直すところが無い
        //   応答で全頭が一致したときだけ</b>です。緩めていません。
        $o = ['band_ranks' => [], 'public_candidates' => [], 'proof' => null,
              'attempt_no' => null,
              // どの帯の順位をどこから採ったか（監査・APIへ出します）
              'rank_source' => [], 'fallback' => [], 'rank_notes' => []];

        $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
        if ($snap === null) { return $o; }

        $rankTypes = null;
        try { $rankInput = BaganrikiFrozenInput::forInspection($snap, $role, $rankTypes); }
        catch (\InvalidArgumentException $e) { return $o; }
        $current = ($rankInput['prompt_version'] ?? null) === BaganrikiCandidateSelection::VERSION;
        if ($current && ($accepted['ok'] ?? false) !== true) return $o;
        $src1 = $current ? $rankInput : json_decode((string)$snap->input_json_1st,true);
        $partial = ($accepted['code'] ?? null) === 'FIX_PARTIAL';
        $merged = (array) ($accepted['merged'] ?? []);
        $frozenBand = []; $bandMembers = ['A' => [], 'B' => [], 'D' => []];
        foreach ((array) ($src1['runners'] ?? []) as $r) {
            $n = (int) ($r['horse_no'] ?? 0);
            $b = (string) ($r['band'] ?? '');
            if ($n <= 0 || !isset($bandMembers[$b])) { continue; }
            $frozenBand[$n] = $b;
            if (!$current || ($merged[$n]['eligibility'] ?? null) === 'ELIGIBLE') $bandMembers[$b][] = $n;
        }
        foreach ($bandMembers as $b => $v) { sort($v); $bandMembers[$b] = $v; }

        // 帯の順位として使えるか（集合が凍結入力と同じ・重複なし）
        $usable = static function ($list, array $want): bool {
            if (!is_array($list) || !array_is_list($list) || count(array_filter($list,'is_int')) !== count($list)) { return false; }
            $g = array_map('intval', array_values($list));
            if (count(array_unique($g)) !== count($g)) { return false; }
            $gs = $g; sort($gs);
            return ($gs === $want);
        };

        $mixed = ((array) ($accepted['applied'] ?? []) !== []
               || (array) ($accepted['rescued'] ?? []) !== [])
               && (string) ($accepted['code'] ?? '') === 'FIX_APPLIED';
        $try = ((string) ($accepted['code'] ?? '') === 'FIX_APPLIED')
             ? [BaganrikiWorker::FIX, BaganrikiWorker::FIRST]
             : [BaganrikiWorker::FIRST];

        // ── ① 使う応答（訂正があれば訂正、無ければ初回）──────────────
        $useNo = null; $useRes = null; $useInsp = null; $useRow = null;
        foreach ($try as $no) {
            $a = \DB::table('baganriki_ai_attempts')
                   ->where('snapshot_id', $snapshotId)->where('role', $role)
                   ->where('attempt_no', $no)
                   ->where('status', BaganrikiWorker::RECEIVED)->first();
            if ($a === null) { continue; }
            // @ANCHOR-20261020-PREDICTION-COMPLETE  未完了は順位にも使いません
            if ((int) ($a->complete ?? 0) !== 1) { continue; }
            $ex = BaganrikiResponseFrame::supportsVersion($src1['prompt_version'] ?? null)
                ? BaganrikiResponseFrame::extract((string) $a->raw_response)
                : BaganrikiResponse::extract((string) $a->raw_response);
            if (!$ex['ok']) { continue; }
            $insp = $this->inspectAiResponse($snapshotId, $role, (int) $no);
            if (!$insp['ok']) { continue; }
            if ($current && ($insp['response_hash'] ?? null) !== hash('sha256',(string)$a->raw_response)) continue;
            $useNo = (int) $no; $useRes = (array) $ex['json'];
            // E3・E5版：書き方を整えた後の返事を使います（検査・地図と同じ中身。候補の並びや指標の形の違いで、正しい部分まで捨てないため）
            if ($current && is_array($insp['reply_json'] ?? null)) $useRes = $insp['reply_json'];
            $useInsp = $insp; $useRow = $a;
            break;
        }
        if ($useNo === null) { return $o; }
        $wholeFix = $current && ($accepted['code'] ?? null) === 'FIX_APPLIED';
        if ($wholeFix && ($useNo !== BaganrikiWorker::FIX || ($useInsp['needs_fix'] ?? true)
            || ($accepted['rank_receipts'][1] ?? null) !== ($useRow->response_hash ?? null))) return $o;


        // ── ② 初回の順位（②の縮退と、対象外の帯の保持に使います）────
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-BANDSCOPE
        // ══════════════════════════════════════════════════════════════
        //   【本物の不具合でした】
        //   acceptCorrection は<b>馬の行しか比べていません。</b>
        //   band_ranks のような top の項目は比べていません。
        //   そのため「別の馬を直す訂正」が、初回では正常だった帯の
        //   並びを<b>黙って変えて</b>も通り、FIX_APPLIED なら
        //   その変えた並びが使われていました。
        //   【いま】訂正で直してよい帯（初回の repair_scope.rank_bands）
        //   だけを、訂正の並びで上書きできます。
        //   それ以外の帯は<b>初回の並びを保持</b>します。
        //   変えようとしていた場合は、そのことを記録に残します。
        $badForMerged = static function (array $candidate) use ($merged, $rankInput): array {
            $effective = $candidate;
            $effective['horses'] = array_values($merged);
            $diagnosis = BaganrikiDiagnose::run($effective, is_array($rankInput) ? $rankInput : []);
            $bad = self::invalidBandsOf($diagnosis['repair_scope']);
            // An old rank mode must not be relabeled by newer merged horse metadata.
            foreach ((array) ($candidate['horses'] ?? []) as $row) {
                if (!is_array($row) || !is_int($row['horse_no'] ?? null)) { continue; }
                $h = $merged[$row['horse_no']] ?? null;
                if (!is_array($h) || ($h['eligibility'] ?? null) !== 'ELIGIBLE') { continue; }
                if (($row['rank_basis'] ?? null) !== ($h['rank_basis'] ?? null)
                    && in_array($h['band'] ?? null, ['A','B','D'], true)) {
                    $bad[] = $h['band'];
                }
            }
            return array_values(array_unique($bad));
        };
        $prevRanks = [];
        $allowBands = [];      // 訂正で順位を変えてよい帯
        $prevSeen   = false;   // 初回の応答を読めたか
        if ($useNo === BaganrikiWorker::FIX) {
            $p = \DB::table('baganriki_ai_attempts')
                   ->where('snapshot_id', $snapshotId)->where('role', $role)
                   ->where('attempt_no', BaganrikiWorker::FIRST)
                   ->where('status', BaganrikiWorker::RECEIVED)->first();
            if ($p !== null && (int) ($p->complete ?? 0) === 1) {
                $pe = BaganrikiResponseFrame::supportsVersion($src1['prompt_version'] ?? null)
                    ? BaganrikiResponseFrame::extract((string) $p->raw_response)
                    : BaganrikiResponse::extract((string) $p->raw_response);
                if ($pe['ok']) {
                    $prevSeen = true;
                    $pi = $this->inspectAiResponse($snapshotId, $role,
                              BaganrikiWorker::FIRST);
                    if ($wholeFix && (!($pi['ok'] ?? false) || !($pi['needs_fix'] ?? false)
                        || ($accepted['rank_receipts'][0] ?? null) !== ($p->response_hash ?? null)
                        || ($pi['response_hash'] ?? null) !== hash('sha256',(string)$p->raw_response))) return $o;
                    if ($wholeFix) {
                        try {
                            BaganrikiRecovery::inspectCorrection($rankInput,$rankTypes,
                                ['raw'=>$p->raw_response,'hash'=>$p->response_hash],
                                ['raw'=>$useRow->raw_response,'hash'=>$useRow->response_hash],$pi['repair_scope']);
                        } catch (\InvalidArgumentException $ex) { return $o; }
                    }
                    $pBad = self::invalidBandsOf($pi['repair_scope'] ?? null);
                    // @ANCHOR-20261021-PREDICTION-BANDSCOPE  直してよい帯はこれだけ
                    $allowBands = $pBad;
                    $previousIncompatible = $badForMerged((array) $pe['json']);
                    foreach (['A', 'B', 'D'] as $b) {
                        if (in_array($b, $pBad, true)
                            || in_array($b, $previousIncompatible, true)) { continue; }
                        $lst = ((array) $pe['json'])['band_ranks'][$b] ?? null;
                        if ($usable($lst, $bandMembers[$b])) {
                            $prevRanks[$b] = array_map('intval', array_values($lst));
                        }
                    }
                }
            }
        }

        if ($wholeFix && !$prevSeen) return $o;
        $o['attempt_no'] = $useNo;

        // ── ③ 帯ごとに順位を決めます ─────────────────────────────────
        $badBands = self::invalidBandsOf($useInsp['repair_scope'] ?? null);
        $resBands = (array) ($useRes['band_ranks'] ?? []);
        $evBands  = (array) ($useRes['evidence_band_ranks'] ?? []);
        $mergedBad = $badForMerged($useRes);
        $badBands = array_values(array_unique(array_merge($badBands, $mergedBad)));
        foreach ($mergedBad as $b) {
            $o['rank_notes'][] = $b . '帯は初回と訂正を統合した評価と順位方式が合わないため、検証済み根拠順位等へ縮退します';
        }

        foreach (['A', 'B', 'D'] as $b) {
            if ($bandMembers[$b] === []) {
                if ($current) { $o['band_ranks'][$b] = []; $o['rank_source'][$b] = 'NONE'; }
                continue;
            }

            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-BANDSCOPE  訂正で直してよい帯だけ
            // ══════════════════════════════════════════════════════════
            //   訂正を使っていて、その帯が<b>訂正の対象ではない</b>のに
            //   初回の並びが使えるなら、初回の並びを保持します。
            //   訂正が違う並びを返していたら、そのことを残します
            //   （採用はしません）。
            if (!$wholeFix && $useNo === BaganrikiWorker::FIX && $prevSeen
                && !in_array($b, $allowBands, true)
                && isset($prevRanks[$b])) {
                $o['band_ranks'][$b]  = $prevRanks[$b];
                $o['rank_source'][$b] = 'PREVIOUS_KEPT';
                $rl = $resBands[$b] ?? null;
                if (is_array($rl)
                    && array_map('intval', array_values($rl)) !== $prevRanks[$b]) {
                    $o['rank_notes'][] = $b . '帯は訂正の対象ではないので初回の順位を'
                                       . '保持しました（訂正は違う順位を返していました）';
                } else {
                    $o['rank_notes'][] = $b . '帯は訂正の対象ではないので初回の順位を'
                                       . '保持しました';
                }
                continue;
            }
            // ①応答の順位（指摘が無く、集合が合っているとき）
            if (!in_array($b, $badBands, true)
                && $usable($resBands[$b] ?? null, $bandMembers[$b])) {
                $o['band_ranks'][$b]  = array_map('intval', array_values($resBands[$b]));
                $o['rank_source'][$b] = 'RESPONSE';
                continue;
            }
            // ②初回の順位
            if (isset($prevRanks[$b])) {
                $o['band_ranks'][$b]  = $prevRanks[$b];
                $o['rank_source'][$b] = 'PREVIOUS';
                $o['rank_notes'][]    = $b . '帯は訂正の順位を使えないので初回の順位を保持しました';
                continue;
            }
            // ③検証済みの予備根拠順位
            if ($usable($evBands[$b] ?? null, $bandMembers[$b])) {
                $o['band_ranks'][$b]  = array_map('intval', array_values($evBands[$b]));
                $o['rank_source'][$b] = 'EVIDENCE_RANK';
                $o['rank_notes'][]    = $b . '帯は根拠順位（evidence_band_ranks）へ切り替えました';
                continue;
            }
            // ④明示縮退（独立根拠群数の多い順 → 馬番昇順）
            $rows = [];
            foreach ($bandMembers[$b] as $n) {
                $h = $merged[$n] ?? null;
                if (!is_array($h)) { continue; }
                $sup = 0;
                foreach ((array) ($h['family_review'] ?? []) as $fam) {
                    if (($fam['status'] ?? '') === 'SUPPORTED') { $sup++; }
                }
                $rows[] = ['no' => $n, 'sup' => $sup];
            }
            if ($rows === []) {
                $o['rank_source'][$b] = 'NONE';
                $o['rank_notes'][]    = $b . '帯は使える順位が作れませんでした';
                continue;
            }
            usort($rows, static function ($x, $y) {
                if ($x['sup'] !== $y['sup']) { return $y['sup'] <=> $x['sup']; }
                return $x['no'] <=> $y['no'];
            });
            $o['band_ranks'][$b]  = array_column($rows, 'no');
            $o['rank_source'][$b] = 'EXPLICIT_FALLBACK';
            $o['fallback'][]      = $b;
            $o['rank_notes'][]    = $b . '帯は明示縮退（独立根拠群数の多い順→馬番昇順）です';
        }

        // ── ④ 公開リスト（表示の指摘が無いときだけ採ります）──────────
        $rs = (array) ($useInsp['repair_scope'] ?? []);
        $presOk = (((array) ($rs['presentation_horses'] ?? [])) === [])
               && (($rs['public_candidates'] ?? false) !== true);
        $sameRanks = true;
        if ($current) foreach (['A','B','D'] as $band) {
            if (($o['band_ranks'][$band] ?? []) !== ($resBands[$band] ?? null)) { $sameRanks = false; break; }
        }
        if ($presOk && !$partial && $sameRanks) {
            $o['public_candidates'] = (array) ($useRes['public_candidates'] ?? []);
        } else {
            $o['rank_notes'][] = '公開リストに指摘があるので原公開候補は採りません';
        }

        // ── ⑤ 加点の証明（ここは緩めません）──────────────────────────
        //   complete で、直すところが無く、公開に使う全頭がその応答の
        //   中身と一致しているときだけです。縮退した帯があるときも出しません。
        if (!$partial && $sameRanks && ($useInsp['needs_fix'] ?? true) === false
            && (array) ($useInsp['tidied'] ?? []) === []   // 整えた返事は原文と同じではないので、加点の証明は出しません
            && $o['fallback'] === []
            && !($mixed && $useNo === BaganrikiWorker::FIRST)) {
            $want = array_keys($merged);
            $got  = array_keys((array) $useInsp['normal']);
            sort($want); sort($got);
            if ($want === $got && $want !== []) {
                $same = true;
                // @ANCHOR-20261023-NARU-011  実効の帯順位が原応答と違えば、加点の証明は出しません（011再精査2〜3版）
                foreach (['A', 'B', 'D'] as $band) {
                    if (array_values((array) ($o['band_ranks'][$band] ?? []))
                        !== array_values((array) ($resBands[$band] ?? []))) {
                        $same = false; break;
                    }
                }
                foreach ($want as $num) {
                    if (!BaganrikiResponse::sameHorseRow((array) ($merged[$num] ?? []),
                                                         (array) ($useInsp['normal'][$num] ?? []))) {
                        $same = false; break;
                    }
                }
                if ($same) {
                    $proof = [];
                    foreach ($want as $num) {
                        $proof[(int) $num] = (string) $useRow->response_hash;
                    }
                    $o['proof'] = $proof;
                    if ($current) $o['public_proof_hash'] = (string) $useRow->response_hash;
                }
            }
        }
        if ($current && $role==='1st' && BaganrikiRaceMetrics::enabled($rankInput)) {
            // @ANCHOR-20261109-PREDICTION-REPLY-TIDY  地図（$useInsp['map']）は整えた後の返事のものなので、中身も整えた後の返事を使います
            $metricRes = is_array($useInsp['reply_json'] ?? null) ? $useInsp['reply_json'] : $useRes;
            $o['race_metrics']=BaganrikiRaceMetrics::fromResponse($rankInput,$metricRes,(string)$useRow->response_hash,$useInsp['map']);
            // Partial horse recovery keeps the original ranks. Display repairs have their own
            // receipt binding and must not silently fall back to those original, invalid fields.
            if ($partial && !empty($accepted['metric_repairs'])) {
                $metricFix=\DB::table('baganriki_ai_attempts')->where('snapshot_id',$snapshotId)
                    ->where('role',$role)->where('attempt_no',BaganrikiWorker::FIX)
                    ->where('status',BaganrikiWorker::RECEIVED)->first();
                if ($metricFix!==null && ($accepted['metric_receipts']??null)===[$useRow->response_hash,$metricFix->response_hash]) {
                    $metricInspection=$this->inspectAiResponse($snapshotId,$role,BaganrikiWorker::FIX);
                    if (($metricInspection['ok']??false) && ($metricInspection['complete']??false)===true &&
                        ($metricInspection['response_hash']??null)===$metricFix->response_hash) {
                        try {
                            $fixedMetric=BaganrikiCandidateSelection::parseOriginal($metricFix->raw_response,$metricFix->response_hash);
                            BaganrikiRaceMetrics::checkCorrection($rankInput,$useRes,$fixedMetric['json'],$useInsp['map'],$fixedMetric['map']);
                            $metricIssues=BaganrikiRaceMetrics::issues($rankInput,$fixedMetric['json'],$fixedMetric['map']);
                            if (!isset($metricIssues['race_metrics']))
                                $o['race_metrics']=BaganrikiRaceMetrics::fromResponse($rankInput,$fixedMetric['json'],$metricFix->response_hash,$fixedMetric['map']);
                        } catch (\InvalidArgumentException $e) { /* Keep verified initial display values. */ }
                    }
                }
            }
        }
        return $o;
    }

    // ##########################################################################
    // ## AIへの送信・訂正・巡回・Job・実状態の反映
    // ##########################################################################

    // public function callPredictionAi(string $role, string $system, string $user,
    // @ANCHOR-20261023-NARU-BUDGET2  $snapshotId を渡すと、予算の待ちのあとで期限を確かめ直します
    public function callPredictionAi(string $role, string $system, string $user,
                               int $timeout, ?int $snapshotId = null): array
    {
        $callStartUs = BaganrikiWorker::nowUs();
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-R122-USAGE  実モデルと使用量を残します（010の7章⑤）
        // ══════════════════════════════════════════════════════════════
        //   ご指摘：
        //     「12個のrace_idへの限定、非公開の読取経路、usageと実モデルの
        //       記録、初回24回／最大48回の費用を示すこと。」
        //     「2ndは添付コード上でdeepseek-chat、max_tokens=8,192です。
        //       …2nd自身のトークナイザ、実モデル、finish_reason、usage、
        //       JSON検証結果を揃えてください。」
        //   応答の usage と、API が名乗ったモデル名をそのまま残します。
        //   <b>こちらで決めた名前ではなく、応答に書かれていたもの</b>です。
        //   入れる場所は baganriki_audit_events です（新しい表は作りません）。
        $out = ['ok' => false, 'raw' => '', 'complete' => false,
                'code' => null, 'reason' => null,
                'usage' => null, 'model' => null, 'stop' => null,
                'wire_encoding' => null, 'wire_bytes' => null, 'wire_sha256' => null];
        // @ANCHOR-20261023-COMPACT  送信本文を詰めます（BAGANRIKI_PREDICTION_COMPACT=1 のときだけ。既定は詰めない）
        //   凍結した本文（snapshot_hash の元）と検証は元のままです。ここで変えるのは、送る文字列だけです。
        $ju = json_decode($user, true);
        $input = $ju['input'] ?? $ju;
        $w2Input = class_exists(BaganrikiCandidateSelection::class)
            && ($input['prompt_version'] ?? null) === BaganrikiCandidateSelection::VERSION ? $input : null;
        if ($w2Input !== null && $snapshotId === null) {
            $out['code'] = 'NO_SNAPSHOT';
            $out['reason'] = 'E3・E5版の送信には凍結入力のIDが必要です';
            return $out;
        }
        // E3・E5版のsystemとuserはprepareSendが費用と原文を既に照合している。
        // 予想の版（E3・E5を使う版）の送信文は、送る前の準備で圧縮・付録・指標の頼み書きまで確定させ、
        //   費用と原文を照合済みです。ここでは何も足しません（足すと照合した原文と変わります）。
        $cw = $w2Input === null ? self::compactUserForSend($user, $role) : ['applied' => false];
        if ($cw['applied']) {
            $user   = $cw['user'];
            $system = $system . "\n\n" . (string) $cw['addendum'];
            $out['wire_encoding'] = (string) $cw['encoding'];
            $out['compact_addendum_version'] = (string) $cw['addendum_version'];
            $out['wire_bytes']    = strlen($user);
            $out['wire_sha256']   = hash('sha256', $user);
        }
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261107-PREDICTION-PROMPTFILE  レースごとのプロンプトをファイルに残す
        // ══════════════════════════════════════════════════════════════
        //   旧方式は public/prompt・public/prompt_2nd にレースごとのファイルを書いていました。
        //   新方式は DB にだけ残していたので、11/7 はファイルができませんでした。
        //   旧方式と同じ場所・同じファイル名で、実際に送った system と user をそのまま書きます。
        //   ・書けなくても送信は止めません（失敗はログに残すだけ）
        //   ・試験用の場コード（90〜99）は書きません
        // @ANCHOR-20261108-PREDICTION-METRICS  1st にだけ、3指標の頼み書きを末尾に足す
        if ($w2Input === null && $role === '1st' && self::predictionMetricsEnabled()) {
            $system = $system . "\n\n" . self::PREDICTION_METRICS_ADDENDUM;
            $out['metrics_addendum_version'] = self::PREDICTION_METRICS_ADDENDUM_VERSION;
        }
        self::writePredictionPromptFile($role, $system, $user);
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261023-R122-MODELCFG  モデルと出力上限を .env で変えられるようにします
        // ══════════════════════════════════════════════════════════════
        //   【10/23 の実AI 18頭実測で分かったこと（003 P-07・AC05）】
        //   ・1st：本文 878,885 バイトで claude-haiku-4-5 の受付上限（20万トークン）を
        //     超え、API が 400 を返しました（10/21 の count_tokens 実測でも
        //     18頭は 4.5 で約26万トークン＝上限超え、5.5 なら上限100万で収まる）。
        //   ・2nd：max_tokens=8,192 で出力が途中で切れました（finish_reason=length）。
        //     18頭ぶんの正常出力は約1.2万トークン（配布の正常出力14本の実測）。
        //   【いま】次の .env で変えられます。<b>書かなければ今までと同じ</b>です。
        //     BAGANRIKI_PREDICTION_1ST_MODEL        1st のモデル（空なら AnthropicService の既定）
        //     BAGANRIKI_PREDICTION_1ST_MAX_TOKENS   1st の出力上限（既定 32768）
        //     BAGANRIKI_PREDICTION_2ND_MODEL        2nd のモデル（既定 deepseek-chat）
        //     BAGANRIKI_PREDICTION_2ND_MAX_TOKENS   2nd の出力上限（既定 8192）
        //   旧経路（旧1st・旧2nd）の送信には一切効きません。新方式 の送信口だけです。
        $m1 = trim((string) env('BAGANRIKI_PREDICTION_1ST_MODEL', ''));
        // @ANCHOR-20261023-NARU-FAKEAI  worker の受入試験用の偽AI（なる 10/23 8-3）
        //   BAGANRIKI_PREDICTION_FAKE_AI=1 かつ、送る本文の race_id が「試験用の場コード 90〜99」の
        //   ときだけ、外部へは送らずに保存済みの応答を返します。実在のレース（場 01〜10）には
        //   旗に関わらず一切効きません。課金は0です。
        $fake = self::fakeAiResponse($role, $user, $timeout);
        if ($fake !== null) { return $fake; }
        $t1 = (int) env('BAGANRIKI_PREDICTION_1ST_MAX_TOKENS', 32768);
        $m2 = trim((string) env('BAGANRIKI_PREDICTION_2ND_MODEL', ''));
        // if ($m2 === '') { $m2 = 'deepseek-chat'; }   // 旧
        // @ANCHOR-20261023-NARU-2NDMODEL  DeepSeek の現行の公式名は deepseek-flash です（応答のモデル名も deepseek-flash）。
        //   送信名と応答のモデル名をそろえるため、既定を deepseek-flash にしました（なる 10/23 2通目 6章）。
        if ($m2 === '') { $m2 = 'deepseek-flash'; }
        // @ANCHOR-20261023-NARU-2NDMODEL  2nd の思考（thinking）を明示できるようにします
        //   DeepSeek Flash は既定で思考が有効（effort=high）です。書かなければ何も送らず、既定のままです。
        //     BAGANRIKI_PREDICTION_2ND_THINKING  空＝送らない（既定＝有効）／enabled／disabled
        //     BAGANRIKI_PREDICTION_2ND_EFFORT    空＝送らない（既定＝high）／low／high／max
        $th2 = strtolower(trim((string) env('BAGANRIKI_PREDICTION_2ND_THINKING', '')));
        $ef2 = strtolower(trim((string) env('BAGANRIKI_PREDICTION_2ND_EFFORT', '')));
        $t2 = (int) env('BAGANRIKI_PREDICTION_2ND_MAX_TOKENS', 8192);
        // @ANCHOR-20261023-R122-THINKING  1st の「考える機能」と effort（モデル指定のときだけ）
        //   claude-haiku-5-5 は考える機能が既定で入り、出力上限に数えられます。
        //   10/23 の2回目の実測では 32,768 トークンを全部考える側に使い、本文が0バイトでした。
        //     BAGANRIKI_PREDICTION_1ST_THINKING  空＝送らない（モデルの既定）／disabled／adaptive
        //     BAGANRIKI_PREDICTION_1ST_EFFORT    空＝送らない（モデルの既定）／low／medium／high／xhigh／max
        //   disabled は effort が high 以下のときだけ使えます（公式の説明）。
        $th1 = strtolower(trim((string) env('BAGANRIKI_PREDICTION_1ST_THINKING', '')));
        $ef1 = strtolower(trim((string) env('BAGANRIKI_PREDICTION_1ST_EFFORT', '')));
        if ($t1 <= 0) { $t1 = 32768; }
        if ($t2 <= 0) { $t2 = 8192; }
        // @ANCHOR-20261023-NARU-BUDGET  送る前に予算を確かめ、最悪額を予約します
        $rid0 = '';
        if (is_array($ju)) { $rid0 = (string) ($ju['race_id'] ?? ($ju['input']['race_id'] ?? ($ju['fix_request']['race_id'] ?? ''))); }
        $bModel = ($role === '2nd') ? $m2 : (($m1 !== '') ? $m1 : 'claude-haiku-4-5');
        $bg = self::budgetReserve($role, $bModel, $rid0, strlen($system) + strlen($user),
                                  ($role === '2nd') ? $t2 : $t1);
        if (!$bg['ok']) {
            // $out['code'] = 'BUDGET_EXCEEDED';   // 旧（理由の種類を分けました）
            $out['code'] = (string) ($bg['code'] ?? 'BUDGET_EXCEEDED');
            $out['reason'] = (string) $bg['reason'];
            return $out;
        }
        $out['budget_hold'] = $bg['hold'];
        $out['budget_worst_usd'] = ($bg['worst'] === null) ? null : round((float) $bg['worst'], 6);
        // @ANCHOR-20261023-NARU-BUDGET2  予算の待ち（最大10秒）と圧縮のあとで、期限と待ち時間を確かめ直します
        //   （なる 10/23 4通目 4-2）。期限の直前・過ぎていれば送らず、予約は0ドルで精算します。
        $replyDeadline = null;
        if ($snapshotId !== null) {
            $dlB = self::finalAiSendWindow($snapshotId, $role, $w2Input, isset($ju['fix_request']) ? 1 : 0,
                                           hash('sha256',$system), hash('sha256',$user));
            // 試験用：読取が遅れた場面を作るための差し込み口（本番では null のまま何もしません）
            if (self::$testAfterDeadlineRead !== null) { (self::$testAfterDeadlineRead)(); }
            if (!$dlB['ok']) {
                self::budgetRelease($bg['hold'], $rid0, '送信直前の状態を確認できないか、送信対象外になりました');
                $out['code'] = (string) $dlB['code'];
                if ($out['code'] === 'OUTBOUND_MISMATCH') { $out['skip_attempt_update'] = true; }
                $out['reason'] = (string) $dlB['reason'];
                return $out;
            }
            $replyDeadline = (int) $dlB['reply'];
        }
        // 正本・取消の取得待ちも、呼び出しと応答期限の両方の残り時間から引く。
        $nowB = BaganrikiWorker::nowUs();
        $elapsedUs = $nowB - $callStartUs;
        $timeout = $elapsedUs < 0 ? 0 : $timeout - (int) ceil($elapsedUs / 1000000);
        if ($replyDeadline !== null) {
            $timeout = min($timeout, intdiv(max(0, $replyDeadline - $nowB), 1000000));
        }
        if ($timeout < 5) {
            self::budgetRelease($bg['hold'], $rid0, '期限の直前なので送りませんでした');
            $out['code'] = 'DEADLINE_AFTER_WAIT';
            $out['reason'] = '予算の待ち・圧縮のあと、応答期限までの残りが5秒未満なので送りません';
            return $out;
        }
        $out['requested_model'] = $bModel;
        $out['sent_at_us']      = $nowB;
        $out['sent_at']         = BaganrikiTime::toJst($nowB);
        $out['system_sha256']   = hash('sha256', $system);
        $out['user_sha256']     = hash('sha256', $user);
        $out['timeout_sec']     = $timeout;
        $out['settings'] = ($role === '2nd')
            ? ['model' => $m2, 'max_tokens' => $t2, 'thinking' => ($th2 === '' ? '送らない（既定＝有効）' : $th2),
               'effort' => ($ef2 === '' ? '送らない（既定＝high）' : $ef2)]
            : ['model' => ($m1 === '' ? 'AnthropicService の既定' : $m1), 'max_tokens' => $t1,
               'thinking' => ($th1 === '' ? '送らない' : $th1), 'effort' => ($ef1 === '' ? '送らない' : $ef1),
               'prompt_cache' => self::promptCacheEnabled()];
        try {
            if ($role === '2nd') {
                // @ANCHOR-20261110-PREDICTION-2ND-RESEND  2nd も、評価を投げ出したときだけ同じ内容をもう1回送れるよう、送信を関数にまとめました（送る中身は変えていません）
                $send2 = function (int $maxTokens, int $sec) use ($m2, $system, $user, $th2, $ef2) {
                return \Illuminate\Support\Facades\Http::timeout($sec)
                    ->withHeaders(['Authorization' => 'Bearer ' . env('DEEPSEEK_API_KEY')])
                    ->post('https://api.deepseek.com/v1/chat/completions', array_merge([
                        'model'    => $m2,
                        'messages' => [
                            ['role' => 'system', 'content' => $system],
                            ['role' => 'user',   'content' => $user],
                        ],
                        // 'max_tokens' => $t2,   // 旧（送り直しでは、1回目に使った分を引いた上限にします）
                        'max_tokens' => $maxTokens,
                    ],
                    // @ANCHOR-20261023-NARU-2NDMODEL  書いたときだけ送ります
                    in_array($th2, ['enabled', 'disabled'], true) ? ['thinking' => ['type' => $th2]] : [],
                    in_array($ef2, ['low', 'high', 'max'], true) ? ['reasoning_effort' => $ef2] : []));
                };
                $res = $send2($t2, $timeout);
                // @ANCHOR-20261110-PREDICTION-ARRIVED  よっしー11/10夜2 ①：返事が届いた直後の時刻を、その返事の原文のハッシュと組で持ちます（受領の期限判定は、使う返事が届いた時刻で行う）
                self::markArrived($out, $res, '2nd');
                // $res = $this->resendAbandoned('2nd', $res, $send2, $w2Input, $t2, $timeout, $nowB, $replyDeadline, $out, $m2);   // 旧
                $res = $this->resendAbandoned('2nd', $res, $send2, $w2Input, $t2, $timeout, $nowB, $replyDeadline, $out, $m2, (int) ($snapshotId ?? 0));
                if ($res->failed()) {
                    $out['code']   = 'API_ERROR';
                    // @ANCHOR-20261023-R122-MODELCFG  理由が読めるよう本文の先頭も残します
                    $out['reason'] = '2nd AI の API エラー status=' . $res->status()
                                   . ' ' . mb_substr((string) $res->body(), 0, 300);
                    return $out;
                }
                $j   = $res->json();
                $content = $j['choices'][0]['message']['content'] ?? null;
                // E3・E5版の verifies and archives the exact provider text, including its outer bytes.
                $raw = $w2Input === null ? trim((string) ($content ?? '')) : (is_string($content) ? $content : '');
                $fin = $j['choices'][0]['finish_reason'] ?? null;
                $out['ok']       = true;
                $out['code']     = 'OK';
                $out['raw']      = $raw;
                $out['complete'] = (($fin === 'stop') && trim($raw) !== '');   // @ANCHOR-20261023-NARU-011  終了理由が無い応答は未完了（011再精査）
                // @ANCHOR-20261021-R122-USAGE  応答が名乗ったモデルと使用量
                $out['model'] = (string) ($j['model'] ?? '');
                $out['stop']  = ($fin === null) ? null : (string) $fin;
                $out['usage'] = is_array($j['usage'] ?? null)
                              ? (array) $j['usage'] : null;
                self::sumResentUsage($out);   // @ANCHOR-20261110-PREDICTION-2ND-RESEND  送り直したときは、送信ごとの使用量を1回ずつ足す
                return $out;
            }
            if ($m1 === '') {
                $res = $this->anthropic->sendWithRetry(
                    prompt:      $user,
                    system:      $system,
                    maxAttempts: 1,     // 仕様: 自動再試行禁止。2以上にしてはいけない
                    sleepBase:   2,
                    timeout:     $timeout,
                );
            } else {
                // @ANCHOR-20261023-R122-MODELCFG  モデルを指定したときだけ直接送ります
                //   AnthropicService はモデルが定数で、旧経路も同じ物を使うので触りません。
                //   送り方（1回だけ・自動再試行なし・同じ鍵・同じ版）は AnthropicService と同じです。
                // @ANCHOR-20261110-PREDICTION-1ST-RESEND  同じ内容を、もう1回だけ送れるように関数にまとめました（送る中身は変えていません）
                $send1 = function (int $maxTokens, int $sec) use ($m1, $system, $user, $th1, $ef1) {
                return \Illuminate\Support\Facades\Http::withHeaders([
                        'x-api-key'         => config('services.anthropic.api_key'),
                        'anthropic-version' => '2023-06-01',
                        'content-type'      => 'application/json',
                    ])
                    ->timeout($sec)
                    ->post('https://api.anthropic.com/v1/messages', array_merge([
                        'model'      => $m1,
                        // 'max_tokens' => $t1,   // 旧（送り直しでは、1回目に使った分を引いた上限にします）
                        'max_tokens' => $maxTokens,
                        // @ANCHOR-20261023-COST-PROMPTCACHE  指示文はどのレースでも同じなので、
                        //   AI のキャッシュを使います（2回目以降、その部分の料金が約1割）。
                        //   BAGANRIKI_PREDICTION_PROMPT_CACHE=0 で止められます。
                        // 'system'     => $system,   // 旧
                        'system'     => self::promptCacheEnabled()
                            ? [['type' => 'text', 'text' => $system,
                                'cache_control' => ['type' => 'ephemeral']]]
                            : $system,
                        'messages'   => [['role' => 'user', 'content' => $user]],
                    ],
                    // @ANCHOR-20261023-R122-THINKING  書いたときだけ送ります
                    in_array($th1, ['disabled', 'adaptive'], true)
                        ? ['thinking' => ['type' => $th1]] : [],
                    in_array($ef1, ['low', 'medium', 'high', 'xhigh', 'max'], true)
                        ? ['output_config' => ['effort' => $ef1]] : []));
                };
                $res = $send1($t1, $timeout);
                self::markArrived($out, $res, '1st');   // @ANCHOR-20261110-PREDICTION-ARRIVED
                // $res = $this->resendAbandonedFirst($res, $send1, $w2Input, $t1, $timeout, $nowB, $replyDeadline, $out);   // 旧
                // $res = $this->resendAbandonedFirst($res, $send1, $w2Input, $t1, $timeout, $nowB, $replyDeadline, $out, $m1);   // 旧（1st だけ）
                // $res = $this->resendAbandoned('1st', $res, $send1, $w2Input, $t1, $timeout, $nowB, $replyDeadline, $out, $m1);   // 旧
                $res = $this->resendAbandoned('1st', $res, $send1, $w2Input, $t1, $timeout, $nowB, $replyDeadline, $out, $m1, (int) ($snapshotId ?? 0));
            }
            if ($res->failed()) {
                $out['code']   = 'API_ERROR';
                // @ANCHOR-20261023-R122-MODELCFG  理由が読めるよう本文の先頭も残します
                $out['reason'] = '1st AI の API エラー status=' . $res->status()
                               . ' ' . mb_substr((string) $res->body(), 0, 300);
                return $out;
            }
            // ══════════════════════════════════════════════════════════
            // @ANCHOR-20261023-R122-THINKING  本文は type=text の塊を全部つなぎます
            // ══════════════════════════════════════════════════════════
            //   AnthropicService::extractText は content の<b>先頭</b>だけを読みます。
            //   考える機能が入っていると先頭は thinking の塊なので、本文があっても
            //   空になります。text の塊だけを順につなぎます。
            //   【旧】$raw  = trim((string) $this->anthropic->extractText($res));
            $raw = '';
            $blocks = $res->json('content');
            $validBlocks = $w2Input === null || (is_array($blocks)
                && BaganrikiExpectedPacket::isList($blocks));
            foreach ((array) $blocks as $blk) {
                if ($w2Input !== null && (!is_array($blk) || !is_string($blk['type'] ?? null)
                    || $blk['type'] === '' || ($blk['type'] === 'text' && !is_string($blk['text'] ?? null)))) {
                    $validBlocks = false;
                    continue;
                }
                if (is_array($blk) && ($blk['type'] ?? '') === 'text') {
                    $raw .= (string) ($blk['text'] ?? '');
                }
            }
            if ($w2Input === null) { $raw = trim($raw); }
            $stop = $res->json('stop_reason');
            $out['ok']       = true;
            $out['code']     = 'OK';
            $out['raw']      = $raw;
            $out['complete'] = (($stop === 'end_turn') && $validBlocks && trim($raw) !== '');   // @ANCHOR-20261023-NARU-011  終了理由が無い応答は未完了（011再精査）
            // @ANCHOR-20261021-R122-USAGE  応答が名乗ったモデルと使用量
            $out['model'] = (string) ($res->json('model') ?? '');
            $out['stop']  = ($stop === null) ? null : (string) $stop;
            $u = $res->json('usage');
            $out['usage'] = is_array($u) ? (array) $u : null;
            // @ANCHOR-20261110-PREDICTION-1ST-RESEND  送り直したときは、1回目の使用量も足します（料金の記録を実際の額にするため）
            // if (isset($out['first_send_usage']) && is_array($out['first_send_usage']) && is_array($out['usage'])) {
            //     $out['usage'] = self::addUsage($out['usage'], $out['first_send_usage']);
            // }   // 旧（なる11/10 7章：2回目の送信が例外のとき、1回目の使用量を二重に足していた）
            //   送り直したときは、送信ごとに記録した使用量（resent.sends）を、確かめられたものだけ1回ずつ足します。
            //   2回目の結果が分からない（通信の例外など）ときは、使用量を null（費用不明）にし、予算は予約額（最悪額）のまま数えます。
            self::sumResentUsage($out);
            return $out;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // 届いたかどうか分からないので「結果不明」にします（再送しません）
            $out['code']   = 'CONNECTION';
            $out['reason'] = $e->getMessage();
            return $out;
        } catch (\Throwable $e) {
            $out['code']   = 'EXCEPTION';
            $out['reason'] = $e->getMessage();
            return $out;
        }
    }

    /**
     * @ANCHOR-20261110-PREDICTION-1ST-RESEND  1st が評価を投げ出したときだけ、同じ内容をもう1回送ります（豊田さん 11/10）
     *   送り直すのは、次をすべて満たすときの1回だけです（それ以外は今までどおり1回目の返事を使います）。
     *     ・予想の版（E3・E5版）の 1st で、1回目が最後まで返ってきた（end_turn）
     *     ・1回目が「投げ出し」：次のどれか（よっしー・なる 11/10 で広げました）
     *         - 出走馬の中で書いた馬（馬番の重複・出走にない馬番は数えない）が出走頭数に足りない（読めない返事は0頭）
     *         - 根拠（evidence）を1件以上書いた馬が、出走頭数の3分の1に満たない（行はあるが中身が空。11/10 京都6R は18頭とも空）
     *         - 写すだけの欄を書き換えた（selection_enabled・role_cap が凍結入力と違う。11/10 東京6R）
     *         - 波乱度（race_metrics.wave_level）を null にした（断層の有無にかかわらず出せる指標。11/10 京都6R・京都5R）
     *     ・時間：返事の締め切りまで、1回目にかかった秒数＋20秒以上、かつ60秒以上残っている
     *       （【旧】1回目が30秒以内のときだけ。なる11/10「30秒超なら再送しない条件を、残り時間との関係で」）
     *     ・費用：1回目の実際の額＋2回目の最悪額（同じ入力・出力上限は1回目の出力を引いた分）が、この呼び出しの予約額以内
     *   2回目を使うのは、2回目の方が良いとき（根拠を書いた馬の数が多い。同じなら書いた馬の数が多い）だけです。
     *   送信ごとの使用量・秒数・判定・使わなかった返事の原文を resent.sends に残し、使用量は確かめられたものだけを1回ずつ足します。
     */
    // 11/10 夜：2nd にも使えるよう、役割（$role）を受け取る形にしました（旧名 resendAbandonedFirst）。
    //   2nd（DeepSeek）は、返事の本文が choices[0].message.content、最後まで返ったかは finish_reason=stop、
    //   波乱度は 1st だけが出すので 2nd では見ません。使用量の形も DeepSeek のものです。
    private function resendAbandoned(string $role, $res, callable $send1, ?array $w2Input, int $t1, int $timeout,
                                     int $sentUs, ?int $replyDeadline, array &$out, string $model = '', int $snapshotId = 0)
    {
        $now = BaganrikiWorker::nowUs();
        try {
            if ($w2Input === null || $res->failed() || !self::replyEnded($role, $res)) { return $res; }
            $nums = [];
            foreach ((array) ($w2Input['runners'] ?? []) as $r) {
                if (is_array($r) && is_int($r['horse_no'] ?? null)) { $nums[$r['horse_no']] = true; }
            }
            $runners = count($nums);
            if ($runners < 2) { return $res; }
            $q1 = self::replyQuality($res, $nums, $w2Input, $role);
            $why = self::abandonedWhy($q1, $runners);
            if ($why === null) { return $res; }
            $sec1 = ($now - $sentUs) / 1000000;
            $left = $timeout - (int) ceil($sec1);
            if ($replyDeadline !== null) { $left = min($left, intdiv(max(0, $replyDeadline - $now), 1000000)); }
            $u1 = is_array($res->json('usage')) ? (array) $res->json('usage') : null;
            $base = ['first_seconds' => round($sec1, 1), 'first' => $q1, 'runners' => $runners, 'why' => $why];
            // if ($now - $sentUs > 30000000) { return $res; }   // 旧（30秒以内のときだけ）
            if ($left < 60 || $left < (int) ceil($sec1) + 20) {
                $out['resent'] = $base + ['done' => false, 'skip' => '締め切りまでの残り ' . $left . '秒では、送り直しが間に合わない'];
                return $res;
            }
            $used = (int) ($res->json($role === '2nd' ? 'usage.completion_tokens' : 'usage.output_tokens') ?? 0);
            $max2 = $t1 - $used;
            if ($max2 < 4096) { $out['resent'] = $base + ['done' => false, 'skip' => '出力の上限が残っていない']; return $res; }
            // 費用の上限：1回目の実額＋2回目の最悪額が、予約額（budget_worst_usd）を超えないときだけ送ります
            $worst = $out['budget_worst_usd'] ?? null;
            if (is_numeric($worst) && $model !== '') {
                $c1 = AiController::costFromUsage($role, $model, $u1, 'conservative');
                $t = AiController::usageTokens($role, $u1);
                if ($role === '2nd') {
                    // 2回目は同じ入力を、キャッシュなしの単価で数える（高い側）
                    $c2 = ($t === null) ? null : AiController::costFromUsage('2nd', $model,
                              ['prompt_tokens' => $t['hit'] + $t['miss'], 'prompt_cache_hit_tokens' => 0, 'prompt_cache_miss_tokens' => $t['hit'] + $t['miss'],
                               'completion_tokens' => $max2], 'conservative');
                } else {
                $c2 = ($t === null) ? null : AiController::costFromUsage('1st', $model,
                          ['input_tokens' => $t['in'] + $t['cw'] + $t['cr'], 'output_tokens' => $max2], 'conservative');
                }
                if ($c1 === null || $c2 === null || $c1 + $c2 > (float) $worst) {
                    $out['resent'] = $base + ['done' => false, 'skip' => '送り直すと予約額を超えるおそれがある',
                                              'cost_first' => $c1, 'cost_second_worst' => $c2, 'reserved' => (float) $worst];
                    return $res;
                }
            }
            $out['resent'] = $base + ['done' => true, 'reason' => '1回目が ' . $why . ' だったため、同じ内容をもう1回送りました',
                'sends' => [['n' => 1, 'status' => 'RECEIVED', 'seconds' => round($sec1, 1), 'usage' => $u1, 'quality' => $q1]]];
            \Log::info('[PREDICTION-RESEND] ' . $role . ' が評価を投げ出したので、もう1回送ります', $base);
            $t2s = BaganrikiWorker::nowUs();
            try {
                $res2 = $send1($max2, $left);
                $arrived2Us = BaganrikiWorker::nowUs();   // @ANCHOR-20261110-PREDICTION-ARRIVED  2回目が届いた直後の時刻
            } catch (\Throwable $e) {
                // 届いたか・料金がかかったかが分からない：使用量は不明として残します（呼び出し側で usage=null＝予約額のまま）
                $out['resent']['sends'][] = ['n' => 2, 'status' => 'UNKNOWN', 'seconds' => round((BaganrikiWorker::nowUs() - $t2s) / 1000000, 1),
                                             'usage' => null, 'error' => mb_substr($e->getMessage(), 0, 300)];
                $out['resent']['used'] = false;
                return $res;
            }
            $sec2 = round((BaganrikiWorker::nowUs() - $t2s) / 1000000, 1);
            if ($res2->failed()) {
                $u2 = is_array($res2->json('usage')) ? (array) $res2->json('usage') : null;
                $out['resent']['sends'][] = ['n' => 2, 'status' => ($u2 === null ? 'HTTP_ERROR_NO_USAGE' : 'HTTP_ERROR'), 'http' => $res2->status(),
                                             'seconds' => $sec2, 'usage' => $u2, 'body' => mb_substr((string) $res2->body(), 0, 500)];
                $out['resent']['used'] = false;
                return $res;
            }
            $u2 = is_array($res2->json('usage')) ? (array) $res2->json('usage') : null;
            $q2 = self::replyEnded($role, $res2) ? self::replyQuality($res2, $nums, $w2Input, $role) : null;
            $q1c = $q1 ?? ['horses' => 0, 'evidence_horses' => 0, 'changed_fixed' => true];   // 1回目が読めなければ0頭として比べる
            // $better = $q2 !== null && !$q2['changed_fixed']
            //           && ((($q2['evidence_horses'] <=> $q1c['evidence_horses']) ?: ($q2['horses'] <=> $q1c['horses'])
            //                ?: ((int) !($q2['wave_null'] ?? true) <=> (int) !($q1c['wave_null'] ?? true))) > 0 || $q1c['changed_fixed']);   // 旧（根拠を「書いた」馬の数で比べていた）
            $rawBetter = $q2 !== null && !$q2['changed_fixed']
                      && ((($q2['evidence_horses'] <=> $q1c['evidence_horses']) ?: ($q2['horses'] <=> $q1c['horses'])
                           ?: ((int) !($q2['wave_null'] ?? true) <=> (int) !($q1c['wave_null'] ?? true))) > 0 || $q1c['changed_fixed']);
            // @ANCHOR-20261110-PREDICTION-RESEND-VERIFY  よっしー11/10夜 ③：本番と同じ検査に両方の返事を通し、「検査を通って候補になれる馬」の数で比べます
            //   2回目のほうが多いときだけ2回目を使います。同じ数のときだけ、これまでの比べ方（根拠を書いた馬→書いた馬→波乱度）で決めます。
            //   検査そのものが動かないとき（凍結入力や枠が読めない等）は、使える1回目を失わないよう1回目を使います（1回目が読めない返事のときだけ、これまでの比べ方）。
            $e1 = ($snapshotId > 0) ? $this->dryInspectEligible($snapshotId, $role, self::replyText($res, $role)) : null;
            $e2 = ($snapshotId > 0 && $q2 !== null) ? $this->dryInspectEligible($snapshotId, $role, self::replyText($res2, $role)) : null;
            // @ANCHOR-20261110-PREDICTION-RESEND-LATE  よっしー11/10夜3：2回目が、最新の実効応答期限より後に届いたときは、中身の比べより先に「使わない」と決めます
            //   （2回目に切り替えると受領で遅着として拒否され、期限内に届いた1回目まで失うため）。期限を読めないときも2回目は使いません。
            $late2 = null;
            if ($snapshotId > 0) {
                try {
                    $snL = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
                    $lvL = \DB::table('baganriki_live_states')->where('snapshot_id', $snapshotId)->first();
                    $dlL = ($snL === null) ? ['ok' => false] : BaganrikiWorker::deadlines($snL, $lvL);
                    $late2 = (($dlL['ok'] ?? false) !== true || !is_int($dlL['reply'] ?? null)) ? true : ($arrived2Us > (int) $dlL['reply']);
                } catch (\Throwable $e) {
                    $late2 = true;
                }
            }
            if ($q2 === null) {
                $better = false;
            } elseif ($late2 === true) {
                $better = false;
            } elseif ($e1 !== null && $e2 !== null) {
                $better = !$q2['changed_fixed'] && ($e2 > $e1 || ($e2 === $e1 && $rawBetter));
            } else {
                $better = ($q1 === null) ? $rawBetter : false;
            }
            $out['resent']['sends'][0]['eligible'] = $e1;
            $send2 = ['n' => 2, 'status' => ($u2 === null ? 'RECEIVED_NO_USAGE' : 'RECEIVED'), 'seconds' => $sec2, 'usage' => $u2,
                      'stop' => $role === '2nd' ? $res2->json('choices.0.finish_reason') : $res2->json('stop_reason'), 'quality' => $q2,
                      'eligible' => $e2,    // 検査を通って候補になれる馬の数（null＝検査を動かせなかった）
                      'late' => $late2];    // 応答期限より後に届いた（true なら使わない）
            if (!$better) { $send2['raw_unused'] = self::replyText($res2, $role); }   // 使わなかった返事の原文（なる11/10 7章）
            $out['resent']['sends'][] = $send2;
            $out['resent']['used'] = $better;
            if (!$better) {
                // 1回目を使うので、1回目の原文は通常の保存先に入ります。2回目の原文は上の raw_unused に残します
                return $res;
            }
            $out['resent']['sends'][0]['raw_unused'] = self::replyText($res, $role);   // 使わなかった1回目の原文
            self::markArrived($out, $res2, $role, $arrived2Us);   // @ANCHOR-20261110-PREDICTION-ARRIVED  2回目を使うので、2回目が届いた時刻にします
            return $res2;
        } catch (\Throwable $e) {
            \Log::warning('[PREDICTION-RESEND] ' . $role . ' の送り直しの判定で止まりました（1回目の返事を使います）', ['message' => $e->getMessage()]);
            if (isset($out['resent']['sends'])) { $out['resent']['used'] = false; $out['resent']['error'] = mb_substr($e->getMessage(), 0, 300); }
            return $res;
        }
    }

    /**
     * @ANCHOR-20261110-PREDICTION-RESEND-VERIFY  まだ保存していない返事を、本番と同じ検査（整え・封筒照合・馬ごとの隔離・診断・根拠の組み直し）に通し、
     *   「検査を通って候補になれる馬（eligibility=ELIGIBLE）」の数を返します。保存はしません。
     *   読めない返事・封筒が合わない返事は 0頭。検査そのものが動かないとき（凍結入力・枠が読めない、例外）は null。
     */
    private function dryInspectEligible(int $snapshotId, string $role, string $raw): ?int
    {
        $prev = $this->dryInspectRaw;
        $this->dryInspectRaw = $raw;
        try {
            $r = $this->inspectAiResponse($snapshotId, $role, 0);
        } catch (\Throwable $e) {
            \Log::warning('[PREDICTION-RESEND] 試しの検査で止まりました', ['role' => $role, 'message' => $e->getMessage()]);
            return null;
        } finally {
            $this->dryInspectRaw = $prev;
        }
        $stage = (string) ($r['stage'] ?? '');
        if (in_array($stage, ['extract', 'envelope'], true)) { return 0; }
        if ($stage !== 'ok') { return null; }
        return count(array_filter((array) ($r['normal_kept'] ?? []), static fn($h) => is_array($h) && (($h['eligibility'] ?? '') === 'ELIGIBLE')));
    }

    /**
     * @ANCHOR-20261110-PREDICTION-ARRIVED  返事が届いた直後の時刻を、その返事の本文（保存する原文）の SHA-256 と組にして残します（よっしー11/10夜2 ①）
     *   受領（BaganrikiWorker::receive）の期限判定は、送り直し・比べるための検査・料金の記録が終わった時刻ではなく、
     *   <b>使う返事が届いた時刻</b>で行います。保存する原文のハッシュが一致するときだけ、この時刻を使います（一致しなければ今の時刻）。
     *   最新の正式締切・取消・公開期限の検査は、受領の中でこれまでどおり行います。
     */
    private static function markArrived(array &$out, $res, string $role, ?int $atUs = null): void
    {
        $out['arrived'] = ['us' => $atUs ?? BaganrikiWorker::nowUs(), 'sha256' => hash('sha256', self::replyText($res, $role))];
    }

    /** 受領に使う時刻：保存する原文が、届いた時刻を記録した返事と同じなら、その届いた時刻。違えば今の時刻 */
    private static function receivedAtFor(array $call): int
    {
        $ar = $call['arrived'] ?? null;
        if (is_array($ar) && is_int($ar['us'] ?? null) && is_string($ar['sha256'] ?? null)
            && hash_equals($ar['sha256'], hash('sha256', (string) ($call['raw'] ?? '')))) {
            return min((int) $ar['us'], BaganrikiWorker::nowUs());
        }
        return BaganrikiWorker::nowUs();
    }

    /** 投げ出しの理由（無ければ null） */
    private static function abandonedWhy(?array $q, int $runners): ?string
    {
        if ($q === null) { return '読めない返事'; }
        if ($q['changed_fixed']) { return '写すだけの欄（selection_enabled・role_cap）を書き換えた返事'; }
        if ($q['horses'] < $runners) { return '書いた馬が ' . $q['horses'] . '頭（出走 ' . $runners . '頭）の返事'; }
        if ($q['evidence_horses'] < max(1, (int) ceil($runners / 3))) {
            return '根拠を書いた馬が ' . $q['evidence_horses'] . '頭（出走 ' . $runners . '頭）の返事';
        }
        if ($q['wave_null'] ?? false) { return '波乱度（race_metrics.wave_level）を出さなかった返事'; }
        return null;
    }

    /** 最後まで返ってきたか（1st は stop_reason=end_turn、2nd は finish_reason=stop） */
    private static function replyEnded(string $role, $res): bool
    {
        return $role === '2nd' ? ($res->json('choices.0.finish_reason') === 'stop') : ($res->json('stop_reason') === 'end_turn');
    }

    /** 送り直したときは、送信ごとに記録した使用量（resent.sends）を、確かめられたものだけ1回ずつ足します。
     *  2回目の結果が分からない（通信の例外など）ときは、使用量を null（費用不明）にし、予算は予約額（最悪額）のまま数えます。 */
    private static function sumResentUsage(array &$out): void
    {
        if (!isset($out['resent']['sends']) || !is_array($out['resent']['sends'])) { return; }
        $sum = null; $unknown = false;
        foreach ($out['resent']['sends'] as $sd) {
            if (is_array($sd['usage'] ?? null)) { $sum = ($sum === null) ? (array) $sd['usage'] : self::addUsage($sum, (array) $sd['usage']); }
            elseif (($sd['status'] ?? '') !== 'HTTP_ERROR_NO_USAGE') { $unknown = true; }
        }
        $out['usage'] = $unknown ? null : $sum;
        $out['resent']['usage_unknown'] = $unknown;
    }

    /** 返事の本文（1st は text ブロックをつないだもの、2nd は choices[0].message.content） */
    private static function replyText($res, string $role = '1st'): string
    {
        if ($role === '2nd') { $c = $res->json('choices.0.message.content'); return is_string($c) ? $c : ''; }
        $raw = '';
        foreach ((array) $res->json('content') as $blk) {
            if (is_array($blk) && ($blk['type'] ?? '') === 'text') { $raw .= (string) ($blk['text'] ?? ''); }
        }
        return $raw;
    }

    /**
     * 1st の返事の中身の量（読めなければ null）
     *   horses：出走馬の馬番のうち書いた数（重複・出走にない馬番は数えない）
     *   evidence_horses：そのうち、根拠（evidence）を1件以上書いた馬の数
     *   changed_fixed：写すだけの欄（selection_enabled・role_cap）が凍結入力と違う
     */
    private static function replyQuality($res, array $nums, array $in, string $role = '1st'): ?array
    {
        $raw = self::replyText($res, $role);
        $a = strpos($raw, '{'); $b = strrpos($raw, '}');
        if ($a === false || $b === false || $b <= $a) { return null; }
        $j = json_decode(substr($raw, $a, $b - $a + 1), true);
        if (!is_array($j)) {
            $fx = preg_replace(['/([\]}]),"\{"/', '/\}\},"(lower_entry|big_gap_entry)":\{/', '/("V5":\{"source_refs":\[[^\]]*\],"reason":"(?:[^"\\\\]|\\\\.)*"\}),"score_status"/u'],
                               ['$1,{"', '},"$1":{', '$1},"score_status"'], substr($raw, $a, $b - $a + 1));
            $j = is_string($fx) ? json_decode($fx, true) : null;
        }
        if (!is_array($j) || !isset($j['horses']) || !is_array($j['horses'])) { return null; }
        $seen = []; $ev = [];
        foreach ($j['horses'] as $h) {
            $no = is_array($h) ? ($h['horse_no'] ?? null) : null;
            if (!is_int($no) || !isset($nums[$no])) { continue; }
            $seen[$no] = true;
            if (is_array($h['evidence'] ?? null) && $h['evidence'] !== []) { $ev[$no] = true; }
        }
        $changed = (array_key_exists('selection_enabled', $j) && array_key_exists('selection_enabled', $in) && $j['selection_enabled'] !== $in['selection_enabled'])
                || (array_key_exists('role_cap', $j) && array_key_exists('role_cap', $in) && $j['role_cap'] !== $in['role_cap']);
        // 波乱度は、断層があってもなくても出せる指標なので、null は「指標を出さなかった」とみなします（なる11/10 5章：資料がそろうのに資料不足と申告）
        $wave = $j['race_metrics']['wave_level']['value'] ?? null;
        // 2nd は表示指標を出さない決まりなので、波乱度は見ません
        return ['horses' => count($seen), 'evidence_horses' => count($ev), 'changed_fixed' => $changed, 'wave_null' => ($role === '2nd') ? false : !is_int($wave)];
    }

    // 【使わない】11/10 午後から replyQuality（書いた馬・根拠を書いた馬・写すだけの欄の書き換え）に置き換えました
    // /** 1st の返事に書かれた、出走馬の馬番の数（同じ馬番は1回だけ数え、出走馬にない馬番は数えない。読めなければ null） */
    // private static function countReplyHorses($res, array $nums): ?int
    // {
    //     $raw = '';
    //     foreach ((array) $res->json('content') as $blk) {
    //         if (is_array($blk) && ($blk['type'] ?? '') === 'text') { $raw .= (string) ($blk['text'] ?? ''); }
    //     }
    //     $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    //     if ($a === false || $b === false || $b <= $a) { return null; }
    //     $j = json_decode(substr($raw, $a, $b - $a + 1), true);
    //     // 読めないときは、検査と同じ2つの書き損じ（余計な " ・race_metrics の余計な }）だけを直して読み直します
    //     if (!is_array($j)) {
    //         $fx = preg_replace(['/([\]}]),"\{"/', '/\}\},"(lower_entry|big_gap_entry)":\{/', '/("V5":\{"source_refs":\[[^\]]*\],"reason":"(?:[^"\\\\]|\\\\.)*"\}),"score_status"/u'],
    //                            ['$1,{"', '},"$1":{', '$1},"score_status"'], substr($raw, $a, $b - $a + 1));
    //         $j = is_string($fx) ? json_decode($fx, true) : null;
    //     }
    //     if (!is_array($j) || !isset($j['horses']) || !is_array($j['horses'])) { return null; }
    //     // return count($j['horses']);   // 旧（行の数）
    //     $seen = [];
    //     foreach ($j['horses'] as $h) {
    //         $no = is_array($h) ? ($h['horse_no'] ?? null) : null;
    //         if (is_int($no) && isset($nums[$no])) { $seen[$no] = true; }
    //     }
    //     return count($seen);
    // }

    /** 使用量（数の項目）を足し合わせます。入れ子の数も足します */
    private static function addUsage(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (is_int($v) || is_float($v)) { $a[$k] = (is_int($a[$k] ?? null) || is_float($a[$k] ?? null)) ? $a[$k] + $v : $v; }
            elseif (is_array($v)) { $a[$k] = self::addUsage(is_array($a[$k] ?? null) ? $a[$k] : [], $v); }
            elseif (!array_key_exists($k, $a)) { $a[$k] = $v; }
        }
        return $a;
    }

    /** 予算を確保したあと、同じレースの正本・取消・期限を再取得する。 */
    private static function finalAiSendWindow(int $snapshotId, string $role, ?array $w2Input, int $slot, string $systemHash, string $userHash): array
    {
        try {
            // 取消と公式時刻を同じ判断にそろえる。通信前にロックを解放する。
            return \DB::transaction(static function () use ($snapshotId, $role, $w2Input, $slot, $systemHash, $userHash) {
                $live = null;
                $query = \DB::table('baganriki_snapshots')->where('id', $snapshotId);
                if ($w2Input !== null) {
                    $live = \DB::table('baganriki_live_states')->where('snapshot_id', $snapshotId)->lockForUpdate()->first();
                    $query->lockForUpdate();
                }
                $snap = $query->first();
                if ($snap === null) {
                    return ['ok' => false, 'code' => 'NO_SNAPSHOT', 'reason' => '凍結入力がありません'];
                }
                $current = BaganrikiWorker::isExpectedSnapshot($snap);
                if ($current || $w2Input !== null) {
                    $hash = $w2Input['snapshot_hash'] ?? null;
                    if (!$current || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)
                        || $hash !== ($snap->snapshot_hash ?? null) || ($w2Input['race_id'] ?? null) !== $snap->race_id
                        || !in_array($role, ['1st','2nd'], true) || ($w2Input['ai_role'] ?? null) !== $role) {
                        return ['ok' => false, 'code' => 'SNAPSHOT_MISMATCH', 'reason' => '送信本文と凍結入力が一致しません'];
                    }
                }
                if ($w2Input === null) {
                    $live = \DB::table('baganriki_live_states')->where('snapshot_id', $snapshotId)->lockForUpdate()->first();
                }
                $deadline = BaganrikiWorker::deadlines($snap, $live, true, true);
                if (!$deadline['ok']) { return $deadline; }
                $issue = BaganrikiWorker::sendRosterIssue($snap, $live);
                if ($issue !== null || !$current) { return $issue ?? $deadline; }
                $column = 'input_json_' . $role;
                $raw = $snap->$column ?? null;
                $refused = ['ok'=>false,'code'=>'OUTBOUND_MISMATCH','reason'=>'予約枠・実送信原文・役割別の凍結原文を照合できません'];
                if (!is_string($raw) || $raw === '') { return $refused; }
                $attempt = \DB::table('baganriki_ai_attempts')->where('snapshot_id',$snapshotId)->where('role',$role)
                    ->where('attempt_no',$slot)->lockForUpdate()->first();
                if ($attempt === null || $attempt->status !== BaganrikiWorker::SENT || !is_string($attempt->token ?? null)
                    || $attempt->token === '') { return $refused; }
                $format = BaganrikiDispatch::formatOfInput($w2Input);
                $request = BaganrikiDispatch::requestHash($snap->race_id,$snap->snapshot_hash,$role,$slot,$format,$systemHash,$userHash);
                $binding = BaganrikiDispatch::sendBinding($snapshotId,$attempt->token,$raw,$request);
                if (($attempt->send_proof ?? null) !== 'R' . $binding) { return $refused; }
                $n = \DB::table('baganriki_ai_attempts')->where('token',$attempt->token)->where('status',BaganrikiWorker::SENT)
                    ->where('send_proof','R'.$binding)->update(['send_proof'=>'U'.$binding]);
                return $n === 1 ? $deadline : $refused;
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => 'LIVE_STATE_UNAVAILABLE', 'reason' => '送信直前のレース状態を取得できません'];
        }
    }

    /**
     * @ANCHOR-20261020-PREDICTION-WIRE  訂正依頼の本文を組みます
     *
     *   system は初回と同じ本文です（別の本文を送ると別の契約になります）。
     *   user は1個のJSONで、中身は4つだけです。
     *     fix_request … race_id・ai_role・snapshot_hash・repair_scope・エラー一覧
     *     first_response … 当該AIの初回応答の原文（相手AIのものは入れません）
     *     input … 同じ凍結入力（固定後は変えません）
     *   送る前に自分の本文も厳密読込を通します（@ANCHOR-...-STRICTJSON）。
     *
     * @return array ['ok','code','reason','system','user','user_bytes','sha256']
     */
    public function assembleCorrectionRequest(int $snapshotId, string $role, array $insp): array
    {
        $out = ['ok' => false, 'code' => null, 'reason' => null,
                'system' => null, 'user' => null, 'user_bytes' => 0, 'sha256' => null];

        $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
        if ($snap === null) {
            $out['code'] = 'NO_SNAPSHOT'; $out['reason'] = '凍結入力がありません';
            return $out;
        }
        $col = ($role === '2nd') ? 'input_json_2nd' : 'input_json_1st';
        $src = json_decode((string) $snap->{$col}, true);
        if (!is_array($src)) {
            $out['code'] = 'NO_INPUT';
            $out['reason'] = '凍結入力のJSONを読めません（' . $col . '）';
            return $out;
        }

        $a = \DB::table('baganriki_ai_attempts')
               ->where('snapshot_id', $snapshotId)->where('role', $role)
               ->where('attempt_no', BaganrikiWorker::FIRST)->first();
        if ($a === null || (string) $a->status !== BaganrikiWorker::RECEIVED) {
            $out['code'] = 'FIRST_NOT_RECEIVED';
            $out['reason'] = '初回の原応答がありません';
            return $out;
        }

        $sys = BaganrikiPrompt::systemBody($role);
        if (!$sys['ok']) {
            $out['code'] = 'SYSTEM_UNUSABLE';
            $out['reason'] = (string) $sys['reason'];
            return $out;
        }

        $lst = self::correctionErrorList($insp);
        $body = [
            'fix_request' => [
                'race_id'       => (string) $snap->race_id,
                'ai_role'       => $role,
                'snapshot_hash' => (string) $snap->snapshot_hash,
                'attempt_no'    => BaganrikiWorker::FIX,
                'max_attempts'  => 1,
                'repair_scope'  => (array) ($insp['repair_scope'] ?? []),
                'errors'            => $lst['errors'],
                'missing_horses'    => $lst['missing_horses'],
                'duplicated_horses' => $lst['duplicated_horses'],
                'unknown_horses'    => $lst['unknown_horses'],
                'isolated_horses'   => $lst['isolated_horses'],
            ],
            // 当該AIの初回応答そのもの。原文のまま渡します（要約しません）。
            'first_response' => (string) $a->raw_response,
            // 同じ凍結入力。別の入力を渡すと別のレースになります。
            'input' => $src,
        ];
        // @ANCHOR-20261023-NARU-011  凍結した INPUT_JSON は1バイトも変えずに載せます（数値の書き方も保つ。011再精査版）
        unset($body['input']);
        $enc = BaganrikiPrompt::encode($body);
        if ($enc['ok']) {
            $rawIn = (string) $snap->{$col};
            $enc['json']  = substr((string) $enc['json'], 0, -1) . ',"input":' . $rawIn . '}';
            $enc['bytes'] = strlen($enc['json']);
        }
        if (!$enc['ok']) {
            $out['code'] = 'ENCODE_FAILED';
            $out['reason'] = (string) $enc['reason'];
            return $out;
        }
        // @ANCHOR-20261020-PREDICTION-STRICTJSON  送る前に自分の本文も厳密に読みます
        $st = BaganrikiJson::strict((string) $enc['json']);
        if (!$st['ok']) {
            $out['code'] = 'SELF_JSON_INVALID';
            $out['reason'] = '訂正依頼のJSONが自分の検査を通りません（'
                           . (string) ($st['code'] ?? '') . '）';
            return $out;
        }
        $out['ok']         = true;
        $out['code']       = 'OK';
        $out['system']     = (string) $sys['body'];
        $out['user']       = (string) $enc['json'];
        $out['user_bytes'] = (int) $enc['bytes'];
        $out['sha256']     = hash('sha256', (string) $enc['json']);
        return $out;
    }

    /**
     * @ANCHOR-20261020-PREDICTION-WIRE  訂正を1回だけ送ります
     *
     *   順番を守ります（契約10章）。
     *     ① 初回の原応答と complete が保存済みか（fixAllowed）
     *     ② 枠を予約してから送る（reserve → allowSend → 送信）
     *     ③ 残り時間が無いなら<b>予約しません</b>（空振りの枠を残さないため）
     *   結果不明・失敗でも初回の正常部分は残ります（resolve 側の約束）。
     *
     * @return array ['sent','ok','code','reason']
     */
    public function sendCorrectionRequest(int $snapshotId, string $role, array $insp): array
    {
        $out = ['sent' => false, 'ok' => false, 'code' => null, 'reason' => null];

        $fa = BaganrikiWorker::fixAllowed($snapshotId, $role);
        // @ANCHOR-20261023-NARU-RESUME  訂正の枠が「予約済み・未送信」で止まっていたら、
        //   同じ token で送り直します（なる 10/23 重要4）。送信済み・結果不明は送り直しません。
        $fixResumeToken = null;
        if (!$fa['ok'] && (string) $fa['code'] === 'SLOT_TAKEN') {
            $fx0 = \DB::table('baganriki_ai_attempts')
                     ->where('snapshot_id', $snapshotId)->where('role', $role)
                     ->where('attempt_no', BaganrikiWorker::FIX)->first();
            if ($fx0 !== null && (string) $fx0->status === BaganrikiWorker::RESERVED) {
                $fixResumeToken = (string) $fx0->token;
            }
        }
        if (!$fa['ok'] && $fixResumeToken === null) {
            $out['code'] = (string) $fa['code']; $out['reason'] = (string) $fa['reason'];
            return $out;
        }

        $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
        $live = \DB::table('baganriki_live_states')
                  ->where('snapshot_id', $snapshotId)->first();
        if ($snap === null) {
            $out['code'] = 'NO_SNAPSHOT'; $out['reason'] = '凍結入力がありません';
            return $out;
        }
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-TARGET12  訂正の入口でも対象レースを見ます
        // ══════════════════════════════════════════════════════════════
        //   訂正は1レースあたり最大1回ですが、レースの数は制限されません。
        //   ここでも見ます。判定は全入口で同じ関数（checkPredictionTarget）です。
        $tgF = self::checkPredictionTarget((string) $snap->race_id);
        if (!$tgF['ok']) {
            $out['code']   = (string) $tgF['code'];
            $out['reason'] = (string) $tgF['reason'];
            return $out;
        }

        $dl = BaganrikiWorker::deadlines($snap, $live);
        if (!$dl['ok']) {
            $out['code'] = (string) $dl['code']; $out['reason'] = (string) $dl['reason'];
            return $out;
        }
        $now  = BaganrikiWorker::nowUs();
        // 送って受け取って公開するのに最低どれだけ要るか。
        //   応答期限までに受領できない見込みなら予約しません。
        $left = (int) floor(($dl['reply'] - $now) / 1000000);
        // @ANCHOR-20261023-NARU-JOBTIME  Job の残り時間（保存の余裕を引いたもの）でも縛ります
        $left = min($left, self::jobSecondsLeft() - self::JOB_SAVE_MARGIN_SEC);
        if ($left < self::CORRECTION_MIN_SEC) {
            $out['code']   = 'NO_TIME_FOR_FIX';
            $out['reason'] = '応答期限まで ' . $left . '秒しかないので訂正を送りません'
                           . '（最低 ' . self::CORRECTION_MIN_SEC . '秒）';
            return $out;
        }

        $asm = $this->assembleCorrectionRequest($snapshotId, $role, $insp);
        if (!$asm['ok']) {
            $out['code'] = (string) $asm['code']; $out['reason'] = (string) $asm['reason'];
            return $out;
        }

        // 版の見張り。応答の版は予約そのもので増えるので入れません。
        $expect = ($live === null) ? [] : [
            'state_revision'       => (int) $live->state_revision,
            'publication_revision' => (int) $live->publication_revision,
        ];
        // @ANCHOR-20261023-NARU-RESUME  予約済み・未送信の訂正は同じ token
        //   【旧】$r = BaganrikiWorker::reserve($snapshotId, $role, BaganrikiWorker::FIX, …);
        if ($fixResumeToken !== null) {
            $r = ['ok' => true, 'token' => $fixResumeToken];
        } else {
            $r = BaganrikiWorker::reserve($snapshotId, $role, BaganrikiWorker::FIX,
                                          BaganrikiWorker::nowUs(), $expect);
        }
        if (!$r['ok']) {
            $out['code'] = (string) $r['code']; $out['reason'] = (string) $r['reason'];
            return $out;
        }
        // @ANCHOR-20261110-PREDICTION-EXPECTED-SEND  予想の版：初回と同じ形式・同じ system で、許可した範囲だけの訂正を送ります
        $isExpected = BaganrikiWorker::isExpectedSnapshot($snap);
        $ep = null;
        if ($isExpected) {
            try {
                $ep = self::expectedPrepare($snap, $role, self::expectedRepair($snapshotId, $role, $snap, $insp));
            } catch (\Throwable $e) {
                $ep = ['ok' => false, 'code' => 'EXPECTED_PREPARE_FAILED', 'reason' => '訂正の材料を作れません：' . $e->getMessage()];
            }
            if (!$ep['ok']) {
                BaganrikiWorker::fail((string) $r['token'], (string) $ep['reason'], BaganrikiWorker::nowUs(), false);
                $out['code'] = (string) $ep['code']; $out['reason'] = (string) $ep['reason'];
                return $out;
            }
        } else {
            $s = BaganrikiWorker::allowSend((string) $r['token'], BaganrikiWorker::nowUs());
            if (!$s['ok']) {
                $out['code'] = (string) $s['code']; $out['reason'] = (string) $s['reason'];
                return $out;
            }
        }

        \Log::error('[PREDICTION-WIRE] 訂正を送ります', [
            'race_id'      => (string) $snap->race_id,
            'role'         => $role,
            'repair_scope' => (array) ($insp['repair_scope'] ?? []),
            'errors'       => count(self::correctionErrorList($insp)['errors']),
            'user_bytes'   => (int) $asm['user_bytes'],
            'user_sha256'  => (string) $asm['sha256'],
            '残り秒'       => $left,
        ]);

        // ══════════════════════════════════════════════════════════
        // @ANCHOR-20261023-PREDICTION-FIXWAIT  訂正の待ち時間の上限を .env で変えられます
        // ══════════════════════════════════════════════════════════
        //   【10/23 の実AI 18頭実測（3回目）で分かったこと】
        //   18頭の初回は 1st 79秒・2nd 89秒で受領。訂正を送ったところ、
        //   上限60秒で cURL が切れて結果不明になりました（両role）。
        //   訂正も初回とほぼ同じ分量（約2.4〜2.6万トークン）を書き直すため、
        //   60秒では足りません。応答期限まではまだ約90秒残っていました。
        //   【いま】BAGANRIKI_PREDICTION_FIX_MAX_WAIT（既定60）で上限を変えられます。
        //   どんな値にしても「応答期限の5秒前」より長くは待ちません（$left − 5）。
        //   遅着で捨てる時刻まで待つことはありません。
        //   【旧】$wait = max(20, min(self::CORRECTION_MAX_WAIT_SEC, $left - 5));
        $fixCap = (int) env('BAGANRIKI_PREDICTION_FIX_MAX_WAIT', self::CORRECTION_MAX_WAIT_SEC);
        if ($fixCap <= 0) { $fixCap = self::CORRECTION_MAX_WAIT_SEC; }
        $wait = max(20, min($fixCap, $left - 5));
        // $call = $this->callPredictionAi($role, (string) $asm['system'], (string) $asm['user'],
        $call = $isExpected
              ? $this->sendReservedExpectedRequest($snapshotId, $role, BaganrikiWorker::FIX, (string) $r['token'],
                                                   $ep['frozen'], $ep['prepared'], $wait)
              : $this->callPredictionAi($role, (string) $asm['system'], (string) $asm['user'],
                                        $wait, $snapshotId);
        // @ANCHOR-20261023-NARU-BUDGET2  予算・期限で送る前に止めたときは「送っていない」
        if (!empty($call['skip_attempt_update'])) {
            $out['code'] = $call['code']; $out['reason'] = $call['reason'];
            return $out;
        }
        $out['sent'] = !in_array((string) ($call['code'] ?? ''), self::NOT_SENT_CODES, true);
        // @ANCHOR-20261021-PREDICTION-USAGE  実モデルと使用量を残します（章⑤）
        self::recordAiUsage($snapshotId, $role, BaganrikiWorker::FIX, $call);
        if (!$call['ok']) {
            // 通信失敗・待ち時間切れは「結果不明」。明確な失敗は FAILED。
            $unknown = ($call['code'] === 'CONNECTION' || $call['code'] === 'EXCEPTION');
            BaganrikiWorker::fail((string) $r['token'],
                '訂正の送信が失敗しました（' . (string) $call['code'] . '）'
                . (string) $call['reason'],
                BaganrikiWorker::nowUs(), $unknown);
            $out['code']   = (string) $call['code'];
            $out['reason'] = (string) $call['reason'];
            return $out;
        }
        $rc = BaganrikiWorker::receive((string) $r['token'], (string) $call['raw'],
                                       (bool) $call['complete'],
                                       BaganrikiWorker::nowUs());
        if (!($rc['ok'] ?? false)) {
            $out['code']   = (string) ($rc['code'] ?? 'RECEIVE_FAILED');
            $out['reason'] = (string) ($rc['reason'] ?? '訂正の原応答を保存できません');
            return $out;
        }
        $out['ok']     = true;
        $out['code']   = 'OK';
        $out['reason'] = '訂正の原応答を保存しました（complete='
                       . ((bool) $call['complete'] ? 'true' : 'false') . '）';
        return $out;
    }

    public function resumeReceivedRole(int $snapshotId, string $role): array
    {
        $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
        if ($snap === null) { return ['ran' => false]; }
        $p = explode('-', (string) $snap->race_id);
        if (count($p) !== 5) { return ['ran' => false]; }
        $d = substr($p[0], 0, 4) . '-' . substr($p[0], 4, 2) . '-' . substr($p[0], 6, 2);
        \Log::error('[PREDICTION-RESUME] 受領済みの role を再開します', [
            'race_id' => (string) $snap->race_id, 'role' => $role,
        ]);
        return $this->afterAiResponseReceived($d, $p[1], $p[2], $p[3], (int) $p[4], $role,
            ['skip' => false, 'ok' => true, 'snapshot_id' => $snapshotId]);
    }

    public function sendInitialAiRequest(int $snapshotId, string $role,
                                  array $opt = []): array
    {
        $out = ['sent' => false, 'ok' => false, 'code' => null, 'reason' => null];
        if ($role !== '1st' && $role !== '2nd') {
            $out['code'] = 'ROLE_INVALID'; return $out;
        }
        $cfg = self::checkPredictionFlagConfig();
        if (!($cfg['ok'] ?? false)) {
            $out['code'] = (string) $cfg['code'];
            $out['reason'] = (string) $cfg['reason'];
            return $out;
        }
        // @ANCHOR-20261021-PREDICTION-SENDFIRST  旗の検査
        //   $opt['test_ignore_flags'] は<b>試験からだけ</b>渡します。
        //   送信口を偽物に差し替えた通し試験で、旗が下りている環境でも
        //   経路そのものを通すためのものです。
        //   本番の呼び出し元（sweepPendingPredictions／afterCachedResponse）は
        //   渡しません。渡していないことは試験で見張ります。
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261023-PREDICTION-SEPARATE  新経路の送信を旧本文の旗から切り離す（003 P-01）
        // ══════════════════════════════════════════════════════════════
        //   【いま】入力を作る段（PROMPT=shadow または 1）と枠の段（QUEUE）が
        //   上がっていれば送ります。旧入口の本文は PROMPT=1 のときだけ置き換わります。
        //   対象レースの確認は下の checkPredictionTarget で必ず行います。
        //   【旧】&& (!BaganrikiPrompt::sending() || !BaganrikiWorker::enabled())) {
        if (($opt['test_ignore_flags'] ?? false) !== true
            && (!BaganrikiPrompt::enabled() || !BaganrikiWorker::enabled())) {
            $out['code']   = 'FLAG_OFF';
            $out['reason'] = '本文の段か枠の段が上がっていないので初回は送りません';
            return $out;
        }
        $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
        // 非公開の試験（試験の時計を使っている artisan の実行）だけは、試験の世代900も送れるようにします。本番は世代0だけ
        $privateRun = ($snap !== null && (int) $snap->generation === 900
                       && BaganrikiWorker::$testNowUs !== null && app()->runningInConsole());
        if ($snap === null || ((int) $snap->generation !== 0 && !$privateRun)) {
            $out['code'] = 'NO_SNAPSHOT';
            $out['reason'] = '本番の固定入力（generation=0）がありません';
            return $out;
        }
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261023-NARU-RESUME  枠の状態ごとに分けます（なる 10/23 重要4）
        // ══════════════════════════════════════════════════════════════
        //   未予約            … 予約して送る（従来どおり）
        //   予約済み・未送信  … 同じ token で送信許可を取り直して送る（新しく枠を作らない）
        //   送信済み・結果不明・失敗 … 送り直さない（ALREADY）
        //   受領済み・採否未確定 … AIへは送り直さず、保存した応答の診断・訂正・採否確定から再開
        //   受領済み・採否確定 … 何もしない（ALREADY）
        //   【旧】枠があれば状態を見ずに ALREADY で終わっていました。
        $existing = \DB::table('baganriki_ai_attempts')
                 ->where('snapshot_id', $snapshotId)->where('role', $role)
                 ->where('attempt_no', BaganrikiWorker::FIRST)->first();
        $resumeToken = null;
        if ($existing !== null) {
            $st = (string) $existing->status;
            if ($st === BaganrikiWorker::RESERVED) {
                $resumeToken = (string) $existing->token;
            } elseif ($st === BaganrikiWorker::RECEIVED
                      && !self::roleSettled($snapshotId, $role)['settled']) {
                $tgR = self::checkPredictionTarget((string) $snap->race_id);
                if (!$tgR['ok'] && ($opt['test_ignore_flags'] ?? false) !== true) {
                    $out['code'] = (string) $tgR['code'];
                    $out['reason'] = (string) $tgR['reason'];
                    return $out;
                }
                $rs = $this->resumeReceivedRole($snapshotId, $role);
                $out['ok'] = (bool) ($rs['ran'] ?? false);
                $out['code'] = 'RESUMED_RECEIVED';
                $out['reason'] = '受領済みの初回から、診断・訂正・採否確定を再開しました（AIへは送り直していません）';
                return $out;
            } else {
                $out['code'] = 'ALREADY';
                $out['reason'] = 'その role の初回の枠はすでにあります（状態 ' . $st . '。送り直しません）';
                return $out;
            }
        }
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261023-NARU-PATH  記録した経路を送信側でも守ります（なる 10/23 重要1 追加）
        // ══════════════════════════════════════════════════════════════
        //   Job で始めたレースを、Job の段を下ろしたあとに同期側から送らない。逆も同じ。
        //   まだ記録が無ければ、いまの経路で記録します。
        if (($opt['test_ignore_flags'] ?? false) !== true) {
            $mine = BaganrikiWorker::jobs() ? 'JOB' : 'SYNC';
            $owned = self::processingPathOf($snapshotId);
            if ($owned !== null && $owned !== $mine) {
                $out['code'] = 'PATH_OWNED_BY_OTHER';
                $out['reason'] = 'このレースは ' . $owned . ' の経路で始まっているので、'
                               . $mine . ' からは送りません';
                return $out;
            }
            if ($owned === null) { self::fixProcessingPath($snapshotId, $mine); }
        }
        $live = \DB::table('baganriki_live_states')
                  ->where('snapshot_id', $snapshotId)->first();
        $dl = BaganrikiWorker::deadlines($snap, $live);
        if (!$dl['ok']) {
            $out['code'] = (string) $dl['code'];
            $out['reason'] = (string) $dl['reason'];
            return $out;
        }
        // ══════════════════════════════════════════════════════════════
        // @ANCHOR-20261021-PREDICTION-TARGET12  初回の送信許可の直前（）
        // ══════════════════════════════════════════════════════════════
        //   「送信許可の直前でも確認します」。
        //   巡回や待ち行列から来たときは、拾ってから時間が経っています。
        //   予約（reserve）の前にもう一度見ます。
        $tgS = self::checkPredictionTarget((string) $snap->race_id);
        if (!$tgS['ok']) {
            $out['code']   = (string) $tgS['code'];
            $out['reason'] = (string) $tgS['reason'];
            return $out;
        }
        // @ANCHOR-20261102-PREDICTION-PUBLIC  旧方式へ切り替えたレースは、あとから凍結できても送りません（二重の課金を防ぐ）
        if (\DB::table('baganriki_audit_events')->where('race_id', (string) $snap->race_id)
                ->where('kind', self::PREDICTION_FALLBACK_KIND)->exists()) {
            $out['code']   = 'FALLBACK_LEGACY';
            $out['reason'] = 'このレースは旧方式へ切り替えたので、新しい予想は送りません';
            return $out;
        }

        $now  = BaganrikiWorker::nowUs();
        $left = (int) floor(($dl['reply'] - $now) / 1000000);
        if ($left < self::FIRST_REQUEST_MIN_SEC) {
            $out['code']   = 'NO_TIME_FOR_FIRST';
            $out['reason'] = '応答期限まで ' . $left . '秒しかないので初回を送りません'
                           . '（最低 ' . self::FIRST_REQUEST_MIN_SEC . '秒）';
            return $out;
        }

        // ── 保存した本文をそのまま使います（作り直しません）──────────
        $col  = ($role === '2nd') ? 'input_json_2nd' : 'input_json_1st';
        $user = (string) ($snap->{$col} ?? '');
        if ($user === '') {
            $out['code'] = 'NO_SAVED_INPUT';
            $out['reason'] = '固定入力に ' . $role . ' の本文がありません';
            return $out;
        }
        $in = json_decode($user, true);
        if (!is_array($in)
            || (string) ($in['snapshot_hash'] ?? '') !== (string) $snap->snapshot_hash) {
            $out['code'] = 'SAVED_HASH_MISMATCH';
            $out['reason'] = '保存本文の snapshot_hash が列の値と違います';
            return $out;
        }
        $sys = BaganrikiPrompt::systemBody($role);
        if (!$sys['ok']) {
            $out['code'] = 'PROMPT_BODY_INVALID';
            $out['reason'] = (string) $sys['reason'];
            return $out;
        }

        $expect = ($live === null) ? [] : [
            'state_revision'       => (int) $live->state_revision,
            'publication_revision' => (int) $live->publication_revision,
        ];
        // @ANCHOR-20261023-NARU-RESUME  予約済み・未送信なら同じ token を使います
        //   【旧】$r = BaganrikiWorker::reserve(…) だけでした
        if ($resumeToken !== null) {
            $r = ['ok' => true, 'token' => $resumeToken];
        } else {
            $r = BaganrikiWorker::reserve($snapshotId, $role, BaganrikiWorker::FIRST,
                                          BaganrikiWorker::nowUs(), $expect);
        }
        if (!$r['ok']) {
            $out['code'] = (string) $r['code']; $out['reason'] = (string) $r['reason'];
            return $out;
        }
        // @ANCHOR-20261110-PREDICTION-EXPECTED-SEND  予想の版の凍結なら、送る原文を準備してから枠に結び付けて送ります
        $isExpected = BaganrikiWorker::isExpectedSnapshot($snap);
        $ep = null;
        if ($isExpected) {
            $ep = self::expectedPrepare($snap, $role, null);
            if (!$ep['ok']) {
                BaganrikiWorker::fail((string) $r['token'], (string) $ep['reason'], BaganrikiWorker::nowUs(), false);
                $out['code'] = (string) $ep['code']; $out['reason'] = (string) $ep['reason'];
                return $out;
            }
        } else {
            $a = BaganrikiWorker::allowSend((string) $r['token'], BaganrikiWorker::nowUs());
            if (!$a['ok']) {
                $out['code'] = (string) $a['code']; $out['reason'] = (string) $a['reason'];
                return $out;
            }
        }
        \Log::error('[PREDICTION-SENDFIRST] 初回を送ります（やり直し経路）', [
            'race_id'     => (string) $snap->race_id,
            'role'        => $role,
            'user_bytes'  => strlen($user),
            'snapshot_hash' => (string) $snap->snapshot_hash,
            '残り秒'      => $left,
        ]);

        // @ANCHOR-20261023-NARU-JOBTIME  Job の残り時間も見ます（なる 10/23 重要6）
        //   【旧】$wait = max(20, min(self::FIRST_REQUEST_MAX_WAIT_SEC, $left - 5));
        $wait = max(20, min(self::FIRST_REQUEST_MAX_WAIT_SEC, $left - 5,
                            self::jobSecondsLeft() - self::JOB_SAVE_MARGIN_SEC));
        $call = $isExpected
              ? $this->sendReservedExpectedRequest($snapshotId, $role, BaganrikiWorker::FIRST, (string) $r['token'],
                                                   $ep['frozen'], $ep['prepared'], $wait)
              : $this->callPredictionAi($role, (string) $sys['body'], $user, $wait, $snapshotId);
        if (!empty($call['skip_attempt_update'])) {
            $out['code'] = $call['code']; $out['reason'] = $call['reason'];
            return $out;
        }
        $out['sent'] = !in_array((string) ($call['code'] ?? ''), self::NOT_SENT_CODES, true);
        // @ANCHOR-20261021-PREDICTION-USAGE  実モデルと使用量を残します（章⑤）
        self::recordAiUsage($snapshotId, $role, BaganrikiWorker::FIRST, $call);
        if (!$call['ok']) {
            $unknown = ($call['code'] === 'CONNECTION' || $call['code'] === 'EXCEPTION');
            BaganrikiWorker::fail((string) $r['token'],
                '初回の送信が失敗しました（' . (string) $call['code'] . '）'
                . (string) $call['reason'],
                BaganrikiWorker::nowUs(), $unknown);
            $out['code'] = (string) $call['code'];
            $out['reason'] = (string) $call['reason'];
            return $out;
        }
        // $rc = BaganrikiWorker::receive((string) $r['token'], (string) $call['raw'],
        //                                (bool) $call['complete'],
        //                                BaganrikiWorker::nowUs());   // 旧（送り直し・検査・料金の記録が終わった時刻で期限を見ていた）
        $rc = BaganrikiWorker::receive((string) $r['token'], (string) $call['raw'],
                                       (bool) $call['complete'],
                                       self::receivedAtFor($call));   // @ANCHOR-20261110-PREDICTION-ARRIVED  使う返事が届いた時刻
        if (!($rc['ok'] ?? false)) {
            $out['code'] = (string) ($rc['code'] ?? 'RECEIVE_FAILED');
            $out['reason'] = (string) ($rc['reason'] ?? '初回の原応答を保存できません');
            return $out;
        }
        $out['ok']   = true;
        $out['code'] = 'OK';
        $out['reason'] = '初回の原応答を保存しました（complete='
                       . ((bool) $call['complete'] ? 'true' : 'false') . '）';

        // 受け取ったので、検証→訂正→解決→公開まで続けます
        $rid = (string) $snap->race_id;
        $p = explode('-', $rid);
        if (count($p) === 5) {
            $d = substr($p[0], 0, 4) . '-' . substr($p[0], 4, 2) . '-' . substr($p[0], 6, 2);
            $this->afterAiResponseReceived($d, $p[1], $p[2], $p[3], (int) $p[4], $role,
                ['skip' => false, 'ok' => true, 'snapshot_id' => $snapshotId]);
        }
        return $out;
    }

    /** Stage the already prepared E3・E5版の request on its RESERVED row. A retry can only repeat identical bytes. */
    public static function stagePreparedExpectedRequest(int $snapshotId, string $role, int $attemptNo, string $token,
                BaganrikiFrozenInput $frozen, array $prepared): array
    {
        $refused = ['ok'=>false, 'code'=>'OUTBOUND_MISMATCH', 'skip_attempt_update'=>true, 'reason'=>'予約枠と照合できる送信原文がありません'];
        try {
            $in = BaganrikiFrozenInput::checked($frozen);
            $record = BaganrikiDispatch::recordSent($frozen, $prepared, $prepared['messages'] ?? []);
            if ($role !== $in['ai_role'] || $record['attempt_no'] !== $attemptNo || $token === '') { return $refused; }
            $ready = 'R' . BaganrikiDispatch::sendBinding($snapshotId,$token,$frozen->raw(),$record['request_sha256']);
            return \DB::transaction(static function () use ($snapshotId,$role,$attemptNo,$token,$frozen,$in,$record,$ready,$refused) {
                $snap = \DB::table('baganriki_snapshots')->where('id',$snapshotId)->first();
                $column = 'input_json_' . $role;
                if ($snap === null || !BaganrikiWorker::isExpectedSnapshot($snap) || $snap->race_id !== $in['race_id']
                    || $snap->snapshot_hash !== $in['snapshot_hash'] || ($snap->$column ?? null) !== $frozen->raw()
                    || !is_string($snap->common_json ?? null) || hash('sha256',$snap->common_json) !== $snap->snapshot_hash) { return $refused; }
                $slot = \DB::table('baganriki_ai_attempts')->where('token',$token)->where('snapshot_id',$snapshotId)
                    ->where('role',$role)->where('attempt_no',$attemptNo)->lockForUpdate()->first();
                if ($slot === null || $slot->status !== BaganrikiWorker::RESERVED) { return $refused; }
                $saved = $slot->send_proof ?? null;
                if ($saved === null) {
                    $updated = \DB::table('baganriki_ai_attempts')->where('token',$token)->where('status',BaganrikiWorker::RESERVED)
                        ->whereNull('send_proof')->update(['send_proof'=>$ready,
                            'sent_request'=>json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
                    if ($updated !== 1) { return $refused; }
                } elseif ($saved !== $ready) { return $refused; }
                return ['ok'=>true,'code'=>'OK','sent_request'=>$record];
            });
        } catch (\Throwable $error) { return $refused; }
    }

    /** Use the same E3・E5版の entry for the first request and its one authorized correction. */
    public function sendReservedExpectedRequest(int $snapshotId, string $role, int $attemptNo, string $token,
            BaganrikiFrozenInput $frozen, array $prepared, int $timeout): array
    {
        $stage = self::stagePreparedExpectedRequest($snapshotId,$role,$attemptNo,$token,$frozen,$prepared);
        if (!$stage['ok']) { return $stage + ['raw'=>'','complete'=>false]; }
        $allow = BaganrikiWorker::allowSend($token,BaganrikiWorker::nowUs());
        if (!$allow['ok']) {
            return $allow + ['skip_attempt_update'=>true, 'raw'=>'', 'complete'=>false];
        }
        $record = $stage['sent_request'];
        return $this->callPredictionAi($role,$record['system_text'],$record['user_json'],$timeout,$snapshotId);
    }

    // ══════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261110-PREDICTION-EXPECTED-SEND  予想の版（E3・E5を使う版）の送信の準備
    // ══════════════════════════════════════════════════════════════════════
    //   よっしーの一式（R15.19）は部品まで。凍結原文から送る原文を作る流れを、ここでつなぎます。
    //     凍結原文（DB）→ BaganrikiFrozenInput::fromStored（E3・E5の計算原文と照合）
    //     → BaganrikiExpectedBridge::prepareSend（役割ごとの形式で圧縮し、元に戻して1バイトも違わないことを確かめる）
    //     → sendReservedExpectedRequest（予約した枠に原文を結び付けてから送る）
    //   1st は SOURCE_DICT_V3（名前で引ける形）、2nd は SOURCE_TABLE_V1（よっしー11/9 3A）。
    //   1st の system には3指標の頼み書きが入ります（よっしー11/9 2A。config の role_system_addenda）。
    //   費用の確保は callPredictionAi の中の予算の予約で行います（ここでは形式・原文だけを確かめます）。

    // ══════════════════════════════════════════════════════════════════════
    // @ANCHOR-20261110-PREDICTION-EXPECTED-FREEZE  予想の版：凍結のときに E3・E5 を付ける
    // ══════════════════════════════════════════════════════════════════════
    //   .env の BAGANRIKI_PREDICTION_EXPECTED=1 のときだけ、凍結で E3・E5 を付けた「予想の版」を作ります。
    //   0（既定）のあいだは、今の10/20版の凍結のままです（非公開2レースの確認が通るまでは 0）。
    //   計算はよっしーの BaganrikiExpectedBridge::attach。ここでは DB から「今のレース」と「過去レース」を、
    //   よっしーの決めた形（02 の2章）にそろえて渡すだけです。

    public static function expectedEnabled(): bool
    {
        $v = strtolower(trim((string) env('BAGANRIKI_PREDICTION_EXPECTED', '0')));
        return in_array($v, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * 今のレース（current）。凍結した10/20版の共通入力から作るので、オッズの値・バッチ・時刻は凍結と1バイトも違いません。
     *   芝ダ・距離・クラスは t_horse_odds_finder_races（その週のレース）から。クラスの対応は既存の
     *   SummaryExpectedScore::classGroup と同じ表を使います（LLM に推測させない）。
     */
    /** @ANCHOR-20261111-EXPECTED-JUMP  試験用：true にすると直す前（障害も FLAT）と同じ材料を作ります。本番は false のまま */
    public static bool $expectedLegacyJumpAsFlat = false;

    private static function expectedCurrent(array $base, array $ctx): array
    {
        $rid = (string) $base['race_id'];
        $p = explode('-', $rid);
        $date = substr($p[0], 0, 4) . '-' . substr($p[0], 4, 2) . '-' . substr($p[0], 6, 2);
        $race = \DB::table('t_horse_odds_finder_races')->where('date', $date)->where('kaisuu', $p[1])
                  ->where('basho', $p[2])->where('day', $p[3])->where('race', (int) $p[4])->first();
        $course = (string) ($race->course ?? '');
        $surface = (mb_strpos($course, '芝') !== false) ? 'TURF' : ((mb_strpos($course, 'ダ') !== false) ? 'DIRT' : null);
        $dist = (int) preg_replace('/\D/', '', (string) ($race->dist ?? ''));
        $class = \App\Console\Commands\SummaryExpectedScore::classGroup($course, (string) ($race->grade ?? ''),
                   (string) ($race->race_name ?? ''), null);
        $roster = [];
        foreach ((array) $base['runners'] as $h) {
            $no = (int) $h['horse_no'];
            $roster[] = ['horse_no' => $no, 'horse_id' => 'H' . $no];
        }
        $batches = [];
        foreach ([6, 21] as $slot) {
            $a = $base['collection_audit'][(string) $slot] ?? null;
            if (($a['status'] ?? null) !== 'ADOPTED') { continue; }
            $quotes = [];
            foreach ((array) $base['runners'] as $h) {
                $no = (int) $h['horse_no'];
                $refs = $base['input_indices']["horse/$no/win_odds/$slot"] ?? [];
                if (!$refs) { continue; }
                $quotes[] = ['horse_id' => 'H' . $no, 'win' => $base['source_registry'][$refs[0]]['value'] ?? null];
            }
            $batches[(string) $slot] = ['batch_id' => $a['batch_id'], 'requested_at' => $a['request_started_at'],
                'received_at' => $a['response_received_at'], 'available_at' => $a['available_at'],
                'source_updated_at' => $a['source_updated_at'] ?? null, 'quotes' => $quotes];
        }
        // return ['race_id' => $rid, 'N' => (int) $base['N'], 'post_at' => $base['time_contract']['anchor_at'],
        //         'input_freeze_at' => $base['input_freeze_at'], 'jurisdiction' => 'JRA', 'race_type' => 'FLAT',   // 旧（障害も平地として渡していた）
        // @ANCHOR-20261111-EXPECTED-JUMP  よっしー・なる 11/11（B案）：障害レース（クラス JUMP）は平地用の E3・E5 の対象外（OUT_OF_SCOPE）にします
        $raceType = ($class === 'JUMP' && !self::$expectedLegacyJumpAsFlat) ? 'JUMP' : 'FLAT';
        return ['race_id' => $rid, 'N' => (int) $base['N'], 'post_at' => $base['time_contract']['anchor_at'],
                'input_freeze_at' => $base['input_freeze_at'], 'jurisdiction' => 'JRA', 'race_type' => $raceType,
                'surface' => $surface, 'distance_metres' => $dist > 0 ? $dist : null, 'class_group' => $class,
                'roster' => $roster, 'batches' => $batches];
    }

    /**
     * 過去レース（history）。対象日の前日までの730日。毎日の E3・E5 試算の保存表（t_horse_odds_finder_expected_score）を正本にします。
     *   この表には、レースごとに発走予定・6分前と21分前の取得時刻（roster_json）・芝ダ・距離・クラスが残っています。
     *   取得時刻が残っていないレースは使いません（よっしーの決まり。時刻を推測で埋めない）。
     *   着順は t_horse_odds_finder_race_result_history。中止＝DNF、失格＝DQ。着順不明（−1）や取消のあるレースは丸ごと外します。
     *   結果が使えるようになった時刻の記録は無いので、レース当日の 23:59:59（日本時間）とします（翌日以降の予想にだけ使う）。
     *   照会に失敗したら null（＝履歴未提供）、照会できて0件なら []。
     */
    private static function expectedHistory(string $targetDate): ?array
    {
        try {
            $from = (new \DateTimeImmutable($targetDate . ' 00:00:00', new \DateTimeZone('Asia/Tokyo')))->modify('-730 days')->format('Y-m-d');
            $rows = \DB::table('t_horse_odds_finder_expected_score')->where('date', '<', $targetDate)->where('date', '>=', $from)
                      ->orderBy('date')->orderBy('kaisuu')->orderBy('basho')->orderBy('day')->orderBy('race')->orderBy('num')->get();
            $res = [];
            \DB::table('t_horse_odds_finder_race_result_history')->where('date', '<', $targetDate)->where('date', '>=', $from)
                ->orderBy('id')->get(['date', 'kaisuu', 'basho_code', 'day', 'race', 'num', 'finishing_position', 'finishing_status'])
                ->each(function ($r) use (&$res) {
                    // 着順の表は basho に場所の名前（東京など）、basho_code に番号（05など）が入っている。番号で合わせる
                    $res[substr((string) $r->date, 0, 10) . '|' . (int) $r->kaisuu . '|' . $r->basho_code . '|' . (int) $r->day . '|' . (int) $r->race][(int) $r->num] = $r;
                });
        } catch (\Throwable $e) {
            return null;
        }
        $races = [];
        foreach ($rows as $r) {
            $key = substr((string) $r->date, 0, 10) . '|' . (int) $r->kaisuu . '|' . $r->basho . '|' . (int) $r->day . '|' . (int) $r->race;
            $races[$key][] = $r;
        }
        $out = [];
        $savedLedger = self::expectedSavedLedger();   // @ANCHOR-20261116-EXPECTED-SAVED-CONFIRM
        self::$expectedSavedHistoryAudit = ['target_date' => $targetDate, 'ledger' => $savedLedger === null ? null : count($savedLedger['races']), 'adopted' => 0, 'excluded' => []];
        $savedFirst = null;   // @ANCHOR-20261116-EXPECTED-OLDHIST  保存表から作った過去レースの最初の日（これより前を古いデータで足す）
        foreach ($races as $key => $hs) { $d0 = explode('|', $key)[0]; $savedFirst = ($savedFirst === null || $d0 < $savedFirst) ? $d0 : $savedFirst; }
        $tz = new \DateTimeZone('Asia/Tokyo');
        foreach ($races as $key => $hs) {
            [$d, $k, $b, $dy, $rc] = explode('|', $key);
            $rj = json_decode((string) ($hs[0]->roster_json ?? ''), true);
            $t6 = $rj['odds_get_timing_6'] ?? null; $t21 = $rj['odds_get_timing_21'] ?? null;
            $start = $hs[0]->nominal_start_at ?? null;
            if (!is_string($t6) || $t6 === '' || $start === null || !isset($res[$key])) { continue; }
            $cancel = (array) ($rj['cancelled'] ?? []);
            $post = new \DateTimeImmutable((string) $start, $tz);
            $iso = static fn($v) => (new \DateTimeImmutable((string) $v, $tz))->format('Y-m-d\TH:i:s.uP');
            $roster = []; $q6 = []; $q21 = []; $results = []; $bad = false;
            foreach ($hs as $h) {
                $no = (int) $h->num; $id = 'H' . $no;
                $roster[] = ['horse_no' => $no, 'horse_id' => $id];
                $q6[]  = ['horse_id' => $id, 'win' => $h->odds_6min === null ? null : (string) $h->odds_6min];
                if ($h->odds_21min !== null) { $q21[] = ['horse_id' => $id, 'win' => (string) $h->odds_21min]; }
                $f = $res[$key][$no] ?? null;
                if ($f === null) { $bad = true; break; }
                $pos = (int) $f->finishing_position; $st = (string) ($f->finishing_status ?? '');
                if ($pos >= 1) { $results[] = ['horse_id' => $id, 'finish' => $pos]; }
                elseif ($st === '中止') { $results[] = ['horse_id' => $id, 'finish' => 'DNF']; }
                elseif ($st === '失格') { $results[] = ['horse_id' => $id, 'finish' => 'DQ']; }
                else { $bad = true; break; }
            }
            if ($bad) { continue; }
            $rid = AiController::buildPredictionRaceId($d, $k, $b, $dy, (int) $rc);
            // @ANCHOR-20261116-EXPECTED-SAVED-CONFIRM  なる11/16 質問2 A：結果の時刻は「当日 23:59:59」と仮に置かず、
            //   結果の確認記録（results_confirmed_v1.json）の、今の内容と同じ最新の版の確認時刻を使う。
            //   対象日の 0:00 より前に確認した版だけを使う。未確認・内容が違う・0:00 以降の確認は、理由を残して外す。
            $resultAt = self::expectedSavedResultAt($savedLedger, $rid, self::expectedSavedDigest($rid, $roster, $results), $targetDate);
            if ($resultAt === null) { continue; }
            $batches = ['6' => ['batch_id' => 'expected_score:' . $rid . ':6', 'requested_at' => $iso($t6),
                                'received_at' => $iso($t6), 'available_at' => $iso($t6), 'quotes' => $q6]];
            if (is_string($t21) && $t21 !== '' && $q21) {
                $batches['21'] = ['batch_id' => 'expected_score:' . $rid . ':21', 'requested_at' => $iso($t21),
                                  'received_at' => $iso($t21), 'available_at' => $iso($t21), 'quotes' => $q21];
            }
            $savedFirst = ($savedFirst === null || $d < $savedFirst) ? $d : $savedFirst;   // @ANCHOR-20261116-EXPECTED-OLDHIST
            self::$expectedSavedHistoryAudit['adopted']++;   // @ANCHOR-20261116-EXPECTED-SAVED-CONFIRM
            $out[] = ['race_id' => $rid, 'N' => count($roster), 'post_at' => $post->format('Y-m-d\TH:i:s.uP'),
                'input_freeze_at' => $post->modify(BaganrikiTime::INPUT_FREEZE_OFFSET_SEC . ' seconds')->format('Y-m-d\TH:i:s.uP'),
                // 'jurisdiction' => 'JRA', 'race_type' => 'FLAT', 'surface' => $hs[0]->surface,   // 旧（障害も平地として基準集団に入りえた）
                // @ANCHOR-20261111-EXPECTED-JUMP  過去の障害レース（クラス JUMP）は race_type=JUMP にして、平地の基準集団に入れない（HISTORY_SCOPE で外れる）
                'jurisdiction' => 'JRA', 'race_type' => ((string) ($hs[0]->class_group ?? '') === 'JUMP' && !self::$expectedLegacyJumpAsFlat) ? 'JUMP' : 'FLAT', 'surface' => $hs[0]->surface,
                'distance_metres' => $hs[0]->distance === null ? null : (int) $hs[0]->distance,
                'class_group' => $hs[0]->class_group, 'roster' => $roster, 'batches' => $batches,
                'post_freeze_scratch' => $cancel !== [], 'results' => $results,
                // 'result_available_at' => (new \DateTimeImmutable($d . ' 23:59:59', $tz))->format('Y-m-d\TH:i:s.uP')];   // 旧（20261116 なる11/16 質問2 A：仮の時刻を置かない）
                'result_available_at' => $resultAt];   // @ANCHOR-20261116-EXPECTED-SAVED-CONFIRM
        }
        // @ANCHOR-20261116-EXPECTED-OLDHIST  なる11/15「条件付きA」：取得時刻の記録が無い6月〜10/23の過去レースを、時刻不明の資料として足す
        //   方針が有効なとき（contract.json の expected_history_policy）だけ。保存表に無い日（保存表の最初の日より前）だけを足す。
        if (self::expectedOldHistoryEnabled()) {
            try {
                foreach (self::expectedOldHistory($targetDate, $from, $savedFirst ?? $targetDate) as $e) { $out[] = $e; }
            } catch (\Throwable $e) {
                \Log::error('[EXPECTED-OLDHIST] 古い過去レースを作れません（保存表の分だけで計算します）', ['message' => $e->getMessage()]);
            }
        }
        return $out;
    }

    /**
     * @ANCHOR-20261116-EXPECTED-OLDHIST  古い過去レース（取得時刻の記録なし）の方針
     *   EXPECTED_HISTORY_UNRECORDED_TIME_V1（なる11/15 回答00の2章）
     *   ・取得時刻は不明のまま（null）。発走時刻からの逆算で埋めない。発走時刻も記録が無いので、日付だけ（post_time_status=DATE_ONLY）
     *   ・外すレース：全頭の21分前＝6分前／全頭の6分前＝確定単勝（上書きの疑い）／取消・除外・6分前の欠け／着順が数字・中止・失格でない馬がいる／払戻表なし・芝ダ以外
     *   ・クラスは夜の試算と同じ固定の対応表（SummaryExpectedScore::classGroup）。決まらなければ UNK（近傍に使わない）
     *   ・今のレースの取得猶予・情報上限・結果の混入防止は変えない（この例外は過去レースの取得時刻だけ）
     */
    /**
     * @ANCHOR-20261116-EXPECTED-SAVED-CONFIRM  なる11/16 質問2 A：保存表の経路の「結果の確認記録」
     *   記録の単位は「レース × 結果内容の版」。版ごとに、完全で有効な結果を確認した実時刻と内容の照合値を持つ。
     *   ・同じ内容を確認し直しても、最初の確認時刻は変えない（毎晩の処理で時刻が後ろへずれない）
     *   ・内容が変わったら（訂正）新しい版と確認時刻を足す。訂正版が前から使えたことにはしない
     *   ・裏付けの無い既存データは、最初に確認した実時刻を使う（過去の日付へ戻さない）
     *   ・予想に使うのは、今の内容と同じ「最新の版」で、確認時刻が対象日の 0:00（日本時間）より前のものだけ
     *   毎晩 keiba:summaryExpectedScore（23:40）の最後に expectedResultsConfirm() で確認します。既存の凍結入力・回答・公開・成績は書き換えません。
     */
    public const EXPECTED_SAVED_LEDGER = 'app/baganriki/expected_results/results_confirmed_v1.json';
    public const EXPECTED_SAVED_POLICY = 'EXPECTED_RESULT_CONFIRMED_TIME_V1';
    /** 試験用：確認記録の置き場所を変える（本番は null） */
    public static ?string $expectedSavedLedgerPath = null;
    /** 試験用：「内容を確かめ終えた時刻」を返す時計（本番は null＝実時計）。なる11/16 残件：処理の途中で 0:00 をまたぐ試験に使う */
    public static ?\Closure $expectedSavedClock = null;
    /** 直近の expectedHistory で、保存表の経路から使った数・外した理由ごとの数 */
    public static array $expectedSavedHistoryAudit = [];

    private static function expectedSavedLedgerFile(): string
    {
        return self::$expectedSavedLedgerPath ?? storage_path(self::EXPECTED_SAVED_LEDGER);
    }

    public static function expectedSavedLedger(): ?array
    {
        try {
            $j = json_decode((string) @file_get_contents(self::expectedSavedLedgerFile()), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) { return null; }
        if (!is_array($j) || ($j['policy'] ?? null) !== self::EXPECTED_SAVED_POLICY || !is_array($j['races'] ?? null)) { return null; }
        return $j;
    }

    /** 結果内容の照合値（馬番と着順・中止・失格だけ。時刻は入れない） */
    public static function expectedSavedDigest(string $rid, array $roster, array $results): string
    {
        $r = $results; usort($r, static fn($a, $b) => strcmp((string) $a['horse_id'], (string) $b['horse_id']));
        return hash('sha256', json_encode([$rid, array_column($roster, 'horse_no'), $r], JSON_UNESCAPED_UNICODE));
    }

    /** 予想に使える確認時刻（対象日の 0:00 より前に確認した、今の内容と同じ最新の版）。使えなければ null（理由を数える） */
    private static function expectedSavedResultAt(?array $ledger, string $rid, string $digest, string $targetDate): ?string
    {
        $why = null; $at = null;
        if ($ledger === null) { $why = 'NO_RESULT_LEDGER'; }
        else {
            $versions = $ledger['races'][$rid] ?? [];
            $last = is_array($versions) && $versions !== [] ? end($versions) : null;
            if (!is_array($last)) { $why = 'RESULT_NOT_CONFIRMED'; }
            elseif (($last['digest'] ?? null) !== $digest) { $why = 'RESULT_DIGEST_MISMATCH'; }
            else {
                try {
                    $tz = new \DateTimeZone('Asia/Tokyo');
                    $c = new \DateTimeImmutable((string) $last['confirmed_at']);
                    if ($c >= new \DateTimeImmutable($targetDate . ' 00:00:00', $tz)) { $why = 'RESULT_CONFIRMED_AFTER_TARGET'; }
                    else { $at = $c->setTimezone($tz)->format('Y-m-d\TH:i:s.uP'); }
                } catch (\Throwable $e) { $why = 'RESULT_LEDGER_INVALID'; }
            }
        }
        if ($why !== null) { self::$expectedSavedHistoryAudit['excluded'][$why] = (self::$expectedSavedHistoryAudit['excluded'][$why] ?? 0) + 1; }
        return $at;
    }

    /**
     * 結果を確認して記録する（毎晩 keiba:summaryExpectedScore の最後から呼ぶ）。
     *   保存表（t_horse_odds_finder_expected_score）のレースのうち、全頭の結果が数字の着順・中止・失格でそろったものだけを確認します。
     *   $nowIso は試験用（本番は null＝今の実時刻）。戻り値は件数。
     */
    public static function expectedResultsConfirm(?string $nowIso = null): array
    {
        $tz = new \DateTimeZone('Asia/Tokyo');
        $now = ($nowIso !== null) ? new \DateTimeImmutable($nowIso) : new \DateTimeImmutable('now', $tz);
        $nowStr = $now->setTimezone($tz)->format('Y-m-d\TH:i:s.uP');
        $to = $now->setTimezone($tz)->format('Y-m-d');
        $from = $now->setTimezone($tz)->modify('-800 days')->format('Y-m-d');
        // 旧（20261116 11:30 の手動実行で、800日分の結果を一度に読んでメモリ不足（128MB）になったため、日付ごとに読む形へ）
        // $rows = \DB::table('t_horse_odds_finder_expected_score')->where('date', '<=', $to)->where('date', '>=', $from)
        //           ->orderBy('date')->orderBy('kaisuu')->orderBy('basho')->orderBy('day')->orderBy('race')->orderBy('num')
        //           ->get(['date', 'kaisuu', 'basho', 'day', 'race', 'num']);
        // $res = [];
        // \DB::table('t_horse_odds_finder_race_result_history')->where('date', '<=', $to)->where('date', '>=', $from)
        //     ->orderBy('id')->get(['date', 'kaisuu', 'basho_code', 'day', 'race', 'num', 'finishing_position', 'finishing_status'])
        //     ->each(function ($r) use (&$res) {
        //         $res[substr((string) $r->date, 0, 10) . '|' . (int) $r->kaisuu . '|' . $r->basho_code . '|' . (int) $r->day . '|' . (int) $r->race][(int) $r->num] = $r;
        //     });
        // $races = [];
        // foreach ($rows as $r) { $races[substr((string) $r->date, 0, 10) . '|' . (int) $r->kaisuu . '|' . $r->basho . '|' . (int) $r->day . '|' . (int) $r->race][] = $r; }
        $dates = \DB::table('t_horse_odds_finder_expected_score')->where('date', '<=', $to)->where('date', '>=', $from)
                   ->distinct()->orderBy('date')->pluck('date')->map(static fn($d) => substr((string) $d, 0, 10))->all();
        $path = self::expectedSavedLedgerFile();
        @mkdir(dirname($path), 0755, true);
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) { return ['ok' => false, 'code' => 'LOCK_FAILED']; }
        try {
            $ledger = self::expectedSavedLedger() ?? ['policy' => self::EXPECTED_SAVED_POLICY,
                'note' => '結果の確認記録（なる11/16 質問2 A）。レース×結果内容の版ごとに、確認した実時刻と照合値。最初の確認時刻は変えない', 'races' => []];
            $cnt = ['checked' => 0, 'new' => 0, 'same' => 0, 'corrected' => 0, 'incomplete' => 0];
            $add = [];   // 今回足す版（race_id => 照合値）
            foreach ($dates as $date) {   // 日付ごとに読む（メモリを1日分だけ使う）
            $res = [];
            foreach (\DB::table('t_horse_odds_finder_race_result_history')->where('date', $date)
                ->orderBy('id')->get(['kaisuu', 'basho_code', 'day', 'race', 'num', 'finishing_position', 'finishing_status']) as $r) {
                $res[$date . '|' . (int) $r->kaisuu . '|' . $r->basho_code . '|' . (int) $r->day . '|' . (int) $r->race][(int) $r->num]
                    = [(int) $r->finishing_position, (string) ($r->finishing_status ?? '')];
            }
            $races = [];
            foreach (\DB::table('t_horse_odds_finder_expected_score')->where('date', $date)
                ->orderBy('kaisuu')->orderBy('basho')->orderBy('day')->orderBy('race')->orderBy('num')
                ->get(['kaisuu', 'basho', 'day', 'race', 'num']) as $r) {
                $races[$date . '|' . (int) $r->kaisuu . '|' . $r->basho . '|' . (int) $r->day . '|' . (int) $r->race][] = (int) $r->num;
            }
            foreach ($races as $key => $hs) {
                [$d, $k, $b, $dy, $rc] = explode('|', $key);
                if (!isset($res[$key])) { $cnt['incomplete']++; continue; }
                $roster = []; $results = []; $bad = false;
                foreach ($hs as $no) {
                    $id = 'H' . $no; $roster[] = ['horse_no' => $no, 'horse_id' => $id];
                    $f = $res[$key][$no] ?? null;
                    if ($f === null) { $bad = true; break; }
                    [$pos, $st] = $f;
                    if ($pos >= 1) { $results[] = ['horse_id' => $id, 'finish' => $pos]; }
                    elseif ($st === '中止') { $results[] = ['horse_id' => $id, 'finish' => 'DNF']; }
                    elseif ($st === '失格') { $results[] = ['horse_id' => $id, 'finish' => 'DQ']; }
                    else { $bad = true; break; }
                }
                if ($bad) { $cnt['incomplete']++; continue; }
                $cnt['checked']++;
                $rid = AiController::buildPredictionRaceId($d, $k, $b, $dy, (int) $rc);
                $digest = self::expectedSavedDigest($rid, $roster, $results);
                $versions = $ledger['races'][$rid] ?? [];
                $last = $versions !== [] ? end($versions) : null;
                if (is_array($last) && ($last['digest'] ?? null) === $digest) { $cnt['same']++; continue; }   // 同じ内容：時刻は変えない
                // $versions[] = ['digest' => $digest, 'confirmed_at' => $nowStr];   // 旧（20261116 なる残件：処理を始めた時刻を確認時刻にしていた）
                // $ledger['races'][$rid] = $versions;
                $add[$rid] = $digest;   // 足す版はいったん集め、全部の検査が終わった後の実時刻を付ける
                count($versions) === 0 ? $cnt['new']++ : $cnt['corrected']++;
            }
            unset($res, $races);
            }   // 日付ごと
            // @ANCHOR-20261116-EXPECTED-SAVED-CONFIRM  なる11/16 残件：確認時刻は、結果の読み取りと内容の検査を全部終えた後（台帳を書く直前）に実時計から取り直す。
            //   処理を始めた時刻（$now）は、調べる期間を決めるためだけに使う。今回足す版だけにこの時刻を付け、同じ内容の既存の版の時刻は変えない。
            $confirmedStr = null;
            if ($add !== []) {
                $doneAt = (self::$expectedSavedClock !== null) ? (self::$expectedSavedClock)() : new \DateTimeImmutable('now', $tz);
                $confirmedStr = $doneAt->setTimezone($tz)->format('Y-m-d\TH:i:s.uP');
                foreach ($add as $rid => $digest) { $ledger['races'][$rid][] = ['digest' => $digest, 'confirmed_at' => $confirmedStr]; }
            }
            if ($cnt['new'] > 0 || $cnt['corrected'] > 0 || !is_file($path)) {
                $tmp = $path . '.tmp';
                if (@file_put_contents($tmp, json_encode($ledger, JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $path)) {
                    return ['ok' => false, 'code' => 'WRITE_FAILED'] + $cnt;
                }
                @chmod($path, 0644);
            }
            // return ['ok' => true, 'confirmed_at' => $nowStr, 'path' => $path] + $cnt;   // 旧（処理を始めた時刻を返していた）
            return ['ok' => true, 'started_at' => $nowStr, 'confirmed_at' => $confirmedStr, 'path' => $path] + $cnt;
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public const EXPECTED_HISTORY_POLICY = 'EXPECTED_HISTORY_UNRECORDED_TIME_V1';
    public const EXPECTED_CLASS_TABLE = 'SummaryExpectedScore::classGroup@20261028';
    /**
     * @ANCHOR-20261116-EXPECTED-OLDHIST-FIX  なる11/15 再確認（02）の1・2・4への対応
     *   1．結果の利用可能時刻を「レース当日 23:59:59」と仮に置かない。結果が入った時刻の記録が DB に無いため、
     *      結果を実際に確かめて固定した時刻（確認台帳 old_results_confirmed_v1.json の confirmed_at。台帳を作った実時刻）を
     *      result_available_at にする。対象日の 0:00 より後に確かめた結果は、その対象日には使わない（件数を別に数える）。
     *      台帳に無いレース・台帳を作った後に中身が変わったレースも使わない。
     *   2．summary の馬番と結果の馬番の全体が一致し、1〜N の連番であるレースだけを使う（欠けた馬を落として小さい頭立てにしない）。
     *   4．期間は 2026-06-01〜2026-10-23 に固定（保存表の最初の日がずれても広がらない）。
     *      「全頭の21分前＝6分前」の一律除外はやめ、台帳に書いたレースID（6/6〜6/20 の21分前の取得不具合）と原因で外す。
     */
    public const EXPECTED_OLD_FROM = '2026-06-01';
    public const EXPECTED_OLD_BEFORE = '2026-10-24';   // この日を含まない（10/23 まで）
    public const EXPECTED_OLD_LEDGER = 'app/baganriki/expected_history/old_results_confirmed_v1.json';
    /** 試験用：true にすると contract.json に関係なく有効にします（本番は null のまま） */
    public static ?bool $expectedOldHistoryForce = null;
    /** 試験用（なる02 の3）：効果の試算だけ。確認台帳の時刻より前の対象日にも古い過去レースを使い、
     *  result_time_status=EFFECT_TRIAL_NOT_AVAILABLE_THEN を付ける（当時使えた履歴だとは扱わない）。本番は false */
    public static bool $expectedOldHistoryEffectTrial = false;
    /** 直近に作った古い過去レースの監査（採用数・外した理由ごとの数・外したレースの一覧） */
    public static array $expectedOldHistoryAudit = [];
    private static ?bool $expectedOldHistoryMemo = null;

    public static function expectedOldHistoryEnabled(): bool
    {
        if (self::$expectedOldHistoryForce !== null) { return self::$expectedOldHistoryForce; }
        if (self::$expectedOldHistoryMemo === null) {
            try {
                $cfg = json_decode((string) file_get_contents(BaganrikiExpectedFiles::path('config/contract.json')), true, 512, JSON_THROW_ON_ERROR);
                self::$expectedOldHistoryMemo = (($cfg['expected_history_policy'] ?? null) === self::EXPECTED_HISTORY_POLICY);
            } catch (\Throwable $e) { self::$expectedOldHistoryMemo = false; }
        }
        return self::$expectedOldHistoryMemo;
    }

    /** 古い過去レース（$from 以上 $before 未満）を、summary・払戻表・着順表から作ります。1日分はキャッシュします（過去の資料は変わらないため） */
    // 旧（20261116 なる02 の1・4 で置き換え：結果の時刻を仮に置いていた・期間が保存表の最初の日しだいで広がった）
    // private static function expectedOldHistory(string $targetDate, string $from, string $before): array
    // {
    //     $key = 'expected_oldhist:v1:' . $from . ':' . $before;
    //     $built = \Cache::remember($key, 86400, static fn() => self::expectedOldHistoryBuild($from, $before));
    //     self::$expectedOldHistoryAudit = $built['audit'];
    //     return $built['races'];
    // }
    private static function expectedOldHistory(string $targetDate, string $from, string $before): array
    {
        // @ANCHOR-20261116-EXPECTED-OLDHIST-FIX  期間は固定。730日の範囲・保存表の最初の日の内側だけ
        $f = max($from, self::EXPECTED_OLD_FROM); $b = min($before, self::EXPECTED_OLD_BEFORE);
        $audit = ['policy' => self::EXPECTED_HISTORY_POLICY, 'from' => $f, 'before' => $b, 'target_date' => $targetDate,
                  'adopted' => 0, 'excluded' => [], 'excluded_races' => [], 'ledger' => null];
        if ($f >= $b) { self::$expectedOldHistoryAudit = $audit + ['note' => 'NO_PERIOD']; return []; }
        $ledger = self::expectedOldLedger();
        if ($ledger === null) { self::$expectedOldHistoryAudit = $audit + ['note' => 'NO_LEDGER']; return []; }
        $audit['ledger'] = ['confirmed_at' => $ledger['confirmed_at'], 'races' => count($ledger['races']), 'excluded_ids' => count($ledger['excluded_ids'])];
        $built = \Cache::remember('expected_oldhist:v2:' . $f . ':' . $b, 86400, static fn() => self::expectedOldHistoryBuild($f, $b));
        $audit['excluded'] = $built['audit']['excluded']; $audit['excluded_races'] = $built['audit']['excluded_races'];
        $drop = static function (string $why, string $rid) use (&$audit) {
            $audit['excluded'][$why] = ($audit['excluded'][$why] ?? 0) + 1;
            $audit['excluded_races'][$why][] = $rid;
        };
        $tz = new \DateTimeZone('Asia/Tokyo');
        $day = new \DateTimeImmutable($targetDate . ' 00:00:00', $tz);
        $confirmed = new \DateTimeImmutable($ledger['confirmed_at']);
        $out = [];
        foreach ($built['races'] as $r) {
            $rid = $r['race_id'];
            if (isset($ledger['excluded_ids'][$rid])) { $drop('LEDGER_EXCLUDED:' . $ledger['excluded_ids'][$rid], $rid); continue; }
            $digest = $ledger['races'][$rid] ?? null;
            if ($digest === null) { $drop('RESULT_NOT_CONFIRMED', $rid); continue; }
            if (!hash_equals($digest, $r['result_digest'])) { $drop('RESULT_CHANGED_AFTER_CONFIRM', $rid); continue; }
            $trial = false;
            if ($confirmed >= $day) {   // 過去の対象日へ遡って使わない
                if (!self::$expectedOldHistoryEffectTrial) { $drop('RESULT_CONFIRMED_AFTER_TARGET', $rid); continue; }
                $trial = true;
            }
            unset($r['result_digest'], $r['odds21_equals_odds6']);
            $r['result_available_at'] = $trial ? $day->modify('-1 second')->format('Y-m-d\TH:i:s.uP') : $confirmed->setTimezone($tz)->format('Y-m-d\TH:i:s.uP');
            $r['result_time_status'] = $trial ? 'EFFECT_TRIAL_NOT_AVAILABLE_THEN' : 'CONFIRMED_LEDGER';
            $out[] = $r; $audit['adopted']++;
        }
        ksort($audit['excluded']);
        self::$expectedOldHistoryAudit = $audit;
        return $out;
    }

    /** @ANCHOR-20261116-EXPECTED-OLDHIST-FIX  結果の確認台帳を読む。無い・形が違えば null（古い過去レースは使わない） */
    public static function expectedOldLedger(): ?array
    {
        try {
            $j = json_decode((string) @file_get_contents(storage_path(self::EXPECTED_OLD_LEDGER)), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) { return null; }
        if (!is_array($j) || ($j['policy'] ?? null) !== self::EXPECTED_HISTORY_POLICY || !is_string($j['confirmed_at'] ?? null)
            || !is_array($j['races'] ?? null) || !is_array($j['excluded_ids'] ?? null)
            || ($j['from'] ?? null) !== self::EXPECTED_OLD_FROM || ($j['before'] ?? null) !== self::EXPECTED_OLD_BEFORE) { return null; }
        try { new \DateTimeImmutable($j['confirmed_at']); } catch (\Throwable $e) { return null; }
        return $j;
    }

    /**
     * @ANCHOR-20261116-EXPECTED-OLDHIST-FIX  結果の確認台帳を作る（試験・確認用の手順から一度だけ呼ぶ。本番の予想の流れからは呼ばない）
     *   今の DB の結果を確かめ、各レースの中身の照合値と、確かめた実時刻（confirmed_at）を書く。既にあれば上書きしない。
     *   21分前の取得不具合（6/6〜6/20 に全頭の21分前＝6分前）のレースは、レースIDと原因を excluded_ids に書く。
     */
    public static function expectedOldLedgerWrite(): array
    {
        $path = storage_path(self::EXPECTED_OLD_LEDGER);
        if (is_file($path)) { return ['ok' => false, 'code' => 'LEDGER_EXISTS', 'path' => $path]; }
        $built = self::expectedOldHistoryBuild(self::EXPECTED_OLD_FROM, self::EXPECTED_OLD_BEFORE);
        $races = []; $excluded = [];
        foreach ($built['races'] as $r) {
            if ($r['odds21_equals_odds6'] && substr($r['race_id'], 0, 8) >= '20260606' && substr($r['race_id'], 0, 8) <= '20260620') {
                $excluded[$r['race_id']] = 'ODDS21_ACQUISITION_DEFECT_20260606_20260620'; continue;
            }
            $races[$r['race_id']] = $r['result_digest'];
        }
        ksort($races); ksort($excluded);
        $j = ['policy' => self::EXPECTED_HISTORY_POLICY, 'from' => self::EXPECTED_OLD_FROM, 'before' => self::EXPECTED_OLD_BEFORE,
              'confirmed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo')))->format('Y-m-d\TH:i:s.uP'),
              'note' => '結果が入った時刻の記録が DB に無いため、この台帳を作った実時刻を結果の利用可能時刻とする（なる11/15 02 の1）',
              'build_excluded' => $built['audit']['excluded'], 'races' => $races, 'excluded_ids' => $excluded];
        @mkdir(dirname($path), 0755, true);
        if (@file_put_contents($path, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
            return ['ok' => false, 'code' => 'LEDGER_WRITE_FAILED', 'path' => $path];
        }
        @chmod($path, 0644);
        return ['ok' => true, 'path' => $path, 'confirmed_at' => $j['confirmed_at'], 'races' => count($races), 'excluded_ids' => count($excluded),
                'build_excluded' => $built['audit']['excluded']];
    }

    private static function expectedOldHistoryBuild(string $from, string $before): array
    {
        $tz = new \DateTimeZone('Asia/Tokyo');
        $num = static fn($v) => (is_string($v) && preg_match('/^[0-9]+(\.[0-9])?$/D', $v) === 1 && (float) $v >= 1.0) ? $v : null;
        $pay = [];
        \DB::table('t_horse_odds_finder_race_result_payout')->where('date', '>=', $from)->where('date', '<', $before)
            ->get(['date', 'kaisuu', 'basho_code', 'day', 'race', 'race_name', 'grade', 'course', 'dist', 'race_condition'])
            ->each(function ($p) use (&$pay) { $pay[substr((string) $p->date, 0, 10) . '|' . $p->basho_code . '|' . (int) $p->race] = $p; });
        $res = [];
        \DB::table('t_horse_odds_finder_race_result_history')->where('date', '>=', $from)->where('date', '<', $before)
            ->get(['date', 'basho_code', 'race', 'num', 'tan', 'finishing_position', 'finishing_status'])
            ->each(function ($r) use (&$res) { $res[substr((string) $r->date, 0, 10) . '|' . $r->basho_code . '|' . (int) $r->race][(int) $r->num] = $r; });
        $races = [];
        \DB::table('t_horse_odds_finder_summary')->where('date', '>=', $from)->where('date', '<', $before)
            ->orderBy('date')->orderBy('basho')->orderBy('race')->orderBy('num')
            ->get(['date', 'kaisuu', 'basho', 'day', 'race', 'num', 'odds_tan_before_6', 'odds_tan_before_21'])
            ->each(function ($r) use (&$races) { $races[substr((string) $r->date, 0, 10) . '|' . $r->basho . '|' . (int) $r->race][] = $r; });
        $out = []; $audit = ['policy' => self::EXPECTED_HISTORY_POLICY, 'class_table' => self::EXPECTED_CLASS_TABLE,
                              'from' => $from, 'before' => $before, 'adopted' => 0, 'excluded' => [], 'excluded_races' => []];
        $drop = static function (string $why, string $rid) use (&$audit) {
            $audit['excluded'][$why] = ($audit['excluded'][$why] ?? 0) + 1;
            $audit['excluded_races'][$why][] = $rid;
        };
        foreach ($races as $key => $hs) {
            [$d, $b, $rc] = explode('|', $key);
            $rid = AiController::buildPredictionRaceId($d, $hs[0]->kaisuu, $b, $hs[0]->day, (int) $rc);
            $p = $pay[$key] ?? null;
            if ($p === null) { $drop('NO_PAYOUT', $rid); continue; }
            $surface = ($p->course === '芝') ? 'TURF' : (($p->course === 'ダート') ? 'DIRT' : null);
            $class = \App\Console\Commands\SummaryExpectedScore::classGroup($p->course, $p->grade, $p->race_name, $p->race_condition);
            if ($surface === null || $class === 'JUMP') { $drop('OUT_OF_SCOPE', $rid); continue; }
            $roster = []; $q6 = []; $q21 = []; $results = []; $bad = null; $same = true; $final = true;
            foreach ($hs as $h) {
                $no = (int) $h->num; $id = 'H' . $no;
                $o6 = $num(trim((string) $h->odds_tan_before_6)); $o21 = $num(trim((string) $h->odds_tan_before_21));
                if ($o6 === null) { $bad = 'CANCEL_OR_MISSING_ODDS6'; break; }
                $f = $res[$key][$no] ?? null;
                if ($f === null) { $bad = 'NO_RESULT'; break; }
                $pos = (int) $f->finishing_position; $st = (string) ($f->finishing_status ?? '');
                if ($pos >= 1) { $results[] = ['horse_id' => $id, 'finish' => $pos]; }
                elseif ($st === '中止') { $results[] = ['horse_id' => $id, 'finish' => 'DNF']; }
                elseif ($st === '失格') { $results[] = ['horse_id' => $id, 'finish' => 'DQ']; }
                else { $bad = 'CANCEL_OR_UNKNOWN_RESULT'; break; }
                $roster[] = ['horse_no' => $no, 'horse_id' => $id];
                $q6[] = ['horse_id' => $id, 'win' => $o6];
                if ($o21 !== null) { $q21[] = ['horse_id' => $id, 'win' => $o21]; }
                if ($o21 === null || (float) $o21 !== (float) $o6) { $same = false; }
                $ft = $num(trim((string) ($f->tan ?? '')));
                if ($ft === null || (float) $ft !== (float) $o6) { $final = false; }
            }
            if ($bad !== null) { $drop($bad, $rid); continue; }
            // @ANCHOR-20261116-EXPECTED-OLDHIST-FIX  2．summary の馬番の全体＝結果の馬番の全体＝1〜N（欠けた馬を落として小さい頭立てにしない）
            $sumNos = array_map(static fn($h) => (int) $h->num, $hs); sort($sumNos);
            $resNos = array_map('intval', array_keys($res[$key] ?? [])); sort($resNos);
            if ($sumNos !== $resNos) { $drop('ROSTER_MISMATCH_SUMMARY_VS_RESULT', $rid); continue; }
            if ($sumNos !== range(1, count($sumNos))) { $drop('ROSTER_NOT_CONTIGUOUS', $rid); continue; }
            if (count($roster) < 2) { $drop('TOO_FEW_RUNNERS', $rid); continue; }
            // if ($same) { $drop('ODDS21_EQUALS_ODDS6', $rid); continue; }   // 旧（20261116 なる02 の4：一律に外さず、台帳のレースIDと原因で外す）
            if ($final) { $drop('ODDS6_EQUALS_FINAL', $rid); continue; }
            $post = new \DateTimeImmutable($d . ' 00:00:00', $tz);   // 発走時刻の記録が無いので日付だけ（post_time_status=DATE_ONLY）
            $batches = ['6' => ['batch_id' => 'summary:' . $rid . ':6', 'requested_at' => null, 'received_at' => null, 'available_at' => null,
                                'acquisition_time_status' => 'UNRECORDED', 'quotes' => $q6]];
            if ($q21) {
                $batches['21'] = ['batch_id' => 'summary:' . $rid . ':21', 'requested_at' => null, 'received_at' => null, 'available_at' => null,
                                  'acquisition_time_status' => 'UNRECORDED', 'quotes' => $q21];
            }
            $out[] = ['race_id' => $rid, 'N' => count($roster), 'post_at' => $post->format('Y-m-d\TH:i:s.uP'),
                'input_freeze_at' => $post->modify(BaganrikiTime::INPUT_FREEZE_OFFSET_SEC . ' seconds')->format('Y-m-d\TH:i:s.uP'),
                'acquisition_time_status' => 'UNRECORDED', 'post_time_status' => 'DATE_ONLY', 'history_policy' => self::EXPECTED_HISTORY_POLICY,
                'jurisdiction' => 'JRA', 'race_type' => 'FLAT', 'surface' => $surface,
                'distance_metres' => ((int) preg_replace('/\D/', '', (string) $p->dist)) ?: null,
                'class_group' => $class, 'roster' => $roster, 'batches' => $batches,
                'post_freeze_scratch' => false, 'results' => $results,
                // 'result_available_at' => (new \DateTimeImmutable($d . ' 23:59:59', $tz))->format('Y-m-d\TH:i:s.uP')];   // 旧（20261116 なる02 の1：仮の時刻を置かない）
                // @ANCHOR-20261116-EXPECTED-OLDHIST-FIX  結果の利用可能時刻は、確認台帳を当てるとき（expectedOldHistory）に入れる
                'result_available_at' => null, 'odds21_equals_odds6' => $same,
                'result_digest' => hash('sha256', json_encode([$rid, $roster, $q6, $q21, $results, $surface, $class, $p->dist], JSON_UNESCAPED_UNICODE))];
            $audit['adopted']++;
        }
        ksort($audit['excluded']);
        return ['races' => $out, 'audit' => $audit];
    }

    /**
     * 10/20版で組み立てた両役割の入力から、E3・E5 を付けた「予想の版」の入力を作ります。
     * @return array ['ok','common','json','hash','user1','user2','proof','audit','code','reason']
     */
    private static function expectedAssemble(array $a1, array $a2, array $ctx): array
    {
        try {
            BaganrikiDerivedValue::$lastProblem = null;
            $base = $a1['input'];
            foreach (BaganrikiPrompt::HASH_EXCLUDE as $k) { unset($base[$k]); }
            $base['schema_version'] = BaganrikiCandidateSelection::SCHEMA;
            $base['prompt_version'] = BaganrikiCandidateSelection::VERSION;
            $base = self::expectedAddGapPairs($base);
            $base = self::expectedMarkAbilitySignals($base);
            $current = self::expectedCurrent($base, $ctx);
            $history = self::expectedHistory(substr((string) $current['post_at'], 0, 10));
            $att = BaganrikiExpectedBridge::attach($base, $current, $history);
            $common = $att['common'];
            $hash = BaganrikiPrompt::commonHash($common);
            if (!$hash['ok']) { throw new \InvalidArgumentException('COMMON_HASH'); }
            $users = [];
            foreach (['1st' => $a1, '2nd' => $a2] as $role => $a) {
                $in = $common;
                foreach (['ai_role', 'role_cap', 'role_quota'] as $k) { $in[$k] = $a['input'][$k]; }
                $in['snapshot_hash'] = $hash['hash'];
                $enc = BaganrikiPrompt::encode($in);
                if (!$enc['ok']) { throw new \InvalidArgumentException('ROLE_ENCODE'); }
                $users[$role] = $enc['json'];
            }
            $proof = $att['audit']['expected_proof'];
            // 作った原文が、送信のときの照合（fromStored）を通ることを、凍結の前に確かめます
            foreach (['1st', '2nd'] as $role) {
                BaganrikiFrozenInput::fromStored($hash['json'], $users[$role], $hash['hash'], $role, $proof);
            }
            return ['ok' => true, 'common' => $common, 'json' => $hash['json'], 'hash' => $hash['hash'],
                    'user1' => $users['1st'], 'user2' => $users['2nd'], 'proof' => $proof, 'audit' => $att['audit'],
                    'code' => null, 'reason' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => 'EXPECTED_INPUT_INVALID',
                    'reason' => 'E3・E5を付けた入力を作れません：' . $e->getMessage()
                              . (BaganrikiDerivedValue::$lastProblem !== null ? ' ／ ' . BaganrikiDerivedValue::$lastProblem : '')];
        }
    }

    /**
     * @ANCHOR-20261109-PREDICTION-GAP-PAIRS  断層（H3・gap）の材料を凍結のときに作ります
     *   よっしーの仕様（R12 2章 H3）：「6分前単勝順で隣接する固定ペア。上側→下側の順、同じ観測枠、
     *   同じ win_odds または place_min の2頭の値に基づく ratio_12dp（後÷前、12桁で四捨五入）」。
     *   10/20版の入力にはこの組の値が無く、AIが断層を根拠にすると必ず検査で落ちていました（11/9 非公開試験）。
     *   ここで、隣り合う2頭（6分前の人気順で r 位と r+1 位）の比を、各観測枠（12・9・6分前など）で登録します。
     *   値は凍結時の元のオッズからの計算だけで、新しいデータは足しません。式は BaganrikiDerivedValue::matches が凍結時に検算します。
     */
    private static function expectedAddGapPairs(array $base): array
    {
        $reg = (array) ($base['source_registry'] ?? []);
        $byRank = [];
        foreach ((array) ($base['runners'] ?? []) as $r) {
            $rk = $r['win_rank'] ?? null;
            if (!is_int($rk)) { continue; }
            if (isset($byRank[$rk])) { $byRank[$rk] = false; continue; }   // 同じ人気順が2頭いるときは組にしません
            $byRank[$rk] = (int) $r['horse_no'];
        }
        ksort($byRank);
        $slots = (array) ($base['time_contract']['snapshot_offsets'] ?? []);
        $freeze = (string) ($base['input_freeze_at'] ?? '');
        // 12桁で四捨五入した b÷a（どちらも正の小数の文字列）
        $ratio = static function (string $a, string $b): ?string {
            if (!preg_match('/^\d+(\.\d+)?$/D', $a) || !preg_match('/^\d+(\.\d+)?$/D', $b)) { return null; }
            [$aw, $af] = array_pad(explode('.', $a, 2), 2, ''); [$bw, $bf] = array_pad(explode('.', $b, 2), 2, '');
            $sc = max(strlen($af), strlen($bf));
            $den = (int) ($aw . str_pad($af, $sc, '0')); $num = (int) ($bw . str_pad($bf, $sc, '0'));
            if ($den <= 0 || $num <= 0 || $num > 9000000) { return null; }
            $q = intdiv(2 * $num * 1000000000000 + $den, 2 * $den);
            $int = intdiv($q, 1000000000000); $frac = rtrim(str_pad((string) ($q % 1000000000000), 12, '0', STR_PAD_LEFT), '0');
            return $frac === '' ? (string) $int : $int . '.' . $frac;
        };
        $num = static function ($v): ?string {
            if ($v instanceof BaganrikiDecimal) { return $v->decimal(); }
            if (is_int($v)) { return (string) $v; }
            if (is_float($v) && is_finite($v)) { return BaganrikiPrompt::numToDecimalString($v); }
            return null;
        };
        $ranks = array_keys($byRank);
        foreach ($ranks as $rk) {
            $up = $byRank[$rk]; $low = $byRank[$rk + 1] ?? null;
            if ($up === false || !is_int($low)) { continue; }
            foreach (['win_odds' => 'win', 'place_min' => 'place'] as $field => $short) {
                foreach ($slots as $slot) {
                    $aRef = 'h' . $up . '_' . $field . '_' . $slot; $bRef = 'h' . $low . '_' . $field . '_' . $slot;
                    $a = $reg[$aRef] ?? null; $b = $reg[$bRef] ?? null;
                    if (!is_array($a) || !is_array($b) || ($a['source_kind'] ?? '') !== 'OBSERVED' || ($b['source_kind'] ?? '') !== 'OBSERVED') { continue; }
                    $av = $num($a['value'] ?? null); $bv = $num($b['value'] ?? null);
                    if ($av === null || $bv === null) { continue; }
                    $val = $ratio($av, $bv);
                    if ($val === null) { continue; }
                    $id = 'g' . $up . '_' . $low . '_' . $short . '_ratio_' . $slot;
                    $later = (BaganrikiTime::us((string) $b['available_at']) > BaganrikiTime::us((string) $a['available_at'])) ? $b : $a;
                    $e = array_replace($a, [
                        'scope' => 'PAIR', 'horse_nos' => [$up, $low], 'source_ref' => $id,
                        'field' => 'fixed_gap_' . $short . '_ratio', 'unit' => 'ratio', 'dependency_key' => $id,
                        'source_kind' => 'DERIVED', 'formula_id' => 'ratio_12dp', 'formula_version' => '1',
                        'computed_at' => $freeze, 'parents' => [$aRef, $bRef],
                        'observed_at' => $later['observed_at'] ?? ($a['observed_at'] ?? null),
                        'available_at' => $later['available_at'] ?? ($a['available_at'] ?? null),
                        'value' => (float) $val,
                    ]);
                    $reg[$id] = $e;
                    $base['input_indices'][BaganrikiPrompt::indexKey($e)][] = $id;
                    if (!isset($base['field_catalog'][$e['field']])) {
                        $base['field_catalog'][$e['field']] = ['unit' => 'ratio', 'scope' => 'PAIR', 'display_label' => $e['field'],
                            'value_type' => 'number', 'missing_allowed' => false, 'minimum' => 0, 'maximum' => null];
                    }
                }
            }
        }
        $base['source_registry'] = $reg;
        return $base;
    }

    /**
     * @ANCHOR-20261109-PREDICTION-ABILITY-SIGNAL  能力（H1・ability）の根拠に使う過去走の実測値を SIGNAL にします
     *   よっしーの仕様：「各evidenceには当該馬に適用できる非欠損の…SIGNALを含める」「H1　当該馬の過去成績…過去着順…」。
     *   10/20版の入力では過去走がすべて CONTEXT（補足）扱いで、能力の根拠が1つも検査を通りませんでした（11/9 非公開試験）。
     *   E3・E5版の凍結のときだけ、その馬自身の過去走の実測値（着順・着差・上がり3F）を SIGNAL にします。
     *   値・出典・時刻は変えません。日付・距離・馬場・頭数・人気などの条件と、まとめの計算値は CONTEXT のままです。
     */
    private static function expectedMarkAbilitySignals(array $base): array
    {
        foreach ((array) ($base['source_registry'] ?? []) as $id => $e) {
            if (!is_array($e) || ($e['scope'] ?? '') !== 'HORSE' || ($e['source_kind'] ?? '') !== 'OBSERVED') { continue; }
            if (!preg_match('/^past_(?:finish|time_diff|last_3f)_[1-9][0-9]*$/D', (string) ($e['field'] ?? ''))) { continue; }
            if (($e['value'] ?? null) === null) { continue; }
            $base['source_registry'][$id]['dependency_role'] = 'SIGNAL';
        }
        return $base;
    }

    /** 送信の窓を確かめるための、今のレース状態（よっしーの AttemptWindow が読む形） */
    /** 試験用の時計（artisan からの実行のときだけ効く）。本番では常に null。保存済みの過去レースで送る原文の準備だけを試すため */
    public static ?int $testExpectedNowUs = null;

    private static function expectedLiveState(int $snapshotId): array
    {
        if (self::$testExpectedNowUs !== null && app()->runningInConsole()) {
            $snapT = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
            return ['race_id' => (string) ($snapT->race_id ?? ''), 'snapshot_hash' => (string) ($snapT->snapshot_hash ?? ''),
                    'live_close_at' => null, 'all_cancelled' => false, 'race_started' => false,
                    'now' => BaganrikiTime::toJst(self::$testExpectedNowUs)];
        }
        $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
        $live = \DB::table('baganriki_live_states')->where('snapshot_id', $snapshotId)->first();
        $dl = ($snap === null) ? ['ok' => false] : BaganrikiWorker::deadlines($snap, $live, false, true);
        $close = BaganrikiWorker::dbUs($live->live_close ?? null);
        return [
            'race_id'       => (string) ($snap->race_id ?? ''),
            'snapshot_hash' => (string) ($snap->snapshot_hash ?? ''),
            'live_close_at' => $close === null ? null : BaganrikiTime::toJst($close),
            'all_cancelled' => ($live !== null && (int) ($live->active_count ?? 1) === 0),
            'race_started'  => ($dl['race_started'] ?? (($dl['code'] ?? '') === 'RACE_STARTED' ? true : null)) ?? false,
            'now'           => BaganrikiTime::toJst(BaganrikiWorker::nowUs()),
        ];
    }

    /** 保存した凍結原文から、その役割の入力を組み立て直す（E3・E5の計算原文とも照合） */
    private static function expectedFrozen(object $snap, string $role): BaganrikiFrozenInput
    {
        $col = ($role === '2nd') ? 'input_json_2nd' : 'input_json_1st';
        $proof = $snap->expected_proof ?? null;
        $proof = is_string($proof) ? json_decode($proof, true) : null;
        return BaganrikiFrozenInput::fromStored((string) $snap->common_json, (string) $snap->{$col},
            (string) $snap->snapshot_hash, $role, $proof);
    }

    /**
     * 送る原文を作ります。$repair は訂正のときだけ（初回は null）。
     * @return array ['ok','frozen','prepared','code','reason']
     */
    private static function expectedPrepare(object $snap, string $role, ?array $repair): array
    {
        try {
            $frozen = self::expectedFrozen($snap, $role);
            $format = ($repair !== null) ? (string) ($repair['format'] ?? '') : BaganrikiDispatch::wireFormatFor($role);
            if ($format === 'FULL' || $format === '') { $format = BaganrikiDispatch::wireFormatFor($role); }
            [$packWire, $expandWire] = BaganrikiDispatch::codec($format);
            $pack = static function (array $t) use ($packWire) {
                $r = $packWire($t);
                if (!($r['ok'] ?? false) || !is_array($r['wire'] ?? null)) { throw new \InvalidArgumentException('PACK_FAILED：' . (string) ($r['reason'] ?? '')); }
                return $r['wire'];
            };
            $expand = static function (array $w) use ($expandWire) {
                $r = $expandWire($w);
                if (!($r['ok'] ?? false) || !is_array($r['input'] ?? null)) { throw new \InvalidArgumentException('EXPAND_FAILED：' . (string) ($r['reason'] ?? '')); }
                return $r['input'];
            };
            $sid = (int) $snap->id;
            $prepared = BaganrikiExpectedBridge::prepareSend($frozen, $pack, $expand,
                static fn() => true, static fn() => self::expectedLiveState($sid), false,
                $repair === null ? null : $repair['repair']);
            return ['ok' => true, 'frozen' => $frozen, 'prepared' => $prepared, 'code' => 'OK', 'reason' => null];
        } catch (\Throwable $e) {
            $why = $e->getMessage();
            for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) { $why .= ' ← ' . $p->getMessage(); }
            if (BaganrikiExpectedBridge::$lastLoss !== null) { $why .= ' ／ ' . BaganrikiExpectedBridge::$lastLoss; }
            return ['ok' => false, 'frozen' => null, 'prepared' => null, 'code' => 'EXPECTED_PREPARE_FAILED',
                    'reason' => '送る原文を作れません：' . $why];
        }
    }

    /** 訂正のための材料（初回の受領・許可した範囲・診断・その封印）を、保存した記録から作ります */
    private static function expectedRepair(int $snapshotId, string $role, object $snap, array $insp): array
    {
        $row = \DB::table('baganriki_ai_attempts')->where('snapshot_id', $snapshotId)->where('role', $role)
                 ->where('attempt_no', BaganrikiWorker::FIRST)->first();
        if ($row === null) { throw new \InvalidArgumentException('FIRST_ATTEMPT_MISSING'); }
        $sent = json_decode((string) ($row->sent_request ?? ''), true);
        if (!is_array($sent)) { throw new \InvalidArgumentException('FIRST_SENT_REQUEST_MISSING'); }
        $frozen = self::expectedFrozen($snap, $role);
        $in = BaganrikiFrozenInput::checked($frozen);
        $hash = (string) $row->response_hash;
        $grant = BaganrikiAttemptWindow::repairGrant($hash, (array) ($insp['repair_scope'] ?? []));
        $diag = self::correctionErrorList($insp);
        $sentAt = self::inspectionSentAtFor($row, $snapshotId, (string) $snap->race_id, $role, BaganrikiWorker::FIRST);
        $receipt = [
            'attempt_no' => 0, 'ai_role' => $role, 'snapshot_hash' => $in['snapshot_hash'], 'race_id' => $in['race_id'],
            'status' => 'RECEIVED', 'complete' => (bool) $row->complete, 'original_approved' => true,
            'raw' => (string) $row->raw_response, 'response_hash' => $hash,
            'sent_at' => $sentAt, 'received_at' => self::dbTimeToRfc3339((string) $row->received_at),
            'sent_request' => $sent,
        ];
        return ['format' => (string) ($sent['format'] ?? ''), 'repair' => [
            'first_receipt' => $receipt, 'repair_scope' => $grant, 'diagnostics' => $diag,
            'inspection_sha256' => BaganrikiDispatch::inspectionSeal($frozen, $hash, $grant, $diag),
        ]];
    }

    /** 初回を実際に送った時刻（RFC3339）。sent_at 列が無いので、送信の記録（使用量の記録）から読みます */
    private static function inspectionSentAtFor(object $row, int $snapshotId, string $race, string $role, int $slot): string
    {
        $t = app(self::class)->inspectionSentAt($row, $snapshotId, $race, $role, $slot);
        return $t->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * @ANCHOR-20261020-PREDICTION-WIRE  両roleが終端になってから公開します
     *
     *   1stだけで公開してしまうと、2ndがこれから返ってくるのに
     *   片側だけの統合で出したことになります。
     *   ただし応答期限を過ぎたら、来ないものを待ちません。
     *
     * @return array publishPrediction の戻り、または ['ok'=>false,'code'=>'WAITING'…]
     */
    public function publishWhenReady(int $snapshotId, array $opt = []): array
    {
        $result = $this->publishReadyOnce($snapshotId, $opt);
        if (($result['window_changed'] ?? false) === true) {
            // Recheck pending roles as well as saved receipts, with a fresh server clock.
            unset($opt['now_us']);
            // return $this->publishReadyOnce($snapshotId, $opt);   // 旧
            $result = $this->publishReadyOnce($snapshotId, $opt);
        }
        self::recordNotPublished($snapshotId, $result);   // @ANCHOR-20261110-PREDICTION-NOTPUBLISHED
        return $result;
    }

    /**
     * @ANCHOR-20261110-PREDICTION-NOTPUBLISHED  予想を出せなかったレースを、原因と一緒に1回だけ記録します（よっしー11/10「予想を出せなかった件数と原因は記録」）
     *   公開の判定が「出せない」で確定したとき（NO_RESPONSE：両AIとも検証を通った候補が無い／PAST_PUBLISH_DEADLINE：公開期限を過ぎた）に、
     *   baganriki_audit_events へ kind=PREDICTION_NOT_PUBLISHED を1件入れます（同じ凍結入力には1件だけ）。
     *   原因として、AIごとに「送信の状態・失敗の理由・検査の判定と理由・送り直しの有無」を残します。
     *   記録できなくても、公開の判定には影響させません。
     */
    /** @ANCHOR-20261110-PREDICTION-NOTPUBLISHED-LOCK  試験用：ロックの中で「まだ無い」と確かめた直後に呼びます（本番は null） */
    public static ?\Closure $testAfterNotPublishedCheck = null;

    private static function recordNotPublished(int $snapshotId, array $result): void
    {
        try {
            $code = (string) ($result['code'] ?? '');
            // if (!in_array($code, ['NO_RESPONSE', 'PAST_PUBLISH_DEADLINE'], true)) { return; }   // 旧（公開期限切れのコード名を間違えていた：正しくは DEADLINE_PASSED）
            // @ANCHOR-20261110-PREDICTION-NOTPUBLISHED2  よっしー11/10夜 ④：「AIの評価を確認できない」と「評価は終わったが候補になれる馬が0頭」を分けて記録します
            //   AI_EVALUATION_UNCONFIRMED … 両AIとも検査を通った評価が無い（NO_RESPONSE）、または評価がそろわないまま公開期限を過ぎた（DEADLINE_PASSED）
            //   EVALUATED_NO_ELIGIBLE     … 評価は検査を通ったが、候補になれる馬が1頭もいないまま公開した（候補0頭の公開）
            $zero = (($result['ok'] ?? false) === true && $code === 'PUBLISHED' && (array) ($result['candidates'] ?? []) === []);
            if (!$zero && !in_array($code, ['NO_RESPONSE', 'DEADLINE_PASSED', 'PAST_PUBLISH_DEADLINE'], true)) { return; }
            if (!\Schema::hasTable('baganriki_audit_events')) { return; }
            if (\DB::table('baganriki_audit_events')->where('snapshot_id', $snapshotId)->where('kind', 'PREDICTION_NOT_PUBLISHED')->exists()) { return; }
            // 公開済みのレースを、公開期限のあとにもう一度判定したとき（DEADLINE_PASSED）は記録しません
            if (!$zero && \DB::table('baganriki_publications')->where('snapshot_id', $snapshotId)->exists()) { return; }
            $category = $zero ? 'EVALUATED_NO_ELIGIBLE' : 'AI_EVALUATION_UNCONFIRMED';
            // $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first(['race_id', 'generation']);   // 旧
            $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first(['race_id', 'generation', 'mode_status', 'allocation_mode']);
            if ($snap === null) { return; }
            // @ANCHOR-20261115-PREDICTION-SELECTION-DISABLED  豊田さん11/15（B）：選出停止（selection_enabled=false）のレースは、
            //   プロンプトの決まり（UNRESOLVED/selection_enabled=false では全頭評価だけを返し、公開は停止する）どおり公開しません。
            //   AIの評価を確かめられなかったのではないので、AI_EVALUATION_UNCONFIRMED ではなく SELECTION_DISABLED として分けて残します
            //   （11/15 京都6R：両AIの返事は届いて検査も通っていたのに、期限切れ・AI未確認と記録されていた）。公開するかどうかの動きは変えません。
            if (!$zero && $snap->allocation_mode === null && (string) $snap->mode_status === 'UNRESOLVED') { $category = 'SELECTION_DISABLED'; }
            $roles = [];
            foreach (['1st', '2nd'] as $role) {
                $a = \DB::table('baganriki_ai_attempts')->where('snapshot_id', $snapshotId)->where('role', $role)
                       ->where('attempt_no', 0)->first(['id', 'status', 'failure_reason', 'reserved_at', 'received_at']);
                $v = $a ? \DB::table('baganriki_validation_results')->where('attempt_id', $a->id)->orderByDesc('id')->value('diagnostics') : null;
                $vd = $v ? json_decode((string) $v, true) : null;
                $u = \DB::table('baganriki_audit_events')->where('snapshot_id', $snapshotId)->where('kind', 'R122_USAGE')
                       ->where('related_id', $role . '#0')->value('diagnostics');
                $ud = $u ? json_decode((string) $u, true) : null;
                $rs = is_array($ud['resent'] ?? null) ? $ud['resent'] : null;
                if (is_array($rs)) { foreach ((array) ($rs['sends'] ?? []) as $ri => $sd) { unset($rs['sends'][$ri]['raw_unused'], $rs['sends'][$ri]['usage']); } }
                $roles[$role] = [
                    'attempt_status' => $a ? (string) $a->status : 'NO_ATTEMPT',
                    'failure_reason' => $a ? $a->failure_reason : null,
                    'inspection_code' => is_array($vd) ? ($vd['code'] ?? null) : null,
                    'inspection_reason' => is_array($vd) ? mb_substr((string) ($vd['reason'] ?? ''), 0, 300) : null,
                    'resent' => $rs,
                ];
            }
            $now = BaganrikiWorker::nowUs();
            // \DB::table('baganriki_audit_events')->insert([ ... ]);   // 旧（「無いことの確認」と「追加」が別々で、同時に2つの処理が来ると2件入り得た）
            // @ANCHOR-20261110-PREDICTION-NOTPUBLISHED-LOCK  よっしー11/10夜2 ②：同じ凍結入力の行を FOR UPDATE でロックしたトランザクションの中で、
            //   「まだ記録が無いこと」の確認と追加を一体にします。同時に来た2つ目の処理は、1つ目の確定を待ってから確認し、追加しません。
            \DB::transaction(static function () use ($snapshotId, $snap, $category, $code, $result, $roles, $now, $zero) {
                \DB::table('baganriki_snapshots')->where('id', $snapshotId)->lockForUpdate()->first(['id']);
                if (\DB::table('baganriki_audit_events')->where('snapshot_id', $snapshotId)->where('kind', 'PREDICTION_NOT_PUBLISHED')->exists()) { return; }
                if (!$zero && \DB::table('baganriki_publications')->where('snapshot_id', $snapshotId)->exists()) { return; }
                if (self::$testAfterNotPublishedCheck !== null) { (self::$testAfterNotPublishedCheck)($snapshotId); }   // 試験用の差し込み口（本番は null）
                \DB::table('baganriki_audit_events')->insert([
                    'race_id' => (string) $snap->race_id, 'snapshot_id' => $snapshotId, 'kind' => 'PREDICTION_NOT_PUBLISHED',
                    'happened_at' => BaganrikiWorker::toDb($now), 'old_revision' => null, 'new_revision' => null,
                    'diagnostics' => json_encode(['category' => $category, 'publish_code' => $code, 'publish_reason' => mb_substr((string) ($result['reason'] ?? ''), 0, 500),
                                                  'generation' => (int) $snap->generation, 'roles' => $roles], JSON_UNESCAPED_UNICODE),
                    'related_id' => $category,
                ]);
            });
        } catch (\Throwable $e) {
            \Log::warning('[PREDICTION-NOTPUBLISHED] 予想を出せなかった記録を残せませんでした', ['snapshot_id' => $snapshotId, 'message' => $e->getMessage()]);
        }
    }

    private function publishReadyOnce(int $snapshotId, array $opt): array
    {
        $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
        if ($snap === null) {
            return ['ok' => false, 'code' => 'NO_SNAPSHOT',
                    'reason' => '凍結入力がありません'];
        }
        if (BaganrikiWorker::isExpectedSnapshot($snap)) {
            // The queue decision belongs to the same revision and deadlines as its selection.
            $opt['require_settled_expected'] = true;
            return $this->publishPrediction($snapshotId, $opt);
        }
        $live = \DB::table('baganriki_live_states')
                  ->where('snapshot_id', $snapshotId)->first();
        $dl   = BaganrikiWorker::deadlines($snap, $live);
        $now  = (int) ($opt['now_us'] ?? BaganrikiWorker::nowUs());

        return $this->pendingPublicationRoles($snap, $dl, $now)
            ?? $this->publishPrediction($snapshotId, $opt);
    }

    private function pendingPublicationRoles(object $snap, array $dl, int $now): ?array
    {
        $snapshotId = (int) $snap->id;
        $settled = 0; $waiting = [];
        foreach (['1st', '2nd'] as $role) {
            $f = \DB::table('baganriki_ai_attempts')
                   ->where('snapshot_id', $snapshotId)->where('role', $role)
                   ->where('attempt_no', BaganrikiWorker::FIRST)->first();
            if ($f === null) { $waiting[] = $role . '（枠なし）'; continue; }
            if (in_array((string) $f->status, BaganrikiWorker::TERMINAL, true)) {
                // 訂正の枠が予約済みで未終端なら、まだ待ちます
                $x = \DB::table('baganriki_ai_attempts')
                       ->where('snapshot_id', $snapshotId)->where('role', $role)
                       ->where('attempt_no', BaganrikiWorker::FIX)->first();
                if ($x !== null
                    && !in_array((string) $x->status, BaganrikiWorker::TERMINAL, true)) {
                    $waiting[] = $role . '（訂正中）';
                    continue;
                }
                // ══════════════════════════════════════════════════════════
                // @ANCHOR-20261021-R122-SETTLE  受領の終端≠採否の確定
                // ══════════════════════════════════════════════════════════
                //   ここまでで分かるのは「HTTPが返った」ことだけです。
                //   TERMINAL には RECEIVED が含まれるので、
                //   診断・訂正の要否をまだ判定していない role まで
                //   終端として数えてしまいます（010の2-4）。
                //   採否確定の印（R122_ROLE_SETTLED）を別に見ます。
                $sv = self::roleSettled($snapshotId, $role);
                if (!$sv['settled']) {
                    $waiting[] = $role . '（採否未確定）';
                    continue;
                }
                $settled++;
                continue;
            }
            $waiting[] = $role . '（' . (string) $f->status . '）';
        }
        if ($waiting !== []) {
            // 応答期限を過ぎたら待ちません（もう届いても遅着です）
            // ══════════════════════════════════════════════════════════════
            // @ANCHOR-20261021-R122-ATDEADLINE  期限ぴったりも「過ぎた」と見ます
            // ══════════════════════════════════════════════════════════════
            //   ご指摘（010の2-3 末尾）：
            //     「現行 publishWhenReady は $now > reply の判定なので、
            //       期限ぴったりの1回でWAITINGになって終わらないよう、
            //       期限直後の再判定まで含めて設計してください。」
            //   `>` だと、期限と同じマイクロ秒に来た1回が WAITING で終わり、
            //   その後だれも呼び直さないと公開されないままになります。
            //   `>=` にして、期限に達した時点で待つのをやめます。
            // $late = ($dl['ok'] && $dl['reply'] !== null && $now > $dl['reply']);  // 旧
            $late = ($dl['ok'] && $dl['reply'] !== null && $now >= $dl['reply']);
            if (!$late) {
                return ['ok' => false, 'code' => 'WAITING',
                        'reason' => 'まだ終端でないroleがあります: '
                                  . implode('・', $waiting),
                        'settled' => $settled, 'waiting' => $waiting];
            }
            \Log::error('[PREDICTION-WIRE] 応答期限を過ぎたので待たずに公開します', [
                'race_id' => (string) $snap->race_id, 'waiting' => $waiting,
            ]);
        }
        return null;
    }

    /**
     * @ANCHOR-20261020-PREDICTION-WIRE  原応答を保存した直後に呼ぶ入口
     *
     *   receiveAiSlotResponse() が成功した直後に、roleごとに1回だけ呼びます。
     *   ここから先は商品（旧来の予想）とは独立です。何が起きても
     *   例外を外へ出しません。出すと予想が落ちるためです。
     *
     * @param array $q reserveAiSlot の戻り
     * @return array ['ran','inspect','fix','resolve','publish']
     */
    public function afterAiResponseReceived($date, $kaisuu, $basho, $day, $race,
                                     string $role, array $q): array
    {
        $out = ['ran' => false, 'inspect' => null, 'fix' => null,
                'resolve' => null, 'publish' => null, 'settled' => null];
        if (($q['skip'] ?? true) || !($q['ok'] ?? false)) { return $out; }
        $sid = (int) ($q['snapshot_id'] ?? 0);
        if ($sid <= 0) { return $out; }
        $raceId = self::buildPredictionRaceId($date, $kaisuu, $basho, $day, $race);

        try {
            $out['ran'] = true;

            // ① 初回の検証（局所回復はこの中でやります）
            $insp = $this->inspectAiResponse($sid, $role, BaganrikiWorker::FIRST);
            $out['inspect'] = ['ok' => $insp['ok'], 'code' => $insp['code'],
                               'complete' => $insp['complete'],
                               'needs_fix' => $insp['needs_fix'],
                               'normal' => count((array) $insp['normal']),
                               'kept' => count((array) $insp['normal_kept']),
                               'salvaged' => (array) ($insp['salvaged'] ?? [])];
            \Log::error('[PREDICTION-WIRE] 初回を検証しました', [
                'race_id' => $raceId, 'role' => $role,
                'ok' => $insp['ok'], 'code' => $insp['code'],
                'complete' => $insp['complete'], 'needs_fix' => $insp['needs_fix'],
                '採用できた頭数' => count((array) $insp['normal']),
                '保持した頭数'   => count((array) $insp['normal_kept']),
                'reason' => $insp['reason'],
            ]);

            // ② 必要なら訂正を1回だけ（最大1回は fixAllowed が見張ります）
            // @ANCHOR-20261023-COST-FIXPOLICY  訂正の依頼は、サーバ側で補えないときだけ（費用のため）
            //   【旧】if ($insp['ok'] && $insp['needs_fix'] && $insp['complete'] === true) {
            $fixPol = self::correctionPolicyAllows($insp);
            if (!$fixPol['allow'] && $insp['ok'] && $insp['needs_fix']) {
                $out['fix'] = ['sent' => false, 'ok' => false, 'code' => 'FIX_SKIPPED_BY_POLICY',
                               'reason' => $fixPol['reason']];
                \Log::error('[PREDICTION-WIRE] 訂正は依頼しません（費用の方針）', [
                    'race_id' => $raceId, 'role' => $role, 'reason' => $fixPol['reason'],
                ]);
            }
            if ($insp['ok'] && $insp['needs_fix'] && $insp['complete'] === true
                && $fixPol['allow']) {
                $fx = $this->sendCorrectionRequest($sid, $role, $insp);
                $out['fix'] = $fx;
                \Log::error('[PREDICTION-WIRE] 訂正の結果', [
                    'race_id' => $raceId, 'role' => $role,
                    'sent' => $fx['sent'], 'ok' => $fx['ok'],
                    'code' => $fx['code'], 'reason' => $fx['reason'],
                ]);
            }

            // ③ 解決（訂正が無い・採用できないときは初回の正常部分）
            // @ANCHOR-20261023-NARU-011  訂正の予約が版の変化で取れなかった（枠を使っていない）ときは、
            //   採否を確定せずに戻ります。次の巡回・Job でもう一度判断します（011再精査2版）
            if (is_array($out['fix']) && !($out['fix']['sent'] ?? false)
                && (string) ($out['fix']['code'] ?? '') === 'STATE_CHANGED') {
                \Log::error('[PREDICTION-WIRE] 訂正の予約が版の変化で取れなかったので、採否は確定しません', [
                    'race_id' => $raceId, 'role' => $role,
                ]);
                return $out;
            }
            $rv = $this->acceptedHorsesOf($sid, $role);
            $out['resolve'] = ['ok' => $rv['ok'] ?? false, 'code' => $rv['code'] ?? null,
                               'merged' => count((array) ($rv['merged'] ?? []))];

            // ══════════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-SETTLE  ここで採否が確定しました（）
            // ══════════════════════════════════════════════════════════════
            //   診断（①）→必要な訂正（②）→採否（③）まで終わったので、
            //   この role の「採否確定」の印を付けます。
            //   印には、いまの応答の版（初回と訂正の状態から作るSHA-256）を
            //   入れます。あとから訂正が届けば版が変わり、印は効きません。
            $mk = self::markRoleSettled($sid, $role, [
                'resolve_ok'   => (bool) ($rv['ok'] ?? false),
                'resolve_code' => (string) ($rv['code'] ?? ''),
                'merged'       => count((array) ($rv['merged'] ?? [])),
                'fix_sent'     => (bool) ($out['fix']['sent'] ?? false),
                'inspect_code' => (string) ($insp['code'] ?? ''),
                // @ANCHOR-20261023-NARU-FIXVER  どの訂正方針で決めたかを残します（なる 10/23 4章(1)「適用版を分ける」）
                //   contract＝010提出版までの方針（FIX_POLICY_CONTRACT_V1）
                //   missing ＝10/23 からの方針（FIX_POLICY_MISSING_V2）。指示文・Schema は変えていません
                'fix_policy'        => self::correctionPolicyId(),
                'fix_policy_reason' => (string) ($fixPol['reason'] ?? ''),
                'fix_skipped'       => (string) ($out['fix']['code'] ?? '') === 'FIX_SKIPPED_BY_POLICY',
            ]);
            $out['settled'] = $mk;
            \Log::error('[PREDICTION-WIRE] 採否を確定しました', [
                'race_id' => $raceId, 'role' => $role,
                'marked'  => $mk['marked'], 'already' => $mk['already'],
                'version' => $mk['version'],
            ]);

            // ══════════════════════════════════════════════════════════════
            // @ANCHOR-20261021-PREDICTION-JOBHANDOFF  公開は集計Jobへ渡します
            // ══════════════════════════════════════════════════════════════
            //
            //   Jobの段が上がっているときは、ここで公開まで進めません。
            //   ③で採否確定の印を残したので、集計Jobがそれを見ます。
            //   Jobの段が下りているときは、これまでどおりここで進めます
            //   （いまの本番はこちらです。動きは変わりません）。
            if (BaganrikiWorker::jobs()
                && class_exists(\App\Jobs\PredictionAggregateRaceJob::class)) {
                $out['publish'] = ['ok' => false, 'code' => 'HANDED_TO_AGGREGATE',
                                   'publication_id' => null, 'candidates' => 0,
                                   'reason' => '採否確定の印を残し、集計Jobへ渡しました'];
                \Log::error('[PREDICTION-WIRE] 公開は集計Jobへ渡しました', [
                    'race_id' => $raceId, 'role' => $role,
                    'settled' => $mk['marked'] ?? null,
                ]);
                return $out;
            }

            // ④ 公開（両roleの採否が確定してから）
            $pb = $this->publishWhenReady($sid);
            $out['publish'] = ['ok' => $pb['ok'] ?? false, 'code' => $pb['code'] ?? null,
                               'publication_id' => $pb['publication_id'] ?? null,
                               'candidates' => count((array) ($pb['candidates'] ?? [])),
                               'reason' => $pb['reason'] ?? null];
            \Log::error('[PREDICTION-WIRE] 公開の結果', [
                'race_id' => $raceId, 'role' => $role,
                'ok' => $pb['ok'] ?? false, 'code' => $pb['code'] ?? null,
                'publication_id' => $pb['publication_id'] ?? null,
                '公開候補' => count((array) ($pb['candidates'] ?? [])),
                'reason' => $pb['reason'] ?? null,
            ]);
            return $out;
        } catch (\Throwable $e) {
            // @ANCHOR-20261005-AUX-NEVER-BREAKS  記録のための処理で予想を殺しません
            \Log::error('[PREDICTION-WIRE] 接続で例外（予想は返します）', [
                'race_id' => $raceId, 'role' => $role,
                'message' => $e->getMessage(),
                'at'      => $e->getFile() . ':' . $e->getLine(),
            ]);
            return $out;
        }
    }

    public function sweepPendingPredictions(array $opt = []): array
    {
        $out = ['scanned' => 0, 'resumed' => 0, 'published' => 0, 'rows' => [],
                // @ANCHOR-20261021-PREDICTION-SENDFIRST  初回をやり直した件数
                'first_sent' => 0, 'first_codes' => [],
                // @ANCHOR-20261021-PREDICTION-JOBSWEEP  Jobを積んだ件数（）
                'dispatched' => 0];
        if (!BaganrikiWorker::enabled() || !BaganrikiWorker::ready()) { return $out; }
        $now = (int) ($opt['now_us'] ?? BaganrikiWorker::nowUs());
        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261023-PREDICTION-SWEEPFREEZE  対象レースの凍結を巡回から起こす（003 P-03）
        // ══════════════════════════════════════════════════════════════════
        //   【いま】対象レース（TARGETS）のうち、まだ凍結が無く、
        //   情報上限（発走330秒前）を過ぎて発走前のものを、ここで凍結します。
        //   Jobの段なら凍結Jobを積むだけ、そうでなければその場で凍結します。
        //   凍結ができれば、このすぐ下の巡回が同じ回で初回の送信へ進めます。
        // ══════════════════════════════════════════════════════════════════
        // @ANCHOR-20261023-PREDICTION-SWEEPROLE  1st と 2nd を別のプロセスで同時に回せます
        // ══════════════════════════════════════════════════════════════════
        //   【いま】$opt['role'] に '1st' か '2nd' を渡すと、そのroleだけを
        //   受け持ちます。cron に2行（1st用・2nd用）入れれば同時に進みます。
        //   凍結は '2nd' 用では起こしません（同じレースを2本で凍結しないため。
        //   2nd 用は、1st 用が作った凍結を次の刻みで拾います）。
        //   渡さなければ、いままでどおり両方を1本で回します。
        $roleOnly = (string) ($opt['role'] ?? '');
        $roles = in_array($roleOnly, ['1st', '2nd'], true) ? [$roleOnly] : ['1st', '2nd'];
        $out['roles'] = $roles;
        if ($roleOnly !== '2nd') {
            $out['freeze'] = self::sweepFreezeTargetRaces($this, $now);
        }
        $now = (int) ($opt['now_us'] ?? BaganrikiWorker::nowUs());

        // 期限内のものだけ。終わった開催を毎分なめないよう、当日前後に絞ります。
        $snaps = \DB::table('baganriki_snapshots')
                   ->where('generation', 0)
                   ->whereNotNull('sales_close_at')
                   ->whereBetween('sales_close_at', [
                        BaganrikiWorker::toDb($now - 3600 * 1000000),
                        BaganrikiWorker::toDb($now + 6 * 3600 * 1000000),
                   ])
                   ->orderBy('sales_close_at')->get();

        foreach ($snaps as $snap) {
            $out['scanned']++;
            $sid = (int) $snap->id;
            try {
                // ══════════════════════════════════════════════════════
                // @ANCHOR-20261115-PREDICTION-SWEEPSKIP  投票締切を過ぎて、公開か「出せなかった」の記録まで終わったレースは飛ばします
                // ══════════════════════════════════════════════════════
                //   11/15 の実測：この巡回は1時間前までのレースを毎回なめ、1レースごとに凍結入力（約50万字）を
                //   読み直して期限を計算していました（frozenSales）。そのため、その日の2レース目以降は、
                //   凍結から初回の送信まで 30〜60秒 かかり（朝の1レース目は約10秒）、
                //   東京6R・福島8R・東京9R・京都8R は 2nd を送る時間が残らず、京都6R は公開が締切に間に合いませんでした。
                //   【いま】DBの投票締切（sales_close_at）を60秒過ぎ、公開か「出せなかった」の記録があり、
                //   送信中・予約中のAIが無いレースは、重い読み直しの前に飛ばします。まだ終わっていないレースは今までどおり回します。
                $closeUs = BaganrikiWorker::dbUs($snap->sales_close_at ?? null);
                if ($closeUs !== null && $now > $closeUs + 60 * 1000000
                    && (\DB::table('baganriki_publications')->where('snapshot_id', $sid)->exists()
                        || \DB::table('baganriki_audit_events')->where('snapshot_id', $sid)
                               ->where('kind', 'PREDICTION_NOT_PUBLISHED')->exists())
                    && !\DB::table('baganriki_ai_attempts')->where('snapshot_id', $sid)
                           ->whereNotIn('status', BaganrikiWorker::TERMINAL)->exists()) {
                    $out['rows'][] = (string) $snap->race_id . ':DONE_SKIPPED';
                    continue;
                }
                // @ANCHOR-20261023-NARU-STARTTIME  発走時刻の前倒しを、オッズ取得とは別にここでも入れます
                if ($roleOnly !== '2nd') {
                    $st = self::syncLiveStartTime($sid, 'sweep');
                    if ($st['ran']) { $out['rows'][] = (string) $snap->race_id . ':START_TIME_' . (string) $st['code']; }
                }
                $live = \DB::table('baganriki_live_states')
                          ->where('snapshot_id', $sid)->first();
                $dl   = BaganrikiWorker::deadlines($snap, $live);
                // ══════════════════════════════════════════════════════
                // いま飛んでいる送信を殺さないための見張り
                // ══════════════════════════════════════════════════════
                //   resume() は status=SENT を「結果不明」にします。
                //   HTTPの中でまだAIを待っている枠も SENT なので、
                //   <b>応答期限を過ぎてからでないと呼べません</b>。
                //   （過ぎていれば、いま届いても遅着で捨てる時刻です）
                if ($dl['ok'] && $dl['reply'] !== null && $now > $dl['reply']) {
                    $rs = BaganrikiWorker::resume($sid, $now);
                    if ((array) ($rs['unknown'] ?? []) !== []) { $out['resumed']++; }
                }

                // ══════════════════════════════════════════════════════
                // @ANCHOR-20261021-PREDICTION-TARGET12  巡回の入口（）
                // ══════════════════════════════════════════════════════
                //   対象レースでないものは、拾っても何もしません。
                //   送信の手前で断ってもよいのですが、空振りのログが
                //   毎分ぶん増えるので、ここで外します。
                $tgW = self::checkPredictionTarget((string) $snap->race_id);
                if (!$tgW['ok']) {
                    $out['rows'][] = (string) $snap->race_id . ':'
                                   . (string) $tgW['code'];
                    continue;
                }

                // @ANCHOR-20261023-PREDICTION-SWEEPROLE  受け持つroleだけ回します
                foreach ($roles as $role) {
                    // ══════════════════════════════════════════════════
                    // @ANCHOR-20261021-PREDICTION-SENDFIRST
                    // ══════════════════════════════════════════════════
                    $any = \DB::table('baganriki_ai_attempts')
                             ->where('snapshot_id', $sid)->where('role', $role)
                             ->where('attempt_no', BaganrikiWorker::FIRST)->first();
                    // @ANCHOR-20261023-NARU-RESUME  予約済み・未送信で止まった初回も、同じ口から送り直します
                    //   （sendInitialAiRequest が同じ token で送信許可を取り直します。新しい枠は作りません）
                    if ($any === null
                        || (string) $any->status === BaganrikiWorker::RESERVED) {
                        // ══════════════════════════════════════════════
                        // @ANCHOR-20261021-PREDICTION-JOBSWEEP
                        // ══════════════════════════════════════════════
                        //   Jobの段が上がっているときは、ここでは送らず、
                        //   <b>積むだけ</b>にします。巡回は1分ごとなので、
                        //   ここでAIを待つと巡回そのものが詰まります。
                        if (BaganrikiWorker::jobs()
                            && class_exists(\App\Jobs\PredictionRunRoleJob::class)) {
                            \App\Jobs\PredictionRunRoleJob::dispatch($sid, $role);
                            $out['first_codes'][] = $role . ':JOB_DISPATCHED';
                            $out['dispatched'] = (int) ($out['dispatched'] ?? 0) + 1;
                            continue;
                        }
                        $fs = $this->sendInitialAiRequest($sid, $role);
                        if ($fs['sent']) { $out['first_sent']++; }
                        $out['first_codes'][] = $role . ':' . (string) $fs['code'];
                        continue;
                    }
                    $f = \DB::table('baganriki_ai_attempts')
                           ->where('snapshot_id', $sid)->where('role', $role)
                           ->where('attempt_no', BaganrikiWorker::FIRST)
                           ->where('status', BaganrikiWorker::RECEIVED)->first();
                    if ($f === null) { continue; }
                    // 受領の直後は、HTTPの側がいま訂正を送っている最中かも
                    // しれません。少し間を置いた枠だけを拾います
                    //   （枠の一意キーで二重送信は防げますが、
                    //     空振りのログを増やさないための用心です）。
                    $recv = BaganrikiWorker::dbUs($f->received_at ?? null);
                    if ($recv !== null
                        && ($now - $recv) < self::SWEEP_RECEIVE_GRACE_SEC * 1000000) {
                        continue;
                    }
                    // ══════════════════════════════════════════════════
                    // @ANCHOR-20261021-PREDICTION-JOBSWEEP  訂正も同期では送りません
                    // ══════════════════════════════════════════════════
                    //   Jobの段が上がっているときは、巡回から訂正を
                    //   送りません。受領まで進んでいて採否が未確定なら、
                    //   そのroleのJobを積み直します（投入漏れの回復）。
                    //   訂正を送るのは RunRoleJob の中だけです。
                    if (BaganrikiWorker::jobs()) {
                        // @ANCHOR-20261023-NARU-DEADLINE  回復の経路でも期限の集計を確保します
                        self::ensureDeadlineAggregation($sid);
                        $sv = self::roleSettled($sid, $role);
                        if (!$sv['settled']
                            && class_exists(\App\Jobs\PredictionRunRoleJob::class)) {
                            \App\Jobs\PredictionRunRoleJob::dispatch($sid, $role);
                            $out['first_codes'][] = $role . ':JOB_REDISPATCHED';
                            $out['dispatched'] = (int) ($out['dispatched'] ?? 0) + 1;
                        }
                        continue;
                    }
                    // @ANCHOR-20261023-NARU-RESUME  同期でも、受領済み・採否未確定は
                    //   「診断→訂正→採否確定→公開判定」を最後まで通します（なる 10/23 重要4）。
                    //   【旧】診断と訂正だけで、採否確定の印を付けていませんでした。
                    // $insp = $this->inspectAiResponse($sid, $role, BaganrikiWorker::FIRST);
                    // if ($insp['ok'] && $insp['needs_fix'] && $insp['complete'] === true) {
                    //     $this->sendCorrectionRequest($sid, $role, $insp);
                    // }
                    if (!self::roleSettled($sid, $role)['settled']) {
                        $this->resumeReceivedRole($sid, $role);
                    }
                }
                // ══════════════════════════════════════════════════
                // @ANCHOR-20261021-PREDICTION-NOWFRESH
                // ══════════════════════════════════════════════════
                //   【そのとおりでした】$now は巡回を<b>始めたときの1回だけ</b>
                //   取っていました。この間に訂正の送信でAIを待つので、
                //   長いときは分単位でずれます。期限の判定と公開の起動に
                //   古い時刻を使うと、過ぎている期限を過ぎていないと見ます。
                //   【いま】ここで取り直します。試験用の時計は opt から
                //   渡されたときだけ使い、本番では渡しません。
                $nowPub = isset($opt['now_us'])
                        ? (int) $opt['now_us'] : BaganrikiWorker::nowUs();
                $pb = $this->publishWhenReady($sid, ['now_us' => $nowPub]);
                if ($pb['ok'] ?? false) { $out['published']++; }
                $out['rows'][] = [
                    'race_id' => (string) $snap->race_id,
                    'publish' => (string) ($pb['code'] ?? ''),
                ];
            } catch (\Throwable $e) {
                \Log::error('[PREDICTION-WIRE] やり直しで例外', [
                    'race_id' => (string) $snap->race_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        return $out;
    }

    public function dispatchPredictionJobs($date, $kaisuu, $basho, $day, $race): array
    {
        $out = ['ran' => false, 'code' => null, 'reason' => null, 'dispatched' => []];
        try {
            if (!BaganrikiWorker::jobs()) {
                $out['code'] = 'JOBS_OFF';
                $out['reason'] = 'Jobの段が下りているので積みません';
                return $out;
            }
            if (!class_exists(\App\Jobs\PredictionFreezeRaceJob::class)) {
                $out['code'] = 'JOBS_MISSING';
                $out['reason'] = 'app/Jobs が配置されていません';
                return $out;
            }
            if (!\App\Jobs\PredictionJob::ready()) {
                $out['code'] = 'QUEUE_TABLE_MISSING';
                $out['reason'] = 'jobs 表がありません';
                return $out;
            }
            $raceId = self::buildPredictionRaceId($date, $kaisuu, $basho, $day, $race);

            // ── 対象レースの確認（ここでも見ます）────────────────────
            $tg = self::checkPredictionTarget($raceId);
            if (!$tg['ok']) {
                $out['code'] = (string) $tg['code'];
                $out['reason'] = (string) $tg['reason'];
                return $out;
            }
            $out['ran'] = true;

            $snap = \DB::table('baganriki_snapshots')
                      ->where('race_id', $raceId)->where('generation', 0)->first();

            // ── 凍結がまだなら、凍結のJobだけ積みます ────────────────
            if ($snap === null) {
                \App\Jobs\PredictionFreezeRaceJob::dispatch(
                    (string) $date, (string) $kaisuu, (string) $basho,
                    (string) $day, (int) $race);
                $out['code'] = 'FREEZE_DISPATCHED';
                $out['dispatched'][] = 'freeze';
                return $out;
            }

            $sid = (int) $snap->id;

            // ── 旧経路で始まったレースには触りません（010の2-5）──────
            $path = self::processingPathOf($sid);
            if ($path === 'SYNC') {
                $out['code'] = 'PATH_IS_SYNC';
                $out['reason'] = 'このレースは旧経路で始まっているので積みません';
                return $out;
            }
            self::fixProcessingPath($sid, 'JOB');

            // @ANCHOR-20261023-NARU-DEADLINE  先に期限での集計を確保します（なる 10/23 重要3）
            //   role を積んだあと・期限を予約する前に止まっても、予約が欠けないように順番を入れ替えました。
            $ed = self::ensureDeadlineAggregation($sid);
            if ($ed['scheduled']) { $out['dispatched'][] = 'deadline'; }

            // ── 足りない role のJobを積みます ───────────────────────
            //   @ANCHOR-20261023-NARU-RESUME  「枠が無い」だけでなく、予約済み・未送信と
            //   受領済み・採否未確定も積みます（RunRoleJob → sendInitialAiRequest が再開します）
            foreach (['1st', '2nd'] as $role) {
                if (!self::roleNeedsWork($sid, $role)) { continue; }
                \App\Jobs\PredictionRunRoleJob::dispatch($sid, $role);
                $out['dispatched'][] = 'role:' . $role;
            }

            // ── 集計も積みます（受領を待たずに期限で動く側）──────────
            \App\Jobs\PredictionAggregateRaceJob::dispatch($sid, 'ENTRY');
            $out['dispatched'][] = 'aggregate';
            $out['code'] = 'DISPATCHED';
            return $out;
        } catch (\Throwable $e) {
            // @ANCHOR-20261005-AUX-NEVER-BREAKS  記録のための処理で予想を殺しません
            \Log::error('[PREDICTION-JOB] 積むところで例外（予想は返します）',
                ['message' => $e->getMessage(),
                 'at' => $e->getFile() . ':' . $e->getLine()]);
            $out['code'] = 'EXCEPTION';
            $out['reason'] = $e->getMessage();
            return $out;
        }
    }

    public function applyLiveChange(int $snapshotId, array $change): array
    {
        $out = ['ok' => false, 'code' => null, 'reason' => null, 'changed' => [],
                'state_revision' => null, 'cancellations' => [],
                'live_close_us' => null];
        try {
            $snap = \DB::table('baganriki_snapshots')->where('id', $snapshotId)->first();
            if ($snap === null) {
                $out['code'] = 'NO_SNAPSHOT';
                $out['reason'] = '凍結入力がありません';
                return $out;
            }
            BaganrikiWorker::ensureLive($snapshotId, BaganrikiWorker::nowUs());

            $res = \DB::transaction(function () use ($snapshotId, $change, $snap) {
                $live = \DB::table('baganriki_live_states')
                          ->where('snapshot_id', $snapshotId)
                          ->lockForUpdate()->first();
                if ($live === null) {
                    return ['ok' => false, 'code' => 'NO_LIVE',
                            'reason' => 'ライブ状態の行がありません', 'changed' => []];
                }
                $changed = [];

                // ── ① 正式取消（足すだけ）────────────────────────────
                $isExpected = BaganrikiWorker::isExpectedSnapshot($snap);
                if ($isExpected) {
                    $saved = BaganrikiWorker::verifiedLiveRoster($snap, $live, true, $issue);
                    if ($saved === null) {
                        return BaganrikiWorker::liveStateFailure($issue);
                    }
                    $cur = $saved['withdrawn'];
                    $delta = array_key_exists('cancellations', $change) ? $change['cancellations'] : [];
                    $invalid = ['ok' => false, 'code' => 'INVALID_LIVE_CHANGE', 'reason' => '取消馬番または変更締切を照合できません'];
                    if (!is_array($delta) || !array_is_list($delta)) { return $invalid; }
                    try {
                        $add = BaganrikiAttemptWindow::horseSet($delta, $saved['roster'], true);
                    } catch (\InvalidArgumentException $e) {
                        return $invalid;
                    }
                } else {
                    $cur = (array) json_decode((string) ($live->cancellations ?? '[]'), true);
                    $cur = array_values(array_unique(array_map('intval', $cur)));
                    sort($cur);
                    $add = array_values(array_unique(array_map('intval', (array) ($change['cancellations'] ?? []))));
                }
                $new = $cur;
                foreach ($add as $n) {
                    if ($n > 0 && !in_array($n, $new, true)) { $new[] = $n; }
                }
                sort($new);
                if ($new !== $cur) {
                    $changed['cancellations'] = ['前' => $cur, '後' => $new];
                }

                // ── ② 締切の前倒し（延長はしません）──────────────────
                $curClose = BaganrikiWorker::dbUs($live->live_close ?? null);
                $want = $change['live_close_us'] ?? null;
                $newClose = $curClose;
                if ($isExpected) {
                    if ((($live->live_close ?? null) !== null && $curClose === null)
                        || ($want !== null && BaganrikiWorker::dbUs($snap->sales_close_at ?? null) === null)) {
                        return ['ok' => false, 'code' => 'TIME_UNCOMPUTABLE', 'reason' => '既存の締切を確認できません'];
                    }
                    if ($want !== null && ((!is_int($want) && !is_string($want))
                        || (string) (int) $want !== (string) $want || (int) $want <= 0)) { return $invalid; }
                }
                if ($want !== null) {
                    $want = (int) $want;
                    $sales = BaganrikiWorker::dbUs($snap->sales_close_at ?? null);
                    if ($sales !== null && $want >= $sales) {
                        // 正本より後ろへは動かしません（延長になります）
                        $changed['live_close_rejected'] =
                            '正本の投票締切より後ろなので入れません';
                    } elseif ($curClose === null || $want < $curClose) {
                        $newClose = $want;
                        $changed['live_close'] = ['前' => $curClose, '後' => $newClose];
                    } else {
                        $changed['live_close_rejected'] =
                            'いまの前倒しより後ろなので入れません（延長はしません）';
                    }
                }

                // ── ③ 実際に変わったときだけ版を増やします ────────────
                $realChange = isset($changed['cancellations'])
                           || isset($changed['live_close']);
                $rev = (int) $live->state_revision;
                if ($realChange) {
                    $rev = $rev + 1;
                    $upd = ['state_revision' => $rev,
                            'updated_at' => BaganrikiWorker::toDb(
                                                BaganrikiWorker::nowUs())];
                    if (isset($changed['cancellations'])) {
                        $upd['cancellations'] = self::encodeJson($new);
                        // 有効頭数は集合矛盾のときNULLにする列です。
                        // @ANCHOR-20261023-NARU-LIVE  【旧】ここで数え直していなかったので、凍結後に取消が入っても
                        //   いまの頭数（active_count）が凍結時のままで、全頭取消の判定（ALL_CANCELLED）と
                        //   画面の「いまの頭数」が動きませんでした。
                        //   【いま】凍結集合から累積取消を引いた頭数を入れます（BaganrikiSnapshot と同じ数え方）。
                        $frozenL = $isExpected ? $saved['roster'] : json_decode((string) ($snap->frozen_nums ?? 'null'), true);
                        if (is_array($frozenL)) {
                            $upd['active_count'] = count(array_diff(array_map('intval', $frozenL), $new));
                            if ($isExpected && (property_exists($live,'live_target_count') || property_exists($live,'live_quota'))) {
                                try {
                                    BaganrikiPublishedRead::frozenSales($snap,$frozenInput);
                                    $allocation = BaganrikiPublishedRead::allocation($frozenInput,$frozenL,$new);
                                } catch (\InvalidArgumentException $invalid) {
                                    // Keep the official withdrawal even when the frozen allocation source is incomplete.
                                    $allocation = ['target_count'=>null,'quota'=>null];
                                }
                                if (property_exists($live,'live_target_count')) { $upd['live_target_count']=$allocation['target_count']; }
                                if (property_exists($live,'live_quota')) {
                                    $upd['live_quota']=$allocation['quota'] === null ? null : self::encodeJson($allocation['quota']);
                                }
                            }
                        }
                    }
                    if (isset($changed['live_close'])) {
                        $upd['live_close'] = BaganrikiWorker::toDb($newClose);
                    }
                    \DB::table('baganriki_live_states')
                      ->where('snapshot_id', $snapshotId)
                      ->where('state_revision', (int) $live->state_revision)
                      ->update($upd);

                    BaganrikiWorker::event(
                        (string) $snap->race_id, $snapshotId, 'R122_LIVE_APPLIED',
                        (int) $live->state_revision, $rev, $changed, null,
                        BaganrikiWorker::nowUs()
                    );
                }
                return ['ok' => true, 'code' => $realChange ? 'APPLIED' : 'NO_CHANGE',
                        'reason' => $realChange ? '実状態を入れました'
                                                : '変わるところがありません',
                        'changed' => $changed, 'state_revision' => $rev,
                        'cancellations' => $new, 'live_close_us' => $newClose];
            });

            $out['ok']     = (bool) ($res['ok'] ?? false);
            $out['code']   = (string) ($res['code'] ?? '');
            $out['reason'] = $res['reason'] ?? null;
            $out['changed'] = (array) ($res['changed'] ?? []);
            $out['state_revision'] = $res['state_revision'] ?? null;
            $out['cancellations']  = (array) ($res['cancellations'] ?? []);
            $out['live_close_us']  = $res['live_close_us'] ?? null;
            return $out;
        } catch (\Throwable $e) {
            \Log::error('[PREDICTION-LIVE] 実状態を入れるところで例外',
                ['message' => $e->getMessage(),
                 'at' => $e->getFile() . ':' . $e->getLine()]);
            $out['code'] = 'EXCEPTION';
            $out['reason'] = $e->getMessage();
            return $out;
        }
    }

    // ##########################################################################
    // ## ほかのクラスへの窓口（中身は下の Baganriki〜 クラス。ほかのファイルからの呼び方を変えないために残しています）
    // ##########################################################################

    /** BaganrikiAnswer::predictionBothRolesFailed の窓口（中身は BaganrikiAnswer） */
    public static function predictionBothRolesFailed(int $snapshotId): bool
    {
        return BaganrikiAnswer::predictionBothRolesFailed($snapshotId);
    }

    /** BaganrikiAnswer::predictionAnchorUs の窓口（中身は BaganrikiAnswer） */
    public static function predictionAnchorUs(string $date, string $st): ?int
    {
        return BaganrikiAnswer::predictionAnchorUs($date, $st);
    }

    /** BaganrikiAnswer::predictionLegacySavedOrUndetermined の窓口（中身は BaganrikiAnswer） */
    public static function predictionLegacySavedOrUndetermined($date, $kaisuu, $basho, $day, $race, string $reason): array
    {
        return BaganrikiAnswer::predictionLegacySavedOrUndetermined($date, $kaisuu, $basho, $day, $race, $reason);
    }

    /** BaganrikiAnswer::predictionNotReady の窓口（中身は BaganrikiAnswer） */
    public static function predictionNotReady($date, $kaisuu, $basho, $day, $race, string $why): array
    {
        return BaganrikiAnswer::predictionNotReady($date, $kaisuu, $basho, $day, $race, $why);
    }

    /** BaganrikiAnswer::predictionUndetermined の窓口（中身は BaganrikiAnswer） */
    public static function predictionUndetermined($date, $kaisuu, $basho, $day, $race, string $reason): array
    {
        return BaganrikiAnswer::predictionUndetermined($date, $kaisuu, $basho, $day, $race, $reason);
    }

    /** BaganrikiAnswer::predictionQuotaOf の窓口（中身は BaganrikiAnswer） */
    public static function predictionQuotaOf($pubQuota, $snapQuota): array
    {
        return BaganrikiAnswer::predictionQuotaOf($pubQuota, $snapQuota);
    }

    /**
     * @ANCHOR-20261115-PREDICTION-FILLSTATUS  頭数の充足の区分（記録用。表示や選び方は変えません）
     *   REGULAR_FULL＝通常候補だけで目標の頭数／WITH_FILL＝補充を含めて目標の頭数／SHORT＝補充しても目標に届かない（通常候補あり）
     *   REFERENCE_ONLY＝通常候補0で、参考の補充だけ／EMPTY＝出せる馬なし
     */
    public static function predictionFillStatus(int $regular, int $fill, int $target): string
    {
        if ($regular <= 0) { return $fill > 0 ? 'REFERENCE_ONLY' : 'EMPTY'; }
        if ($regular >= $target && $fill === 0) { return 'REGULAR_FULL'; }
        return ($regular + $fill >= $target) ? 'WITH_FILL' : 'SHORT';
    }

    /** BaganrikiAnswer::predictionFillRows の窓口（中身は BaganrikiAnswer） */
    public static function predictionFillRows(array $in, array $quota, array $have, array $cancelled,
                                              array $aiPos = []): array
    {
        return BaganrikiAnswer::predictionFillRows($in, $quota, $have, $cancelled, $aiPos);
    }

    /** BaganrikiAnswer::predictionRegNum の窓口（中身は BaganrikiAnswer） */
    public static function predictionRegNum(array $reg, string $ref): ?float
    {
        return BaganrikiAnswer::predictionRegNum($reg, $ref);
    }

    /** BaganrikiAnswer::predictionReason の窓口（中身は BaganrikiAnswer） */
    public static function predictionReason(array $reg, int $no, array $families): string
    {
        return BaganrikiAnswer::predictionReason($reg, $no, $families);
    }

    /** BaganrikiInput::buildPredictionRaceId の窓口（中身は BaganrikiInput） */
    public static function buildPredictionRaceId($date, $kaisuu, $basho, $day, $race): string
    {
        return BaganrikiInput::buildPredictionRaceId($date, $kaisuu, $basho, $day, $race);
    }

    /** BaganrikiInput::gatherPredictionContext の窓口（中身は BaganrikiInput） */
    public static function gatherPredictionContext($date, $kaisuu, $basho, $day, $race,
                                             array $override = []): array
    {
        return BaganrikiInput::gatherPredictionContext($date, $kaisuu, $basho, $day, $race, $override);
    }

    /** BaganrikiInput::dbTimeToRfc3339 の窓口（中身は BaganrikiInput） */
    public static function dbTimeToRfc3339($v): ?string
    {
        return BaganrikiInput::dbTimeToRfc3339($v);
    }

    /** BaganrikiInput::parseNumericValue の窓口（中身は BaganrikiInput） */
    public static function parseNumericValue(string $s)
    {
        return BaganrikiInput::parseNumericValue($s);
    }

    /** BaganrikiInput::buildExtraSourceRegistry の窓口（中身は BaganrikiInput） */
    public static function buildExtraSourceRegistry(array $ctx, array $frozenRank,
                                             array $cells, ?int $freezeUs): array
    {
        return BaganrikiInput::buildExtraSourceRegistry($ctx, $frozenRank, $cells, $freezeUs);
    }

    /** BaganrikiInput::formatDbTime の窓口（中身は BaganrikiInput） */
    public static function formatDbTime($v): ?string
    {
        return BaganrikiInput::formatDbTime($v);
    }

    /** BaganrikiInput::slugify の窓口（中身は BaganrikiInput） */
    public static function slugify(string $s): string
    {
        return BaganrikiInput::slugify($s);
    }

    /** BaganrikiSendConfig::aiTimeoutFromDeadline の窓口（中身は BaganrikiSendConfig） */
    public static function aiTimeoutFromDeadline(int $fallback, ?array $q): int
    {
        return BaganrikiSendConfig::aiTimeoutFromDeadline($fallback, $q);
    }

    /** BaganrikiInput::fieldSourcesOf の窓口（中身は BaganrikiInput） */
    public static function fieldSourcesOf(array $horses): array
    {
        return BaganrikiInput::fieldSourcesOf($horses);
    }

    /** BaganrikiInput::rankSourcesOf の窓口（中身は BaganrikiInput） */
    public static function rankSourcesOf(array $res): array
    {
        return BaganrikiInput::rankSourcesOf($res);
    }

    /** BaganrikiInput::invalidBandsOf の窓口（中身は BaganrikiInput） */
    public static function invalidBandsOf($scope): array
    {
        return BaganrikiInput::invalidBandsOf($scope);
    }

    /** BaganrikiInput::buildPastRunsRegistry の窓口（中身は BaganrikiInput） */
    public static function buildPastRunsRegistry(string $raceId, string $date,
                                                array $nums, ?string $freezeAt): array
    {
        return BaganrikiInput::buildPastRunsRegistry($raceId, $date, $nums, $freezeAt);
    }

    /** BaganrikiInput::pastRunsMax の窓口（中身は BaganrikiInput） */
    public static function pastRunsMax(): int
    {
        return BaganrikiInput::pastRunsMax();
    }

    /** BaganrikiInput::intOrNull の窓口（中身は BaganrikiInput） */
    public static function intOrNull($v): ?int
    {
        return BaganrikiInput::intOrNull($v);
    }

    /** BaganrikiInput::strOrNull の窓口（中身は BaganrikiInput） */
    public static function strOrNull($v): ?string
    {
        return BaganrikiInput::strOrNull($v);
    }

    /** BaganrikiInput::buildMarketPriorRegistry の窓口（中身は BaganrikiInput） */
    public static function buildMarketPriorRegistry(string $raceId, string $date,
                                                   array $frozenRank, array $cells,
                                                   ?string $freezeAt): array
    {
        return BaganrikiInput::buildMarketPriorRegistry($raceId, $date, $frozenRank, $cells, $freezeAt);
    }

    /** BaganrikiInput::marketPriorEnabled の窓口（中身は BaganrikiInput） */
    public static function marketPriorEnabled(): bool
    {
        return BaganrikiInput::marketPriorEnabled();
    }

    /** BaganrikiInput::decimalOrNull の窓口（中身は BaganrikiInput） */
    public static function decimalOrNull($v): ?float
    {
        return BaganrikiInput::decimalOrNull($v);
    }

    /** BaganrikiRaceState::roleResponseVersion の窓口（中身は BaganrikiRaceState） */
    public static function roleResponseVersion(int $snapshotId, string $role): string
    {
        return BaganrikiRaceState::roleResponseVersion($snapshotId, $role);
    }

    /** BaganrikiRaceState::markRoleSettled の窓口（中身は BaganrikiRaceState） */
    public static function markRoleSettled(int $snapshotId, string $role,
                                               array $info = [],
                                               ?int $nowUs = null): array
    {
        return BaganrikiRaceState::markRoleSettled($snapshotId, $role, $info, $nowUs);
    }

    /** BaganrikiRaceState::roleSettled の窓口（中身は BaganrikiRaceState） */
    public static function roleSettled(int $snapshotId, string $role): array
    {
        return BaganrikiRaceState::roleSettled($snapshotId, $role);
    }

    /** BaganrikiRaceState::fixProcessingPath の窓口（中身は BaganrikiRaceState） */
    public static function fixProcessingPath(int $snapshotId, string $path): array
    {
        return BaganrikiRaceState::fixProcessingPath($snapshotId, $path);
    }

    /** BaganrikiRaceState::processingPathOf の窓口（中身は BaganrikiRaceState） */
    public static function processingPathOf(int $snapshotId): ?string
    {
        return BaganrikiRaceState::processingPathOf($snapshotId);
    }

    /** BaganrikiRaceState::predictionTargets の窓口（中身は BaganrikiRaceState） */
    public static function predictionTargets(): array
    {
        return BaganrikiRaceState::predictionTargets();
    }

    /** BaganrikiRaceState::resolveShortTargetId の窓口（中身は BaganrikiRaceState） */
    public static function resolveShortTargetId(string $ymd, string $basho, int $race): ?string
    {
        return BaganrikiRaceState::resolveShortTargetId($ymd, $basho, $race);
    }

    /** BaganrikiRaceState::predictionTargetMax の窓口（中身は BaganrikiRaceState） */
    public static function predictionTargetMax(): int
    {
        return BaganrikiRaceState::predictionTargetMax();
    }

    /** BaganrikiRaceState::checkPredictionTarget の窓口（中身は BaganrikiRaceState） */
    public static function checkPredictionTarget(string $raceId): array
    {
        return BaganrikiRaceState::checkPredictionTarget($raceId);
    }

    /** BaganrikiRaceState::roleNeedsWork の窓口（中身は BaganrikiRaceState） */
    public static function roleNeedsWork(int $snapshotId, string $role): bool
    {
        return BaganrikiRaceState::roleNeedsWork($snapshotId, $role);
    }

    /** BaganrikiRaceState::ensureDeadlineAggregation の窓口（中身は BaganrikiRaceState） */
    public static function ensureDeadlineAggregation(int $snapshotId): array
    {
        return BaganrikiRaceState::ensureDeadlineAggregation($snapshotId);
    }

    /** BaganrikiRaceState::syncLiveStartTime の窓口（中身は BaganrikiRaceState） */
    public static function syncLiveStartTime(int $snapshotId, string $from): array
    {
        return BaganrikiRaceState::syncLiveStartTime($snapshotId, $from);
    }

    /** BaganrikiRaceState::syncLiveStartTimeForRace の窓口（中身は BaganrikiRaceState） */
    public static function syncLiveStartTimeForRace(string $date, string $basho, int $race): array
    {
        return BaganrikiRaceState::syncLiveStartTimeForRace($date, $basho, $race);
    }

    /** BaganrikiRaceState::syncLiveFromAcquisition の窓口（中身は BaganrikiRaceState） */
    public static function syncLiveFromAcquisition($race, string $rawPayload): array
    {
        return BaganrikiRaceState::syncLiveFromAcquisition($race, $rawPayload);
    }

    /** BaganrikiUsage::encodeJson の窓口（中身は BaganrikiUsage） */
    public static function encodeJson($v): string
    {
        return BaganrikiUsage::encodeJson($v);
    }

    /** BaganrikiUsage::sentAtUs の窓口（中身は BaganrikiUsage） */
    public static function sentAtUs(array $call): ?int
    {
        return BaganrikiUsage::sentAtUs($call);
    }

    /** BaganrikiUsage::recordAiUsage の窓口（中身は BaganrikiUsage） */
    public static function recordAiUsage(int $snapshotId, string $role,
                                           int $attemptNo, array $call): void
    {
        BaganrikiUsage::recordAiUsage($snapshotId, $role, $attemptNo, $call);
    }

    /** BaganrikiUsage::aiUsageOf の窓口（中身は BaganrikiUsage） */
    public static function aiUsageOf(int $snapshotId): array
    {
        return BaganrikiUsage::aiUsageOf($snapshotId);
    }

    /** BaganrikiSendConfig::checkPredictionReadAccess の窓口（中身は BaganrikiSendConfig） */
    public static function checkPredictionReadAccess(array $opt = []): array
    {
        return BaganrikiSendConfig::checkPredictionReadAccess($opt);
    }

    /** BaganrikiSendConfig::checkPredictionFlagConfig の窓口（中身は BaganrikiSendConfig） */
    public static function checkPredictionFlagConfig(): array
    {
        return BaganrikiSendConfig::checkPredictionFlagConfig();
    }

    /** BaganrikiSendConfig::correctionErrorList の窓口（中身は BaganrikiSendConfig） */
    public static function correctionErrorList(array $insp): array
    {
        return BaganrikiSendConfig::correctionErrorList($insp);
    }

    /** BaganrikiRaceState::isTestRaceId の窓口（中身は BaganrikiRaceState） */
    public static function isTestRaceId(string $raceId): bool
    {
        return BaganrikiRaceState::isTestRaceId($raceId);
    }

    /** BaganrikiSendConfig::promptCacheEnabled の窓口（中身は BaganrikiSendConfig） */
    public static function promptCacheEnabled(): bool
    {
        return BaganrikiSendConfig::promptCacheEnabled();
    }

    /** BaganrikiSendConfig::fakeAiEnabled の窓口（中身は BaganrikiSendConfig） */
    public static function fakeAiEnabled(): bool
    {
        return BaganrikiSendConfig::fakeAiEnabled();
    }

    /** BaganrikiSendConfig::fakeAiResponse の窓口（中身は BaganrikiSendConfig） */
    public static function fakeAiResponse(string $role, string $user, int $timeout): ?array
    {
        return BaganrikiSendConfig::fakeAiResponse($role, $user, $timeout);
    }

    /** BaganrikiUsage::budgetUsdDay の窓口（中身は BaganrikiUsage） */
    public static function budgetUsdDay(): ?float
    {
        return BaganrikiUsage::budgetUsdDay();
    }

    /** BaganrikiUsage::priceKeyOf の窓口（中身は BaganrikiUsage） */
    public static function priceKeyOf(string $role, string $model): ?string
    {
        return BaganrikiUsage::priceKeyOf($role, $model);
    }

    /** BaganrikiUsage::usageTokens の窓口（中身は BaganrikiUsage） */
    public static function usageTokens(string $role, $u): ?array
    {
        return BaganrikiUsage::usageTokens($role, $u);
    }

    /** BaganrikiUsage::deepseekPeakAt の窓口（中身は BaganrikiUsage） */
    public static function deepseekPeakAt(int $us): bool
    {
        return BaganrikiUsage::deepseekPeakAt($us);
    }

    /** BaganrikiUsage::costFromUsage の窓口（中身は BaganrikiUsage） */
    public static function costFromUsage(string $role, ?string $model, $u, string $mode = 'conservative', ?int $atUs = null): ?float
    {
        return BaganrikiUsage::costFromUsage($role, $model, $u, $mode, $atUs);
    }

    /** BaganrikiUsage::worstCostUsd の窓口（中身は BaganrikiUsage） */
    public static function worstCostUsd(string $role, string $model, int $bytes, int $maxTokens): ?float
    {
        return BaganrikiUsage::worstCostUsd($role, $model, $bytes, $maxTokens);
    }

    /** BaganrikiUsage::budgetDb の窓口（中身は BaganrikiUsage） */
    public static function budgetDb(): \Illuminate\Database\ConnectionInterface
    {
        return BaganrikiUsage::budgetDb();
    }

    /** BaganrikiUsage::spentTodayUsd の窓口（中身は BaganrikiUsage） */
    public static function spentTodayUsd(int $nowUs): float
    {
        return BaganrikiUsage::spentTodayUsd($nowUs);
    }

    /** BaganrikiUsage::budgetSettle の窓口（中身は BaganrikiUsage） */
    public static function budgetSettle(string $raceId, string $role, int $attemptNo, array $call, $u): void
    {
        BaganrikiUsage::budgetSettle($raceId, $role, $attemptNo, $call, $u);
    }

    /** BaganrikiUsage::budgetRelease の窓口（中身は BaganrikiUsage） */
    public static function budgetRelease(?string $hold, string $raceId, string $why): void
    {
        BaganrikiUsage::budgetRelease($hold, $raceId, $why);
    }

    /** BaganrikiUsage::budgetReserve の窓口（中身は BaganrikiUsage） */
    public static function budgetReserve(string $role, string $model, string $raceId, int $bytes, int $maxTokens): array
    {
        return BaganrikiUsage::budgetReserve($role, $model, $raceId, $bytes, $maxTokens);
    }

    /** BaganrikiSendConfig::compactFormatFor の窓口（中身は BaganrikiSendConfig） */
    public static function compactFormatFor(string $role): string
    {
        return BaganrikiSendConfig::compactFormatFor($role);
    }

    /** BaganrikiSendConfig::compactUserForSend の窓口（中身は BaganrikiSendConfig） */
    public static function compactUserForSend(string $user, string $role = ''): array
    {
        return BaganrikiSendConfig::compactUserForSend($user, $role);
    }

    /** BaganrikiSendConfig::predictionMetricsEnabled の窓口（中身は BaganrikiSendConfig） */
    public static function predictionMetricsEnabled(): bool
    {
        return BaganrikiSendConfig::predictionMetricsEnabled();
    }

    /** BaganrikiSendConfig::predictionMetricsFromRaw の窓口（中身は BaganrikiSendConfig） */
    public static function predictionMetricsFromRaw(string $raw): ?array
    {
        return BaganrikiSendConfig::predictionMetricsFromRaw($raw);
    }

    /** BaganrikiSendConfig::writePredictionPromptFile の窓口（中身は BaganrikiSendConfig） */
    public static function writePredictionPromptFile(string $role, string $system, string $user): void
    {
        BaganrikiSendConfig::writePredictionPromptFile($role, $system, $user);
    }

    /** BaganrikiSendConfig::correctionPolicy の窓口（中身は BaganrikiSendConfig） */
    public static function correctionPolicy(): string
    {
        return BaganrikiSendConfig::correctionPolicy();
    }

    /** BaganrikiSendConfig::correctionPolicyId の窓口（中身は BaganrikiSendConfig） */
    public static function correctionPolicyId(): string
    {
        return BaganrikiSendConfig::correctionPolicyId();
    }

    /** BaganrikiSendConfig::correctionPolicyAllows の窓口（中身は BaganrikiSendConfig） */
    public static function correctionPolicyAllows(array $insp): array
    {
        return BaganrikiSendConfig::correctionPolicyAllows($insp);
    }

    /** BaganrikiSendConfig::setJobDeadline の窓口（中身は BaganrikiSendConfig） */
    public static function setJobDeadline(?int $deadlineUs): void
    {
        BaganrikiSendConfig::setJobDeadline($deadlineUs);
    }

    /** BaganrikiSendConfig::jobSecondsLeft の窓口（中身は BaganrikiSendConfig） */
    public static function jobSecondsLeft(): int
    {
        return BaganrikiSendConfig::jobSecondsLeft();
    }

}
