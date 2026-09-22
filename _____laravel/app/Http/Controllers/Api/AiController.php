<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

class AiController extends Controller
{
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
        'fake_inflow_warning',   // B-8 偽流入警戒
        'fake_inflow_true_cnt',  // B-8 成立条件数
        'high_payout_score',     // B-10 高配当総合点
        'market_score',          // B-10 市場妙味基礎点
        'ability_grade_merged',  // B-10 統合後の能力適性グレード
        'ability_corr_merged',   // B-10 統合後の能力適性補正点
        'mismatch_category',     // B-10 不一致馬A〜D分類
        'betting_judgment',      // B-10 馬券判定
    ];

    public function __construct(private AnthropicService $anthropic)
    {
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

        return response()->json(['data' => $result]);
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

        return response()->json(['data' => $result]);
    }




    
/**
 * 指定レースのAI分析結果を返す
 *
 * すでに分析済みのレースは t_horse_odds_finder_ai_analysis からキャッシュを返す。
 * 未分析の場合は Claude API を呼び出して分析を生成し、DBに保存してから返す。
 *
 * ＜テーブル間のカラム対応に注意＞
 *   t_horse_odds_finder_races.basho      → t_horse_odds_finder_ai_analysis.basho_code（場コード）
 *   t_horse_odds_finder_races.basho_name → t_horse_odds_finder_ai_analysis.basho    （場名称）
 *
 * @param  Request $request
 *   クエリパラメータ: date, kaisuu, basho（場コード）, day, race
 * @return \Illuminate\Http\JsonResponse
 */
public function getHorseOddsFinderAiAnalysis(Request $request)
{
    // ─── リクエストパラメータの取り出し ───────────────────────────────
    $date   = $request->query('date');
    $kaisuu = $request->query('kaisuu');
    $basho  = $request->query('basho');  // 場コード（t_horse_odds_finder_races.basho と同値）
    $day    = $request->query('day');
    $race   = $request->query('race');

    $gapHorseNums   = $request->query('gapHorseNums');
    $upsetPickupHorseNums   = $request->query('upsetPickupHorseNums');

// // ─── 注目馬の抽出ロジック ─────────────────────────────────────────
// // AIにプロンプトで "PICKUP:馬番|馬名/..." 形式の最終行を出力させているので、
// // その行だけを抜き出す。形式が固定されているため自由文のパースより確実。
// $parsePickupHorses = function (string $text): string {
//     if (preg_match('/^PICKUP:(.+)$/mu', $text, $m)) {
//         return trim($m[1]);
//     }
//     return '';
// };

    // ─── キャッシュ確認 ───────────────────────────────────────────────
    // 同一レースの分析がすでに保存済みであればDBから即返す。
    // exists() + first() の2クエリを first() 1本にまとめている。
    $cached = DB::table('t_horse_odds_finder_ai_analysis')
        ->where('date',       $date)
        ->where('kaisuu',     $kaisuu)
        ->where('basho_code', $basho)
        ->where('day',        $day)
        ->where('race',       $race)
        ->first();

    if ($cached) {

// DBの analysis_text には PICKUP: 行が含まれているので、レスポンスでは除去して返す

        return response()->json(['data' => [
            'date'          => $date,
            'kaisuu'        => $kaisuu,
            'basho_code'    => $basho,
            'day'           => $day,
            'race'          => $race,

// 'analysis_text' => trim(preg_replace('/^PICKUP:.+$/mu', '', $cached->analysis_text)),
// 'pickup_horse'  => $parsePickupHorses($cached->analysis_text),

'analysis_text' => trim($cached->analysis_text),

        ]]);
    }

    // ─── 排他ロック（同一レースへの並行リクエスト防止） ──────────────
    // 初回キャッシュミス後に複数リクエストが同時に来ると API が二重呼び出しされる。
    // ロックを取得してから再度キャッシュを確認することで 1 回だけ呼び出しを保証する。
    $lockKey = "ai_analysis_{$date}_{$kaisuu}_{$basho}_{$day}_{$race}";
    $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 120);

    try {
        $lock->block(60); // 先行リクエストが完了するまで最大60秒待つ

        // ─── ロック後に再度キャッシュ確認（先行リクエストが保存済みの場合） ──
        $cached = DB::table('t_horse_odds_finder_ai_analysis')
            ->where('date',       $date)
            ->where('kaisuu',     $kaisuu)
            ->where('basho_code', $basho)
            ->where('day',        $day)
            ->where('race',       $race)
            ->first();

        if ($cached) {
            return response()->json(['data' => [
                'date'          => $date,
                'kaisuu'        => $kaisuu,
                'basho_code'    => $basho,
                'day'           => $day,
                'race'          => $race,

                // 'analysis_text' => trim(preg_replace('/^PICKUP:.+$/mu', '', $cached->analysis_text)),
                // 'pickup_horse'  => $parsePickupHorses($cached->analysis_text),

'analysis_text' => trim($cached->analysis_text),

            ]]);
        }

        // ─── レース基本情報の取得 ─────────────────────────────────────────
        $raceRow = DB::table('t_horse_odds_finder_races')
            ->where('date',   $date)
            ->where('kaisuu', $kaisuu)
            ->where('basho',  $basho)
            ->where('day',    $day)
            ->where('race',   intval($race))
            ->first();

        if (!$raceRow) {
            return response()->json(['error' => 'レースが見つかりません'], 404);
        }

        // ─── AIプロンプト生成 ─────────────────────────────────────────────
        $prompt = $this->_getAiAnalysisPrompt($date, $kaisuu, $basho, $day, $race, $gapHorseNums, $upsetPickupHorseNums);

        if ($prompt === null) {
            return response()->json(['error' => 'プロンプト生成に失敗しました（レースまたはオッズデータが不足しています）'], 404);
        }

        // ─── プロンプトをファイルに出力（デバッグ・履歴用） ──────────────
        file_put_contents(
            public_path("prompt/prompt_{$date}_{$kaisuu}_{$basho}_{$day}_{$race}.data"),
            $prompt
        );

        // ─── 脳みそ（判断基準）の読み込み ────────────────────────────────────
        $brainFile = public_path('baganriki_brain/baganriki_brain.txt');
        $brain     = file_exists($brainFile) ? trim(file_get_contents($brainFile)) : '';

        // 脳みそがある場合、プロンプト末尾に参考情報として追加（システムプロンプトには使わない）
        if ($brain !== '') {
            $prompt .= "\n\n参考情報：以下は過去のレース分析から導き出した判断基準です。あくまで参考として、目の前のオッズデータを優先して判断してください。\n" . $brain;
        }

        $prompt .= "\n\n" . '※画面の表示幅の問題があるので、テーブルは使わないでください。';

        // ─── 1st AI 固定システムプロンプト（仕様書 §1より。既存プロンプトと併用せず完全置換） ─
        $firstAiSystemPrompt = 'あなたは競馬オッズ分析の専門家（1st AI）です。入力された取得開始S〜発走6分前までの全頭データだけを使用し、全頭を評価してから候補を決定してください。DB・PHP算出済みの人気順、OPI、流入ランク、推定確定オッズ、断層構造タイプ、厳選穴レース条件を再計算・変更してはいけません。3分前、確定オッズ・確定人気、実着順、払戻金その他発走後情報、入力に存在しない情報を使用・推測・創作してはいけません。主目的は的中頭数ではなく長期回収率の向上です。最低基準点、低配当除外、回収率フィルター、断層位置別・人気帯別上限を順守し、上限を埋めるための追加をしてはいけません。能力・適性は時系列オッズの補強材料として評価し、能力・適性だけで候補を決めてはいけません。有料公開するため正しい日本語を使用し、ユーザープロンプトで指定されたFlutter互換フォーマット以外の前置き・後書き・見出し・補足を出力してはいけません。';

        // ─── Claude API 呼び出し（自動再試行なし・1回のみ）───────────────────
        // 【仕様】外部AIの呼び出しは1レースにつき 1st AI + 2nd AI の合計2回まで。
        //   自動再試行は禁止。AnthropicService::sendWithRetry() は
        //   for ($attempt = 1; $attempt <= $maxAttempts; ...) のループなので、
        //   maxAttempts: 1 を渡すと送信は必ず1回だけで、429/529でも再送しない。
        //   ★ここを 2 以上にしたり、引数を省略（既定値3）したりしてはいけない。
        //   ※以前このコメントには「529時は指数バックオフでリトライ」と書かれていたが、
        //     仕様と食い違うためコメントごと是正した。コードは以前から1回のみ。
        $aiResponse = $this->anthropic->sendWithRetry(
            prompt:      $prompt,
            system:      $firstAiSystemPrompt,
            maxAttempts: 1,   // 仕様: 自動再試行禁止。2以上にしてはいけない
            sleepBase:   2,
            timeout:     30,
        );

        if ($aiResponse->failed()) {
            \Log::error('Anthropic API error', [
                'status' => $aiResponse->status(),
                'body'   => $aiResponse->body(),
            ]);
            return response()->json(['error' => 'AI分析に失敗しました'], 500);
        }

        $rawText = $this->anthropic->extractText($aiResponse);

// $pickupHorse  = $parsePickupHorses($rawText);
// $analysisText = trim(preg_replace('/^PICKUP:.+$/mu', '', $rawText));

$analysisText = trim($rawText);

        // ─── 分析結果をDBに保存（次回以降はキャッシュから返す） ──────────
        DB::table('t_horse_odds_finder_ai_analysis')->insertOrIgnore([
            'date'          => $date,
            'kaisuu'        => $kaisuu,
            'basho_code'    => $basho,
            'basho'         => $raceRow->basho_name,
            'day'           => $day,
            'race'          => $race,
            'race_name'     => $raceRow->race_name,
            'analysis_text' => $rawText,
        ]);

        // ─── 2nd AI プリフェッチ（レスポンス送信後にバックグラウンドで実行） ──
        // ユーザーが「2nd AI」ボタンを押す前にキャッシュを作っておく。
        // app()->terminating() はレスポンス送信後に呼ばれるため、
        // 1st AI のレスポンス速度には一切影響しない。
        // getHorseOddsFinderSecondAiOpinion が持つ排他ロック機構により、
        // ユーザーがボタンを押しても2重実行・エラーにはならない。
        $prefetchDate   = $date;
        $prefetchKaisuu = $kaisuu;
        $prefetchBasho  = $basho;
        $prefetchDay    = $day;
        $prefetchRace   = $race;
        $selfController = $this;
        app()->terminating(function () use ($selfController, $prefetchDate, $prefetchKaisuu, $prefetchBasho, $prefetchDay, $prefetchRace) {
            // 既にキャッシュがあれば何もしない
            $already = DB::table('t_horse_odds_finder_ai_analysis2')
                ->where('date',       $prefetchDate)
                ->where('kaisuu',     $prefetchKaisuu)
                ->where('basho_code', $prefetchBasho)
                ->where('day',        $prefetchDay)
                ->where('race',       $prefetchRace)
                ->exists();
            if ($already) return;

            try {
                $req2nd = new \Illuminate\Http\Request();
                $req2nd->query->add([
                    'date'   => $prefetchDate,
                    'kaisuu' => $prefetchKaisuu,
                    'basho'  => $prefetchBasho,
                    'day'    => $prefetchDay,
                    'race'   => $prefetchRace,
                ]);
                $selfController->getHorseOddsFinderSecondAiOpinion($req2nd);
            } catch (\Throwable $e) {
//                 \Log::info('[2nd AI prefetch] skip: ' . $e->getMessage());
            }
        });

        return response()->json(['data' => [
            'date'          => $date,
            'kaisuu'        => $kaisuu,
            'basho_code'    => $basho,
            'day'           => $day,
            'race'          => $race,
            'analysis_text' => $analysisText,

// 'pickup_horse'  => $pickupHorse,

        ]]);

    } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
        return response()->json(['error' => 'しばらくしてから再試行してください'], 503);
    } finally {
        $lock->release();
    }
}






private function _getAiAnalysisPrompt($targetDate, $targetKaisuu, $targetBasho, $targetDay, $targetRace, $gapHorseNums, $upsetPickupHorseNums)
{
    // ─── レース存在確認 ───────────────────────────────────────────────
    $race = DB::table('t_horse_odds_finder_races')
        ->where('date',   $targetDate)
        ->where('kaisuu', $targetKaisuu)
        ->where('basho',  $targetBasho)
        ->where('day',    $targetDay)
        ->where('race',   intval($targetRace))
        ->first();

    if (!$race) {
        return null;
    }

    // ─── 出走馬情報の取得（馬番をキーにした連想配列） ────────────────
    $horses = DB::table('t_horse_odds_finder_horses')
        ->where('date',   $targetDate)
        ->where('kaisuu', $race->kaisuu)
        ->where('basho',  $race->basho)
        ->where('day',    $race->day)
        ->where('race',   $race->race)
        ->orderBy('num')
        ->get()
        ->keyBy('num');

    // ─── オッズ取得（計測開始前〜6分前の全時点） ───────────────────────
    // 999 = 計測開始前ベース（ODDS_DB_FIRST）
    // ODDS_GET_TIMING から 6分前〜21分前の中間時点を抽出して追加
    $midTimings   = array_values(array_filter(Constants::ODDS_GET_TIMING, fn($t) => $t >= 6 && $t < 30));
    $fetchTimings = array_merge([Constants::ODDS_DB_FIRST], $midTimings);
    // = [999, 21, 18, 15, 12, 9, 6]

    $oddsRows = DB::table('t_horse_odds_finder_odds')
        ->where('date',   $targetDate)
        ->where('kaisuu', $race->kaisuu)
        ->where('basho',  $race->basho)
        ->where('day',    $race->day)
        ->where('race',   $race->race)
        ->whereIn('minutes_before_start', $fetchTimings)
        ->get();

    // ─── 馬番ごとに全時点のオッズをまとめる ──────────────────────────
    // tan[timing]      = 単勝オッズ
    // fuku_min[timing] = 複勝最小オッズ
    // fuku_max[timing] = 複勝最大オッズ
    $oddsByNum = [];
    foreach ($oddsRows as $row) {
        $num    = $row->num;
        $timing = $row->minutes_before_start;
        if (!isset($oddsByNum[$num])) {
            $oddsByNum[$num] = ['tan' => [], 'fuku_min' => [], 'fuku_max' => []];
        }
        $oddsByNum[$num]['tan'][$timing]      = floatval($row->odds);
        $oddsByNum[$num]['fuku_min'][$timing] = floatval($row->fuku_min);
        $oddsByNum[$num]['fuku_max'][$timing] = floatval($row->fuku_max);
    }

    // ─── プロンプト用データの組み立て ────────────────────────────────
    $promptHorses = [];
    foreach ($oddsByNum as $num => $o) {
        $tanBase = $o['tan'][Constants::ODDS_DB_FIRST] ?? null;
        $tan6    = $o['tan'][6] ?? null;

        // 計測開始前と6分前が両方なければスキップ
        if ($tanBase === null || $tan6 === null || $tanBase == 0) continue;

        // 単勝の変化率（計測開始前 → 6分前）
        $changeRate = round(($tan6 - $tanBase) / $tanBase * 100, 1);
        if ($changeRate < 0) {
            $changeLabel = '下落 ' . abs($changeRate) . '%';
        } elseif ($changeRate > 0) {
            $changeLabel = '上昇 +' . $changeRate . '%';
        } else {
            $changeLabel = '変化なし';
        }

        // 複勝の変化率（計測開始前 → 6分前）
        $fukuMinBase = $o['fuku_min'][Constants::ODDS_DB_FIRST] ?? null;
        $fukuMin6    = $o['fuku_min'][6] ?? null;
        $fukuMax6    = $o['fuku_max'][6] ?? null;

        $fukuChangeLabel = '－';
        if ($fukuMinBase && $fukuMin6 && $fukuMinBase > 0) {
            $fukuChange = round(($fukuMin6 - $fukuMinBase) / $fukuMinBase * 100, 1);
            if ($fukuChange < 0) {
                $fukuChangeLabel = '下落 ' . abs($fukuChange) . '%';
            } elseif ($fukuChange > 0) {
                $fukuChangeLabel = '上昇 +' . $fukuChange . '%';
            } else {
                $fukuChangeLabel = '変化なし';
            }
        }

        // 単複比（6分前）
        $tanpukuRatio = '－';
        if ($fukuMin6 && $fukuMin6 > 0) {
            $tanpukuRatio = round($tan6 / $fukuMin6, 1) . '倍';
        }

        $name = isset($horses[$num]) ? $horses[$num]->name : '馬' . $num;

        // 単勝短縮率: (計測前 - 6分前) / 計測前 × 100 → 正値 = 資金流入（オッズ短縮）
        $tanShrinkRate  = ($tanBase > 0 && $tan6 !== null)
            ? round(($tanBase - $tan6) / $tanBase * 100, 1)
            : null;
        // 複勝短縮率: 同計算式で複勝最小オッズ版
        $fukuShrinkRate = ($fukuMinBase && $fukuMin6 && $fukuMinBase > 0)
            ? round(($fukuMinBase - $fukuMin6) / $fukuMinBase * 100, 1)
            : null;

        $promptHorses[] = [
            'num'              => $num,
            'name'             => $name,
            'tan_series'       => $o['tan'],      // 全時点の単勝オッズ
            'fuku_min_series'  => $o['fuku_min'], // 全時点の複勝最小オッズ
            'fuku_max_series'  => $o['fuku_max'], // 全時点の複勝最大オッズ
            'odds_base'        => $tanBase,       // 人気順ソート用
            'odds_6'           => $tan6,          // 人気順ソート用
            'change_label'     => $changeLabel,
            'change_rate_raw'  => $changeRate,    // 乖離帯判定用（計測開始→6分前の生の変化率）
            'fuku_min_6'       => $fukuMin6,
            'fuku_max_6'       => $fukuMax6,
            'fuku_change'      => $fukuChangeLabel,
            'tanpuku_ratio'    => $tanpukuRatio,
            'tan_shrink_rate'  => $tanShrinkRate,  // 単勝短縮率（相対資金流入ランク算出用）
            'fuku_shrink_rate' => $fukuShrinkRate, // 複勝短縮率（相対資金流入ランク算出用）
        ];
    }

    if (empty($promptHorses)) {
        return null;
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

    // ─── 相対資金流入ランクの算出（E-1）────────────────────────────────
    // 人気帯グループ: 1〜3番人気 / 4〜6番人気 / 7番人気以下
    // 各グループ内で単勝・複勝の短縮率（正値ほど資金が流入）を比較してランク付け
    // 短縮率が null の馬はランク計算から除外し「データなし」として扱う
    $getInflowGroupLabel = function (int $pop): string {
        if ($pop <= 3) return '1〜3人気';
        if ($pop <= 6) return '4〜6人気';
        return '7人気以下';
    };

    // グループ別に $promptHorses のインデックスを振り分け
    $inflowGroups = ['1〜3人気' => [], '4〜6人気' => [], '7人気以下' => []];
    foreach ($promptHorses as $idx => $h) {
        $inflowGroups[$getInflowGroupLabel($h['popularity'])][] = $idx;
    }

    foreach ($inflowGroups as $groupLabel => $idxList) {
        // 単勝流入ランク（短縮率 null を除外してランク付け）
        $tanValidIdxs = array_values(array_filter($idxList, fn($i) => $promptHorses[$i]['tan_shrink_rate'] !== null));
        usort($tanValidIdxs, fn($a, $b) => $promptHorses[$b]['tan_shrink_rate'] <=> $promptHorses[$a]['tan_shrink_rate']);
        $tanGroupSize = count($tanValidIdxs);
        foreach ($tanValidIdxs as $rank => $idx) {
            $promptHorses[$idx]['tan_inflow_rank']       = $rank + 1;
            $promptHorses[$idx]['tan_inflow_group_size'] = $tanGroupSize;
        }
        foreach (array_diff($idxList, $tanValidIdxs) as $idx) {
            $promptHorses[$idx]['tan_inflow_rank']       = null;
            $promptHorses[$idx]['tan_inflow_group_size'] = $tanGroupSize;
        }

        // 複勝流入ランク（短縮率 null を除外してランク付け）
        $fukuValidIdxs = array_values(array_filter($idxList, fn($i) => $promptHorses[$i]['fuku_shrink_rate'] !== null));
        usort($fukuValidIdxs, fn($a, $b) => $promptHorses[$b]['fuku_shrink_rate'] <=> $promptHorses[$a]['fuku_shrink_rate']);
        $fukuGroupSize = count($fukuValidIdxs);
        foreach ($fukuValidIdxs as $rank => $idx) {
            $promptHorses[$idx]['fuku_inflow_rank']       = $rank + 1;
            $promptHorses[$idx]['fuku_inflow_group_size'] = $fukuGroupSize;
        }
        foreach (array_diff($idxList, $fukuValidIdxs) as $idx) {
            $promptHorses[$idx]['fuku_inflow_rank']       = null;
            $promptHorses[$idx]['fuku_inflow_group_size'] = $fukuGroupSize;
        }

        // グループ名を各馬に付与
        foreach ($idxList as $idx) {
            $promptHorses[$idx]['inflow_group'] = $groupLabel;
        }
    }

    // ─── OPI（Over Popularity Index）計算 ──────────────────────────────
    // OPI = 人気順位別の過去平均単勝オッズ ÷ 今回の6分前単勝オッズ
    //   OPI > 1.0 → 過去同人気より今回は低オッズ（過剰人気・妙味少）
    //   OPI < 1.0 → 過去同人気より今回は高オッズ（妙味あり）
    //   OPI ≒ 1.0 → 歴史的平均並み
    $popularityAvgRows = DB::table('t_horse_odds_finder_popularity_rank_average')->get();
    $popularityAvgMap  = [];
    foreach ($popularityAvgRows as $row) {
        $popularityAvgMap[(int)$row->popularity_rank] = floatval($row->odds_average);
    }

    // ─── 複勝OPI用：人気順位別の過去平均複勝最小オッズ ────────────────────
    $fukuPopularityAvgRows = DB::table('t_horse_odds_finder_fuku_popularity_rank_average')->get();
    $fukuPopularityAvgMap  = [];
    foreach ($fukuPopularityAvgRows as $row) {
        $fukuPopularityAvgMap[(int)$row->popularity_rank] = floatval($row->odds_average);
    }

    // ─── 補正係数の読み込み（t_horse_odds_finder_compute_odds_correction）──
    // 推定確定オッズ = 6分前オッズ × avg_correction_ratio
    // std_correction_ratio = 補正誤差（標準偏差）
    $correctionRows = DB::table('t_horse_odds_finder_compute_odds_correction')->get();
    $correctionMap  = [];
    foreach ($correctionRows as $row) {
        $correctionMap[(int)$row->popularity_rank] = $row;
    }

    // ─── 乖離別回収率の読み込み（t_horse_odds_finder_odds_gap_recovery）──
    // キー: "{gap_band}|{popularity_band}" → 回収率・勝率・サンプル数
    $gapRecoveryRows = DB::table('t_horse_odds_finder_odds_gap_recovery')->get();
    $gapRecoveryMap  = [];
    foreach ($gapRecoveryRows as $row) {
        $gapRecoveryMap[$row->gap_band . '|' . $row->popularity_band] = $row;
    }

    // ─── OPI帯別回収率の読み込み（t_horse_odds_finder_opi_recovery）────
    // キー: "{opi_band}|{popularity_band}" → 回収率・勝率・サンプル数
    $opiRecoveryRows = DB::table('t_horse_odds_finder_opi_recovery')->get();
    $opiRecoveryMap  = [];
    foreach ($opiRecoveryRows as $row) {
        $opiRecoveryMap[$row->opi_band . '|' . $row->popularity_band] = $row;
    }

    // ─── 前半・後半フェーズパターン別回収率の読み込み ────────────────────
    // キー: "{phase_pattern}|{popularity_band}" → 回収率・勝率・サンプル数
    $phasePatternRows = DB::table('t_horse_odds_finder_phase_pattern_recovery')->get();
    $phasePatternMap  = [];
    foreach ($phasePatternRows as $row) {
        $phasePatternMap[$row->phase_pattern . '|' . $row->popularity_band] = $row;
    }

    // ─── AI 回収率実績の読み込み（t_horse_odds_finder_ai_recovery）────────
    // ※ t_horse_odds_finder_ai_recovery は現在未使用のためコメントアウト中
    // ※ ai-analysis-check / SummaryAiRecoveryRate を再有効化する際にコメントを外すこと
    //
    // $aiRecoveryRows = DB::table('t_horse_odds_finder_ai_recovery')
    //     ->orderBy('date',       'desc')
    //     ->orderBy('kaisuu',     'desc')
    //     ->orderBy('basho_code', 'desc')
    //     ->orderBy('day',        'desc')
    //     ->orderBy('race',       'desc')
    //     ->limit(600)
    //     ->get(['ai_pick_count', 'tan_bet', 'fuku_bet']);
    //
    // $calcAiRecovery = function (int $limit) use ($aiRecoveryRows): array {
    //     $slice      = $aiRecoveryRows->take($limit);
    //     $totalPicks = $slice->sum('ai_pick_count');
    //     $totalBet   = $totalPicks * 100;
    //     if ($totalBet <= 0) {
    //         return ['race_count' => $slice->count(), 'tan_rate' => null, 'fuku_rate' => null];
    //     }
    //     return [
    //         'race_count' => $slice->count(),
    //         'tan_rate'   => round($slice->sum('tan_bet') / $totalBet * 100, 1),
    //         'fuku_rate'  => round($slice->sum('fuku_bet') / $totalBet * 100, 1),
    //     ];
    // };
    //
    // $aiRec100 = $calcAiRecovery(100);
    // $aiRec300 = $calcAiRecovery(300);
    // $aiRec600 = $calcAiRecovery(600);

    $emptyAiRec = ['race_count' => 0, 'tan_rate' => null, 'fuku_rate' => null];
    $aiRec100 = $emptyAiRec;
    $aiRec300 = $emptyAiRec;
    $aiRec600 = $emptyAiRec;

    foreach ($promptHorses as &$h) {
        $avgOdds = $popularityAvgMap[$h['popularity']] ?? null;
        if ($avgOdds && $h['odds_6'] > 0) {
            $h['opi'] = round($avgOdds / $h['odds_6'], 2);
        } else {
            $h['opi'] = null;
        }

        // 複勝OPI = 人気順位別の過去平均複勝最小オッズ ÷ 今回の6分前複勝最小オッズ
        $avgFukuOdds = $fukuPopularityAvgMap[$h['popularity']] ?? null;
        if ($avgFukuOdds && isset($h['fuku_min_6']) && $h['fuku_min_6'] > 0) {
            $h['fuku_opi'] = round($avgFukuOdds / $h['fuku_min_6'], 2);
        } else {
            $h['fuku_opi'] = null;
        }

        // 推定確定オッズ
        $corr = $correctionMap[$h['popularity']] ?? null;
        if ($corr && $h['odds_6'] > 0) {
            $h['estimated_final_odds']     = round($h['odds_6'] * floatval($corr->avg_correction_ratio), 2);
            $h['correction_ratio']         = floatval($corr->avg_correction_ratio);
            $h['correction_std']           = floatval($corr->std_correction_ratio);
            // 推定確定複勝最小オッズ: 複勝専用補正テーブル未実装のため単勝補正係数を流用
            $h['estimated_final_fuku_min'] = ($h['fuku_min_6'] !== null && $h['fuku_min_6'] > 0)
                ? round($h['fuku_min_6'] * floatval($corr->avg_correction_ratio), 2)
                : null;
            // ── よっしー20260922指摘②: 推定確定複勝を「最小－最大・誤差」で渡す ──
            // ※最大側も単勝補正係数の流用。複勝専用補正テーブルの実装は別課題として残る。
            $h['estimated_final_fuku_max'] = ($h['fuku_max_6'] !== null && $h['fuku_max_6'] > 0)
                ? round($h['fuku_max_6'] * floatval($corr->avg_correction_ratio), 2)
                : null;
        } else {
            $h['estimated_final_odds']     = null;
            $h['correction_ratio']         = null;
            $h['correction_std']           = null;
            $h['estimated_final_fuku_min'] = null;
            $h['estimated_final_fuku_max'] = null;
        }

        // 予測補正OPI = 人気順位別の過去平均単勝オッズ ÷ 推定確定オッズ
        // 通常OPIの「6分前オッズ」を「推定確定オッズ」に置き換えることで、
        // 締切時点の市場評価に近い割安感を測る。
        if ($avgOdds && isset($h['estimated_final_odds']) && $h['estimated_final_odds'] > 0) {
            $h['estimated_opi'] = round($avgOdds / $h['estimated_final_odds'], 2);
        } else {
            $h['estimated_opi'] = null;
        }

        // 乖離別回収率（変化率帯 × 人気帯 でルックアップ）
        $gapBand = $this->_getGapBand($h['change_rate_raw']);
        $popBand = $this->_getPopularityBand($h['popularity']);
        $gapKey  = $gapBand . '|' . $popBand;
        $gr      = $gapRecoveryMap[$gapKey] ?? null;
        $h['gap_band']          = $gapBand;
        $h['pop_band']          = $popBand;
        $h['gap_recovery_rate'] = $gr ? floatval($gr->recovery_rate) : null;
        $h['gap_win_rate']      = $gr ? floatval($gr->win_rate)      : null;
        $h['gap_sample_count']  = $gr ? intval($gr->sample_count)    : null;

        // OPI帯別回収率（OPI帯 × 人気帯 でルックアップ）
        $opiBand = $h['opi'] !== null ? $this->_getOpiBand($h['opi']) : '－';
        $opiKey  = $opiBand . '|' . $popBand;
        $or      = $opiRecoveryMap[$opiKey] ?? null;
        $h['opi_band']          = $opiBand;
        $h['opi_recovery_rate'] = $or ? floatval($or->recovery_rate) : null;
        $h['opi_win_rate']      = $or ? floatval($or->win_rate)      : null;
        $h['opi_sample_count']  = $or ? intval($or->sample_count)    : null;

        // フェーズパターン別回収率（前半・後半フェーズ方向 × 人気帯 でルックアップ）
        // 前半: 計測開始前（999=ODDS_DB_FIRST）→ 12分前
        // 後半: 12分前 → 6分前
        $oddsStart = isset($h['tan_series'][Constants::ODDS_DB_FIRST]) && $h['tan_series'][Constants::ODDS_DB_FIRST] > 0
            ? $h['tan_series'][Constants::ODDS_DB_FIRST]
            : ($h['odds_base'] > 0 ? $h['odds_base'] : null);
        $odds12    = isset($h['tan_series'][12]) && $h['tan_series'][12] > 0
            ? $h['tan_series'][12]
            : null;
        if ($oddsStart && $odds12 && $h['odds_6'] > 0) {
            $half1Rate = ($odds12 - $oddsStart) / $oddsStart * 100;
            $half2Rate = ($h['odds_6'] - $odds12)  / $odds12   * 100;
            $phasePat  = '前半' . $this->_getPhaseDirection($half1Rate)
                       . '・後半' . $this->_getPhaseDirection($half2Rate);
        } else {
            $half1Rate = null;
            $half2Rate = null;
            $phasePat  = null;
        }
        $phaseKey = $phasePat ? ($phasePat . '|' . $popBand) : null;
        $pp       = $phaseKey ? ($phasePatternMap[$phaseKey] ?? null) : null;
        $h['phase_pattern']       = $phasePat;
        $h['phase_recovery_rate'] = $pp ? floatval($pp->recovery_rate) : null;
        $h['phase_win_rate']      = $pp ? floatval($pp->win_rate)      : null;
        $h['phase_sample_count']  = $pp ? intval($pp->sample_count)    : null;

        // ─── E-3: 直前3分間流入（9分前→6分前）の加速・減速算出 ──────────────
        // 変化率の符号は既存コードの慣習に合わせる: 負 = オッズ下落 = 買われた
        $odds9 = isset($h['tan_series'][9]) && $h['tan_series'][9] > 0
            ? $h['tan_series'][9]
            : null;
        if ($odds9 !== null && $h['odds_6'] > 0) {
            $last3minTanRate = round(($h['odds_6'] - $odds9) / $odds9 * 100, 1);
        } else {
            $last3minTanRate = null;
        }

        $fukuMin9 = isset($h['fuku_min_series'][9]) && $h['fuku_min_series'][9] > 0
            ? $h['fuku_min_series'][9]
            : null;
        if ($fukuMin9 !== null && isset($h['fuku_min_6']) && $h['fuku_min_6'] > 0) {
            $last3minFukuRate = round(($h['fuku_min_6'] - $fukuMin9) / $fukuMin9 * 100, 1);
        } else {
            $last3minFukuRate = null;
        }

        // 加速・減速判定: 後半フェーズ（12→6分前）変化率 vs 直前3分（9→6分前）変化率を比較
        // 直前加速: last3min がより大きく下落（負方向に大きい）→ 直前に勢いよく買われた
        // 直前減速: last3min が半2Rateより負方向が小さい       → 勢いが落ちた
        // 横ばい  : 差が ±2.0ポイント以内
        if ($last3minTanRate !== null && $half2Rate !== null) {
            $accelDiff = $last3minTanRate - $half2Rate; // 負なら last3min の方が大きく下落
            if ($accelDiff < -2.0) {
                $accelLabel = '直前加速';
            } elseif ($accelDiff > 2.0) {
                $accelLabel = '直前減速';
            } else {
                $accelLabel = '横ばい';
            }
        } elseif ($last3minTanRate !== null) {
            // 12分前データなし → 後半フェーズとの比較不可だが値は表示する
            $accelLabel = '判定不可（12分前データなし）';
        } else {
            $accelLabel = null;
        }

        $h['last3min_tan_rate']  = $last3minTanRate;
        $h['last3min_fuku_rate'] = $last3minFukuRate;
        $h['accel_label']        = $accelLabel;
        // ────────────────────────────────────────────────────────────────────
    }
    unset($h);


    // ─── 馬番順テーブルの組み立て ────────────────────────────────────
    $displayHorses = $promptHorses;
    usort($displayHorses, fn($a, $b) => $a['num'] <=> $b['num']);

    // 頭立て数からピックアップ頭数を決定
    // 【仕様】出走頭数別の上限は 8頭以下:4 / 9〜13頭:5 / 14〜15頭:6 / 16頭以上:7
    //   （旧実装は「14頭以上:6」で、16頭以上の7頭が欠けていた）
    $horseCount  = count($displayHorses);
    $pickupCount = match (true) {
        $horseCount <= 8  => 4,
        $horseCount <= 13 => 5,
        $horseCount <= 15 => 6,
        default           => 7,
    };

    // ─── 類似レース統計の読み込み ─────────────────────────────────────
    // horse_count_band: small=8以下 / medium=9〜13 / large=14以上
    $horseBand = $horseCount <= 8 ? 'small' : ($horseCount <= 13 ? 'medium' : 'large');
    $bandLabel = ['small' => '8頭以下', 'medium' => '9〜13頭', 'large' => '14頭以上'][$horseBand];

    $similarStatsMap = DB::table('t_horse_odds_finder_similar_race_stats')
        ->where('horse_count_band', $horseBand)
        ->get()
        ->keyBy('popularity_rank');

    // 時点ラベル（999 = 計測開始前ベース）
    $timingLabels = [
        Constants::ODDS_DB_FIRST => '計測前',
        21 => '21分',
        18 => '18分',
        15 => '15分',
        12 => '12分',
        9  => ' 9分',
        6  => ' 6分',
    ];

    $lines = [];
    foreach ($displayHorses as $h) {
        // 単勝時系列（計測開始前→21分→18分→…→6分前）
        $tanParts = [];
        foreach ($timingLabels as $timing => $label) {
            if (isset($h['tan_series'][$timing])) {
                $tanParts[] = "[{$label}]" . number_format($h['tan_series'][$timing], 1);
            }
        }
        $tanLine = implode('→', $tanParts) . '倍（' . $h['change_label'] . '）';

        // ── よっしー20260922指摘①: 複勝最小オッズの全時点時系列 ──────────────
        // 計測前・21・18・15・12・9・6分前の複勝最小オッズを全時点渡す。
        // 2時点（計測前・6分前）だけでは継続流入・単発急落・反発・直前加速を
        // 正確に判定できないため。データは fuku_min_series に全時点保持済み。
        $fukuSeriesParts = [];
        foreach ($timingLabels as $timing => $label) {
            if (isset($h['fuku_min_series'][$timing]) && $h['fuku_min_series'][$timing] > 0) {
                $fukuSeriesParts[] = "[{$label}]" . number_format($h['fuku_min_series'][$timing], 1);
            }
        }
        $fukuSeriesLine = !empty($fukuSeriesParts)
            ? implode('→', $fukuSeriesParts) . '倍（' . $h['fuku_change'] . '）'
            : '－（複勝オッズデータなし）';

        // 複勝（計測前と6分前のみ表示）
        $fukuMinBase = $h['fuku_min_series'][Constants::ODDS_DB_FIRST] ?? null;
        $fukuMaxBase = $h['fuku_max_series'][Constants::ODDS_DB_FIRST] ?? null;
        $fukuBase    = ($fukuMinBase && $fukuMaxBase)
            ? number_format($fukuMinBase, 1) . '-' . number_format($fukuMaxBase, 1) . '倍'
            : ($fukuMinBase ? number_format($fukuMinBase, 1) . '倍' : '－');
        $fuku6       = ($h['fuku_min_6'] && $h['fuku_max_6'])
            ? number_format($h['fuku_min_6'], 1) . '-' . number_format($h['fuku_max_6'], 1) . '倍'
            : '－';

        // OPI表示
        if ($h['opi'] !== null) {
            $opiVal  = number_format($h['opi'], 2);
            $opiNote = $h['opi'] >= 1.2 ? '過剰人気' : ($h['opi'] <= 0.8 ? '妙味あり' : '平均並み');
            $opiLine = "  OPI: {$opiVal}（{$opiNote}）  ※人気順{$h['popularity']}番の過去平均オッズ" . number_format($popularityAvgMap[$h['popularity']] ?? 0, 1) . "倍÷現在" . number_format($h['odds_6'], 1) . "倍";
        } else {
            $opiLine = "  OPI: －";
        }

        // 予測補正OPI表示
        if ($h['estimated_opi'] !== null) {
            $estOpiVal  = number_format($h['estimated_opi'], 2);
            $estOpiNote = $h['estimated_opi'] >= 1.2 ? '過剰人気' : ($h['estimated_opi'] <= 0.8 ? '妙味あり' : '平均並み');
            $estOpiLine = "  予測補正OPI: {$estOpiVal}（{$estOpiNote}）  ※過去平均" . number_format($popularityAvgMap[$h['popularity']] ?? 0, 1) . "倍÷推定確定" . number_format($h['estimated_final_odds'], 1) . "倍";
        } else {
            $estOpiLine = "  予測補正OPI: －";
        }

        // 複勝OPI表示
        if ($h['fuku_opi'] !== null) {
            $fukuOpiVal  = number_format($h['fuku_opi'], 2);
            $fukuOpiNote = $h['fuku_opi'] >= 1.2 ? '過剰人気' : ($h['fuku_opi'] <= 0.8 ? '妙味あり' : '平均並み');
            $fukuOpiLine = "  複勝OPI: {$fukuOpiVal}（{$fukuOpiNote}）  ※人気順{$h['popularity']}番の過去平均複勝オッズ" . number_format($fukuPopularityAvgMap[$h['popularity']] ?? 0, 1) . "倍÷現在" . number_format($h['fuku_min_6'], 1) . "倍";
        } else {
            $fukuOpiLine = "  複勝OPI: －";
        }

        // 推定確定オッズ表示
        if ($h['estimated_final_odds'] !== null) {
            $estLine = sprintf(
                '  推定確定オッズ: %.1f倍（±%.2f）  ※6分前%.1f倍×補正係数%.4f',
                $h['estimated_final_odds'],
                $h['correction_std'],
                $h['odds_6'],
                $h['correction_ratio']
            );
        } else {
            $estLine = '  推定確定オッズ: －（補正データなし）';
        }

        // 推定確定複勝最小オッズ表示
        // 【なぜ必要か】プロンプトの【おすすめ度の計算方法】は妙味①を
        //   「推定確定複勝最小オッズを中心に評価する」と定めており、
        //   さらに 1.5倍未満なら妙味小計を5点以下に抑えるルールもこの値が前提。
        //   ところがこの値は算出（$h['estimated_final_fuku_min']）されるだけで
        //   プロンプトに出力されておらず、AIは採点基準の数値を見られなかった。
        //   同時に、_judgeLowPayoutException() が読む「推定確定複勝最小: 」行が
        //   存在しないため低配当除外（Block 13a）が一度も発火していなかった。
        //   ※この行のラベル「推定確定複勝最小: 」は
        //     _judgeLowPayoutException() / _saveHighPayoutShadow() / _saveMlSnapshot()
        //     が正規表現で読み取っている。変更してはいけない。
        //   ※小数2桁で出すのは、1.5倍という判定境界を丸めでまたがせないため。
        if ($h['estimated_final_fuku_min'] !== null) {
            // ※ラベル「推定確定複勝最小: 」と直後の数値の並びは
            //   _judgeLowPayoutException() / _saveHighPayoutShadow() / _saveMlSnapshot()
            //   が正規表現で読むため変更禁止。最大・誤差はその後ろへ追記する。
            $estFukuLine = sprintf(
                '  推定確定複勝最小: %.2f倍  推定確定複勝最大: %s  補正誤差: ±%.4f  ※6分前%.1f倍×補正係数%.4f',
                $h['estimated_final_fuku_min'],
                $h['estimated_final_fuku_max'] !== null
                    ? number_format($h['estimated_final_fuku_max'], 2) . '倍'
                    : '－',
                $h['correction_std'],
                $h['fuku_min_6'],
                $h['correction_ratio']
            );
        } else {
            $estFukuLine = '  推定確定複勝最小: －（補正データなし）';
        }

        // 乖離別回収率の表示
        if ($h['gap_recovery_rate'] !== null) {
            $gapRecoveryLine = sprintf(
                '  過去回収率（%s × %s）: 回収率%.1f%%  勝率%.1f%%  サンプル%d件',
                $h['gap_band'],
                $h['pop_band'],
                $h['gap_recovery_rate'],
                $h['gap_win_rate'],
                $h['gap_sample_count']
            );
        } else {
            $gapRecoveryLine = sprintf(
                '  過去回収率（%s × %s）: －（データなし）',
                $h['gap_band'],
                $h['pop_band']
            );
        }

        // OPI帯別回収率の表示
        if ($h['opi_recovery_rate'] !== null) {
            $opiRecoveryLine = sprintf(
                '  OPI帯別回収率（OPI%s × %s）: 回収率%.1f%%  勝率%.1f%%  サンプル%d件',
                $h['opi_band'],
                $h['pop_band'],
                $h['opi_recovery_rate'],
                $h['opi_win_rate'],
                $h['opi_sample_count']
            );
        } else {
            $opiRecoveryLine = sprintf(
                '  OPI帯別回収率（OPI%s × %s）: －（データなし）',
                $h['opi_band'],
                $h['pop_band']
            );
        }

        // 単勝・複勝流入ランク表示（相対資金流入 E-1）
        if ($h['tan_inflow_rank'] !== null) {
            $tanInflowLine = sprintf(
                '  単勝流入ランク: %d位/%d頭中（%s）  ※短縮率: %+.1f%%',
                $h['tan_inflow_rank'],
                $h['tan_inflow_group_size'],
                $h['inflow_group'],
                $h['tan_shrink_rate']
            );
        } else {
            $tanInflowLine = sprintf('  単勝流入ランク: データなし（%s）', $h['inflow_group']);
        }
        if ($h['fuku_inflow_rank'] !== null) {
            $fukuInflowLine = sprintf(
                '  複勝流入ランク: %d位/%d頭中（%s）  ※短縮率: %+.1f%%',
                $h['fuku_inflow_rank'],
                $h['fuku_inflow_group_size'],
                $h['inflow_group'],
                $h['fuku_shrink_rate']
            );
        } else {
            $fukuInflowLine = sprintf('  複勝流入ランク: データなし（%s）', $h['inflow_group']);
        }

        $lines[] = sprintf('%2d番(%2d人気) %s', $h['num'], $h['popularity'], $h['name']);
        $lines[] = '  単勝: ' . $tanLine;
        $lines[] = '  複勝: 計測前' . $fukuBase . '→6分前' . $fuku6 . '（' . $h['fuku_change'] . '）  単複比: ' . $h['tanpuku_ratio'];
        $lines[] = '  複勝時系列（最小）: ' . $fukuSeriesLine;   // よっしー20260922指摘①
        $lines[] = $tanInflowLine;
        $lines[] = $fukuInflowLine;
        $lines[] = $opiLine;
        $lines[] = $estOpiLine;
        $lines[] = $fukuOpiLine;
        $lines[] = $estLine;
        $lines[] = $estFukuLine;
        // フェーズパターン別回収率の表示
        if ($h['phase_pattern'] !== null && $h['phase_recovery_rate'] !== null) {
            $phasePatternLine = sprintf(
                '  フェーズパターン別回収率（%s × %s）: 回収率%.1f%%  勝率%.1f%%  サンプル%d件',
                $h['phase_pattern'],
                $h['pop_band'],
                $h['phase_recovery_rate'],
                $h['phase_win_rate'],
                $h['phase_sample_count']
            );
        } elseif ($h['phase_pattern'] !== null) {
            $phasePatternLine = sprintf(
                '  フェーズパターン別回収率（%s × %s）: －（データなし）',
                $h['phase_pattern'],
                $h['pop_band']
            );
        } else {
            $phasePatternLine = '  フェーズパターン別回収率: －（オッズデータ不足）';
        }

        $lines[] = $gapRecoveryLine;
        $lines[] = $opiRecoveryLine;
        $lines[] = $phasePatternLine;

        // E-3: 直前流入（9→6分前）
        // 複勝を単勝より先に表示（既存AI指示の重み付けと整合）
        if ($h['accel_label'] !== null) {
            $tanRateStr  = $h['last3min_tan_rate']  !== null
                ? sprintf('%+.1f%%', $h['last3min_tan_rate'])
                : 'データなし';
            $fukuRateStr = $h['last3min_fuku_rate'] !== null
                ? sprintf('%+.1f%%', $h['last3min_fuku_rate'])
                : 'データなし';
            $last3minLine = sprintf(
                '  直前流入（9→6分前）: 複勝 %s / 単勝 %s / 判定: %s',
                $fukuRateStr,
                $tanRateStr,
                $h['accel_label']
            );
        } else {
            $last3minLine = '  直前流入（9→6分前）: データなし（9分前オッズ未取得）';
        }
        $lines[] = $last3minLine;

        // 類似レース統計（過去の同人気順・同頭数帯における3着以内率等）
        $ss = $similarStatsMap[$h['popularity']] ?? null;
        if ($ss && $ss->reliability !== 'insufficient') {
            $relLabel    = ['normal' => '通常', 'low' => '低下', 'reference' => '参考'][$ss->reliability] ?? '不明';
            $similarLine = sprintf(
                '  類似レース統計（%d番人気 × %s・N=%d・信頼度:%s）: 3着以内率%.1f%% / 5着以内率%.1f%% / 平均着順%.1f位 / 着外率%.1f%%',
                $h['popularity'],
                $bandLabel,
                $ss->sample_count,
                $relLabel,
                $ss->top3_rate    * 100,
                $ss->top5_rate    * 100,
                $ss->avg_finishing_position,
                $ss->outside_rate * 100
            );
        } else {
            $similarLine = sprintf(
                '  類似レース統計（%d番人気 × %s）: 統計不足のため参考不可',
                $h['popularity'],
                $bandLabel
            );
        }
        $lines[] = $similarLine;

        $lines[] = '';
    }
    $table = implode("\n", $lines);

    // ─── 単勝断層テーブルの計算 ──────────────────────────────────────────────
    // 断層値 = 直下人気馬のオッズ ÷ 直上人気馬のオッズ
    // $promptHorses は人気順ソート済みなのでそのまま使う
    $gapTableLines = [];
    for ($i = 0; $i < count($promptHorses) - 1; $i++) {
        $upper = $promptHorses[$i];     // 人気上位
        $lower = $promptHorses[$i + 1]; // 人気下位
        if ($upper['odds_6'] > 0) {
            $gapRatio       = round($lower['odds_6'] / $upper['odds_6'], 2);
            if ($gapRatio >= 3.0)      { $gapFlag = '  ★★★非常に強い断層'; }
            elseif ($gapRatio >= 2.5)  { $gapFlag = '  ★★強い断層'; }
            elseif ($gapRatio >= 2.0)  { $gapFlag = '  ★断層'; }
            else                       { $gapFlag = ''; }
            $gapTableLines[] = sprintf(
                ' %d人気(%d番)%5.1f倍 → %d人気(%d番)%5.1f倍  比率: %.2f%s',
                $upper['popularity'], $upper['num'], $upper['odds_6'],
                $lower['popularity'], $lower['num'], $lower['odds_6'],
                $gapRatio,
                $gapFlag
            );
        }
    }
    $gapTable = implode("\n", $gapTableLines);

    // ─── 複勝断層テーブルの計算 ──────────────────────────────────────────────
    // 複勝オッズ（6分前 fuku_min）で人気順ソートし、隣接間の断層を算出する
    $fukuSortedHorses = array_values(
        array_filter($promptHorses, fn($h) => isset($h['fuku_min_6']) && $h['fuku_min_6'] > 0)
    );
    usort($fukuSortedHorses, fn($a, $b) => $a['fuku_min_6'] <=> $b['fuku_min_6']);

    $fukuGapDetails    = []; // 複勝断層生データ（ratio≥2.0）：タイプ判定に使用
    $fukuGapTableLines = [];
    for ($i = 0; $i < count($fukuSortedHorses) - 1; $i++) {
        $upper = $fukuSortedHorses[$i];
        $lower = $fukuSortedHorses[$i + 1];
        if ($upper['fuku_min_6'] > 0) {
            $gapRatio = round($lower['fuku_min_6'] / $upper['fuku_min_6'], 2);
            if ($gapRatio >= 3.0)      { $gapFlag = '  ★★★非常に強い断層'; }
            elseif ($gapRatio >= 2.5)  { $gapFlag = '  ★★強い断層'; }
            elseif ($gapRatio >= 2.0)  { $gapFlag = '  ★断層'; }
            else                       { $gapFlag = ''; }
            if ($gapRatio >= 2.0) {
                $fukuGapDetails[] = ['upper_pos' => $i + 1, 'lower_pos' => $i + 2, 'ratio' => $gapRatio];
            }
            $fukuGapTableLines[] = sprintf(
                ' 複%d位(%d番)%5.1f倍 → 複%d位(%d番)%5.1f倍  比率: %.2f%s',
                $i + 1, $upper['num'], $upper['fuku_min_6'],
                $i + 2, $lower['num'], $lower['fuku_min_6'],
                $gapRatio,
                $gapFlag
            );
        }
    }
    $fukuGapTable = implode("\n", $fukuGapTableLines);

    // ─── 厳選穴レース：条件2の計算 ────────────────────────────────
    // 「6番人気以内の隣接間に断層（比率2.00以上）が2つ以上あるか」
    // 成立 → false(0) 確定。条件1より優先。
    // $promptHorses は人気順ソート済みなのでそのまま使う。
    $gapCountInTop6 = 0;
    for ($i = 0; $i < count($promptHorses) - 1; $i++) {
        $upper = $promptHorses[$i];
        $lower = $promptHorses[$i + 1];
        if ($upper['popularity'] <= 6 && $upper['odds_6'] > 0) {
            $ratio = $lower['odds_6'] / $upper['odds_6'];
            if ($ratio >= 2.0) {
                $gapCountInTop6++;
            }
        }
    }
    $condition2Met = $gapCountInTop6 >= 2;
    $condition2Desc = $condition2Met
        ? "成立（6番人気以内に断層が{$gapCountInTop6}個あるため 0 確定）"
        : "不成立（6番人気以内の断層は{$gapCountInTop6}個）";

    // ─── 厳選穴レース：条件C（全馬の複勝最大値が低い・ガチガチレース） ─────
    // どの馬が来ても複勝が安い = 馬券コストを回収できない → 0 確定
    $fukuOddsAll   = array_filter(array_column($promptHorses, 'fuku_min_6'), fn($v) => $v > 0);
    $maxFukuMin6   = $fukuOddsAll ? max($fukuOddsAll) : 0;
    $condition3Met  = $maxFukuMin6 > 0 && $maxFukuMin6 < 3.5;
    $condition3Desc = $condition3Met
        ? "成立（全馬の6分前複勝最小オッズ最大値が{$maxFukuMin6}倍 < 3.5倍 → 0 確定）"
        : "不成立（全馬の6分前複勝最小オッズ最大値: {$maxFukuMin6}倍）";

    // ─── 厳選穴レース：条件D（1番人気の単勝が極端に低い・1強レース） ───────
    // 1強レースは荒れる余地がなく、複勝でも配当が出ない → 0 確定
    $firstPopOdds  = 0;
    foreach ($promptHorses as $h) {
        if ((int)($h['popularity'] ?? 0) === 1 && ($h['odds_6'] ?? 0) > 0) {
            $firstPopOdds = $h['odds_6'];
            break;
        }
    }
    $condition4Met  = $firstPopOdds > 0 && $firstPopOdds < 2.0;
    $condition4Desc = $condition4Met
        ? "成立（1番人気の6分前単勝オッズが{$firstPopOdds}倍 < 2.0倍 → 0 確定）"
        : "不成立（1番人気の6分前単勝オッズ: {$firstPopOdds}倍）";

    // ─── 断層構造タイプ判定（PHP算出・AI入力として渡す） ──────────────────
    // 単勝断層の生データを収集（タイプ判定用）
    $tanGapAll    = []; // 全断層(ratio≥2.0)
    $tanGapTop6   = []; // 6番人気以内の断層
    $tanGapStrong = []; // 6番人気以内かつratio≥2.5の強断層
    for ($i = 0; $i < count($promptHorses) - 1; $i++) {
        $u = $promptHorses[$i];
        $l = $promptHorses[$i + 1];
        if ($u['odds_6'] > 0 && $l['odds_6'] > 0) {
            $r = round($l['odds_6'] / $u['odds_6'], 2);
            if ($r >= 2.0) {
                $entry = ['upper_pop' => $u['popularity'], 'lower_pop' => $l['popularity'], 'ratio' => $r];
                $tanGapAll[] = $entry;
                if ($u['popularity'] <= 6) {
                    $tanGapTop6[] = $entry;
                    if ($r >= 2.5) $tanGapStrong[] = $entry;
                }
            }
        }
    }

    $tanHasGap  = count($tanGapAll) > 0;
    $fukuHasGap = count($fukuGapDetails) > 0;

    // A: 二重断層・上位完結型
    if (count($tanGapTop6) >= 2 && count($tanGapStrong) >= 1) {
        $gapType      = 'A';
        // タイプA: 複数断層の中で最も上位（upper_popが最小）の断層を主断層とする
        $typeAMinPos = !empty($tanGapTop6)
            ? min(array_column($tanGapTop6, 'upper_pop'))
            : 1;
        $typeAMinPosNext = $typeAMinPos + 1;
        $gapTypeDesc  = '二重断層・上位完結型（6番人気以内に断層' . count($tanGapTop6) . 'か所、主断層' . $typeAMinPos . '〜' . $typeAMinPosNext . '番人気間、うち比率2.5以上' . count($tanGapStrong) . 'か所）';
        // 仕様書のタイプ別選出方針（原文どおり）
        $gapTypeGuide = '断層上側を中心に。断層下側は自動除外せず、複勝流入が強ければ補欠候補に。';

    // E: 単勝・複勝断層の矛盾（どちらか一方にだけ断層）
    } elseif ($tanHasGap !== $fukuHasGap) {
        $gapType      = 'E';
        $gapTypeDesc  = '判定困難型（単勝断層' . ($tanHasGap ? 'あり' : 'なし') . '・複勝断層' . ($fukuHasGap ? 'あり' : 'なし') . 'で矛盾）';
        // 仕様書のタイプ別選出方針（原文どおり）
        $gapTypeGuide = '断層は参考程度。複勝の継続的な動きを最優先で評価。';

    // B: 上位断層型（top6に断層1か所）
    } elseif (count($tanGapTop6) === 1) {
        $e            = $tanGapTop6[0];
        $gapType      = 'B';
        $gapTypeDesc  = '上位断層型（' . $e['upper_pop'] . '〜' . $e['lower_pop'] . '番人気間に断層、比率' . $e['ratio'] . '）';
        // 仕様書のタイプ別選出方針（原文どおり）
        $gapTypeGuide = '断層上側グループが中心。断層拡大中は上側重視、縮小中は下側の浮上を警戒。';

    // C: 中間断層型（断層はあるがtop6外）
    } elseif ($tanHasGap) {
        $e            = $tanGapAll[0];
        $gapType      = 'C';
        $gapTypeDesc  = '中間断層型（' . $e['upper_pop'] . '〜' . $e['lower_pop'] . '番人気間に断層、比率' . $e['ratio'] . '）';
        // 仕様書のタイプ別選出方針（原文どおり）
        $gapTypeGuide = '断層上側=中心グループ、断層下側=穴グループ。複勝流入の方向で判断を補正。';

    // D: 断層なし・混戦型
    } else {
        $gapType      = 'D';
        $gapTypeDesc  = '断層なし・混戦型（2.0以上の断層なし）';
        // 仕様書のタイプ別選出方針（原文どおり）
        $gapTypeGuide = '複勝支持・変化率・単複人気差を重視。7〜10番人気も均等に比較。';
    }

    // ─── Block 8: 断層位置別・人気帯別の選出上限 ─────────────────────────────
    // 主断層位置（upper_pop）を決定し、仕様書テーブルに従い上限を設定する
    // 仕様書テーブル:
    //   1〜2番人気間: Upper3 / Mid2 / Lower1
    //   2〜3番人気間: Upper4 / Mid2 / Lower1
    //   3〜4番人気間: Upper4 / Mid2 / Lower1
    //   4〜5番人気間: Upper4 / Mid2 / Lower1 (大穴進入度1〜2なら Lower0 だがPHP未計算のため1適用)
    //   5〜6番人気間: Upper3 / Mid3 / Lower2
    //   6番人気以降:  Upper2 / Mid3 / Lower2
    //   断層なし・D・E: Upper3 / Mid3 / Lower2
    $primaryGapUpperPop = null; // null = 断層なし・判定困難
    if ($gapType === 'A' && !empty($tanGapTop6)) {
        $primaryGapUpperPop = min(array_column($tanGapTop6, 'upper_pop'));
    } elseif ($gapType === 'B' && !empty($tanGapTop6)) {
        $primaryGapUpperPop = $tanGapTop6[0]['upper_pop'];
    } elseif ($gapType === 'C' && !empty($tanGapAll)) {
        $primaryGapUpperPop = $tanGapAll[0]['upper_pop']; // top6外 → ≥7 → 「6番人気以降」扱い
    }
    // タイプD / E: $primaryGapUpperPop = null（断層なし・判定困難として扱う）

    if ($primaryGapUpperPop === null || in_array($gapType, ['D', 'E'])) {
        [$pickupUpperMax, $pickupMidMax, $pickupLowerMax] = [3, 3, 2];
    } elseif ($primaryGapUpperPop === 1) {
        [$pickupUpperMax, $pickupMidMax, $pickupLowerMax] = [3, 2, 1];
    } elseif ($primaryGapUpperPop <= 4) {
        // 2〜3 / 3〜4 / 4〜5番人気間: 同じ上限
        [$pickupUpperMax, $pickupMidMax, $pickupLowerMax] = [4, 2, 1];
    } elseif ($primaryGapUpperPop === 5) {
        [$pickupUpperMax, $pickupMidMax, $pickupLowerMax] = [3, 3, 2];
    } else {
        // 6番人気以降（upper_pop >= 6）
        [$pickupUpperMax, $pickupMidMax, $pickupLowerMax] = [2, 3, 2];
    }
    // 【仕様】「1st AIの候補上限は7頭」。
    //   人気帯別上限の単純合計は、断層なし／判定困難・5〜6番人気間断層のとき 3+3+2=8 になり、
    //   仕様の7頭を超えてしまう。その状態でAIが8頭返すと B-15 が制御済みエラーで
    //   レースごと無効化してしまうため、ここで必ず7頭に頭打ちする。
    $pickupTotalMax = min(7, $pickupUpperMax + $pickupMidMax + $pickupLowerMax);

    // ─── 断層時系列（単勝・6分前人気順基準） ─────────────────────────────
    // $fetchTimings = [999, 21, 18, 15, 12, 9, 6] から 999 を除いた時点を使用
    $gapSeriesTimings = array_values(array_filter($fetchTimings, fn($t) => $t !== Constants::ODDS_DB_FIRST));
    // = [21, 18, 15, 12, 9, 6]（早い→遅い順）

    $gapTimeSeriesLines = [];
    for ($i = 0; $i < count($promptHorses) - 1; $i++) {
        $upper = $promptHorses[$i];
        $lower = $promptHorses[$i + 1];

        $ratios   = [];
        $maxRatio = 0;
        foreach ($gapSeriesTimings as $t) {
            $uOdds = $upper['tan_series'][$t] ?? null;
            $lOdds = $lower['tan_series'][$t] ?? null;
            if ($uOdds && $lOdds && $uOdds > 0) {
                $r = round($lOdds / $uOdds, 2);
                $ratios[$t] = $r;
                if ($r > $maxRatio) $maxRatio = $r;
            } else {
                $ratios[$t] = null;
            }
        }

        if ($maxRatio < 2.0) continue; // 一度も断層にならなかったペアはスキップ

        // トレンド判定（最初と最後で比較）
        $validRatios = array_values(array_filter($ratios, fn($r) => $r !== null));
        $firstR      = $validRatios[0] ?? null;
        $lastR       = end($validRatios) ?: null;
        $trend       = '';
        if ($firstR !== null && $lastR !== null) {
            $diff = round($lastR - $firstR, 2);
            if ($diff >= 0.3)      $trend = '【拡大中 +' . $diff . '】';
            elseif ($diff <= -0.3) $trend = '【縮小中 ' . $diff . '】';
            else                   $trend = '【安定】';
        }

        $pairLabel = $upper['popularity'] . '〜' . $lower['popularity'] . '番人気間';
        $parts     = [];
        foreach ($ratios as $t => $r) {
            $marker  = ($r !== null && $r >= 2.0) ? '★' : '';
            $parts[] = $t . '分=' . ($r !== null ? $marker . number_format($r, 2) : '－');
        }

        $gapTimeSeriesLines[] = $pairLabel . ': ' . implode(' → ', $parts) . ' ' . $trend;
    }

    // ─── 過去のレース情報から絞り込んだ馬番（forecast_nums）の取得 ──
    $forecastNums = DB::table('t_horse_odds_finder_forecast_from_last_race')
        ->where('date',       $targetDate)
        ->where('kaisuu',     $race->kaisuu)
        ->where('basho',      $race->basho)
        ->where('basho_name', $race->basho_name)
        ->where('day',        $race->day)
        ->where('race',       $race->race)
        ->value('forecast_nums');

    // ─── プロンプト本文の構築 ─────────────────────────────────────────
    $raceLabel = $race->kaisuu . '回' . $race->basho_name . $race->day . '日';
    $raceNum   = $race->race . 'R';
    $raceName  = $race->race_name ?? '';

    $lines = [
        'あなたは競馬オッズ分析の専門家です。',
        '有料公開するものなので、正しい日本語で返してください。',
        '',
        'レース情報',
        '日付: ' . $targetDate,
        '開催: ' . $raceLabel,
        'レース: ' . $raceNum . ' ' . $raceName,
        '',
        '単勝・複勝オッズデータ（計測開始前〜発走6分前・全時点）',
        $table,
        '',
        '単勝断層テーブル（6分前単勝オッズ・隣接人気順間の比率）',
        '※比率2.00以上=断層（★）、2.50以上=強い断層、3.00以上=非常に強い断層',
        $gapTable,
        '',
        '複勝断層テーブル（6分前複勝最小オッズ・隣接複勝人気順間の比率）',
        '※単勝断層と複勝断層が同じ位置に出ている場合は断層の信頼度が上がります',
        '※矛盾する場合（単勝にあるが複勝にない、またはその逆）は複勝断層を優先してください',
        $fukuGapTable,
        '',
    ];

    $lines[] = '【断層構造タイプ（PHP算出済み）】';
    $lines[] = "タイプ{$gapType}：{$gapTypeDesc}";
    $lines[] = "選出方針：{$gapTypeGuide}";
    $lines[] = '';
    $lines[] = '※ タイプ別の詳細ルール（波乱度の目安を含む）';
    $lines[] = 'A（二重断層・上位完結型）: 断層上側を中心に。断層下側は自動除外せず、複勝流入が強ければ補欠候補に。波乱度目安：1〜2（堅い〜やや堅い）';
    $lines[] = 'B（上位断層型）: 断層上側グループが中心。断層拡大中は上側重視、縮小中は下側の浮上を警戒。波乱度目安：2〜3（やや堅い〜中波乱）';
    $lines[] = 'C（中間断層型）: 断層上側=中心グループ、断層下側=穴グループ。複勝流入の方向で判断を補正。波乱度目安：3（中波乱）';
    $lines[] = 'D（断層なし・混戦型）: 複勝支持・変化率・単複人気差を重視。7〜10番人気も均等に比較。波乱度目安：4〜5（波乱〜大波乱）';
    $lines[] = 'E（判定困難型）: 断層は参考程度。複勝の継続的な動きを最優先で評価。波乱度目安：4〜5（波乱〜大波乱）';
    $lines[] = '';
    $lines[] = '【波乱度の修正ルール（1〜5：1=堅い、2=やや堅い、3=中波乱、4=波乱、5=大波乱）】';
    $lines[] = 'タイプ別の波乱度目安を基準に、以下の条件で1段階上下してください。';
    $lines[] = '■ 波乱度を1段階「上げる」条件（複数該当で2段階まで）';
    $lines[] = '・断層時系列が6分前に向けて急縮小している';
    $lines[] = '・断層より下の複数馬に複勝流入が確認できる';
    $lines[] = '・7〜10番人気の複勝流入ランクが継続上昇している';
    $lines[] = '・単勝と複勝の断層位置が不一致（タイプEの判定根拠）';
    $lines[] = '・1番人気の複勝支持が継続低下している';
    $lines[] = '■ 波乱度を1段階「下げる」条件（複数該当で2段階まで）';
    $lines[] = '・断層が複数時点にわたって継続維持されている';
    $lines[] = '・単勝と複勝の断層位置が一致している';
    $lines[] = '・断層時系列が6分前に向けて拡大している';
    $lines[] = '・6番人気以内に二重断層（2か所以上）が成立している';
    $lines[] = '・断層より下の馬に明確な複勝流入がない';
    $lines[] = '';
    if (!empty($gapTimeSeriesLines)) {
        $lines[] = '【断層時系列（単勝・6分前人気順基準）】';
        $lines[] = '※ 断層（比率2.0以上）が一度でも発生した隣接ペアのみ表示。★=断層あり。21分前〜6分前の推移です';
        foreach ($gapTimeSeriesLines as $gapLine) {
            $lines[] = $gapLine;
        }
    } else {
        $lines[] = '【断層時系列（単勝・6分前人気順基準）】';
        $lines[] = '計測期間中、断層（比率2.0以上）は一度も発生しませんでした';
    }
    $lines[] = '';

    if (!empty($gapHorseNums) || !empty($upsetPickupHorseNums) || !empty($forecastNums)) {
        $lines[] = 'なお、オッズ分析にあたり、下記の注目馬番も参考にしてください。';
        if (!empty($upsetPickupHorseNums)) {
            $lines[] = '特に、②の期待数値の馬番はかなり結果を出せているので、重点的に注視してください。';
        }
        $lines[] = '';
        $lines[] = '①　オッズ間断層の調査から絞り込んだ馬番「' . $gapHorseNums . '」（1|2|...のようにパイプで区切られている）';
        $lines[] = 'オッズ間断層とは、隣り合う人気順間のオッズの比率（次の人気順のオッズ ÷ この人気順のオッズ）です。';
        $lines[] = '比率が2以上の場合、「断層が発生している」と判断し、断層上の馬に注目しています。';
        $lines[] = '';
        if (!empty($upsetPickupHorseNums)) {
            $lines[] = '②　期待数値の調査から絞り込んだ馬番「' . $upsetPickupHorseNums . '」（1|2|...のようにパイプで区切られている）';
            $lines[] = '期待数値とは、過去の類似レースにおける人気順別の中央値オッズを、今回のレースの同人気順のオッズで割った値です。';
            $lines[] = '';
        }
        if (!empty($forecastNums)) {
            $lines[] = '③　過去のレース情報から絞り込んだ馬番「' . $forecastNums . '」（1|2|...のようにパイプで区切られている）';
            $lines[] = '過去の出走情報をAIに渡して、レースの頭数に応じて馬番を絞り込んだ値です（8頭以下は4頭、13頭以下は5頭、14頭以上は6頭に絞り込み）。';
            $lines[] = '';
        }
    }

    $lines = array_merge($lines, [
        '分析依頼',
        '',
        '【⚠️ 絶対ルール：DB・PHP算出値は変更・再計算禁止】',
        '以下の値はDBまたはPHPが事前に算出した確定値です。AIは自分で再計算・上書き・補正・推測をしてはなりません。',
        'データ欄に表示されている値を必ずそのまま使用してください。',
        '・断層構造タイプ（A〜E）: PHP算出済み。AIが独自に「Cタイプだと思う」のように上書き判断することは禁止',
        '・OPI（単勝OPI・複勝OPI・推定補正OPI）: DB算出済み。自分でオッズ比率から独自計算しないこと',
        '・相対資金流入ランク（単勝流入ランク・複勝流入ランク）: DB算出済み。自分でオッズ変化率からランクを推測しないこと',
        '・推定確定オッズ・補正係数: DB算出済み。自分でオッズパターンから独自予測しないこと',
        '・厳選穴レース判定（条件B・C・D）: PHP算出済み。自分でオッズを見て再判定しないこと',
        '・人気順: テーブルの「X人気」欄の値をそのまま使用。自分でオッズ順に並べ替えて人気順を変えないこと',
        '',
        '【このシステムの目的（最重要）】',
        'このシステムの目的は「当てること」ではなく「回収率を上げること」です。',
        '1〜3番人気ばかりを正確に当てても、オッズが低いため回収率は上がりません。',
        '「来そうかどうか（信頼度）」と「そのオッズで買う価値があるか（妙味）」を必ず両方考えてください。',
        '信頼度が同等の馬が複数いる場合は、オッズが高い馬（妙味がある馬）を優先して選出してください。',
        '',
        // ── AI回収率実績ブロック: 有効データがある場合のみ送信（仕様: 0件・－%は送信しない）
        ...($aiRec100['race_count'] > 0 || $aiRec300['race_count'] > 0 || $aiRec600['race_count'] > 0 ? [
            '【このAIの過去回収率実績】',
            '※選択馬全頭に100円ずつ単勝・複勝を投資した場合の実績（毎日自動集計）',
            sprintf(
                '直近%dレース: 単勝回収率%s%%  複勝回収率%s%%',
                $aiRec100['race_count'],
                $aiRec100['tan_rate'] !== null ? number_format($aiRec100['tan_rate'], 1) : '－',
                $aiRec100['fuku_rate'] !== null ? number_format($aiRec100['fuku_rate'], 1) : '－'
            ),
            sprintf(
                '直近%dレース: 単勝回収率%s%%  複勝回収率%s%%',
                $aiRec300['race_count'],
                $aiRec300['tan_rate'] !== null ? number_format($aiRec300['tan_rate'], 1) : '－',
                $aiRec300['fuku_rate'] !== null ? number_format($aiRec300['fuku_rate'], 1) : '－'
            ),
            sprintf(
                '直近%dレース: 単勝回収率%s%%  複勝回収率%s%%',
                $aiRec600['race_count'],
                $aiRec600['tan_rate'] !== null ? number_format($aiRec600['tan_rate'], 1) : '－',
                $aiRec600['fuku_rate'] !== null ? number_format($aiRec600['fuku_rate'], 1) : '－'
            ),
            '単勝・複勝どちらかの回収率が100%未満の場合、その馬券種については選出基準をより厳しくし、妙味の低い馬を除外してください。',
            '',
        ] : []),
        "オッズ推移から注目馬を選出してください（合計最大{$pickupTotalMax}頭まで）。",
        '',
        "【このレースの推奨頭数上限（タイプ{$gapType}）】",
        "・1〜6番人気から最大{$pickupUpperMax}頭",
        "・7〜10番人気から最大{$pickupMidMax}頭" . ($pickupMidMax === 0 ? "（原則選出なし）" : ""),
        "・11番人気以下（人気薄注目馬）から最大{$pickupLowerMax}頭" . ($pickupLowerMax === 0 ? "（原則選出なし）" : ""),
        "・合計最大{$pickupTotalMax}頭（推奨頭数は上限。最低基準点を満たす馬だけを選出すること）",
        '・頭数の上限は 1st AI 最大7頭、2nd AI 最大5頭、統合後は出走頭数に応じて最大4〜7頭です。上記のPHP算出上限（人気帯別・合計）がこれより少ない場合は、必ず少ない方を優先してください。',
        '',
        '【能力・適性評価（100点満点・6項目）】',
        'プロンプト末尾の【各馬の直近成績（過去最大10走）と今走データ】を根拠に、選出した各馬を以下の6項目で採点してください。',
        '1. 基礎能力・クラス実績：25点',
        '2. 近走内容・着差・相手関係：20点',
        '3. コース・距離・芝ダート・馬場適性：20点',
        '4. 脚質・想定展開・枠順との適合：15点',
        '5. 上がり性能・位置取り・レース内容：10点',
        '6. 斤量・騎手・馬体重・休養間隔などの補正：10点',
        '評価区分：A（80〜100点）B（70〜79点）C（60〜69点）D（59点以下）',
        '出走履歴がない馬はD評価（0点）とする。',
        '採点結果は、選出理由の冒頭に必ず「能力適性:X（XX点）。」の形式で記載してください。この記載がないとPHP側で能力適性点を抽出できません。',
        'ただし能力・適性だけで候補を決めてはいけません。時系列オッズの補強材料として扱い、能力適性Dでも強い市場根拠がある馬を自動除外しないでください。',
        '',
        '【出力フォーマット（厳守）】',
        'このフォーマットは画面表示アプリがそのままパースします。',
        '前置き・後書き・補足コメントは不要です。フォーマット通りに出力してください。',
        '',
        '─────────────────────────────',
        '厳選穴レース|1または0',
        'レース指標|波乱度: X|下位進入度: X|大穴進入度: X',
        '馬番：X、馬名：XXX、人気順: X、6分前オッズ: X.X、おすすめ度: XX、選出理由：能力適性:A（82点）。XXXXXXXXXXXXXXXXXXXXXXXXXXXX（候補1頭につき改行なしの1行。冒頭の「能力適性:X（XX点）。」は必須。続けて客観的根拠を4〜5要素入れ、箇条書きにしない）',
        '─────────────────────────────',
        '',
        '【厳選穴レースの判定ルール】',
        '選出が終わったあと、以下のルールで「厳選穴レース|1」または「厳選穴レース|0」を出力の先頭1行目に必ず入れてください。',
        '',
        '■ 0に強制する条件（以下のいずれか1つでも成立すれば「0」確定・条件Aより優先）',
        '・条件B: 6番人気以内の隣接間に断層（比率2.00以上）が2つ以上ある（上位完結型）',
        '・条件C: 全馬の6分前複勝最小オッズの最大値が3.5倍未満（ガチガチレース・どの馬が来ても安い）',
        '・条件D: 1番人気の6分前単勝オッズが2.0倍未満（1強レース・荒れる余地なし）',
        '',
        '■ 1になる条件（B・C・D が全て不成立の場合のみ判定）',
        '・条件A: 選出した馬の中に7〜10番人気の馬が1頭以上含まれている',
        '',
        '■ 判定の優先順位',
        '条件B・C・D のいずれか1つでも成立 → 0（条件Aの結果を無視）',
        '条件B・C・D が全て不成立 かつ 条件A成立 → 1',
        'それ以外 → 0',
        '',
        '■ PHP算出済みの結果（必ずこの結果に従うこと・自分で再計算しないこと）',
        "条件B（PHP算出済み）: {$condition2Desc}",
        "条件C（PHP算出済み）: {$condition3Desc}",
        "条件D（PHP算出済み）: {$condition4Desc}",
        '',
        '【おすすめ度の計算方法】',
        // ── 以下は仕様書【おすすめ度の計算方法】の本文そのまま（要約・省略禁止）──
        'おすすめ度は100点満点とし、「信頼度60点＋妙味40点」の固定内訳で全頭を採点してください。項目の追加、削除、配点変更、信頼度と妙味の間での点数移動は禁止です。同じ事実を複数項目で評価する場合は、各項目の目的に限定し、同一根拠を重複して満点評価しないでください。',
        '',
        '■ 信頼度（60点）：この馬が5着以内に来そうか',
        '1．複勝支持・安定性：0〜15点',
        '　複勝最小オッズの水準、複勝レンジの幅、複数時点での支持の安定性を評価する。複勝オッズが継続上昇、またはレンジが大きく拡大して不安定な馬は減点する。',
        '2．単勝・複勝の継続資金流入：0〜12点',
        '　計測開始から6分前までの継続性を評価し、特に複勝流入を重視する。単発急落、急落後の反発、1時点だけの変化は高得点にしない。',
        '3．単複人気差・支持差：0〜8点',
        '　複勝人気が単勝人気より高いこと、複勝流入ランクが単勝流入ランクより良いこと、単複比の整合性を補助材料として評価する。単複比だけで断定しない。',
        '4．断層位置・時間変化・単複一致：0〜10点',
        '　主断層の上側・下側、断層の継続・拡大・縮小・消滅、単勝断層と複勝断層の一致、断層下からの接近を評価する。断層下側という理由だけで自動的に0点または除外にしない。',
        '5．類似レース統計：0〜8点',
        '　サンプル数とともに3着以内率、5着以内率、平均着順、着外率を評価する。サンプル30件未満は参考値とし、単独で高得点にしない。',
        '6．予測補正の維持・直前傾向：0〜4点',
        '　推定確定単勝・複勝オッズ、補正係数、誤差、9分前→6分前の流入維持または加速を評価する。誤差が大きい場合は参考程度にする。',
        '7．能力・今回条件への適性：0〜3点',
        '　別途算出する能力適性A〜Dを補強材料として反映する。A=3点、B=2点、C=1点、D=0点を原則とする。ただし能力適性だけで候補を採用せず、能力適性Dでも強い市場根拠がある馬を自動除外しない。',
        '',
        '■ 妙味（40点）：推定確定オッズで買う価値があるか',
        '1．推定確定配当水準：0〜15点',
        '　推定確定複勝最小オッズを中心に評価する。1.5倍未満は本項目を原則2点以下、1.5〜2.5倍は3〜7点、2.5〜4.0倍は8〜11点、4.0〜7.0倍は12〜14点、7.0倍以上は継続的な複勝流入がある場合だけ15点まで可能とする。高オッズだけで高得点にしない。',
        '2．3種類の回収率の裏付け：0〜12点',
        '　過去回収率、OPI帯別回収率、フェーズパターン別回収率をサンプル数とセットで評価する。2種類以上が110%以上なら高評価、2種類以上が100%未満なら減点、2種類以上が90%未満なら0点とし、別途PHP回収率ハード除外も適用する。欠損・0レース・「－」は不明として中立扱いし、0点や不振と解釈しない。',
        '3．OPI・予測補正OPIによる市場評価：0〜8点',
        '　予測補正OPIを中心に、単勝OPI・複勝OPIとの整合性を評価する。過小評価ゾーンは加点、過剰人気ゾーンは減点する。ただしOPIだけで採用・除外を決めない。',
        '4．配当と市場流入・断層構造の整合性：0〜5点',
        '　中穴・人気薄でありながら複勝への継続流入、断層縮小・接近、単複断層の矛盾など、今回の配当に対する客観的な穴進入根拠が重なる場合に加点する。人気薄という理由だけ、または単発急落だけでは加点しない。',
        '',
        '推定確定複勝最小オッズが1.5倍未満の場合は、上記4項目の合計である妙味小計を原則5点以下とする。後掲の低配当例外3条件をすべて満たす場合だけこの妙味上限を解除できるが、解除した事実と根拠を選出理由へ明記する。',
        '',
        '信頼度60点と妙味40点は、後掲の市場妙味基礎点80点・能力適性補正20点・高配当総合点とは別の「既存おすすめ度」です。両者を加算・平均・置換してはいけません。シャドー検証中は高配当強化指標をこの100点採点へ逆流させないでください。',
        '',
        '低配当例外を除き、信頼度が低い馬を妙味だけで70点以上にしないでください。原則として本候補は信頼度36点以上、補欠は信頼度30点以上を必要とします。ただし11番人気以下は既存の大穴選出条件をすべて満たすことを優先し、この信頼度基準だけで採用してはいけません。',
        '',
        '全頭について信頼度小計、妙味小計、7項目＋4項目の内訳をAI内部で確定してから合計してください。既存Flutter形式を変えないため、内訳、小計、新しいJSON項目は出力へ追加しません。最終おすすめ度だけを既存候補行へ出力し、必ずこの固定配点から算出してください。',
        '',
        'おすすめ度（信頼度＋妙味）の降順でソートしてください。',
        '人気順は上記テーブルの「X人気」欄の値をそのまま出力してください。自分で計算しないでください。',
        '',
        '【低配当除外ルール（PHP側でも同基準で強制適用されます）】',
        '対象: 推定確定複勝最小が1.5倍未満 かつ 推定確定オッズ（単勝）が3.0倍未満 の両方を満たす馬。',
        '該当馬は原則として最終選出から除外してください（本候補・補欠・人気薄注目馬のいずれにも残しません）。妙味小計は原則5点以下、おすすめ度は原則69点以下とします。下記の例外3条件をすべて満たす場合だけ解除できます。',
        '・例外①: 主断層の上側グループ（断層最上位グループ）に属している',
        '・例外②: 単勝・複勝の両方で複数時点にわたる継続流入が確認できる',
        '・例外③: サンプル30件以上かつ回収率110%以上の回収率が2種類以上ある',
        '3条件すべてが成立した場合のみ上限を解除し、解除した事実と根拠を選出理由へ明記してください。1つでも欠ければ解除できません。',
        'この判定はPHP側（Block 13a）でも機械的に行われ、例外3条件を満たさない低配当馬は統合候補から必ず除外されます。AIの判断より優先されます。',
        '',
        '【回収率フィルター（PHP側でも同基準で強制適用されます）】',
        '各馬の3種類の回収率（過去回収率・OPI帯別回収率・フェーズパターン別回収率）は、サンプル30件以上のものだけを有効値として扱ってください。',
        '・欠損・0件・「－」は不明として中立扱いとし、0点や不振と解釈してはいけません',
        '・有効値が2種類以上あり、そのうち2種類以上が90%未満の馬は、PHP側（Block 12）でハード除外されます。AI採点でもおすすめ度64点以下としてください',
        '・有効値のうち2種類以上が110%以上の馬は、妙味を高く評価してください',
        '・サンプル30件未満の回収率を単独の根拠にして高得点・低得点にしてはいけません',
        '',
        '【選出ルール】',
        '・選出した馬が全員4番人気以内の場合、選出理由の最後に必ず「※妙味補足：〜（なぜ高人気馬だけになったか1行で）」を追記してください',
        '・7〜10番人気でオッズが継続下落している馬は、信頼度・妙味ともに積極的に加点してください',
        '・複勝オッズが1.3倍以下の馬は、断層の最上位または複勝継続下落でない限りおすすめ度を下げてください',
        '・一時的なオッズ急落（すぐ戻った）は過大評価しないでください',
        '',
        '【⚠️ 推奨頭数は上限であり規定ではない（絶対ルール）】',
        '頭数を埋めることを目的とした選出は禁止です。全頭を100点満点で採点し、以下の最低基準点を超えた馬だけを選出してください。',
        '・本候補（推奨馬）: おすすめ度70点以上',
        '・補欠: おすすめ度60〜69点',
        '・人気薄注目馬（11番人気以下）: おすすめ度55点以上、かつ複勝流入が複数時点で継続していること',
        '基準点を超えない馬は人気帯の上限内であっても選出しないでください。',
        '※点数の基準は暫定値です。データ蓄積後に調整します。',
        '',
        '【混戦・波乱含み（タイプD・E）における人気帯別の選出条件】',
        '',
        '■ 7〜10番人気の選出条件',
        'タイプD・Eのレースで7〜10番人気を選出する場合、以下のうち3項目以上を満たした馬を優先してください。',
        '・複勝オッズが複数時点で継続低下している',
        '・複勝人気順位が単勝人気順位より2順位以上高い',
        '・同人気帯で複勝流入ランクが1〜2位、または複勝流入ランクが単勝流入ランクより明確に良い',
        '・6分前に向けて複勝流入ランクが上昇している（直前に資金が加速している）',
        '・断層より下に位置するが、断層への接近が見られる',
        '・断層が縮小または消滅し、人気帯の境界が曖昧になっている',
        '・類似レース統計の5着以内率が高い',
        '・予測補正OPIが0.85以下（市場の過小評価ゾーン）',
        '・一時的な急落ではなく、複数時点にわたって資金流入が継続している',
        '3項目以上を満たさない7〜10番人気の馬は選出を慎重に判断してください。',
        '',
        '■ 11番人気以下（大穴）の選出条件',
        '11番人気以下は「人気薄注目馬」として別枠で最大1〜2頭まで。以下の条件を全て満たした馬のみ選出可能。',
        '・おすすめ度55点以上であること',
        '・複勝オッズが複数時点で継続低下していること（単発急落は不可）',
        '・複勝流入ランクが7人気以下グループ内で1〜2位であること',
        '・単勝と複勝の両方で資金流入が確認できること',
        '・断層なし・断層縮小・単複断層の矛盾、または5〜6番人気間／6番人気以降の断層など、下位進入を裏付ける構造があること。タイプB・Cでも強い継続流入と客観的根拠が揃えば人気帯上限内で最大1頭まで比較可能',
        '上記を全て満たさない11番人気以下の馬は選出禁止。「来そうな気がする」だけでは選ばないこと。大穴進入度1〜2では原則0頭、3では最大1頭、4〜5では断層位置別上限までとする。',
        '',
        '【⚠️ 選出理由の記述ルール（厳守）】',
        '・選出理由にAIが独自に算出・推測した確率値（「〜%の確率で」「〜%の可能性」等）を記載することは禁止です',
        '・確率として引用してよいのは、各馬のデータ欄に表示されている「類似レース統計」の3着以内率・5着以内率・着外率のみです',
        '・オッズの動きや断層位置から「〜%」という数値をAIが独自計算してはいけません。「資金が継続流入している」「断層上側に位置する」など事実ベースの表現を使ってください',
        '',
        '【下位進入度・大穴進入度の判定（選出前に必ず行うこと）】',
        '馬を選ぶ前に、以下の3指標をそれぞれ1〜5で判定してください。これが人気帯別の選出頭数の根拠になります。',
        '・波乱度（1〜5）: レース全体の予測困難度。断層タイプと修正ルールで決定。',
        '・下位進入度（1〜5）: 7〜10番人気が5着以内へ入る可能性。断層下への複勝流入・断層縮小・複勝流入ランク上昇で判定。',
        '・大穴進入度（1〜5）: 11番人気以下が5着以内へ入る可能性。継続的な複勝流入が確認できない限り1〜2にとどめること。',
        '【重要】波乱度が高くても下位進入度・大穴進入度が低い場合は、1〜6番人気の中での順番入れ替えが主因です。7番人気以下を無理に選ばないでください。',
        '例：波乱度4・下位進入度2・大穴進入度1 → 混戦だが決着は上位人気中心。7番人気以下の選出は慎重に。',
        '例：波乱度4・下位進入度4・大穴進入度2 → 7〜10番人気に根拠のある馬がいれば積極的に選出。',
        '',
        '分析の観点：',
        '・複勝オッズの動きを単勝より重視してください。複勝は「来るかどうか」を市場が評価している数値です',
        '・単勝より複勝で継続的に資金が入っている馬を高く評価してください',
        '・流入ランクの見方: 「単勝流入ランク」「複勝流入ランク」は同じ人気帯グループ（1〜3人気・4〜6人気・7人気以下）の中で、短縮率（計測前→6分前のオッズ下落率）が大きい順に付けた順位です。1位＝そのグループ内で最も資金が流入している馬。複勝流入ランクを単勝流入ランクより重視してください。同じ人気帯の中でグループ1位の馬は、数字上は人気が近くても相対的に最も買われており、流入シグナルとして重要です',
        '・単勝断層と複勝断層が同じ位置に出ている場合は、その断層を強いシグナルとして扱ってください',
        '・単勝オッズ下落10%以上は人気急上昇として注目（ただし単発の急落は過大評価しない）',
        '・単複比が高い馬＝単勝支持に比べて複勝支持が相対的に強い可能性を示す補助材料。ただし、単複比だけで「勝ちにくい」「3着以内に絡みやすい」と断定せず、複勝時系列、流入ランク、断層、回収率、類似レース統計と合わせて評価する',
        '・複勝の最小・最大の幅が広い馬＝市場の評価が割れている不安定な馬',
        '・複勝の最小・最大の幅が狭い馬＝安定して3着以内が期待されている馬',
        '・複勝オッズが下落している馬は3着以内の信頼度が高い',
        '・OPI（Over Popularity Index）の見方: OPI>1.2は過去同人気より低オッズ＝市場が過大評価している可能性（妙味低）、OPI<0.8は過去同人気より高オッズ＝市場が過小評価している可能性（妙味高）。妙味スコアの補正材料として活用してください',
        '・推定確定オッズの見方: 過去の6分前→確定オッズの変動パターンから算出した「発走時点での最終オッズ予測値」です。6分前オッズより推定確定オッズが大きく下がる馬（補正係数<1）は直前にさらに人気が集中する傾向があり、信頼度の補強材料になります。逆に推定確定オッズが上がる馬（補正係数>1）は直前に売られる傾向があります。±の補正誤差が大きい馬は予測の振れ幅が大きいため参考程度に留めてください。妙味スコアを算出する際は、6分前オッズではなく推定確定オッズを基準にしてください',
        '・過去回収率・OPI帯別回収率・フェーズパターン別回収率の使い方: 各馬に表示されている「過去回収率」「OPI帯別回収率」「フェーズパターン別回収率」は、勝率ではなく回収率（%）を妙味スコア判断の最重要指標として使ってください。回収率が100%を下回るパターン（例: 1〜3人気×変化なし = 83%）は、たとえ勝率が高くても長期的には損をするパターンです。妙味スコアを下げる材料として扱ってください。逆に回収率が110%以上のパターンは積極的に妙味を高く評価してください。フェーズパターン別回収率は特に「前半下落・後半上昇（売り戻し）」や「前半上昇・後半下落（直前急落）」のような市場の急変パターンを捉えた重要シグナルです。1〜3番人気ばかりを選出して回収率の低い予想になることを厳に避けてください',
        '',
        "選出馬は必ず下記フォーマットを厳守して出力してください（合計{$pickupTotalMax}頭以内。人気帯別上限を超えないこと）。",
        '1行目: 「厳選穴レース|X」（X=1: 厳選穴レース成立, X=0: 不成立）',
        '2行目: 「レース指標|波乱度: X|下位進入度: X|大穴進入度: X」（各X=1〜5の整数。このまま1行で出力すること）',
        '3行目以降: 「馬番：X、馬名：XXX、人気順: X、6分前オッズ: X.X、おすすめ度: XX、選出理由：能力適性:X（XX点）。〜」を選出頭数分',
        '※画面表示に影響するので、この形を必ず守ってください。',
    ]);

    // ── Block A: 直近走データ取得・プロンプト付加（① ⑩ 両AI対応）──────────────
    // 仕様:「各馬の直近5走以上（取得可能なら10走）」→ 取得上限を10走にする
    // _getAiAnalysisPrompt 内で 1st AI (Claude) にも全頭の過去成績・能力適性データを渡す。
    // $race->dist/$race->course/$race->grade = 今走レース条件
    // $horses（冒頭で全カラム取得済み）から今走騎手を参照
    {
        $todayDist   = isset($race->dist)   ? (int)$race->dist   : null;
        $todayCourse = isset($race->course) ? $race->course       : null;
        $todayGrade  = isset($race->grade)  ? $race->grade        : null;

        $aHistoryText  = "

【各馬の直近成績（過去最大10走）と今走データ】
";
        $aHistoryText .= "能力・適性評価（100点満点・6項目）の根拠として利用してください。
";
        $aHistoryText .= "評価区分：A（80〜100点）B（70〜79点）C（60〜69点）D（59点以下）
";
        if ($todayCourse || $todayDist) {
            $aHistoryText .= '【今走条件】'
                . ($todayCourse ?? '')
                . ($todayDist   ? $todayDist . 'm' : '')
                . ($todayGrade  ? ' ' . $todayGrade : '')
                . "
";
        }
        $aHistoryText .= "
";

        foreach ($horses as $_aNum => $_aHorse) {
            $_aName   = $_aHorse->name;
            $_aJockey = $_aHorse->jockey ?? null; // 今走騎手

            $_aRows = DB::table('t_horse_odds_finder_shutsuba_history')
                ->where('name', $_aName)
                ->orderBy('date', 'desc')
                ->limit(10)   // 仕様: 取得可能なら10走
                ->get(['date', 'basho', 'race_name', 'dist', 'condition', 'grade',
                       'finishing_position', 'num_horses', 'popularity', 'jockey',
                       'burden_weight', 'horse_weight', 'last_3f', 'fin_time_diff',
                       'corner_1', 'corner_2', 'corner_3', 'corner_4']);

            $aHistoryText .= "{$_aNum}番 {$_aName}";
            if ($_aJockey) {
                $aHistoryText .= "（今走騎手: {$_aJockey}）";
            }
            $aHistoryText .= "
";

            if ($_aRows->isEmpty()) {
                $aHistoryText .= "  （出走履歴なし）
";
            } else {
                $_aRowsArr = $_aRows->values()->all(); // 0-indexed array
                foreach ($_aRowsArr as $_ai => $_ar) {
                    // 通過順位
                    $_corners = array_filter([
                        $_ar->corner_1 ?? null,
                        $_ar->corner_2 ?? null,
                        $_ar->corner_3 ?? null,
                        $_ar->corner_4 ?? null,
                    ], fn($v) => $v !== null && $v !== '');
                    $_cornerStr = !empty($_corners) ? implode('-', $_corners) : '-';

                    // 距離変化（今走 vs 最新前走）
                    $_distDiff = '';
                    if ($_ai === 0 && $todayDist && $_ar->dist) {
                        $_dd = $todayDist - (int)$_ar->dist;
                        if ($_dd > 0) $_distDiff = "（今走+{$_dd}m延長）";
                        elseif ($_dd < 0) $_distDiff = "（今走{$_dd}m短縮）";
                    }

                    // 騎手継続・乗り替わり（今走 vs 最新前走）
                    $_jockeyChg = '';
                    if ($_ai === 0 && $_aJockey && ($_ar->jockey ?? null)) {
                        $_jockeyChg = ($_ar->jockey === $_aJockey)
                            ? '（騎手継続）'
                            : "（乗替:前走{$_ar->jockey}）";
                    }

                    // 休養日数（この走と直前走との日数差）
                    $_restStr = '';
                    if ($_ai === 0 && $targetDate && $_ar->date) {
                        $_d1 = new \DateTime($targetDate);
                        $_d2 = new \DateTime($_ar->date);
                        $_restStr = '休' . $_d1->diff($_d2)->days . '日';
                    } elseif ($_ai > 0 && isset($_aRowsArr[$_ai - 1]->date) && $_ar->date) {
                        $_d1 = new \DateTime($_aRowsArr[$_ai - 1]->date);
                        $_d2 = new \DateTime($_ar->date);
                        $_restStr = '休' . $_d1->diff($_d2)->days . '日';
                    }

                    $aHistoryText .= sprintf(
                        "  %s %s %s %s%s %s着/%s頭中 %s人気 通過:%s 上がり%s 着差%s 斤%s 馬体%s %s%s%s%s
",
                        $_ar->date               ?? '-',
                        $_ar->basho              ?? '-',
                        $_ar->race_name          ?? '-',
                        $_ar->dist               ?? '-',
                        $_ar->condition          ?? '',
                        $_ar->finishing_position ?? '-',
                        $_ar->num_horses         ?? '-',
                        $_ar->popularity         ?? '-',
                        $_cornerStr,
                        $_ar->last_3f            ?? '-',
                        $_ar->fin_time_diff      ?? '-',
                        $_ar->burden_weight      ?? '-',
                        $_ar->horse_weight       ?? '-',
                        $_restStr,
                        $_distDiff,
                        $_jockeyChg,
                        ($_ar->grade ?? null) ? '[' . $_ar->grade . ']' : ''
                    );
                }
            }
            $aHistoryText .= "
";
        }
        $lines[] = $aHistoryText;
    }
    // ── Block A End ──────────────────────────────────────────────────────────────

    return implode("\n", $lines);
}




/**
 * DeepSeek による第2の AI 分析
 *
 * getHorseOddsFinderAiAnalysis で保存した .data ファイルを読み込み、
 * DeepSeek API に投げて Claude とは独立した視点の予想を取得する。
 * 【厳選穴レースの判定ルール】はDeepSeekには不要なので除去して送信する。
 *
 * @param  Request $request  date, kaisuu, basho, day, race
 * @return \Illuminate\Http\JsonResponse
 */
public function getHorseOddsFinderSecondAiOpinion(Request $request)
{
    $date   = $request->query('date');
    $kaisuu = $request->query('kaisuu');
    $basho  = $request->query('basho');  // 場コード
    $day    = $request->query('day');
    $race   = $request->query('race');

    // ─── キャッシュ確認（DBにレコードがあれば無条件で返す） ─────────────
    $cached = DB::table('t_horse_odds_finder_ai_analysis2')
        ->where('date',       $date)
        ->where('kaisuu',     $kaisuu)
        ->where('basho_code', $basho)
        ->where('day',        $day)
        ->where('race',       $race)
        ->first();

    // ── キャッシュの健全性チェック（#65・#66 / 09.txt③）──────────────────
    // 修正前に保存された「空回答」「形式不正」のキャッシュがそのまま返り続けると、
    // 統合・フィルター・保存処理を一度も通らないままになる。
    // 返す前に 1st AI の回答判定を通し、失敗と判定されたキャッシュは破棄して再処理する。
    $cacheJudge = $cached ? $this->_judgeFirstAiResponse((string) $cached->analysis_text) : null;
    if ($cacheJudge !== null && $cacheJudge['status'] === 'failed') {
//         \Log::warning('[Cache] 不正なキャッシュを破棄して再処理する', [
//             'date' => $date, 'kaisuu' => $kaisuu, 'basho_code' => $basho,
//             'day'  => $day,  'race'   => $race,   'reason' => $cacheJudge['reason'],
//         ]);
        DB::table('t_horse_odds_finder_ai_analysis2')
            ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho_code', $basho)
            ->where('day', $day)->where('race', $race)
            ->delete();
        $cached = null;
    }
    if ($cached) {
        // 保存済みの統合結果（merged_horses / upset_race / race_metrics）も併せて返す。
        // これが無いと、キャッシュヒット時にアプリが統合表示へ切り替われない。
        return response()->json(['data' => array_merge([
            'date'          => $date,
            'kaisuu'        => $kaisuu,
            'basho_code'    => $basho,
            'day'           => $day,
            'race'          => $race,
            'analysis_text' => trim($cached->analysis_text),
        ], $this->_loadSavedMergeResult($date, $kaisuu, $basho, $day, $race))]);
    }

    // ─── 排他ロック（同一レースへの並行リクエスト防止） ──────────────
    $lockKey = "ai_analysis2_{$date}_{$kaisuu}_{$basho}_{$day}_{$race}";
    $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 120);

    try {
        $lock->block(60);

        // ロック後に再度キャッシュ確認（フォーマット検証あり）
        $cached = DB::table('t_horse_odds_finder_ai_analysis2')
            ->where('date',       $date)
            ->where('kaisuu',     $kaisuu)
            ->where('basho_code', $basho)
            ->where('day',        $day)
            ->where('race',       $race)
            ->first();

        // ── キャッシュの健全性チェック（#65・#66 / 09.txt③）──────────────────
        // 修正前に保存された「空回答」「形式不正」のキャッシュがそのまま返り続けると、
        // 統合・フィルター・保存処理を一度も通らないままになる。
        // 返す前に 1st AI の回答判定を通し、失敗と判定されたキャッシュは破棄して再処理する。
        $cacheJudge2 = $cached ? $this->_judgeFirstAiResponse((string) $cached->analysis_text) : null;
        if ($cacheJudge2 !== null && $cacheJudge2['status'] === 'failed') {
//             \Log::warning('[Cache] 不正なキャッシュを破棄して再処理する', [
//                 'date' => $date, 'kaisuu' => $kaisuu, 'basho_code' => $basho,
//                 'day'  => $day,  'race'   => $race,   'reason' => $cacheJudge2['reason'],
//             ]);
            DB::table('t_horse_odds_finder_ai_analysis2')
                ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho_code', $basho)
                ->where('day', $day)->where('race', $race)
                ->delete();
            $cached = null;
        }
        if ($cached) {
            // ロック後の再確認でも同じく統合結果を載せる（上のキャッシュ分岐と同じ形）
            return response()->json(['data' => array_merge([
                'date'          => $date,
                'kaisuu'        => $kaisuu,
                'basho_code'    => $basho,
                'day'           => $day,
                'race'          => $race,
                'analysis_text' => trim($cached->analysis_text),
            ], $this->_loadSavedMergeResult($date, $kaisuu, $basho, $day, $race))]);
        }

        // ─── レース基本情報の取得（basho_name・race_name の保存用） ──────
        $raceRow = DB::table('t_horse_odds_finder_races')
            ->where('date',   $date)
            ->where('kaisuu', $kaisuu)
            ->where('basho',  $basho)
            ->where('day',    $day)
            ->where('race',   intval($race))
            ->first();

        if (!$raceRow) {
            return response()->json(['error' => 'レースが見つかりません'], 404);
        }

        // ─── プロンプトの取得（.data ファイルがあれば再利用、なければ自力生成） ──
        // getHorseOddsFinderAiAnalysis で保存した .data ファイルを優先して使い回す
        // 例: /var/www/horse_odds_finder/public/prompt/prompt_2026-08-16_2_07_8_9.data
        $filePath = public_path("prompt/prompt_{$date}_{$kaisuu}_{$basho}_{$day}_{$race}.data");

        if (file_exists($filePath)) {
            $oddsData = file_get_contents($filePath);
        } else {
            // .data ファイルがない場合はプロンプトを自力生成する
            $oddsData = $this->_getAiAnalysisPrompt($date, $kaisuu, $basho, $day, $race, '', '');
            if ($oddsData === null) {
                return response()->json(['error' => 'プロンプト生成に失敗しました（レースまたはオッズデータが不足しています）'], 500);
            }
        }

        // ─── DeepSeek 用にプロンプトを整形 ──────────────────────────────
        // 【仕様「1st AI のプロンプトを読み込んで以下を除去した上で送信する」】
        //   ① 「厳選穴レースの判定ルール」ブロック全体
        //   ② 出力フォーマット内の「厳選穴レース|1または0」行
        //   ③ 「レース指標|波乱度: X|下位進入度: X|大穴進入度: X」行
        //   ④ 末尾の1st AI専用出力指示
        // ※「おすすめ度計算方法」「システムの目的」「回収率優先・低配当除外ルール」等の
        //   【2nd AIにも残す絶対ルール】は除去しない（維持必須）。
        // ※ /u（UTF-8）を付けないと全角文字がバイト単位で扱われ、意図しない切断が起きうる。
        //   不正なUTF-8が混じると preg_replace が null を返すため、その場合は元の文字列を保つ。
        $b2ndStrip = function (string $subject, string $pattern): string {
            $replaced = preg_replace($pattern, '', $subject);
            return ($replaced === null) ? $subject : $replaced;   // null なら除去せず元のまま
        };
        // ① 判定ルールブロック
        $oddsData = $b2ndStrip($oddsData, '/【厳選穴レースの判定ルール】.*?(?=\nおすすめ度は)/su');
        // ② 「厳選穴レース|1または0」行
        $oddsData = $b2ndStrip($oddsData, '/^厳選穴レース\|1または0\n?/mu');
        // ③ 「レース指標|波乱度: X|下位進入度: X|大穴進入度: X」行（2nd AIは候補行だけを返す）
        $oddsData = $b2ndStrip($oddsData, '/^2行目: 「レース指標[^\n]*\n?/mu');
        $oddsData = $b2ndStrip($oddsData, '/^レース指標\|波乱度[^\n]*\n?/mu');
        // ④ 末尾の1st AI専用出力指示
        $oddsData = $b2ndStrip($oddsData, '/^選出馬は必ず「厳選穴レース[^\n]*\n?/mu');
        $oddsData = $b2ndStrip($oddsData, '/^1行目: 「厳選穴レース[^\n]*\n?/mu');
        // ─── 頭数から選出数を再計算（1st AIと同じロジック） ─────────────────
        $horseCount2nd = DB::table('t_horse_odds_finder_horses')
            ->where('date',   $date)
            ->where('kaisuu', $raceRow->kaisuu)
            ->where('basho',  $raceRow->basho)
            ->where('day',    $raceRow->day)
            ->where('race',   $raceRow->race)
            ->count();
        $pickupCount = 5; // 2nd AI（DeepSeek）の回答上限は出走頭数に関係なく固定5頭

        // ── Block 9: 出走履歴データ取得・プロンプト付加（⑩ 項目拡充済み）──────────────
        // 対象馬の直近走（最大10走）を shutsuba_history から取得し、能力適性評価の根拠として追記する
        // 仕様:「各馬の直近5走以上（取得可能なら10走）」
        // ⑩ 拡充: grade/jockey/burden_weight/horse_weight/corner_1〜4 を追加
        // 今走条件（$raceRow->dist/$raceRow->course/$raceRow->grade）も先頭に付加
        // ── よっしー20260922-03指摘①: 過去成績の二重送信を防止 ─────────────
        // 1st AI用プロンプト（.dataファイル）には Block A として同一内容の
        // 【各馬の直近成績（過去最大10走）と今走データ】が既に含まれている。
        // そのまま Block 9 を追記すると DeepSeek へ同じ過去成績が2回送られるため、
        // 未収録の場合（将来 Block A が外れた場合の保険）だけ生成・追記する。
        // ※ 能力適性ブロックの本文にも同じ見出し文字列が含まれる
        //   （'プロンプト末尾の【各馬の直近成績…】を根拠に'）ため、
        //   単純な部分一致では Block A が外れても収録済みと誤判定する。
        //   Block A は必ず行頭に出るので前後の改行込みで判定する。
        $b9NeedHistory = (mb_strpos($oddsData, "\n【各馬の直近成績（過去最大10走）と今走データ】\n") === false);
        if (!$b9NeedHistory) {
//             \Log::info('[Block9] 過去成績は1st AIプロンプト（Block A）に収録済みのため追記をスキップ');
        }

        $b9HorseRows = $b9NeedHistory
            ? DB::table('t_horse_odds_finder_horses')
                ->where('date',   $date)
                ->where('kaisuu', $raceRow->kaisuu)
                ->where('basho',  $raceRow->basho)
                ->where('day',    $raceRow->day)
                ->where('race',   $raceRow->race)
                ->orderBy('num')
                ->get(['num', 'name', 'jockey']) // ⑩ 今走騎手を追加
            : collect();

        $b9TodayDist   = isset($raceRow->dist)   ? (int)$raceRow->dist   : null;
        $b9TodayCourse = isset($raceRow->course) ? $raceRow->course       : null;
        $b9TodayGrade  = isset($raceRow->grade)  ? $raceRow->grade        : null;

        $b9HistoryText  = "\n\n【各馬の直近成績（過去最大10走）と今走データ】\n";
        $b9HistoryText .= "能力・適性評価（100点満点・6項目）の根拠として利用してください。\n";
        $b9HistoryText .= "評価区分：A（80〜100点）B（70〜79点）C（60〜69点）D（59点以下）\n";
        if ($b9TodayCourse || $b9TodayDist) {
            $b9HistoryText .= '【今走条件】'
                . ($b9TodayCourse ?? '')
                . ($b9TodayDist   ? $b9TodayDist . 'm' : '')
                . ($b9TodayGrade  ? ' ' . $b9TodayGrade : '')
                . "\n";
        }
        $b9HistoryText .= "\n";

        foreach ($b9HorseRows as $b9Horse) {
            $b9Num    = (int)$b9Horse->num;
            $b9Name   = $b9Horse->name;
            $b9Jockey = $b9Horse->jockey ?? null; // 今走騎手

            $b9Rows = DB::table('t_horse_odds_finder_shutsuba_history')
                ->where('name', $b9Name)
                ->orderBy('date', 'desc')
                ->limit(10)   // 仕様: 取得可能なら10走
                ->get(['date', 'basho', 'race_name', 'dist', 'condition', 'grade',
                        'finishing_position', 'num_horses', 'popularity', 'jockey',
                        'burden_weight', 'horse_weight', 'last_3f', 'fin_time_diff',
                        'corner_1', 'corner_2', 'corner_3', 'corner_4']);

            $b9HistoryText .= "{$b9Num}番 {$b9Name}";
            if ($b9Jockey) {
                $b9HistoryText .= "（今走騎手: {$b9Jockey}）";
            }
            $b9HistoryText .= "\n";

            if ($b9Rows->isEmpty()) {
                $b9HistoryText .= "  （出走履歴なし）\n";
            } else {
                $b9RowsArr = $b9Rows->values()->all();
                foreach ($b9RowsArr as $b9i => $b9r) {
                    // 通過順位
                    $b9Corners = array_filter([
                        $b9r->corner_1 ?? null,
                        $b9r->corner_2 ?? null,
                        $b9r->corner_3 ?? null,
                        $b9r->corner_4 ?? null,
                    ], fn($v) => $v !== null && $v !== '');
                    $b9CornerStr = !empty($b9Corners) ? implode('-', $b9Corners) : '-';

                    // 距離変化（今走 vs 最新前走）
                    $b9DistDiff = '';
                    if ($b9i === 0 && $b9TodayDist && $b9r->dist) {
                        $b9dd = $b9TodayDist - (int)$b9r->dist;
                        if ($b9dd > 0) $b9DistDiff = "（今走+{$b9dd}m延長）";
                        elseif ($b9dd < 0) $b9DistDiff = "（今走{$b9dd}m短縮）";
                    }

                    // 騎手継続・乗り替わり（今走 vs 最新前走）
                    $b9JockeyChg = '';
                    if ($b9i === 0 && $b9Jockey && ($b9r->jockey ?? null)) {
                        $b9JockeyChg = ($b9r->jockey === $b9Jockey)
                            ? '（騎手継続）'
                            : "（乗替:前走{$b9r->jockey}）";
                    }

                    // 休養日数
                    $b9RestStr = '';
                    if ($b9i === 0 && $date && $b9r->date) {
                        $b9d1 = new \DateTime($date);
                        $b9d2 = new \DateTime($b9r->date);
                        $b9RestStr = '休' . $b9d1->diff($b9d2)->days . '日';
                    } elseif ($b9i > 0 && isset($b9RowsArr[$b9i - 1]->date) && $b9r->date) {
                        $b9d1 = new \DateTime($b9RowsArr[$b9i - 1]->date);
                        $b9d2 = new \DateTime($b9r->date);
                        $b9RestStr = '休' . $b9d1->diff($b9d2)->days . '日';
                    }

                    $b9HistoryText .= sprintf(
                        "  %s %s %s %s%s %s着/%s頭中 %s人気 通過:%s 上がり%s 着差%s 斤%s 馬体%s %s%s%s%s\n",
                        $b9r->date               ?? '-',
                        $b9r->basho              ?? '-',
                        $b9r->race_name          ?? '-',
                        $b9r->dist               ?? '-',
                        $b9r->condition          ?? '',
                        $b9r->finishing_position ?? '-',
                        $b9r->num_horses         ?? '-',
                        $b9r->popularity         ?? '-',
                        $b9CornerStr,
                        $b9r->last_3f            ?? '-',
                        $b9r->fin_time_diff      ?? '-',
                        $b9r->burden_weight      ?? '-',
                        $b9r->horse_weight       ?? '-',
                        $b9RestStr,
                        $b9DistDiff,
                        $b9JockeyChg,
                        ($b9r->grade ?? null) ? '[' . $b9r->grade . ']' : ''
                    );
                }
            }
            $b9HistoryText .= "\n";
        }
        if ($b9NeedHistory) {
            $oddsData .= $b9HistoryText;   // よっしー20260922-03指摘①: 未収録時のみ追記
        }
        // ── Block 9 End ────────────────────────────────────────────────────────────

        // ── 仕様書【末尾に追記される内容】の本文そのまま（要約・改変禁止）──────
        // 【重大な不具合と修正】以前はここで「候補が0頭の場合は、『厳選穴レース行』と
        //   『レース指標行』のみを出力し…」と指示していた。これは仕様と正反対で、
        //   仕様は「基準を満たす馬が0頭の場合だけ、例外出力として『候補なし|0』の
        //   1行だけを返してください」と定めている。
        //   この誤った指示のせいで 2nd AI が候補行以外を返し、PHP側の形式検証に
        //   毎レース弾かれて「1st AI単独継続」になり続けていた。
        //   （2026-09-21 の本番ログで全7レースが [B-7] 形式不正になっていた）
        $oddsData .= "\n\n時系列オッズと能力・適性データを独立して全頭評価したうえで注目馬を最大{$pickupCount}頭選出し、「馬番：X、馬名：XXX、人気順: X、6分前オッズ: X.X、おすすめ度: XX、選出理由：能力適性:A（82点）。〜」の形式で、1頭につき改行なしの1行で出力してください。{$pickupCount}頭を超えて選出してはいけません。最低基準未満の馬を追加して{$pickupCount}頭へ埋めてはいけません。\n"
                   . "基準を満たす馬が0頭の場合だけ、例外出力として「候補なし|0」の1行だけを返してください。PHPはこれを正常な0件として扱い、Flutterへこの文字列を渡してはいけません。";

        // ── よっしー20260922-04指摘: DeepSeek送信直前の $oddsData を自己検証 ──────
        // 04.txt が「1レース分で確認すべき」とした3点を毎レース自動でログへ記録する。
        //   ① 過去成績が1回だけか（見出しの出現回数。正常 = 1）
        //   ② 1st AI専用行（厳選穴レース行・レース指標行）が残っていないか（正常 = 0）
        //   ③ 注釈記号 ★・☆ が出力指定行に紛れていないか（正常 = 空配列）
        // ※ $oddsData 全文はログに出さない（1レース数万文字になるため）。
        //   判定結果とカウントだけを残し、NG時のみ該当行の先頭60文字を添える。
        // ※ここは診断専用。呼び出し元の try は LockTimeoutException しか捕捉しないため、
        //   万一の例外で2nd AIの予測が止まらないよう \Throwable で保護する
        //   （Block B-10 / B-14 と同じ作法）。
        try {
            $vfHistoryCount = substr_count($oddsData, "\n【各馬の直近成績（過去最大10走）と今走データ】\n");
            $vfAnaLine      = preg_match_all('/^厳選穴レース\|/mu',     $oddsData);
            $vfIdxLine      = preg_match_all('/^レース指標\|波乱度/mu', $oddsData);

            // 出力指定行（厳選穴レース／レース指標／馬番：／N行目:）だけを記号混入の検査対象にする。
            // 断層テーブル・断層時系列の「★」は正規のプロンプト内容なので対象外。
            // ※判定は「先頭の記号・空白を取り除いた文字列」に対して行う。
            //   '★馬番：…' のように記号が先頭へ付くと mb_strpos(...) === 0 が成立せず、
            //   検知したい当のケースを取りこぼすため（2026-09-22 試験で検出した不具合）。
            $vfMarkLines = [];
            foreach (explode("\n", $oddsData) as $vfNo => $vfLine) {
                $vfTrim   = ltrim($vfLine);
                $vfNorm   = preg_replace('/^[★☆\s]+/u', '', $vfTrim);
                if ($vfNorm === null) { $vfNorm = $vfTrim; }   // preg失敗時は元の文字列で判定
                $vfIsSpec = (mb_strpos($vfNorm, '厳選穴レース') === 0)
                         || (mb_strpos($vfNorm, 'レース指標')   === 0)
                         || (mb_strpos($vfNorm, '馬番：')       === 0)
                         || (bool) preg_match('/^\d行目/u', $vfNorm);
                if (($vfIsSpec && preg_match('/[★☆]/u', $vfLine))
                    || mb_strpos($vfLine, '☆') !== false) {   // ☆はプロンプト文字列に出ないため全行で異常
                    $vfMarkLines[] = ($vfNo + 1) . ': ' . mb_substr($vfTrim, 0, 60);
                }
            }

            $vfOk = ($vfHistoryCount === 1 && $vfAnaLine === 0
                     && $vfIdxLine === 0 && empty($vfMarkLines));
//             \Log::info('[2nd AI検証] DeepSeek送信直前の$oddsData自己チェック', [
//                 'race'                   => "{$date} {$kaisuu}回{$basho} {$day}日目 {$race}R",
//                 'length'                 => mb_strlen($oddsData),
//                 'chk1_過去成績の出現回数'   => $vfHistoryCount,  // 正常 = 1
//                 'chk2_厳選穴レース行の残り' => $vfAnaLine,       // 正常 = 0
//                 'chk2_レース指標行の残り'   => $vfIdxLine,       // 正常 = 0
//                 'chk3_記号混入行'           => $vfMarkLines,     // 正常 = []
//                 'judge'                  => $vfOk ? 'OK' : 'NG（要確認）',
//             ]);
        } catch (\Throwable $vfE) {
            // 検証ログの失敗は予測処理に影響させない
//             \Log::warning('[2nd AI検証] 自己チェックに失敗（処理は継続）', [
//                 'error' => $vfE->getMessage(),
//             ]);
        }

        // ── #17 仕様書準拠: 市場妙味基礎点を AI実行前に PHP で算出（AIへ送信しない）────
        // 断層タイプ・主断層位置を $oddsData から取得（AI不要 / デフォルト: B）
        $gapTypeForMerge = 'B';
        if (preg_match('/タイプ([A-E])[：:　\\s]/u', $oddsData, $gtm)) {
            $gapTypeForMerge = $gtm[1];
        }
        $primaryGapUpperPopForMerge = null;
        if (preg_match('/(\\d+)〜\\d+番人気間/u', $oddsData, $gpm)) {
            $primaryGapUpperPopForMerge = (int) $gpm[1];
        }
        if (in_array($gapTypeForMerge, ['D', 'E'])) {
            $primaryGapUpperPopForMerge = null;
        }
        // ODDSデータ先行取得（Block 6 フラグ計算不要のため空配列で呼出）
        [
            'b6OddsRows'      => $b6OddsRows,
            'oddsHorseBlocks' => $oddsHorseBlocks,
            'b6TanPopMap'     => $b6TanPopMap,
            'b6FukuPopMap'    => $b6FukuPopMap,
        ] = $this->_buildFlagsAndOddsMap([], $oddsData, $date, $kaisuu, $basho, $day, $race);
        // ── Block 10: 市場妙味基礎点算出（AI実行前・AIへは送信しない（シャドー中））──
        $b8InfoMap           = [];
        $b10TotalScoreMap    = [];
        $b10ScoreDMap        = [];
        $b13UnderevalFlagMap = [];
        $b13ScoreAMap        = [];
        $b10ScoreE           = 0;
        [
            'b8InfoMap'           => $b8InfoMap,
            'b10TotalScoreMap'    => $b10TotalScoreMap,
            'b10ScoreDMap'        => $b10ScoreDMap,
            'b13UnderevalFlagMap' => $b13UnderevalFlagMap,
            'b13ScoreAMap'        => $b13ScoreAMap,
            'b10ScoreE'           => $b10ScoreE,
        // 【#17】市場妙味基礎点は「AI実行前にPHPで算出」する。
        //   ここは DeepSeek 呼び出し（Http::timeout(60)->post(...)）より前で実行される。
        //   引数は全てDB・PHP由来の値だけで、AIの回答は一切含まない（循環参照なし）。
        //   算出した基礎点・内訳・過小評価フラグ・偽流入警戒は、
        //   シャドー検証中は両AIへ送信せず、PHP内部とログにのみ保持する。
        //   ★この呼び出しをAI呼び出しより後ろへ移動してはいけない。
        ] = $this->_calcMarketScore(
            $b9HorseRows, $b6OddsRows, $oddsHorseBlocks,
            $b6TanPopMap, $b6FukuPopMap, $gapTypeForMerge, $primaryGapUpperPopForMerge,
            $date, $kaisuu, $basho, $day, $race, $raceRow
        );
        // ── Block 10 End（AI実行前算出完了・算出済み値はAIへ送信しない）───────────

        // ─── 2nd AI 用整形済みプロンプトをファイルに保存 ────────────────────
        file_put_contents(
            public_path("prompt_2nd/prompt_2nd_{$date}_{$kaisuu}_{$basho}_{$day}_{$race}.data"),
            $oddsData
        );

        $systemPrompt = <<<'SYSTEM'
あなたは競馬オッズ分析の専門家（2nd AI）です。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
【絶対厳守】出力フォーマット ― これが最優先ルールです
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
出力は以下の形式のみ。これ以外のテキストは一切出力してはいけません。

  馬番：X、馬名：XXX、人気順: X、6分前オッズ: X.X、おすすめ度: XX、選出理由：〜

複数頭を選ぶ場合は、上記を1頭ごとに1行で並べるだけです。

【絶対に出力してはいけないもの】
・見出し行（##、###、【】で囲んだタイトル行 など）
・マークダウン記法（**太字**、*斜体*、---区切り線 など）
・前置き文・後書き文・総評・まとめ・解説文
・「買い目」「各馬の評価」「まとめ」などのセクション
・箇条書き（・や- で始まる行）
・上記フォーマット行以外のあらゆるテキスト

【正しい出力例】
馬番：3、馬名：サンプルホース、人気順: 2、6分前オッズ: 3.5、おすすめ度: 82、選出理由：能力適性:A（84点）。単勝オッズが10%下落しており買いが継続的に流入。複勝最小1.3倍と安定感も高く、OPI0.85で妙味あり。
馬番：7、馬名：テストウマ、人気順: 5、6分前オッズ: 12.0、おすすめ度: 71、選出理由：能力適性:B（75点）。複勝最小2.1倍で評価が安定。OPI0.72の過小評価ゾーンで前半横ばい・後半下落パターンの回収率が高い。

【絶対にやってはいけない出力例】
## 総評
複勝オッズの動きを軸に市場の空気感を読む。（← このような出力は厳禁）
### 各馬の評価（← このような見出しも厳禁）

このフォーマットを守らないと、Flutterアプリが応答をパースできず、
画面に選出馬が一切表示されません。有料サービスへの深刻な障害になります。
分析内容はすべて「選出理由：」の文章の中に凝縮してください。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
【あなた（2nd AI）へ ― 最も重要なメッセージ】
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

あなたが受け取ったプロンプトには、DB・PHP算出値の変更禁止、未来情報禁止、全頭評価、回収率優先、低配当除外、頭数上限などのルールが書かれています。

これらのルールは 1st AI と 2nd AI の両方に適用される絶対ルールです。
「DB・PHP算出値の再計算・上書き・補正・推測」はあなたにも禁止です。

あなたの仕事は、1st AIの分析を確認することではなく、
「自分の目で同じデータを見て、自分の答えを出す」ことです。

──────────────────────────────────────
お前は自由に遊んでいい。ただし、真剣にやれ。
──────────────────────────────────────

「自由」の意味を正しく理解してください：

・OPI・断層タイプ・流入ランクの「解釈・重み付け」は自由。ただし値自体の再計算は禁止
・おすすめ度は信頼度60点＋妙味40点の固定配点を厳守し、各項目の指定範囲内で独立して採点すること
・1〜3番人気だろうが10番人気だろうが、あなたが「来る」と判断すれば選んでいい
・入力データと逆方向の判断をする場合は、入力内にある反証根拠を明記。根拠のない逆張りは禁止

1st AI が見落としそうな角度・異なる解釈・少数派の視点——
それがあなたの重要な役割です。ただし、1st AIとの不一致を目的にしてはいけません。
同じデータを独立して分析した結果、1st AIと同じ馬を高く評価する場合は、その馬をそのまま選出してください。
意図的に一致馬を避けたり、違う馬を選ぶためだけに根拠の弱い馬を追加したりすることは禁止です。

一致馬は「独立した2つのAIが同じ結論に到達した信頼材料」、2nd AIだけの選出馬は
「1st AIが拾えなかった可能性を補う独自発見材料」として、どちらも重要です。

フォーマットだけ守って、中身は大胆に。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
【能力・適性評価（100点満点・6項目）】
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
プロンプト末尾の【各馬の直近成績（過去最大10走）】を根拠に、各馬を以下の6項目で採点し、
選出理由の先頭に「能力適性:X（XX点）。」の形式で必ず記載してください。

1. 基礎能力・クラス実績：25点
2. 近走内容・着差・相手関係：20点
3. コース・距離・芝ダート・馬場適性：20点
4. 脚質・想定展開・枠順との適合：15点
5. 上がり性能・位置取り・レース内容：10点
6. 斤量・騎手・馬体重・休養間隔などの補正：10点

評価区分：A（80〜100点）B（70〜79点）C（60〜69点）D（59点以下）
出走履歴がない馬はD評価（0点）とする。

・回収率はサンプル数とセットで評価すること。サンプル数30件未満は中立として扱うこと
・欠損・0レースの回収率データは中立（有利でも不利でもない）として扱うこと
・断層位置別上限・最低基準点・低配当除外のルールを厳守すること
・PHP・DBが算出・確定した値（人気順、OPI、推定確定オッズ、断層タイプ等）を無視してはいけない

有料公開するシステムなので、正しい日本語で返してください。
SYSTEM;

        // ─── DeepSeek API 呼び出し（自動再試行なし・1回のみ）────────────
        // 【仕様】外部AIの呼び出しは1レースにつき 1st AI + 2nd AI の合計2回まで。
        //   自動再試行は通信エラー時も形式不正時も禁止（$maxRetries = 1）。
        //   ※以前このコメントには複数回リトライする旨が書かれていたが、
        //     仕様と食い違うためコメントごと是正した。コードは以前から1回のみ。
        $analysisText     = '';
        $b7SecondAiFailed = false; // B-7: 2nd AI失敗フラグ（失敗時は1st AI単独継続）
        $maxRetries       = 1; // 仕様: 自動再試行禁止。ここを2以上にしてはいけない
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $response = Http::timeout(60)->withHeaders([
                'Authorization' => 'Bearer ' . env('DEEPSEEK_API_KEY'),
            ])->post('https://api.deepseek.com/v1/chat/completions', [
                'model'    => 'deepseek-chat',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $oddsData],
                ],
            ]);

            if ($response->failed()) {
                \Log::error('[B-7] DeepSeek API通信エラー', [
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                    'attempt' => $attempt + 1,
                    'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
                ]);
                if ($attempt < $maxRetries - 1) {
                    continue; // ※ maxRetries=1 のため実質到達しない
                }
                // 全リトライ失敗 → 2nd AI失敗確定
                $b7SecondAiFailed = true;
//                 \Log::warning('[B-7] DeepSeek全リトライ失敗、1st AI単独継続', [
//                     'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
//                 ]);
                break;
            }

            $result       = $response->json();
            $analysisText = trim($result['choices'][0]['message']['content'] ?? '');

            // 候補なし|0 = 正常な0件回答（形式不正ではない・1st AI単独継続）
            if (preg_match('/^候補なし\|0$/mu', $analysisText)) {
//                 \Log::info('[B-7] DeepSeek正常0件（候補なし|0）、1st AI単独継続', [
//                     'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
//                 ]);
                $analysisText = ''; // 0件として正常終了（$b7SecondAiFailed は false のまま）
                break;
            }

            // ── 形式検証は「実際に候補行を読み取れるか」で判定する ──────────────
            // 【不具合と修正】以前は専用の正規表現 /馬番[：:]\d+/ で検証していたが、
            //   読み取り側の _parseAiHorses() は /馬番[：:]\s*(\d+).../ と
            //   コロンの後ろの空白を許容しており、条件が食い違っていた。
            //   そのため「馬番: 3、」のように半角スペースを挟んだ回答は、
            //   読み取れるにもかかわらず「形式不正」として捨てられ、
            //   2nd AI が毎レース無効化されて 1st AI 単独運用になっていた。
            //   検証と読み取りで同じメソッドを使えば、二度と食い違わない。
            // ※候補行の前に「厳選穴レース|0」等の余分な行があっても、
            //   候補行さえ読み取れれば正常として扱う（行単位で解析するため）。
            $b7ParsedHorses = $this->_parseAiHorses($analysisText);
            if (empty($b7ParsedHorses)) {
                // 形式不正 → 再試行禁止（B-7仕様）。即座に2nd AI失敗扱い
                $b7SecondAiFailed = true;
//                 \Log::warning('[B-7] DeepSeek形式不正（再試行禁止）、1st AI単独継続', [
//                     'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
//                     // 原因が分かるよう全文に近い長さを残す（200字では1行目しか写らなかった）
//                     'text' => mb_substr($analysisText, 0, 2000),
//                 ]);
                break;
            }
//             \Log::info('[B-7] DeepSeek回答を受理', [
//                 'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
//                 'parsed_cnt' => count($b7ParsedHorses),
//             ]);

            break; // 正常回答
        }

        // ─── 分析結果をDBに保存（常に保存：失敗テキストはログとして残す） ─────
        DB::table('t_horse_odds_finder_ai_analysis2')->updateOrInsert(
            ['date' => $date, 'kaisuu' => $kaisuu, 'basho_code' => $basho, 'day' => $day, 'race' => $race],
            [
                'basho'         => $raceRow->basho_name,
                'race_name'     => $raceRow->race_name,
                'analysis_text' => $analysisText,
            ]
        );
        // B-7: $b7SecondAiFailed の場合は以降の2nd AI処理をスキップし、1st AI単独で統合処理する

        // ─── 1st AI テキスト取得 → 統合処理 ────────────────────────────────
        $firstAiRecord = DB::table('t_horse_odds_finder_ai_analysis')
            ->where('date',       $date)
            ->where('kaisuu',     $kaisuu)
            ->where('basho_code', $basho)
            ->where('day',        $day)
            ->where('race',       $race)
            ->first(['analysis_text']);
        $firstAiText = $firstAiRecord->analysis_text ?? '';

        // 断層タイプ・主断層位置は AI実行前（Block 10前処理）で設定済み
        // $gapTypeForMerge・$primaryGapUpperPopForMerge をそのまま利用する
        // 人気帯別上限テーブル（_getAiAnalysisPrompt() と同一ロジック）
        if ($primaryGapUpperPopForMerge === null) {
            [$mergeUpperMax, $mergeMidMax, $mergeLowerMax] = [3, 3, 2];
        } elseif ($primaryGapUpperPopForMerge === 1) {
            [$mergeUpperMax, $mergeMidMax, $mergeLowerMax] = [3, 2, 1];
        } elseif ($primaryGapUpperPopForMerge <= 4) {
            [$mergeUpperMax, $mergeMidMax, $mergeLowerMax] = [4, 2, 1];
        } elseif ($primaryGapUpperPopForMerge === 5) {
            [$mergeUpperMax, $mergeMidMax, $mergeLowerMax] = [3, 3, 2];
        } else {
            [$mergeUpperMax, $mergeMidMax, $mergeLowerMax] = [2, 3, 2];
        }

        // ── 1st AI の回答判定（#65・#66）──────────────────────────────────────
        // 失敗（空・形式不正・必要な2行が無い）なら通常予測を公開しない。
        // 2nd AI だけの候補を公開してはいけない。
        $b66First = $this->_judgeFirstAiResponse($firstAiText);
        if ($b66First['status'] === 'failed') {
            \Log::error('[B-7/#66] 1st AI 回答失敗 → 通常予測を公開しない（制御済みエラー）', [
                'reason'         => $b66First['reason'],
                'has_upset_line' => $b66First['has_upset_line'],
                'has_index_line' => $b66First['has_index_line'],
                'second_ai_cnt'  => count($this->_parseAiHorses($b7SecondAiFailed ? '' : $analysisText)),
                'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
                'raw_text'       => mb_substr($firstAiText, 0, 2000),
            ]);
            return response()->json(
                ['error' => '1st AIの回答を取得できませんでした（制御済みエラー）'], 500
            );
        }
        if ($b66First['status'] === 'zero') {
//             \Log::info('[B-7/#65] 1st AI 候補0頭（正常）', [
//                 'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
//             ]);
        }

        $firstAiHorses  = $this->_parseAiHorses($firstAiText);
        $secondAiHorses = $this->_parseAiHorses($b7SecondAiFailed ? '' : $analysisText);

        // ── B-15: AI回答上限超過の形式不正判定（PHPによる切り詰め・再試行禁止）──
        // 1st AI（Claude）が8頭以上出力 → 通常予測を公開しない（制御済みエラー）
        if (count($firstAiHorses) >= 8) {
            \Log::error('[B-15] 1st AI（Claude）上限超過 → 無効化・制御済みエラー', [
                'count'    => count($firstAiHorses),
                'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
                'raw_text' => mb_substr($firstAiText, 0, 500),
            ]);
            return response()->json(['error' => '1st AIが選出上限（8頭以上）を超過しました（制御済みエラー）'], 500);
        }
        // 2nd AI（DeepSeek）が6頭以上出力 → 2nd AI無効化、1st AI単独継続
        if (count($secondAiHorses) >= 6) {
            \Log::error('[B-15] 2nd AI（DeepSeek）上限超過 → 無効化・1st AI単独継続', [
                'count'    => count($secondAiHorses),
                'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
                'raw_text' => mb_substr($analysisText, 0, 500),
            ]);
            $secondAiHorses   = [];
            $b7SecondAiFailed = true; // 統合処理で1st AI単独扱いにする
        }

        // ── Block 9 Session 11: 能力適性グレードの抽出・スコア補正 ─────────────────
        $this->_applyAbilityGrade($secondAiHorses);
        // ── Block 9 Session 11 End ─────────────────────────────────────────────────

        // ── Block 6: F1〜F8フラグ計算（_mergeAiResults に渡す） ──────────────────
        [
            'horseFlagsMap'   => $horseFlagsMap,
            'oddsHorseBlocks' => $oddsHorseBlocks,
            'b6OddsRows'      => $b6OddsRows,
            'b6TanPopMap'     => $b6TanPopMap,
            'b6FukuPopMap'    => $b6FukuPopMap,
        ] = $this->_buildFlagsAndOddsMap($secondAiHorses, $oddsData, $date, $kaisuu, $basho, $day, $race);
        // ── Block 6 End ───────────────────────────────────────────────────────────

        // ── Block 10: 市場妙味基礎点（#17 仕様書準拠: AI実行前に算出済み）────────────
        // $b8InfoMap, $b10TotalScoreMap, $b13UnderevalFlagMap, $b13ScoreAMap, $b10ScoreE は
        // AI呼び出し前（Block 10前処理ブロック）で _calcMarketScore() により算出済み。
        // ── F1/F5 更新（Block 10 算出後、merge前）───────────────────────────────
        foreach (array_keys($horseFlagsMap) as $_hfNum) {
            $horseFlagsMap[$_hfNum]['F1'] = (($b13ScoreAMap[$_hfNum] ?? -1) >= 12);
            $horseFlagsMap[$_hfNum]['F5'] = ($b10ScoreE >= 6);
        }
        // ── Block 10 End ──────────────────────────────────────────────────────────

        // ── Block 16: ⑥ AI回答のDB照合強化 ──────────────────────────────────────────
        // 実在馬番・重複・人気順・6分前オッズ・おすすめ度(0〜100) をDBと照合し、
        // 不正エントリを除去・DB値で上書きする。$b6TanPopMap / $b6OddsRows を利用。
        {
            // 6分前オッズマップ (num => odds)
            $_b16OddsMap = [];
            foreach ($b6OddsRows as $_b16or) {
                $_b16OddsMap[(int)$_b16or->num] = (float)$_b16or->odds;
            }
            // 実在馬番セット
            $_b16ValidNums = array_keys($b6TanPopMap); // numは int

            $this->_dbValidateHorses($firstAiHorses,  '1st', $_b16ValidNums, $b6TanPopMap, $_b16OddsMap);
            $this->_dbValidateHorses($secondAiHorses, '2nd', $_b16ValidNums, $b6TanPopMap, $_b16OddsMap);
        }
        // ── Block 16 End ──────────────────────────────────────────────────────────

        // ── Block 13b・13a・12: _mergeAiResults() 後に適用（正規仕様）──────────────
        // 仕様: 統合→統合おすすめ度算出→最低基準点→低配当除外→回収率フィルター→順位確定
        // 統合スコア（一致馬+5点ボーナス）算出後にフィルターをかけるため
        // 実装は下記 "POST-MERGE フィルター" セクションへ移動した。

        // ⑧ 大穴進入度: 4〜5番人気間断層 + 大穴進入度1〜2 → mergeLowerMax=0
        // ⑨ 波乱度は 1st AI のみ参照（merge前に先取り）
        $_earlyBigGap = null;
        if (preg_match(
            '/レース指標\|波乱度[:：\s]*(\d+)\|下位進入度[:：\s]*(\d+)\|大穴進入度[:：\s]*(\d+)/u',
            $firstAiText, $_egm
        )) {
            $_earlyBigGap = (int)$_egm[3];
        }
        if ($primaryGapUpperPopForMerge !== null && $primaryGapUpperPopForMerge <= 4
            && $_earlyBigGap !== null && $_earlyBigGap <= 2) {
            $mergeLowerMax = 0; // ⑧ 大穴進入度1〜2: 4〜5番人気間断層で11番人気以下0頭
        }

        $mergedHorses   = $this->_mergeAiResults(
            $firstAiHorses,
            $secondAiHorses,
            $gapTypeForMerge,
            $horseCount2nd,
            $mergeUpperMax,
            $mergeMidMax,
            $mergeLowerMax,
            $horseFlagsMap      // Block 6: F1〜F8フラグマップ（F1/F5はBlock10後に有効化済み）
        );

        // ══════════════════════════════════════════════════════════════════════════
        // POST-MERGE フィルター: 統合おすすめ度算出後に Block13b → Block13a → Block12 適用
        // 仕様: 統合→統合おすすめ度算出→最低基準点→低配当除外→回収率フィルター→順位確定→人気帯上限
        // ══════════════════════════════════════════════════════════════════════════

        // ── Block 13b: 最低基準点（merge後・統合スコアで判定）────────────────────
        {
            $mergedHorses = array_values(array_filter($mergedHorses, function ($h) {
                $score = (float)($h['score'] ?? 0);
                $pop   = (int)($h['popularity'] ?? 999);
                if ($score >= 70.0) return true;
                if ($score >= 60.0 && $pop >= 1 && $pop <= 10) return true;
                if ($score >= 55.0 && $pop >= 11) return true;
//                 \Log::info('[Block13b] 最低基準点未満除外（merge後）', [
//                     'num'      => $h['num'], 'name' => $h['name'],
//                     'score'    => $score,    'popularity' => $pop,
//                     'category' => $h['category'] ?? '',
//                 ]);
                return false;
            }));
        }
        // ── Block 13b End ─────────────────────────────────────────────────────────

        // ── 受入チェック #69: 妙味小計上限のPHP側検証（AI採点は書き換えない）──────
        // 仕様「推定確定複勝最小 < 1.5 の馬は妙味小計を原則5点以下」を、
        //   おすすめ度 = 信頼度(最大60) + 妙味小計 という固定内訳から検証する。
        //   例外解除なしの低配当馬が66点以上なら、妙味小計が5点を超えた証拠になる。
        // 検出結果はログと features_json（シャドー）へ保存する。
        $b69MeritCap = $this->_verifyLowPayoutMeritCap(
            $mergedHorses, $oddsHorseBlocks, $primaryGapUpperPopForMerge, $b13ScoreAMap
        );

        // ── Block 13a: 低配当除外（merge後・正規仕様）────────────────────────────
        // 除外条件: 推定確定複勝最小 < 1.5倍 かつ 推定確定単勝（推定確定オッズ）< 3.0倍
        //           → 両方を満たした馬を原則除外
        // 例外解除（次の3条件を【すべて】満たす場合のみ → 除外しない / AND判定）:
        //   例外①: 断層最上位グループ（人気順 <= 主断層上限人気）
        //   例外②: 単勝・複勝の両方が複数時点で継続流入
        //   例外③: サンプル30件以上の回収率110%以上が2種類以上
        // ※最新確定版仕様: 「例外解除は既存の低配当例外3条件をすべて満たす場合だけ」
        //   → OR判定は仕様違反。必ずAND（&&）で結合すること。
        // ※判定そのものは _judgeLowPayoutException() に集約してある。
        //   #69 の検証と同じ判定を使うため、両者が食い違うことはない。
        {
            $mergedHorses = array_values(array_filter(
                $mergedHorses,
                function ($h) use ($oddsHorseBlocks, $primaryGapUpperPopForMerge, $b13ScoreAMap) {
                    $b13aBlock = $oddsHorseBlocks[(int)$h['num']] ?? '';
                    $b13aJ = $this->_judgeLowPayoutException(
                        $h, $b13aBlock, $primaryGapUpperPopForMerge, $b13ScoreAMap
                    );

                    // 低配当の対象外（オッズ未取得・複勝1.5以上・単勝3.0以上）は除外しない
                    if (!$b13aJ['low_payout']) return true;

                    // 3条件すべて成立（AND）した場合のみ除外解除。1つでも不成立なら除外する。
                    if ($b13aJ['released']) return true;

//                     \Log::info('[Block13a] 低配当除外（merge後）', [
//                         'num'                => $h['num'],           'name'     => $h['name'],
//                         'fuku_min'           => $b13aJ['fuku_min'],  'tan_odds' => $b13aJ['tan_odds'],
//                         'ex1_最上位グループ' => $b13aJ['ex1'],
//                         'ex2_単複継続流入'   => $b13aJ['ex2'],
//                         'ex3_回収率110x2'    => $b13aJ['ex3'],
//                     ]);
                    return false;
                }
            ));
        }
        // ── Block 13a End ─────────────────────────────────────────────────────────

        // ── Block 12: 回収率ハード除外（merge後・統合馬リストに適用）────────────────
        {
            $b12mExcludeNums = [];
            foreach ($mergedHorses as $b12mh) {
                $b12mNum   = (int)$b12mh['num'];
                $b12mBlock = $oddsHorseBlocks[$b12mNum] ?? '';
                if ($b12mBlock === '') continue;
                $b12mEntries = [];
                if (preg_match(
                    '/過去回収率[（(][^）)]+[）)]: 回収率([\d.]+)%\s+勝率[\d.]+%\s+サンプル(\d+)件/u',
                    $b12mBlock, $b12me1
                )) {
                    $b12mEntries[] = ['rate' => (float)$b12me1[1], 'samples' => (int)$b12me1[2]];
                }
                if (preg_match(
                    '/OPI帯別回収率[（(][^）)]+[）)]: 回収率([\d.]+)%\s+勝率[\d.]+%\s+サンプル(\d+)件/u',
                    $b12mBlock, $b12me2
                )) {
                    $b12mEntries[] = ['rate' => (float)$b12me2[1], 'samples' => (int)$b12me2[2]];
                }
                if (preg_match(
                    '/フェーズパターン別回収率[（(][^）)]+[）)]: 回収率([\d.]+)%\s+勝率[\d.]+%\s+サンプル(\d+)件/u',
                    $b12mBlock, $b12me3
                )) {
                    $b12mEntries[] = ['rate' => (float)$b12me3[1], 'samples' => (int)$b12me3[2]];
                }
                $b12mValid = array_values(array_filter($b12mEntries, fn($r) => $r['samples'] >= 30));
                $b12mLow   = array_values(array_filter($b12mValid,   fn($r) => $r['rate'] < 90.0));
                if (count($b12mValid) >= 2 && count($b12mLow) >= 2) {
                    $b12mExcludeNums[] = $b12mNum;
//                     \Log::info("[Block12] ハード除外（merge後）: 馬番{$b12mNum}", [
//                         'valid_rates' => $b12mValid,
//                     ]);
                }
            }
            if (!empty($b12mExcludeNums)) {
                $mergedHorses = array_values(array_filter(
                    $mergedHorses,
                    fn($mh) => !in_array((int)$mh['num'], $b12mExcludeNums, true)
                ));
            }
        }
        // ── Block 12 End ──────────────────────────────────────────────────────────
        // ══════════════════════════════════════════════════════════════════════════
        // POST-MERGE フィルター End
        // ══════════════════════════════════════════════════════════════════════════


        // ── Block 15: 厳選穴レース 再判定（最終候補確定後にPHPが上書き） ────────
        // 条件B/C/Dはプロンプトテキストに「条件X（PHP算出済み）: 成立/不成立」として
        // 既に埋め込まれているのでパースして再利用（DB再クエリ不要）
        $condBMet = (bool) preg_match('/条件B（PHP算出済み）: 成立/u', $oddsData);
        $condCMet = (bool) preg_match('/条件C（PHP算出済み）: 成立/u', $oddsData);
        $condDMet = (bool) preg_match('/条件D（PHP算出済み）: 成立/u', $oddsData);

        // 条件A: 最終候補（mergedHorses）の中に7〜10番人気が1頭以上いるか
        $condAMet = false;
        foreach ($mergedHorses as $mh) {
            $pop = (int) ($mh['popularity'] ?? 0);
            if ($pop >= 7 && $pop <= 10) {
                $condAMet = true;
                break;
            }
        }

        // 再判定ルール（仕様書ブロック15）
        // 条件B・C・Dのいずれかが成立 → 0 確定（穴レースなし）
        // B・C・D全不成立 かつ 条件A成立（7〜10人気が1頭以上） → 1
        // それ以外 → 0
        if ($condBMet || $condCMet || $condDMet) {
            $upsetRaceFinal = 0;
        } elseif ($condAMet) {
            $upsetRaceFinal = 1;
        } else {
            $upsetRaceFinal = 0;
        }

        // ── レース指標パース（B-14 / B-11 で共通利用） ───────────────────────────────
        // ⑨ 仕様: 波乱度・下位進入度・大穴進入度は 1st AI（Claude）の出力のみ参照
        // フォーマット: 「レース指標|波乱度: X|下位進入度: X|大穴進入度: X」
        $b14WaveLevel  = null;
        $b14LowerEntry = null;
        $b14BigGap     = null;
        if (preg_match(
            '/レース指標\|波乱度[:：\s]*(\d+)\|下位進入度[:：\s]*(\d+)\|大穴進入度[:：\s]*(\d+)/u',
            $firstAiText, $_gm
        )) {
            $b14WaveLevel  = (int)$_gm[1];
            $b14LowerEntry = (int)$_gm[2];
            $b14BigGap     = (int)$_gm[3];
        }

        // ── Block 14: 統合結果を ai_merge_result テーブルに UPSERT ──────────────────
        $this->_saveAiMergeResult(
            $mergedHorses, $upsetRaceFinal, $gapTypeForMerge,
            $b14WaveLevel, $b14LowerEntry, $b14BigGap,
            $date, $kaisuu, $basho, $day, $race, $raceRow,
            $b69MeritCap   // #69: 妙味小計上限の検証結果（DBに証跡として残す）
        );
        // ── Block 14 End ──────────────────────────────────────────────────────────

        // ── Block 10: 市場妙味基礎点（② 修正: merge前に移動済み）──

        // ── Block B-8: 偽流入警戒フラグ判定（シャドー期間：ログ保存・フラグ付与のみ） ────────
        $this->_judgeFalseInflowFlags(
            $mergedHorses, $b8InfoMap, $b13UnderevalFlagMap,
            $horseFlagsMap, $b6TanPopMap, $b6FukuPopMap,
            $date, $kaisuu, $basho, $day, $race
        );
        // ── Block B-8 End ────────────────────────────────────────────────────────

        // ── Block B-10: シャドー期間中の高配当総合点・仮順位・馬券判定ログ保存 ──────────
        $this->_saveHighPayoutShadow(
            $mergedHorses, $b10TotalScoreMap, $b13UnderevalFlagMap,
            $b13ScoreAMap, $b6OddsRows, $oddsHorseBlocks,
            $gapTypeForMerge, $firstAiHorses, $secondAiHorses,
            $horseFlagsMap, $date, $kaisuu, $basho, $day, $race, $raceRow
        );
        // ── Block B-10 End ──────────────────────────────────────────────────────────

        // ── Block 11: 断層パターン学習 特徴量スナップショット保存 ───────────────────
        $this->_saveMlSnapshot(
            $mergedHorses, $gapTypeForMerge, $primaryGapUpperPopForMerge,
            $mergeUpperMax, $mergeMidMax, $mergeLowerMax,
            $horseCount2nd, $upsetRaceFinal,
            $b14WaveLevel, $b14LowerEntry, $b14BigGap,
            $condAMet, $condBMet, $condCMet, $condDMet,
            $b10ScoreE, $firstAiHorses, $secondAiHorses,
            $oddsData, $oddsHorseBlocks, $b6TanPopMap, $b6FukuPopMap, $b6OddsRows,
            $b13ScoreAMap, $b10ScoreDMap,
            $date, $kaisuu, $basho, $day, $race, $raceRow
        );
        // ── Block 11 End ──────────────────────────────────────────────────────────

        // ── Flutter応答の組み立て: シャドー専用の値を応答から除去する ──────────
        // 【仕様】受入チェック#36・#51、および【シャドー表示制御】
        //   「シャドー検証中の強化指標・馬券判定は内部計算とログ保存だけに使用し、
        //     Flutterの候補・順位・選出理由へ影響しない」
        //   「シャドー中は数値をログだけに保存し、…Flutterへ表示せず」
        //   B-8 / B-10 は $mergedHorses（参照渡し）へシャドー値を付与するため、
        //   そのまま返すとシャドー値がFlutterまで出てしまう。ここで必ず落とす。
        //   ★本番有効化が決まるまで、このキー一覧を減らしてはいけない。
        $shadowOnlyKeys = self::SHADOW_ONLY_KEYS;
        $flutterHorses = array_map(
            function (array $fh) use ($shadowOnlyKeys): array {
                foreach ($shadowOnlyKeys as $fk) unset($fh[$fk]);
                return $fh;
            },
            $mergedHorses
        );
//         \Log::debug('[Flutter] シャドー値を応答から除去', [
//             'removed_keys' => $shadowOnlyKeys,
//             'horses'       => count($flutterHorses),
//         ]);

        return response()->json(['data' => [
            'date'          => $date,
            'kaisuu'        => $kaisuu,
            'basho_code'    => $basho,
            'day'           => $day,
            'race'          => $race,
            'analysis_text' => $analysisText,
            'merged_horses' => $flutterHorses,   // ← シャドー値を除いた候補配列
            'upset_race'    => $upsetRaceFinal,
            // レース指標は 1st AI の出力をそのまま使う（⑨仕様）。
            // Flutter が 1st AI テキストを再パースしなくて済むよう応答にも載せる。
            'race_metrics'  => ($b14WaveLevel !== null && $b14LowerEntry !== null && $b14BigGap !== null)
                ? ['波乱度' => $b14WaveLevel, '下位進入度' => $b14LowerEntry, '大穴進入度' => $b14BigGap]
                : null,
        ]]);

    } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
        return response()->json(['error' => 'しばらくしてから再試行してください'], 503);
    } finally {
        $lock->release();
    }
}

    private function _getGapBand(float $changeRate): string
    {
        if ($changeRate <= -30) return '30%以上下落';
        if ($changeRate <= -20) return '20〜30%下落';
        if ($changeRate <= -10) return '10〜20%下落';
        if ($changeRate <= -5)  return '5〜10%下落';
        if ($changeRate <   5)  return '±5%以内';
        if ($changeRate <  10)  return '5〜10%上昇';
        if ($changeRate <  20)  return '10〜20%上昇';
        if ($changeRate <  30)  return '20〜30%上昇';
        return '30%以上上昇';
    }

    private function _getPopularityBand(int $popularity): string
    {
        if ($popularity <= 3) return '1〜3人気';
        if ($popularity <= 6) return '4〜6人気';
        if ($popularity <= 9) return '7〜9人気';
        return '10人気以上';
    }

    private function _getOpiBand(float $opi): string
    {
        if ($opi <  0.70) return '0.70未満';
        if ($opi <  0.85) return '0.70〜0.85';
        if ($opi <  1.00) return '0.85〜1.00';
        if ($opi <  1.15) return '1.00〜1.15';
        if ($opi <  1.30) return '1.15〜1.30';
        if ($opi <  1.50) return '1.30〜1.50';
        return '1.50以上';
    }

    private function _getPhaseDirection(float $rate): string
    {
        if ($rate <= -5.0) return '下落';
        if ($rate <   5.0) return '横ばい';
        return '上昇';
    }

    private function _parsePayoutString(?string $payoutStr): array
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


    /**
     * 人気帯別回収率の集計（K-3補足）
     *
     * AIが選んだ馬を t_horse_odds_finder_race_result_history の popularity_rank で帯に分類し、
     * 各帯の単勝・複勝回収率を返す。
     *
     * 人気帯:
     *   1_3   : 1〜3番人気
     *   4_6   : 4〜6番人気
     *   7plus : 7番人気以下
     *
     * @return array{1_3: array, 4_6: array, 7plus: array}
     */
    private function _calcAiRecoveryByPopularity(): array
    {
        // AI 選択馬データを全件取得（pickup_horse1〜3）
        // ※ t_horse_odds_finder_ai_analysis_check は現在未使用のためコメントアウト中
        // ※ ai-analysis-check を再有効化する際にコメントを外すこと
        // $checks = DB::table('t_horse_odds_finder_ai_analysis_check')
        //     ->orderBy('date')
        //     ->get(['date', 'kaisuu', 'basho_code', 'day', 'race',
        //            'pickup_horse1', 'pickup_horse2', 'pickup_horse3']);
        $checks = collect([]);

        // 人気帯ごとの集計バケツ
        $buckets = [
            '1_3'   => ['invest' => 0, 'tan_return' => 0, 'fuku_return' => 0, 'count' => 0],
            '4_6'   => ['invest' => 0, 'tan_return' => 0, 'fuku_return' => 0, 'count' => 0],
            '7plus' => ['invest' => 0, 'tan_return' => 0, 'fuku_return' => 0, 'count' => 0],
        ];

        foreach ($checks as $v) {
            // AIが選んだ馬名リスト（null・空文字除外）
            $pickNames = array_values(array_filter([
                $v->pickup_horse1 ?? null,
                $v->pickup_horse2 ?? null,
                $v->pickup_horse3 ?? null,
            ], fn($n) => $n !== null && $n !== ''));

            if (empty($pickNames)) continue;

            // 払戻データを取得（なければスキップ）
            $payout = DB::table('t_horse_odds_finder_race_result_payout')
                ->where('date',       $v->date)
                ->where('kaisuu',     $v->kaisuu)
                ->where('basho_code', $v->basho_code)
                ->where('day',        $v->day)
                ->where('race',       $v->race)
                ->first(['tan', 'fuku']);

            if (!$payout) continue;

            // 払戻マップ（馬番 => 払戻額）
            $tanMap  = $this->_parsePayoutString($payout->tan);
            $fukuMap = $this->_parsePayoutString($payout->fuku);

            // 各選択馬の馬番・人気を race_result_history から取得
            $histRows = DB::table('t_horse_odds_finder_race_result_history')
                ->where('date',       $v->date)
                ->where('kaisuu',     $v->kaisuu)
                ->where('basho_code', $v->basho_code)
                ->where('day',        $v->day)
                ->where('race',       $v->race)
                ->whereIn('name', $pickNames)
                ->get(['name', 'num', 'popularity_rank']);

            foreach ($histRows as $h) {
                $pop = (int) $h->popularity_rank;
                if ($pop <= 0) continue; // 人気不明はスキップ

                // 人気帯を決定
                if ($pop <= 3) {
                    $band = '1_3';
                } elseif ($pop <= 6) {
                    $band = '4_6';
                } else {
                    $band = '7plus';
                }

                $num = ltrim((string) $h->num, '0') ?: '0';

                $buckets[$band]['invest']      += 100;
                $buckets[$band]['tan_return']  += $tanMap[$num]  ?? 0;
                $buckets[$band]['fuku_return'] += $fukuMap[$num] ?? 0;
                $buckets[$band]['count']++;
            }
        }

        // 回収率の計算
        $result = [];
        foreach ($buckets as $band => $b) {
            $result[$band] = [
                'horse_count'  => $b['count'],
                'total_invest' => $b['invest'],
                'tan_return'   => $b['tan_return'],
                'fuku_return'  => $b['fuku_return'],
                'tan_rate'     => $b['invest'] > 0
                    ? round($b['tan_return']  / $b['invest'] * 100, 1)
                    : null,
                'fuku_rate'    => $b['invest'] > 0
                    ? round($b['fuku_return'] / $b['invest'] * 100, 1)
                    : null,
            ];
        }

        return $result;
    }
    // ─────────────────────────────────────────────────────────────────
    // _parseAiHorses()
    // AI 生テキストから選出馬の構造化配列を抽出する
    // 期待フォーマット:
    //   馬番：X、馬名：XXX、人気順: X、6分前オッズ: X.X、おすすめ度: XX、選出理由：〜
    // ─────────────────────────────────────────────────────────────────
    private function _parseAiHorses(string $aiText): array
    {
        $horses = [];
        $lines  = explode("\n", $aiText);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (!preg_match(
                '/馬番[：:]\s*(\d+)[、,].*?馬名[：:]\s*([^、,\n]+)[、,].*?人気順[：: ]\s*(\d+)[、,].*?6分前オッズ[：: ]\s*([\d.]+)[、,].*?おすすめ度[：: ]\s*(\d+)[、,].*?選出理由[：:]\s*(.+)/us',
                $line,
                $m
            )) {
                continue;
            }

            $horses[] = [
                'num'        => (int)   $m[1],
                'name'       => trim(   $m[2]),
                'popularity' => (int)   $m[3],
                'odds_6'     => (float) $m[4],
                'score'      => (int)   $m[5],
                'reason'     => trim(   $m[6]),
            ];
        }

        return $horses;
    }

    // ─────────────────────────────────────────────────────────────────
    // _dbValidateHorses() ── ⑥ AI回答のDB照合強化
    //
    // $aiHorses を参照渡しで受け取り、以下の照合を適用する:
    //   1. 実在馬番チェック → 存在しない馬番のエントリを除去
    //   2. 同一回答内の馬番重複チェック → 後者を除去
    //   3. おすすめ度 0〜100 範囲チェック → 0〜100 にクランプ
    //   4. 人気順のDB照合 → $b6TanPopMap のDB値で上書き
    //   5. 6分前オッズのDB照合 → DBの値で上書き（乖離をLOGに記録）
    //
    // @param array  &$aiHorses   _parseAiHorses() の出力（参照渡し）
    // @param string  $aiLabel    ログ識別子 ('1st' or '2nd')
    // @param array   $validNums  有効な馬番（int）の配列
    // @param array   $popMap     num => 人気順（int）マップ
    // @param array   $oddsMap    num => 6分前単勝オッズ（float）マップ
    // ─────────────────────────────────────────────────────────────────
    private function _dbValidateHorses(
        array  &$aiHorses,
        string  $aiLabel,
        array   $validNums,
        array   $popMap,
        array   $oddsMap
    ): void {
        $seen    = [];
        $removed = [];
        $fixed   = [];

        foreach ($aiHorses as $i => &$h) {
            $num = (int)($h['num'] ?? -1);

            // ① 実在馬番チェック
            if (!in_array($num, $validNums, true)) {
                $removed[] = "馬番{$num}（実在せず）";
                unset($aiHorses[$i]);
                continue;
            }

            // ② 重複チェック
            if (isset($seen[$num])) {
                $removed[] = "馬番{$num}（重複）";
                unset($aiHorses[$i]);
                continue;
            }
            $seen[$num] = true;

            // ③ おすすめ度 0〜100 範囲チェック
            $score = (int)($h['score'] ?? 0);
            if ($score < 0 || $score > 100) {
                $clamped = max(0, min(100, $score));
                $fixed[] = "馬番{$num} おすすめ度 {$score}→{$clamped}";
                $h['score'] = $clamped;
            }

            // ④ 人気順のDB照合（DB値で上書き）
            if (isset($popMap[$num])) {
                $dbPop  = (int)$popMap[$num];
                $aiPop  = (int)($h['popularity'] ?? 0);
                if ($aiPop !== $dbPop) {
                    $fixed[] = "馬番{$num} 人気順 {$aiPop}→{$dbPop}(DB)";
                    $h['popularity'] = $dbPop;
                }
            }

            // ⑤ 6分前オッズのDB照合（DB値で上書き）
            if (isset($oddsMap[$num])) {
                $dbOdds = $oddsMap[$num];
                $aiOdds = (float)($h['odds_6'] ?? 0.0);
                if (abs($aiOdds - $dbOdds) > 0.15) {
                    $fixed[] = sprintf("馬番{$num} 6分前オッズ %.1f→%.1f(DB)", $aiOdds, $dbOdds);
                }
                $h['odds_6'] = $dbOdds; // 常にDB値で上書き
            }
        }
        unset($h);

        // 配列を再インデックス
        $aiHorses = array_values($aiHorses);

        if (!empty($removed)) {
//             \Log::warning("[B16-{$aiLabel}] 除去: " . implode(', ', $removed));
        }
        if (!empty($fixed)) {
//             \Log::info("[B16-{$aiLabel}] 補正: " . implode(', ', $fixed));
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // _mergeAiResults()
    // 1st AI / 2nd AI の選出結果を統合する
    //
    // 処理順:
    //   1. 出走頭数別の最終表示上限を決定
    //   2. 断層タイプ別の 2nd AI 独自発見枠上限を決定
    //   3. 馬番をキーに first/second のインデックスを構築
    //   4. matched / firstOnly / secondOnly に分類し統合おすすめ度を算出
    //   5. 2nd AI 独自発見馬の採用条件判定（70点以上 + 根拠キーワード2件以上）
    //   6. メイン候補（matched + firstOnly）をスコア降順にソート
    //   7. 適格な 2nd 独自馬を独自発見枠として追加 or 入れ替え
    //   8. displayLimit 以内にスライスして返す
    //
    // @param array  $firstAiHorses   1st AI の選出馬配列
    // @param array  $secondAiHorses  2nd AI の選出馬配列
    // @param string $gapType         断層タイプ（'A'〜'E'）
    // @param int    $totalHorses     出走頭数
    // @return array                  統合後の最終候補馬リスト（統合おすすめ度降順）
    // ─────────────────────────────────────────────────────────────────
    private function _mergeAiResults(
        array  $firstAiHorses,
        array  $secondAiHorses,
        string $gapType,
        int    $totalHorses,
        int    $pickupUpperMax = 4,   // Block 8: 1〜6番人気上限
        int    $pickupMidMax   = 3,   // Block 8: 7〜10番人気上限
        int    $pickupLowerMax = 2,   // Block 8: 11番人気以下上限
        array  $horseFlagsMap  = []   // Block 6: F1〜F8フラグマップ（num => [F1..F8]）
    ): array {

        // ── Step1: 出走頭数別の最終表示上限 ──────────────────────────
        if ($totalHorses <= 8) {
            $displayLimit = 4;
        } elseif ($totalHorses <= 13) {
            $displayLimit = 5;
        } elseif ($totalHorses <= 15) {
            $displayLimit = 6;
        } else {
            $displayLimit = 7;
        }

        // ── Step2: 断層タイプ別の 2nd AI 独自発見枠上限 ───────────────
        // タイプ A: 原則0頭。おすすめ度80点以上 かつ F1〜F8フラグ true数3個以上の場合のみ
        //           最大1頭まで許可（F1〜F8判定は Block 6 実装後に $secondUniqueLimit を昇格）
        $secondUniqueLimit = match ($gapType) {
            'A'     => 0,   // 原則0頭（Block 6完了後にF1〜F8判定で昇格）
            'B', 'C'=> 1,
            'D', 'E'=> 2,
            default => 1,
        };
        $strictScoreForA = ($gapType === 'A');  // タイプAは80点以上必須（F1〜F8昇格時にも適用）

        // ── Step3: 馬番をキーにしたインデックスを構築 ────────────────
        $firstMap  = [];
        foreach ($firstAiHorses as $h) {
            $firstMap[$h['num']] = $h;
        }
        $secondMap = [];
        foreach ($secondAiHorses as $h) {
            $secondMap[$h['num']] = $h;
        }

        // ── Step4: 候補区分に分類し、統合おすすめ度を算出 ────────────
        $allNums    = array_unique(array_merge(array_keys($firstMap), array_keys($secondMap)));
        $matched    = [];   // 両 AI 一致馬
        $firstOnly  = [];   // 1st AI 独自馬
        $secondOnly = [];   // 2nd AI 独自発見馬

        foreach ($allNums as $num) {
            $inFirst  = isset($firstMap[$num]);
            $inSecond = isset($secondMap[$num]);

            if ($inFirst && $inSecond) {
                // 統合おすすめ度 = 平均 + 5（上限100）
                $avg   = ($firstMap[$num]['score'] + $secondMap[$num]['score']) / 2;
                $score = round(min(100.0, $avg + 5), 1); // ④ 小数第1位保持（Flutter表示時のみint化）

                $matched[] = [
                    'num'        => $num,
                    'name'       => $firstMap[$num]['name'],
                    'score'      => $score,
                    'score_1st'  => $firstMap[$num]['score'],
                    'score_2nd'  => $secondMap[$num]['score'],
                    'popularity' => $firstMap[$num]['popularity'] ?? null,
                    'odds_6'     => $firstMap[$num]['odds_6']    ?? null,
                    'reason'     => $firstMap[$num]['reason'],
                    'reason_2nd' => $secondMap[$num]['reason'],
                    'category'   => 'matched',
                ];

            } elseif ($inFirst) {
                $firstOnly[] = [
                    'num'        => $num,
                    'name'       => $firstMap[$num]['name'],
                    'score'      => $firstMap[$num]['score'],
                    'score_1st'  => $firstMap[$num]['score'],
                    'score_2nd'  => null,
                    'popularity' => $firstMap[$num]['popularity'] ?? null,
                    'odds_6'     => $firstMap[$num]['odds_6']    ?? null,
                    'reason'     => $firstMap[$num]['reason'],
                    'reason_2nd' => null,
                    'category'   => 'first_only',
                ];

            } else {
                $secondOnly[] = [
                    'num'        => $num,
                    'name'       => $secondMap[$num]['name'],
                    'score'      => $secondMap[$num]['score'],
                    'score_1st'  => null,
                    'score_2nd'  => $secondMap[$num]['score'],
                    'popularity' => $secondMap[$num]['popularity'] ?? null,
                    'odds_6'     => $secondMap[$num]['odds_6']    ?? null,
                    'reason'     => $secondMap[$num]['reason'],
                    'reason_2nd' => null,
                    'category'   => 'second_only',
                ];
            }
        }

        // ── Step5: 2nd AI 独自発見馬の採用条件判定（Block 6: F1〜F8 DB値判定） ──
        // 条件: スコア ≥ 70（タイプA は ≥ 80）+ F2〜F8フラグ（有効フラグ）のうち 2 個以上 true
        // F1・F5 は Block 9 実装まで暫定スキップ（false 固定）
        $b6ActiveFlags = ['F2', 'F3', 'F4', 'F6', 'F7', 'F8'];

        $qualifiedSecondOnly = [];
        foreach ($secondOnly as $h) {
            $minScore = $strictScoreForA ? 80 : 70;
            if ($h['score'] < $minScore) continue;

            $flags     = $horseFlagsMap[$h['num']] ?? [];
            $trueCount = 0;
            foreach ($b6ActiveFlags as $f) {
                if (!empty($flags[$f])) $trueCount++;
            }
            if ($trueCount >= 2) {
                $h['evidence_count'] = $trueCount;
                $qualifiedSecondOnly[] = $h;
            }
        }

        // ── タイプA 独自発見枠昇格（Block 6: F1〜F8フラグ 3 個以上 かつ score ≥ 80） ──
        // gapType=A 時に $secondUniqueLimit を 0 → 1 に昇格し、1 頭だけ採用を許可する
        if ($gapType === 'A' && $secondUniqueLimit === 0) {
            foreach ($qualifiedSecondOnly as $qh) {
                $qFlags = $horseFlagsMap[$qh['num']] ?? [];
                $qCount = 0;
                foreach ($b6ActiveFlags as $f) {
                    if (!empty($qFlags[$f])) $qCount++;
                }
                if ($qCount >= 3 && $qh['score'] >= 80) {
                    $secondUniqueLimit = 1;
//                     \Log::info('[Block6] タイプA 独自発見枠昇格', [
//                         'num'       => $qh['num'],
//                         'name'      => $qh['name'],
//                         'score'     => $qh['score'],
//                         'trueCount' => $qCount,
//                     ]);
                    break;
                }
            }
        }

        // ── Step6: メイン候補をスコア降順でソート ─────────────────────
        $mainCandidates = array_merge($matched, $firstOnly);
        // ── Block 13: 同点タイブレーク（スコア降順 → matched馬優先 → 馬番小順） ─
        $tiebreakMain = function ($a, $b) {
            if ($b['score'] !== $a['score']) return $b['score'] <=> $a['score'];
            $catPriority = ['matched' => 0, 'first_only' => 1, 'second_only' => 2];
            $aCat = $catPriority[$a['category'] ?? 'second_only'] ?? 2;
            $bCat = $catPriority[$b['category'] ?? 'second_only'] ?? 2;
            if ($aCat !== $bCat) return $aCat <=> $bCat;
            return $a['num'] <=> $b['num'];
        };
        usort($mainCandidates, $tiebreakMain);

        // 2nd 独自: スコア降順 → 馬番小順
        usort($qualifiedSecondOnly, fn($a, $b) =>
            $b['score'] !== $a['score'] ? $b['score'] <=> $a['score'] : $a['num'] <=> $b['num']
        );

        // ── Step7: 独自発見枠の追加 / 入れ替え ───────────────────────
        $secondUniqueAdded = 0;
        foreach ($qualifiedSecondOnly as $candidate) {
            if ($secondUniqueAdded >= $secondUniqueLimit) break;

            // 現在の候補数が上限未満なら無条件追加
            if (count($mainCandidates) < $displayLimit) {
                $mainCandidates[] = $candidate;
                $secondUniqueAdded++;
                continue;
            }

            // 上限に達している場合、最低スコア候補と比較
            usort($mainCandidates, fn($a, $b) => $b['score'] <=> $a['score']);
            $weakest      = end($mainCandidates);
            $weakestScore = $weakest['score'];

            // スコアが上回る場合は入れ替え
            if ($candidate['score'] > $weakestScore) {
                array_pop($mainCandidates);
                $mainCandidates[] = $candidate;
                $secondUniqueAdded++;
                continue;
            }

            // ③ 5点差以内の接戦 → F1〜F8フラグ数値比較（AI文章キーワード判定廃止）
            if (($weakestScore - $candidate['score']) <= 5) {
                $cNum   = $candidate['num'];
                $wNum   = $weakest['num'];
                $cFlags = $horseFlagsMap[$cNum] ?? [];
                $wFlags = $horseFlagsMap[$wNum] ?? [];
                $superior = 0;

                // F8: 回収率≥110%（旧「回収率」テキスト判定の代替）
                if (($cFlags['F8'] ?? false) && !($wFlags['F8'] ?? false)) {
                    $superior++;
                }
                // F1: score_a≥12 複勝継続低下（旧「複勝継続流入」テキスト判定の代替）
                if (($cFlags['F1'] ?? false) && !($wFlags['F1'] ?? false)) {
                    $superior++;
                }
                // F5: score_e≥6 断層タイプ（旧「断層上側」テキスト判定の代替）
                if (($cFlags['F5'] ?? false) && !($wFlags['F5'] ?? false)) {
                    $superior++;
                }
                // Fフラグ総数優位（旧「OPI妙味」テキスト判定の代替）
                $cFlagCount = count(array_filter($cFlags));
                $wFlagCount = count(array_filter($wFlags));
                if ($cFlagCount > $wFlagCount) {
                    $superior++;
                }

                if ($superior >= 2) {
                    array_pop($mainCandidates);
                    $mainCandidates[] = $candidate;
                    $secondUniqueAdded++;
                }
            }
        }

        // ── Step8: 最終ソート（タイブレーク適用） ───────────────────────
        usort($mainCandidates, $tiebreakMain);

        // ── Step9: Block 8 人気帯別選出上限チェック ──────────────────────
        // 1〜6番人気 / 7〜10番人気 / 11番人気以下 の3区分で上限を適用し
        // displayLimit 以内に収める（上限を先に適用し余裕があれば displayLimit まで）
        $upperCount = 0;
        $midCount   = 0;
        $lowerCount = 0;
        $finalCandidates = [];
        foreach ($mainCandidates as $h) {
            if (count($finalCandidates) >= $displayLimit) break;
            $pop = (int) ($h['popularity'] ?? 0);
            if ($pop >= 1 && $pop <= 6) {
                if ($upperCount >= $pickupUpperMax) continue;
                $upperCount++;
            } elseif ($pop >= 7 && $pop <= 10) {
                if ($midCount >= $pickupMidMax) continue;
                $midCount++;
            } else {
                // 11番人気以下
                if ($lowerCount >= $pickupLowerMax) continue;
                $lowerCount++;
            }
            $finalCandidates[] = $h;
        }
        return $finalCandidates;
    }



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
     * Block 9 Session 11: 能力適性グレード抽出・スコア補正
     * DeepSeek 回答の「選出理由」先頭の「能力適性:X（XX点）。」を解析し
     * おすすめ度スコアに補正（A=+20/B=+14/C=+7/D=+0）を加算する（上限100）
     */
    private function _applyAbilityGrade(array &$secondAiHorses): void
    {
        // ── Block 9 Session 11: 能力適性グレードの抽出・スコア補正 ─────────────────
        // DeepSeek 回答の「選出理由」先頭「能力適性:X（XX点）。」を解析し、
        // おすすめ度スコアに能力適性補正（A=+20/B=+14/C=+7/D=+0）を加算する（上限100）
        $b9AbilityCorr = ['A' => 20, 'B' => 14, 'C' => 7, 'D' => 0];
        foreach ($secondAiHorses as &$b9h) {
            $b9Grade = null;
            $b9Pts   = null;
            $b9Corr  = 0;
            if (preg_match('/^能力適性:([ABCD])[（(](\d+)点[）)]/u', $b9h['reason'], $b9m)) {
                $b9Grade = $b9m[1];
                $b9Pts   = (int)$b9m[2];
                $b9Corr  = $b9AbilityCorr[$b9Grade] ?? 0;
            }
            $b9h['ability_grade'] = $b9Grade;
            $b9h['ability_score'] = $b9Corr;
            // ※ 仕様: ability_scoreは高配当総合点・シャドー比較専用。既存おすすめ度(score)へは加算しない
//             \Log::debug('[Block9] ability', [
//                 'num'         => $b9h['num'],
//                 'name'        => $b9h['name'],
//                 'grade'       => $b9Grade,
//                 'pts'         => $b9Pts,
//                 'corr'        => $b9Corr,
//                 'score_orig'  => $b9h['score'], // scoreは変更しない
//             ]);
        }
        unset($b9h);
        // ── Block 9 Session 11 End ─────────────────────────────────────────────────
    }

    /**
     * 【未使用】Block 12: 回収率ハード除外（merge前の旧実装）
     * ※フィルターは merge 後に移動したため、どこからも呼ばれない。
     *   仕様順序の証跡として残置している。実処理は POST-MERGE セクション。
     * Block 12: 回収率ハード除外
     * プロンプトに使用した3種類の回収率（過去回収率・OPI帯別・フェーズパターン別）を使用し、
     * 有効値（サンプル30件以上）2種類以上 かつ 90%未満が2種類以上の馬を除外する
     * ※仕様: 馬名単位DBの独自再計算ではなく、プロンプト埋込み済みの3回収率を使う
     */
    private function _applyRecoveryHardFilter(
        array &$firstAiHorses,
        array &$secondAiHorses,
        array  $oddsHorseBlocks  // num => プロンプトブロックテキスト
    ): void {
        // ── Block 12: 回収率ハード除外（_mergeAiResults 前に適用） ─────────────
        // 仕様: プロンプト生成時の3回収率（過去回収率・OPI帯別・フェーズパターン別）を馬番単位で
        // 参照し、有効値2種類以上 かつ 90%未満が2種類以上の場合にハード除外する
        $hardExcludeNums = [];

        $allCandidateNums = array_values(array_unique(array_merge(
            array_map(fn($h) => (int)$h['num'], $firstAiHorses),
            array_map(fn($h) => (int)$h['num'], $secondAiHorses)
        )));

        foreach ($allCandidateNums as $b12Num) {
            $b12Block = $oddsHorseBlocks[$b12Num] ?? '';
            if ($b12Block === '') continue;

            $b12RateEntries = [];
            // ① 過去回収率
            if (preg_match('/過去回収率[（(][^）)]+[）)]: 回収率([\d.]+)%\s+勝率[\d.]+%\s+サンプル(\d+)件/u', $b12Block, $b12m1)) {
                $b12RateEntries[] = ['rate' => (float)$b12m1[1], 'samples' => (int)$b12m1[2]];
            }
            // ② OPI帯別回収率
            if (preg_match('/OPI帯別回収率[（(][^）)]+[）)]: 回収率([\d.]+)%\s+勝率[\d.]+%\s+サンプル(\d+)件/u', $b12Block, $b12m2)) {
                $b12RateEntries[] = ['rate' => (float)$b12m2[1], 'samples' => (int)$b12m2[2]];
            }
            // ③ フェーズパターン別回収率
            if (preg_match('/フェーズパターン別回収率[（(][^）)]+[）)]: 回収率([\d.]+)%\s+勝率[\d.]+%\s+サンプル(\d+)件/u', $b12Block, $b12m3)) {
                $b12RateEntries[] = ['rate' => (float)$b12m3[1], 'samples' => (int)$b12m3[2]];
            }

            // サンプル30件以上のみ有効値
            $b12ValidRates = array_values(array_filter($b12RateEntries, fn($r) => $r['samples'] >= 30));
            $b12LowRates   = array_values(array_filter($b12ValidRates,  fn($r) => $r['rate']    < 90.0));

            if (count($b12ValidRates) >= 2 && count($b12LowRates) >= 2) {
                $hardExcludeNums[] = $b12Num;
//                 \Log::info("[Block12] ハード除外: 馬番{$b12Num}", [
//                     'valid_rates' => $b12ValidRates,
//                 ]);
            }
        }

        // 候補馬リストから除外（_mergeAiResults 呼び出し前）
        if (!empty($hardExcludeNums)) {
            $firstAiHorses  = array_values(array_filter($firstAiHorses,
                fn($h) => !in_array((int)$h['num'], $hardExcludeNums, true)));
            $secondAiHorses = array_values(array_filter($secondAiHorses,
                fn($h) => !in_array((int)$h['num'], $hardExcludeNums, true)));
        }
        // ── Block 12 End ──────────────────────────────────────────────────────
    }

    /**
     * Block 6: F1〜F8フラグ計算
     * $secondAiHorses の各馬について F2〜F8 を判定し horseFlagsMap に格納する
     * F1・F5 は Block 9 依存のため暫定 false
     *
     * @return array{horseFlagsMap:array, oddsHorseBlocks:array,
     *               b6OddsRows:Collection, b6TanPopMap:array, b6FukuPopMap:array}
     */
    private function _buildFlagsAndOddsMap(
        array  $secondAiHorses,
        string $oddsData,
        string $date,
        int    $kaisuu,
        string $basho,
        int    $day,
        int    $race
    ): array {
        // ── Block 6: F1〜F8フラグ計算（_mergeAiResults に渡す） ──────────────────
        // $secondAiHorses の各馬について F2〜F8 を判定し $horseFlagsMap[num] に格納。
        // F1・F5 は Block 9 依存のため暫定 false。
        $horseFlagsMap = [];

        // ── F2 用: 6分前オッズから 単勝人気 / 複勝人気 を算出 ─────────────────
        $b6OddsRows = DB::table('t_horse_odds_finder_odds')
            ->where('date',                 $date)
            ->where('kaisuu',               $kaisuu)
            ->where('basho',                $basho)
            ->where('day',                  $day)
            ->where('race',                 $race)
            ->where('minutes_before_start', 6)
            ->get(['num', 'odds', 'fuku_min']);

        $b6TanSorted  = $b6OddsRows->sortBy('odds')->values();
        $b6FukuSorted = $b6OddsRows->sortBy('fuku_min')->values();
        $b6TanPopMap  = [];   // num => 単勝人気順位
        $b6FukuPopMap = [];   // num => 複勝人気順位
        foreach ($b6TanSorted  as $rank => $row) { $b6TanPopMap[(int)$row->num]  = $rank + 1; }
        foreach ($b6FukuSorted as $rank => $row) { $b6FukuPopMap[(int)$row->num] = $rank + 1; }

        // ── $oddsData を馬番単位のブロックに分割（F3/F4/F6/F7/F8 判定用） ──────
        // フォーマット: " N番(M人気) 馬名\n  各種データ行..." （半角括弧）
        $oddsHorseBlocks = [];
        $b6Lines         = explode("\n", $oddsData);
        $b6CurNum        = null;
        $b6CurLines      = [];
        foreach ($b6Lines as $b6Line) {
            if (preg_match('/^\s{0,2}(\d{1,2})番\(\s*\d+人気\)\s+/u', $b6Line, $b6m)) {
                if ($b6CurNum !== null) {
                    $oddsHorseBlocks[$b6CurNum] = implode("\n", $b6CurLines);
                }
                $b6CurNum   = (int)$b6m[1];
                $b6CurLines = [$b6Line];
            } elseif ($b6CurNum !== null) {
                $b6CurLines[] = $b6Line;
            }
        }
        if ($b6CurNum !== null) {
            $oddsHorseBlocks[$b6CurNum] = implode("\n", $b6CurLines);
        }

        // ── 各2nd AI馬のフラグ計算 ────────────────────────────────────────────
        foreach ($secondAiHorses as $b6sh) {
            $b6Num     = $b6sh['num'];
            $b6Pop     = $b6sh['popularity'];  // 2nd AI が返した単勝人気
            $b6Block   = $oddsHorseBlocks[$b6Num] ?? '';

            // F2: 複勝人気 - 単勝人気 ≥ 2（DB由来の6分前人気で判定）
            $b6TanP  = $b6TanPopMap[$b6Num]  ?? null;
            $b6FukuP = $b6FukuPopMap[$b6Num] ?? null;
            $b6F2    = ($b6TanP !== null && $b6FukuP !== null) && (($b6FukuP - $b6TanP) >= 2);

            // F3: 複勝流入ランク ≤ 2
            $b6F3 = false;
            if (preg_match('/複勝流入ランク: (\d+)位/u', $b6Block, $b6fm3)) {
                $b6F3 = ((int)$b6fm3[1] <= 2);
            }

            // F4: 7番人気以下 かつ 直前流入（9→6分前）で単複ともに 2% 超下落
            $b6F4 = false;
            if ($b6Pop >= 7 && preg_match(
                '/直前流入（9→6分前）: 複勝\s*([+\-][\d.]+)%\s*\/\s*単勝\s*([+\-][\d.]+)%/u',
                $b6Block, $b6fm4
            )) {
                $b6F4 = ((float)$b6fm4[1] < -2.0 && (float)$b6fm4[2] < -2.0);
            }

            // F6: 類似レース統計 サンプル ≥ 30 かつ 5着以内率 ≥ 70%
            $b6F6 = false;
            if (preg_match('/類似レース統計[^・]+・N=(\d+)・/u', $b6Block, $b6fm6n) &&
                preg_match('/5着以内率([\d.]+)%/u',              $b6Block, $b6fm6r)) {
                $b6F6 = ((int)$b6fm6n[1] >= 30 && (float)$b6fm6r[1] >= 70.0);
            }

            // F7: 予測補正OPI ≤ 0.95（「－」 の場合は false）
            $b6F7 = false;
            if (preg_match('/予測補正OPI: ([\d.]+)/u', $b6Block, $b6fm7)) {
                $b6F7 = ((float)$b6fm7[1] <= 0.95);
            }

            // F8: 過去回収率・OPI帯別・フェーズパターン別のいずれかで ≥ 110% かつ サンプル ≥ 30
            $b6F8 = false;
            if (preg_match_all('/回収率([\d.]+)%.*?サンプル(\d+)件/u', $b6Block, $b6fm8, PREG_SET_ORDER)) {
                foreach ($b6fm8 as $b6fm8m) {
                    if ((float)$b6fm8m[1] >= 110.0 && (int)$b6fm8m[2] >= 30) {
                        $b6F8 = true;
                        break;
                    }
                }
            }

            $horseFlagsMap[$b6Num] = [
                'F1' => false,  // Block 9 依存 → 暫定 false
                'F2' => $b6F2,
                'F3' => $b6F3,
                'F4' => $b6F4,
                'F5' => false,  // Block 9 依存 → 暫定 false
                'F6' => $b6F6,
                'F7' => $b6F7,
                'F8' => $b6F8,
            ];

//             \Log::debug('[Block6] F-flags', [
//                 'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho,
//                 'day'  => $day,  'race'   => $race,
//                 'num'  => $b6Num, 'name'  => $b6sh['name'],
//                 'flags' => $horseFlagsMap[$b6Num],
//             ]);
        }
        // ── Block 6 End ───────────────────────────────────────────────────────────

        return compact('horseFlagsMap', 'oddsHorseBlocks', 'b6OddsRows', 'b6TanPopMap', 'b6FukuPopMap');
    }

    /**
     * 1st AI（Claude）の回答を「正常 / 正常な0件 / 失敗」に判定する（受入チェック #65・#66）
     *
     * 仕様:
     *   ・1st AI は「厳選穴レース行」「レース指標行」「候補行」を既存形式で返す。
     *   ・正常な0件 = 厳選穴レース行がある／レース指標行がある／候補行だけが無い。
     *   ・完全な空回答、形式不正、必要な2行が無い回答は【失敗】として扱い、
     *     通常予測を公開してはならない（2nd AI だけの候補を公開するのは禁止）。
     *
     * 【なぜ必要か】
     *   これまでは 1st AI の回答が空でも形式不正でも「候補0頭」として素通りし、
     *   2nd AI だけの候補が通常予測として公開されうる状態だった。
     *   1st AI が落ちたレースは、予測そのものを出さないのが仕様である。
     *
     * @return array{status:'ok'|'zero'|'failed', reason:string,
     *               has_upset_line:bool, has_index_line:bool, horse_count:int}
     */
    private function _judgeFirstAiResponse(string $firstAiText): array
    {
        $faText = trim($firstAiText);

        // 完全な空回答 → 失敗
        if ($faText === '') {
            return ['status' => 'failed', 'reason' => '回答が空',
                    'has_upset_line' => false, 'has_index_line' => false, 'horse_count' => 0];
        }

        // 厳選穴レース行（「厳選穴レース|1」または「厳選穴レース|0」）
        $faUpset = (preg_match('/^厳選穴レース\|[01]\s*$/mu', $faText) === 1);
        // レース指標行（波乱度・下位進入度・大穴進入度の3つが揃っていること）
        $faIndex = (preg_match(
            '/^レース指標\|波乱度[:：\s]*\d+\|下位進入度[:：\s]*\d+\|大穴進入度[:：\s]*\d+/mu',
            $faText
        ) === 1);
        $faHorses = count($this->_parseAiHorses($faText));

        // 必要な2行が揃っていなければ形式不正 → 失敗
        if (!$faUpset || !$faIndex) {
            return ['status' => 'failed',
                    'reason' => '必要な行が無い（厳選穴レース行: ' . ($faUpset ? 'あり' : 'なし')
                              . ' / レース指標行: ' . ($faIndex ? 'あり' : 'なし') . '）',
                    'has_upset_line' => $faUpset, 'has_index_line' => $faIndex,
                    'horse_count' => $faHorses];
        }

        // 2行が揃っていて候補行だけが無い → 正常な0件
        if ($faHorses === 0) {
            return ['status' => 'zero', 'reason' => '候補0頭（正常）',
                    'has_upset_line' => true, 'has_index_line' => true, 'horse_count' => 0];
        }

        return ['status' => 'ok', 'reason' => '',
                'has_upset_line' => true, 'has_index_line' => true, 'horse_count' => $faHorses];
    }

    /**
     * 低配当例外3条件の判定（受入チェック #69）
     *
     * 仕様:
     *   推定確定複勝最小 < 1.5倍 かつ 推定確定単勝 < 3.0倍 の馬は原則除外。
     *   次の3条件を【すべて】満たす場合だけ除外を解除できる（AND。ORは仕様違反）。
     *     例外①: 断層最上位グループ（人気順 <= 主断層上限人気）
     *     例外②: 単勝・複勝の両方が複数時点で継続流入
     *     例外③: サンプル30件以上の回収率110%以上が2種類以上
     *
     * 【このメソッドを作った理由】
     *   以前は Block 13a の中にこの判定が直接書かれていた。
     *   同じ判定を別の場所でもう一度書くと、片方だけ直して食い違う事故が起きる
     *   （形式検証と読み取りの正規表現が食い違っていた不具合と同じ型）。
     *   Block 13a と妙味小計の検証（_verifyLowPayoutMeritCap）で必ず本メソッドを使う。
     *
     * @return array{low_payout:bool, released:bool, ex1:bool, ex2:bool, ex3:bool,
     *               fuku_min:?float, tan_odds:?float}
     */
    private function _judgeLowPayoutException(
        array  $horse,
        string $block,
        ?int   $primaryGapUpperPop,
        array  $b13ScoreAMap
    ): array {
        $lpOut = ['low_payout' => false, 'released' => false,
                  'ex1' => false, 'ex2' => false, 'ex3' => false,
                  'fuku_min' => null, 'tan_odds' => null];

        // 推定確定複勝最小・推定確定単勝（どちらか取れなければ低配当判定の対象外）
        if (!preg_match('/推定確定複勝最小: ([\d.]+)/u', $block, $lpM1)) return $lpOut;
        if (!preg_match('/推定確定オッズ: ([\d.]+)/u',   $block, $lpM2)) return $lpOut;
        $lpOut['fuku_min'] = (float) $lpM1[1];
        $lpOut['tan_odds'] = (float) $lpM2[1];

        // 低配当の対象は「複勝最小 < 1.5 かつ 単勝 < 3.0」の両方を満たす馬だけ
        if ($lpOut['fuku_min'] >= 1.5 || $lpOut['tan_odds'] >= 3.0) return $lpOut;
        $lpOut['low_payout'] = true;

        // 例外①: 断層最上位グループ
        $lpOut['ex1'] = ($primaryGapUpperPop !== null
                         && (int) ($horse['popularity'] ?? 999) <= $primaryGapUpperPop);

        // 例外②: 単勝・複勝の両方が複数時点で継続流入
        $lpTanShrink = null;
        if (preg_match('/単勝流入ランク:[^※]+※短縮率: ([+\-][\d.]+)%/u', $block, $lpTs)) {
            $lpTanShrink = (float) $lpTs[1];
        }
        $lpTanDirect = false;
        if (preg_match('/直前流入（9→6分前）:.*単勝\s*([+\-][\d.]+)%/u', $block, $lpTd)) {
            $lpTanDirect = ((float) $lpTd[1] < 0.0);
        }
        // 【符号に注意・修正済み】プロンプトの「※短縮率」は
        //   ($tanBase - $tan6) / $tanBase * 100 であり【正値＝オッズ短縮＝資金流入】。
        //   一方すぐ上の「直前流入（9→6分前）」は ($odds6 - $odds9) / $odds9 * 100 で
        //   【負値＝オッズ下落＝資金流入】と符号の向きが逆になっている。
        //   以前ここは $lpTanShrink < 0.0 と書かれており、
        //   「計測期間で単勝が売られた馬」でなければ例外②が成立しない状態だった。
        //   例外②は「単勝・複勝の両方が複数時点で継続流入」なので、
        //   単勝側は短縮率が【正】であることが条件。
        $lpTanCont  = ($lpTanShrink !== null && $lpTanShrink > 0.0 && $lpTanDirect);
        $lpScoreA   = $b13ScoreAMap[(int) ($horse['num'] ?? 0)] ?? null;
        $lpFukuCont = (is_int($lpScoreA) && $lpScoreA >= 12);
        $lpOut['ex2'] = ($lpTanCont && $lpFukuCont);

        // 例外③: サンプル30件以上の回収率110%以上が2種類以上
        $lpHiCnt = 0;
        foreach ([
            '/過去回収率[（(][^）)]+[）)]: 回収率([\d.]+)%\s+勝率[\d.]+%\s+サンプル(\d+)件/u',
            '/OPI帯別回収率[（(][^）)]+[）)]: 回収率([\d.]+)%\s+勝率[\d.]+%\s+サンプル(\d+)件/u',
            '/フェーズパターン別回収率[（(][^）)]+[）)]: 回収率([\d.]+)%\s+勝率[\d.]+%\s+サンプル(\d+)件/u',
        ] as $lpRx) {
            if (preg_match($lpRx, $block, $lpRm)
                && (int) $lpRm[2] >= 30 && (float) $lpRm[1] >= 110.0) {
                $lpHiCnt++;
            }
        }
        $lpOut['ex3'] = ($lpHiCnt >= 2);

        // 3条件すべて成立（AND）したときだけ解除
        $lpOut['released'] = ($lpOut['ex1'] && $lpOut['ex2'] && $lpOut['ex3']);
        return $lpOut;
    }

    /**
     * 妙味小計の上限をPHP側で検証する（受入チェック #69）
     *
     * 仕様:
     *   「推定確定複勝最小オッズが1.5倍未満の場合は、妙味4項目の合計である
     *     妙味小計を原則5点以下とする。低配当例外3条件をすべて満たす場合だけ
     *     この妙味上限を解除できる。」
     *
     * 【PHPで検証できること・できないこと】
     *   おすすめ度は「信頼度60点＋妙味40点」の固定内訳と決まっている。
     *     妙味小計 = おすすめ度 - 信頼度小計
     *   信頼度小計の上限を上から押さえれば、妙味小計の下限が分かる。
     *     妙味小計 >= おすすめ度 - 信頼度上限
     *   信頼度7項目のうち⑦能力・今回条件への適性（0〜3点）は A=3/B=2/C=1/D=0 と
     *   仕様で固定されており、グレードは選出理由の冒頭からPHPが読み取れる。よって
     *     信頼度上限 = 57 + 能力適性点
     *   まで絞れる。低配当馬（例外解除なし）のおすすめ度がこの上限＋5点を超えたら、
     *   妙味小計が5点を超えて計算されたことが確定する。
     *
     *   【限界】これは片側検定である。
     *   「信頼度50点＋妙味小計15点＝おすすめ度65点」のように、信頼度が低く
     *   妙味小計が大きい違反は、この方法では検出できない。
     *   AIは仕様により内訳・小計を出力しないため（既存Flutter形式を変えないため）、
     *   妙味小計そのものはPHPから観測できない。出力形式を変えれば観測できるが、
     *   プロンプトは確定版として凍結されているため、独断では変更しない。
     *
     *   【ただし公開結果には影響しない】
     *   例外3条件を満たさない低配当馬は Block 13a が候補から必ず除外する。
     *   妙味小計が何点で計算されていても、その馬が予測として公開されることはない。
     *
     * 本メソッドは検出とログ保存だけを行い、AIの点数を書き換えない
     * （PHPがAIの採点を上書きしない、という仕様を守るため）。
     * 該当馬は Block 13a の低配当除外で候補から外れる。
     *
     * @return array 検証結果（シャドー保存・報告用）
     */
    private function _verifyLowPayoutMeritCap(
        array  $mergedHorses,
        array  $oddsHorseBlocks,
        ?int   $primaryGapUpperPop,
        array  $b13ScoreAMap
    ): array {
        $vcChecked = 0; $vcViolations = []; $vcTargets = [];
        foreach ($mergedHorses as $vcH) {
            $vcNum   = (int) ($vcH['num'] ?? 0);
            $vcBlock = $oddsHorseBlocks[$vcNum] ?? '';
            if ($vcBlock === '') continue;

            $vcJ = $this->_judgeLowPayoutException($vcH, $vcBlock, $primaryGapUpperPop, $b13ScoreAMap);
            if (!$vcJ['low_payout']) continue;   // 低配当の対象外

            $vcChecked++;
            $vcScore = (float) ($vcH['score'] ?? 0);

            // 信頼度⑦（能力・今回条件への適性）は A=3 / B=2 / C=1 / D=0 で仕様固定。
            // 選出理由の冒頭「能力適性:X（XX点）。」からグレードを読み取り、
            // 信頼度小計の上限を 57 + 能力適性点 まで絞る。
            // 読み取れない場合は最大の3点として扱う（上限を緩い側に倒し、誤検出を避ける）。
            $vcGrade = null;
            if (preg_match('/^能力適性:([ABCD])[（(]\d+点[）)]/u', (string) ($vcH['reason'] ?? ''), $vcGm)) {
                $vcGrade = $vcGm[1];
            }
            $vcAbilityPt  = ['A' => 3, 'B' => 2, 'C' => 1, 'D' => 0][$vcGrade] ?? 3;
            $vcTrustMax   = 57 + $vcAbilityPt;                 // 信頼度小計の上限
            $vcCap        = $vcJ['released'] ? 100.0 : (float) ($vcTrustMax + 5);
            $vcMeritLower = round(max(0.0, $vcScore - $vcTrustMax), 1); // 妙味小計の下限（参考値）

            $vcRow   = [
                'num'                => $vcNum,
                'name'               => $vcH['name'] ?? '',
                'score'              => $vcScore,
                'fuku_min'           => $vcJ['fuku_min'],
                'tan_odds'           => $vcJ['tan_odds'],
                'released'           => $vcJ['released'],
                'ability_grade'      => $vcGrade,
                'trust_subtotal_max' => $vcTrustMax,
                'merit_subtotal_min' => $vcMeritLower,
                'cap'                => $vcCap,
                'violation'          => ($vcScore > $vcCap),
            ];
            $vcTargets[] = $vcRow;

            if ($vcScore > $vcCap) {
                $vcViolations[] = $vcRow;
                \Log::error('[#69] 妙味小計の上限超過を検出（AI採点は書き換えない）', [
                    'num'        => $vcNum,
                    'name'       => $vcH['name'] ?? '',
                    'score'      => $vcScore,
                    'cap'        => $vcCap,
                    'fuku_min'   => $vcJ['fuku_min'],
                    'tan_odds'   => $vcJ['tan_odds'],
                    'ex1'        => $vcJ['ex1'], 'ex2' => $vcJ['ex2'], 'ex3' => $vcJ['ex3'],
                    'ability_grade'      => $vcGrade,
                    'trust_subtotal_max' => $vcTrustMax,
                    'merit_subtotal_min' => $vcMeritLower,
                    'note' => '妙味小計 >= おすすめ度 - 信頼度上限(57+能力適性点)。5点超は仕様違反',
                ]);
            }
        }
        $vcResult = [
            'rule'            => '推定確定複勝最小 < 1.5 の馬は妙味小計 <= 5（例外3条件すべて成立時のみ解除）',
            'derivation'      => '妙味小計 >= おすすめ度 - 信頼度上限（57 + 能力適性点 A3/B2/C1/D0）',
            'detection_type'  => 'one_sided',
            'limitation'      => '信頼度が低く妙味小計が大きい違反（例: 信頼度50 + 妙味15 = 65）は'
                               . 'この方法では検出できない。AIは仕様により内訳・小計を出力しないため、'
                               . '妙味小計そのものはPHPから観測できない。',
            'guarantee'       => '検出漏れがあっても、例外3条件を満たさない低配当馬は Block 13a が'
                               . '必ず候補から除外するため、公開される予測には影響しない。',
            'checked_count'   => $vcChecked,
            'violation_count' => count($vcViolations),
            'targets'         => $vcTargets,
            'violations'      => $vcViolations,
        ];
//         \Log::info('[#69] 妙味小計上限の検証', [
//             'checked'    => $vcChecked,
//             'violations' => count($vcViolations),
//         ]);
        return $vcResult;
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
    private function _loadSavedMergeResult($date, $kaisuu, $basho, $day, $race): array
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

        $out = [
            'merged_horses' => $horses,
            'upset_race'    => (int) $row->upset_race,
        ];

        if ($row->wave_level !== null && $row->lower_entry !== null && $row->big_gap_entry !== null) {
            $out['race_metrics'] = [
                '波乱度'     => (int) $row->wave_level,
                '下位進入度' => (int) $row->lower_entry,
                '大穴進入度' => (int) $row->big_gap_entry,
            ];
        }

        return $out;
    }

    /**
     * Block 14: 統合結果を ai_merge_result テーブルに UPSERT
     */
    private function _saveAiMergeResult(
        array   $mergedHorses,
        int     $upsetRaceFinal,
        string  $gapTypeForMerge,
        ?int    $b14WaveLevel,
        ?int    $b14LowerEntry,
        ?int    $b14BigGap,
        string  $date,
        int     $kaisuu,
        string  $basho,
        int     $day,
        int     $race,
        object  $raceRow,
        array   $b69MeritCap = []   // #69: 妙味小計上限の検証結果（証跡）
    ): void {
        // ── Block 14: 統合結果を ai_merge_result テーブルに UPSERT ──────────────────
        // _mergeAiResults() の出力と厳選穴レース再判定結果を JSON で保存する
        // 実テーブルの merge_result 列（text）に全データを JSON として格納する
        try {
            // 【原因】以前は ON DUPLICATE KEY UPDATE に updated_at = CURRENT_TIMESTAMP を
            //   含めていたが、このテーブルに updated_at 列は存在しない。
            //   そのため毎レース SQL エラーになり、catch がログ出力のみで握り潰していたため
            //   テーブルが空のままになっていた。updated_at を外して修正。
            // 【あわせて改善】upset_race / wave_level / lower_entry / big_gap_entry / gap_type は
            //   専用列が用意されているので、JSON に入れるだけでなく専用列へも格納する
            //   （SQL で直接絞り込めるようにするため）。
            //   merge_result（longtext）には統合馬リストを JSON で保存する。
            $b14MergeJson = json_encode([
                // #69: 妙味小計上限の検証結果。ログだけでなくDBへも残し、
                //   「毎レース検証したこと」を後から確認できるようにする。
                'merit_cap_check' => $b69MeritCap,
                'upset_race'    => $upsetRaceFinal,
                'gap_type'      => $gapTypeForMerge,
                'wave_level'    => $b14WaveLevel,
                'lower_entry'   => $b14LowerEntry,
                'big_gap_entry' => $b14BigGap,
                'merged_horses' => $mergedHorses,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            DB::statement(
                'INSERT INTO t_horse_odds_finder_ai_merge_result'
                . ' (date, kaisuu, basho, basho_code, day, race, race_name,'
                . '  upset_race, wave_level, lower_entry, big_gap_entry, gap_type, merge_result)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE'
                . '   basho         = VALUES(basho),'
                . '   race_name     = VALUES(race_name),'
                . '   upset_race    = VALUES(upset_race),'
                . '   wave_level    = VALUES(wave_level),'
                . '   lower_entry   = VALUES(lower_entry),'
                . '   big_gap_entry = VALUES(big_gap_entry),'
                . '   gap_type      = VALUES(gap_type),'
                . '   merge_result  = VALUES(merge_result)',
                [
                    $date,
                    (int) $kaisuu,
                    $raceRow->basho_name ?? '',
                    $basho,
                    (int) $day,
                    (int) $race,
                    $raceRow->race_name ?? '',
                    (int) $upsetRaceFinal,
                    $b14WaveLevel,
                    $b14LowerEntry,
                    $b14BigGap,
                    $gapTypeForMerge,
                    $b14MergeJson,
                ]
            );
//             \Log::debug('[Block14] ai_merge_result UPSERT', [
//                 'date'       => $date,
//                 'kaisuu'     => $kaisuu,
//                 'basho_code' => $basho,
//                 'day'        => $day,
//                 'race'       => $race,
//                 'upset_race' => $upsetRaceFinal,
//                 'gap_type'   => $gapTypeForMerge,
//                 'horses_cnt' => count($mergedHorses),
//             ]);
        } catch (\Throwable $b14e) {
            \Log::error('[Block14] ai_merge_result UPSERT failed', ['err' => $b14e->getMessage()]);
        }
        // ── Block 14 End ──────────────────────────────────────────────────────────
    }

    /**
     * Block 10: 市場妙味基礎点算出・シャドーログ保存
     * score_a〜e・total_score を算出し market_score_log に INSERT する
     * B-13 市場過小評価フラグ・B-8 判定用中間値も生成する
     *
     * @return array{b8InfoMap:array, b10TotalScoreMap:array,
     *               b13UnderevalFlagMap:array, b13ScoreAMap:array, b10ScoreE:int}
     */
    private function _calcMarketScore(
        $b9HorseRows,
        $b6OddsRows,
        array  $oddsHorseBlocks,
        array  $b6TanPopMap,
        array  $b6FukuPopMap,
        string $gapTypeForMerge,
        ?int   $primaryGapUpperPopForMerge,
        string $date,
        int    $kaisuu,
        string $basho,
        int    $day,
        int    $race,
        object $raceRow
    ): array {
        $b8InfoMap = []; // B-8: 偽流入警戒判定用中間変数マップ（try外で初期化）
        $b10TotalScoreMap    = []; // B-10: 高配当総合点算出用 市場妙味基礎点マップ（try外で初期化）
        $b10ScoreDMap        = []; // 帯基準馬方式タイブレーク④用 回収率裏付けD点マップ
        $b13UnderevalFlagMap = []; // B-13: 市場過小評価フラグ馬別マップ（try外で初期化）
        $b13ScoreAMap        = []; // B-13: Score_A（複勝継続流入）馬別マップ（馬券判定用）
        $b10ScoreE = 0; // try 失敗時フォールバック
        // ── Block 10: 市場妙味基礎点 算出・シャドーログ保存 ─────────────────────────
        // フェーズ1: PHP側で score_a〜e・total_score を算出し market_score_log に INSERT。
        // AIへは送信しない（シャドー期間中）。Flutter 表示にも使わない。
        // F1（基礎点A≥12）・F5（基礎点E≥6）の本番有効化も別フェーズ。
        try {
            // ── Score A / E 用: 複勝・単勝オッズ時系列データを一括取得 ───────────
            // t_horse_odds_finder_odds から各時点の fuku_min / odds を取得。
            // 計測前（ODDS_DB_FIRST）も含める（Score E の「計測期間中最大比率」用）。
            $b10SeriesPoints = array_merge(
                [\App\Constants\Constants::ODDS_DB_FIRST], [21, 18, 15, 12, 9, 6]
            );
            $b10FukuSeriesRaw = DB::table('t_horse_odds_finder_odds')
                ->where('date', $date)
                ->where('kaisuu', $kaisuu)
                ->where('basho', $basho)
                ->where('day', $day)
                ->where('race', $race)
                ->whereIn('minutes_before_start', $b10SeriesPoints)
                ->get(['num', 'minutes_before_start', 'fuku_min', 'odds']);

            // 馬番 → [minutes_before_start => 値] のマップに整形
            $b10FukuSeriesMap = [];
            $b10TanSeriesMap  = [];
            foreach ($b10FukuSeriesRaw as $b10fs) {
                $b10FsMin = (int) $b10fs->minutes_before_start;
                $b10FsNum = (int) $b10fs->num;
                $b10FukuSeriesMap[$b10FsNum][$b10FsMin] = (float) $b10fs->fuku_min;
                $b10TanSeriesMap[$b10FsNum][$b10FsMin]  = (float) $b10fs->odds;
            }

            // ── Score E レース共通値（Block11互換用）──────────────────────────────
            // Block11 の ml_snapshot.score_e はレース共通断層タイプベース値として維持する
            $b10ScoreE = match ($gapTypeForMerge) {
                'E'     => 15,
                'D'     => 8,
                'C'     => 5,
                'B'     => 3,
                'A'     => 1,
                default => 0,
            };

            // ── Score E 用: 主断層ペアの比率時系列・単複断層位置の一致判定 ─────────
            // 【仕様】
            //   ・「断層縮小」= 対象隣接ペアの計測期間中最大比率に対して
            //                   6分前比率が10％以上低下した状態
            //   ・「主断層へ接近中」= 6分前時点で断層下側先頭馬と直上馬の比率が
            //                   直前2区間連続で低下し、かつ最大比率から10％以上低下
            //   ・断層は比率2.00以上。単勝断層と複勝断層の位置が食い違う場合は「不一致」
            //   ・必要な時系列が欠損する場合は該当判定を「不明」（null）とする
            // 【#46】馬は6分前の人気順で特定し、以後は馬番で追跡する（時点ごとに入れ替えない）
            $b10GapShrink     = null; // 断層縮小（true/false/null=不明）
            $b10GapApproach   = null; // 主断層へ接近中（true/false/null=不明）
            $b10GapMismatch   = null; // 単複断層の不一致（true/false/null=不明）
            if ($primaryGapUpperPopForMerge !== null) {
                // 6分前人気順で主断層の上側馬・下側先頭馬を特定する
                $b10GapUpNum = array_search($primaryGapUpperPopForMerge,     $b6TanPopMap, true);
                $b10GapLoNum = array_search($primaryGapUpperPopForMerge + 1, $b6TanPopMap, true);
                if ($b10GapUpNum !== false && $b10GapLoNum !== false) {
                    // 比率(t) = 下側先頭馬の単勝オッズ ÷ 直上馬の単勝オッズ
                    $b10RatioSeries = [];
                    foreach ($b10SeriesPoints as $b10rp) {
                        $b10Up = $b10TanSeriesMap[(int)$b10GapUpNum][$b10rp] ?? null;
                        $b10Lo = $b10TanSeriesMap[(int)$b10GapLoNum][$b10rp] ?? null;
                        if ($b10Up !== null && $b10Lo !== null && $b10Up > 0) {
                            $b10RatioSeries[$b10rp] = $b10Lo / $b10Up;
                        }
                    }
                    $b10R6 = $b10RatioSeries[6] ?? null;
                    if ($b10R6 !== null && count($b10RatioSeries) >= 2) {
                        $b10RMax = max($b10RatioSeries);
                        // 最大比率から6分前までに10％以上低下しているか
                        $b10Drop10 = ($b10RMax > 0)
                            && ((($b10RMax - $b10R6) / $b10RMax) >= 0.10);
                        $b10GapShrink = $b10Drop10;

                        // 直前2区間が連続して低下しているか（9→6 と 12→9）
                        $b10SeqDown = null;
                        if (isset($b10RatioSeries[12], $b10RatioSeries[9], $b10RatioSeries[6])) {
                            $b10SeqDown = ($b10RatioSeries[9] < $b10RatioSeries[12])
                                       && ($b10RatioSeries[6] < $b10RatioSeries[9]);
                        }
                        $b10GapApproach = ($b10SeqDown === null) ? null : ($b10SeqDown && $b10Drop10);
                    }
                }

                // 複勝断層の位置（6分前・複勝最小オッズ昇順の隣接比率が2.00以上で最大の箇所）
                $b10FukuSortedRows = [];
                foreach ($b6OddsRows as $b10fr) {
                    $b10FrFuku = isset($b10fr->fuku_min) ? (float)$b10fr->fuku_min : 0.0;
                    if ($b10FrFuku > 0) $b10FukuSortedRows[] = $b10FrFuku;
                }
                sort($b10FukuSortedRows);
                $b10FukuGapPos = null; $b10FukuGapMaxRatio = 0.0;
                for ($b10fi = 0; $b10fi < count($b10FukuSortedRows) - 1; $b10fi++) {
                    if ($b10FukuSortedRows[$b10fi] <= 0) continue;
                    $b10Fr = $b10FukuSortedRows[$b10fi + 1] / $b10FukuSortedRows[$b10fi];
                    if ($b10Fr >= 2.0 && $b10Fr > $b10FukuGapMaxRatio) {
                        $b10FukuGapMaxRatio = $b10Fr;
                        $b10FukuGapPos      = $b10fi + 1; // 上側の順位（1始まり）
                    }
                }
                // 複勝側に断層が無い、または位置が違う → 不一致
                $b10GapMismatch = empty($b10FukuSortedRows)
                    ? null
                    : ($b10FukuGapPos !== $primaryGapUpperPopForMerge);
            }

            // ── 全馬ループ ────────────────────────────────────────────────────────
            $b10Rows = [];
            foreach ($b9HorseRows as $b10h) {
                $b10Num   = (int)$b10h->num;
                $b10Block = $oddsHorseBlocks[$b10Num] ?? '';

                // ── Score A: 複勝継続低下パターン点（0〜25 or null=不明）────────────
                // 判定: 21→18→15→12→9→6 分前の複勝オッズ低下区間数と最終変化
                $b10ScoreA    = null; // default: 不明（4時点未満）
                $b10TimePoints = [21, 18, 15, 12, 9, 6]; // 古い順
                $b10FukuVals   = [];
                $b10FukuSeries = $b10FukuSeriesMap[$b10Num] ?? [];
                foreach ($b10TimePoints as $b10min) {
                    if (isset($b10FukuSeries[$b10min]) && $b10FukuSeries[$b10min] > 0) {
                        $b10FukuVals[$b10min] = $b10FukuSeries[$b10min];
                    }
                }
                if (count($b10FukuVals) >= 4) {
                    // 【仕様】区間は「隣接取得時点間」。欠損区間は母数へ入れない。
                    //   （21-18 / 18-15 / 15-12 / 12-9 / 9-6 の5区間。両端が揃う区間だけ数える）
                    // 【仕様】前時点比 2％超の低下を「低下」、±2％以内は「横ばい」、
                    //   2％超の上昇を「上昇」とする。単なる < で数えてはいけない。
                    $b10DeclineCnt  = 0;
                    $b10IntervalCnt = 0;
                    for ($b10i = 1; $b10i < count($b10TimePoints); $b10i++) {
                        $b10Prev = $b10TimePoints[$b10i - 1];
                        $b10Curr = $b10TimePoints[$b10i];
                        if (!isset($b10FukuVals[$b10Prev], $b10FukuVals[$b10Curr])) continue;
                        if ($b10FukuVals[$b10Prev] <= 0) continue;
                        $b10IntervalCnt++;
                        if ($b10FukuVals[$b10Curr] / $b10FukuVals[$b10Prev] < 0.98) {
                            $b10DeclineCnt++; // 2％超の低下だけを「低下」と数える
                        }
                    }
                    // 9分前→6分前の変化を判定
                    $b10FinalChange = null;
                    if (isset($b10FukuVals[9], $b10FukuVals[6]) && $b10FukuVals[9] > 0) {
                        $b10FinalRatio = $b10FukuVals[6] / $b10FukuVals[9];
                        if ($b10FinalRatio < 0.98) {
                            $b10FinalChange = 'down'; // 2%超低下
                        } elseif ($b10FinalRatio <= 1.02) {
                            $b10FinalChange = 'flat'; // ±2%以内
                        } else {
                            $b10FinalChange = 'up';   // 2%超上昇
                        }
                    }
                    $b10ScoreA = match (true) {
                        $b10DeclineCnt >= 3 && $b10FinalChange === 'down' => 25,
                        $b10DeclineCnt >= 3 && $b10FinalChange === 'flat' => 18,
                        $b10DeclineCnt >= 3 && $b10FinalChange === 'up'   => 0,
                        $b10DeclineCnt >= 3                               => null, // 9/6分データ欠損
                        $b10DeclineCnt === 2 && $b10FinalChange === 'down' => 12,
                        default                                            => 0,   // 単発急落含む
                    };
                }

                // ── B-8 判定用: Score A計算後に中間値を保存（4時点以上の場合のみ非null）──────────
                $b8C1 = null; // 複勝3区間以上低下
                $b8C2 = null; // 9分前→6分前も複勝が低下
                $b8C5 = null; // 急落後に計測開始値付近まで反発していない
                if (count($b10FukuVals) >= 4) {
                    $b8C1 = ($b10DeclineCnt >= 3);
                    $b8C2 = ($b10FinalChange === 'down');
                    // C5: 6分前が計測開始値の102%以下 = 反発していない
                    $b8FukuFirstVal = 0.0;
                    $b8FukuLastVal  = 0.0;
                    foreach ($b10TimePoints as $b8mt) {
                        if (isset($b10FukuVals[$b8mt])) { $b8FukuFirstVal = $b10FukuVals[$b8mt]; break; }
                    }
                    $b8FukuLastVal = $b10FukuVals[6] ?? 0.0;
                    if ($b8FukuFirstVal > 0 && $b8FukuLastVal > 0) {
                        $b8C5 = ($b8FukuLastVal <= $b8FukuFirstVal * 1.02);
                    }
                }

                // ── Score B: 複勝優位性点（0〜15 or null=不明）────────────────────
                // 単勝人気 - 複勝人気（正値 = 複勝のほうが人気で資金流入あり）
                $b10TanP  = $b6TanPopMap[$b10Num]  ?? null;
                $b10FukuP = $b6FukuPopMap[$b10Num] ?? null;
                $b10ScoreB = null; // default: 不明
                if ($b10TanP !== null && $b10FukuP !== null) {
                    $b10PopDiff = $b10TanP - $b10FukuP;
                    $b10ScoreB = match (true) {
                        $b10PopDiff >= 3  => 15,
                        $b10PopDiff === 2 => 10,
                        $b10PopDiff === 1 => 5,
                        default           => 0, // 同順位以下
                    };
                }

                // ── Score C: OPI妙味点（0〜15 or null=不明）─────────────────────
                // 予測補正OPI（小数第2位で丸めて帯判定）
                $b10Opi = null;
                if (preg_match('/予測補正OPI: ([\d.]+)/u', $b10Block, $b10mc)) {
                    $b10Opi = (float)$b10mc[1];
                }
                $b10ScoreC = null; // default: 不明
                if ($b10Opi !== null) {
                    $b10OpiRounded = round($b10Opi, 2);
                    $b10ScoreC = match (true) {
                        $b10OpiRounded <= 0.75 => 15,
                        $b10OpiRounded <= 0.85 => 12,
                        $b10OpiRounded <= 0.95 => 7,
                        $b10OpiRounded <= 1.10 => 3,
                        default                => 0,
                    };
                }

                // ── Score D: 回収率裏付け点（0〜15 or null=不明）─────────────────
                // 3種類の回収率をそれぞれパース（サンプル30件以上のみ有効値）
                $b10ScoreD     = null;
                $b10RateEntries = [];
                // 過去回収率
                if (preg_match('/過去回収率[（(][^）)]+[）)]: 回収率([\d.]+)%\s+勝率[\d.]+%\s+サンプル(\d+)件/u', $b10Block, $b10md1)) {
                    $b10RateEntries[] = ['rate' => (float)$b10md1[1], 'samples' => (int)$b10md1[2]];
                }
                // OPI帯別回収率
                if (preg_match('/OPI帯別回収率[（(][^）)]+[）)]: 回収率([\d.]+)%\s+勝率[\d.]+%\s+サンプル(\d+)件/u', $b10Block, $b10md2)) {
                    $b10RateEntries[] = ['rate' => (float)$b10md2[1], 'samples' => (int)$b10md2[2]];
                }
                // フェーズパターン別回収率
                if (preg_match('/フェーズパターン別回収率[（(][^）)]+[）)]: 回収率([\d.]+)%\s+勝率[\d.]+%\s+サンプル(\d+)件/u', $b10Block, $b10md3)) {
                    $b10RateEntries[] = ['rate' => (float)$b10md3[1], 'samples' => (int)$b10md3[2]];
                }
                // サンプル30件以上のみ有効値
                $b10ValidEntries = array_values(array_filter($b10RateEntries, fn($r) => $r['samples'] >= 30));
                $b10ValidCount   = count($b10ValidEntries);
                if ($b10ValidCount >= 2) {
                    $b10Above110       = 0;
                    $b10MaxSamples110  = 0;
                    $b10Above100Not110 = 0;
                    $b10AllAbove100    = true;
                    foreach ($b10ValidEntries as $b10ve) {
                        if ($b10ve['rate'] >= 110.0) {
                            $b10Above110++;
                            if ($b10ve['samples'] > $b10MaxSamples110) {
                                $b10MaxSamples110 = $b10ve['samples'];
                            }
                        } elseif ($b10ve['rate'] >= 100.0) {
                            $b10Above100Not110++;
                        } else {
                            $b10AllAbove100 = false;
                        }
                    }
                    $b10ScoreD = match (true) {
                        // ① 2種以上110%以上、うち1種以上100件以上
                        $b10Above110 >= 2 && $b10MaxSamples110 >= 100 => 15,
                        // ② 2種以上110%以上（全て100件未満）
                        $b10Above110 >= 2                              => 12,
                        // ③ 110%以上1種のみ + 別の有効値1種以上100%以上
                        $b10Above110 === 1 && $b10Above100Not110 >= 1  => 8,
                        // ④ 110%以上0種 + 有効値2種以上すべて100〜109%
                        $b10Above110 === 0 && $b10AllAbove100          => 4,
                        // ⑤ それ以外
                        default                                        => 0,
                    };
                }
                // 有効値2種類未満 → null（不明）のまま

                // ── Score E（馬別）: 断層構造の穴進入根拠（0〜10 or null=不明）──────
                // 【仕様】
                //   ・断層下側に位置し、断層がS→6分前で縮小、かつ複勝継続流入あり：10点
                //   ・単勝断層と複勝断層が不一致、かつ複勝継続流入あり            ：8点
                //   ・主断層へ接近中、かつ複勝継続流入あり                        ：6点
                //   ・断層上側または構造的な穴進入根拠なし                        ：0点
                //   ※複数条件成立時は最高点のみ。加算しない。
                //   ※必要な時系列が欠損する場合は該当判定を不明（null）とする。
                $b10ScoreEPerHorse = null; // default: 不明
                if ($primaryGapUpperPopForMerge !== null && $b10TanP !== null) {
                    $b10IsGapLowerSide = ($b10TanP > $primaryGapUpperPopForMerge);
                    if (!$b10IsGapLowerSide) {
                        $b10ScoreEPerHorse = 0;              // 断層上側は一律0点
                    } elseif ($b10ScoreA === null) {
                        $b10ScoreEPerHorse = null;           // 複勝継続流入が不明 → 判定不能
                    } else {
                        $b10HasFukuInflow = ($b10ScoreA >= 12);
                        if (!$b10HasFukuInflow) {
                            $b10ScoreEPerHorse = 0;          // 複勝継続流入なし → 0点
                        } elseif ($b10GapShrink === true) {
                            $b10ScoreEPerHorse = 10;
                        } elseif ($b10GapMismatch === true) {
                            $b10ScoreEPerHorse = 8;
                        } elseif ($b10GapApproach === true) {
                            $b10ScoreEPerHorse = 6;
                        } elseif ($b10GapShrink === null
                               && $b10GapMismatch === null
                               && $b10GapApproach === null) {
                            $b10ScoreEPerHorse = null;       // 時系列が全て欠損 → 不明
                        } else {
                            $b10ScoreEPerHorse = 0;          // 根拠なし
                        }
                    }
                }
                // タイプD/E（$primaryGapUpperPopForMerge===null）→ 不明のまま

                // ── 欠損処理・80点換算（市場妙味基礎点）────────────────────────────
                // 有効（非null）スコアの満点合計が50点未満 → 不明
                $b10MaxMap   = ['A' => 25, 'B' => 15, 'C' => 15, 'D' => 15, 'E' => 10];
                $b10ScoreMap = [
                    'A' => $b10ScoreA,
                    'B' => $b10ScoreB,
                    'C' => $b10ScoreC,
                    'D' => $b10ScoreD,
                    'E' => $b10ScoreEPerHorse,
                ];
                $b10ValidSum = 0;
                $b10ValidMax = 0;
                foreach ($b10ScoreMap as $b10key => $b10val) {
                    if ($b10val !== null) {
                        $b10ValidSum += $b10val;
                        $b10ValidMax += $b10MaxMap[$b10key];
                    }
                }
                if ($b10ValidMax < 50) {
                    $b10TotalScore = null; // 有効満点合計50点未満 → 不明
                } else {
                    $b10TotalScore = (int) round($b10ValidSum / $b10ValidMax * 80);
                }

                // F1 / F5 フラグ判定
                $b10F1Active = (is_int($b10ScoreA) && $b10ScoreA >= 12);
                // F5: 基礎点E ≥ 6（Eの満点は仕様どおり10点。6/8/10 のいずれかで成立）
                $b10F5Active = ($b10ScoreEPerHorse !== null && $b10ScoreEPerHorse >= 6);

                // ── B-8 判定用: C6（単複両方低下）の判定と $b8InfoMap 保存 ─────────────────
                // C6: 単勝と複勝の両方が計測開始→6分前で低下
                $b8C6 = null;
                if (preg_match('/変化率（計測開始→6分前）: ([+\-][\d.]+)%/u', $b10Block, $b8m6c)) {
                    $b8TanChangeRate = (float)$b8m6c[1];
                    // 複勝も全体で低下しているか（最古値 > 6分前値）
                    $b8FukuFirstAlt = 0.0;
                    $b8FukuLastAlt  = 0.0;
                    if (count($b10FukuVals) >= 4) {
                        foreach ($b10TimePoints as $b8mtc) {
                            if (isset($b10FukuVals[$b8mtc])) { $b8FukuFirstAlt = $b10FukuVals[$b8mtc]; break; }
                        }
                        $b8FukuLastAlt = $b10FukuVals[6] ?? 0.0;
                    }
                    $b8FukuDownAlt = ($b8FukuFirstAlt > 0 && $b8FukuLastAlt > 0 && $b8FukuLastAlt < $b8FukuFirstAlt);
                    $b8C6 = ($b8TanChangeRate < 0 && $b8FukuDownAlt);
                }
                // $b8InfoMap に保存（Block B-8 で参照）
                $b8InfoMap[$b10Num] = [
                    'pop' => $b10TanP,  // 単勝人気順位（null=不明）
                    'c1'  => $b8C1,     // 複勝3区間以上低下（null=不明/4時点未満）
                    'c2'  => $b8C2,     // 9→6分前も低下（null=不明）
                    'c5'  => $b8C5,     // 反発していない（null=不明）
                    'c6'  => $b8C6,     // 単複両方低下（null=不明）
                ];

//                 \Log::debug('[Block10] market_score', [
//                     'num'       => $b10Num,
//                     'score_a'   => $b10ScoreA,
//                     'score_b'   => $b10ScoreB,
//                     'score_c'   => $b10ScoreC,
//                     'score_d'   => $b10ScoreD,
//                     'score_e'   => $b10ScoreEPerHorse,
//                     'valid_max' => $b10ValidMax,
//                     'total'     => $b10TotalScore,
//                     'f1'        => $b10F1Active,
//                     'f5'        => $b10F5Active,
//                 ]);

                $b10Rows[] = [
                    'date'        => $date,
                    'kaisuu'      => (int)$kaisuu,
                    'basho'       => $raceRow->basho_name ?? '',
                    'basho_code'  => $basho,
                    'day'         => (int)$day,
                    'race'        => (int)$race,
                    'num'         => $b10Num,
                    'name'        => $b10h->name ?? null, // 実DDLの name 列（varchar(50)）へ格納
                    'score_a'     => $b10ScoreA,         // null=不明
                    'score_b'     => $b10ScoreB,         // null=不明
                    'score_c'     => $b10ScoreC,         // null=不明
                    'score_d'     => $b10ScoreD,         // null=不明
                    'score_e'     => $b10ScoreEPerHorse, // null=不明
                    'total_score' => $b10TotalScore,     // null=不明
                ];
                $b10TotalScoreMap[$b10Num] = $b10TotalScore; // B-10: 高配当総合点算出用
                $b10ScoreDMap[$b10Num]     = $b10ScoreD;     // 帯基準馬方式タイブレーク④用

                // ── B-13: 市場過小評価フラグ算出（シャドー期間: 算出・ログ保存のみ） ────
                // 条件1: 市場妙味基礎点56点以上（80点満点の70%）
                // 条件2: A複勝継続流入12点以上
                // 条件3: C予測補正OPI7点以上
                // 条件4: A〜Eのうち0点ではない有効根拠が3項目以上
                // 有効項目満点50点未満 → 不明（null）/ 4条件全成立 → true / それ以外 → false
                if ($b10ValidMax < 50) {
                    $b13Flag = null; // 不明（有効満点不足）
                } else {
                    $b13Cond1 = ($b10TotalScore !== null && $b10TotalScore >= 56);
                    $b13Cond2 = (is_int($b10ScoreA) && $b10ScoreA >= 12);
                    $b13Cond3 = (is_int($b10ScoreC) && $b10ScoreC >= 7);
                    $b13ValidCount = 0;
                    foreach ($b10ScoreMap as $b13v) {
                        if ($b13v !== null && $b13v > 0) $b13ValidCount++;
                    }
                    $b13Cond4 = ($b13ValidCount >= 3);
                    $b13Flag  = ($b13Cond1 && $b13Cond2 && $b13Cond3 && $b13Cond4);
                }
                $b13UnderevalFlagMap[$b10Num] = $b13Flag;
                $b13ScoreAMap[$b10Num]        = $b10ScoreA; // 複勝継続流入（馬券判定用）
//                 \Log::info('[B-13] 市場過小評価フラグ', [
//                     'date'        => $date,
//                     'kaisuu'      => $kaisuu,
//                     'basho_code'  => $basho,
//                     'day'         => $day,
//                     'race'        => $race,
//                     'num'         => $b10Num,
//                     'total_score' => $b10TotalScore,
//                     'score_a'     => $b10ScoreA,
//                     'score_c'     => $b10ScoreC,
//                     'valid_max'   => $b10ValidMax,
//                     'undereval'   => $b13Flag,
//                 ]);
            }

            // UPSERT（全馬まとめてバルク INSERT ... ON DUPLICATE KEY UPDATE）
            if (!empty($b10Rows)) {
                $b10PlaceHolders = implode(',', array_fill(0, count($b10Rows), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
                $b10Values       = [];
                // 【実DDL照合済み】t_horse_odds_finder_market_score_log の全列:
                //   id / date / kaisuu / basho / basho_code / day / race / num / name /
                //   score_a〜score_e / total_score / ability_grade / ability_score / high_score
                //   UNIQUE KEY uq_market_score (date,kaisuu,basho_code,day,race,num)
                // ここでは全出走馬の name と score_a〜e・total_score を保存する。
                // ability_grade / ability_score / high_score は AI 回答後（Block B-10）で
                //   確定するため、_saveHighPayoutShadow() から同一キーへ後追い UPDATE する。
                foreach ($b10Rows as $b10r) {
                    array_push($b10Values,
                        $b10r['date'],    $b10r['kaisuu'],  $b10r['basho'],
                        $b10r['basho_code'], $b10r['day'], $b10r['race'],
                        $b10r['num'],     $b10r['name'],
                        $b10r['score_a'], $b10r['score_b'], $b10r['score_c'],
                        $b10r['score_d'], $b10r['score_e'], $b10r['total_score']
                    );
                }
                DB::statement(
                    'INSERT INTO t_horse_odds_finder_market_score_log'
                    . ' (date,kaisuu,basho,basho_code,day,race,num,name,'
                    . '  score_a,score_b,score_c,score_d,score_e,total_score)'
                    . ' VALUES ' . $b10PlaceHolders
                    . ' ON DUPLICATE KEY UPDATE'
                    . '  basho=VALUES(basho), name=VALUES(name),'
                    . '  score_a=VALUES(score_a), score_b=VALUES(score_b),'
                    . '  score_c=VALUES(score_c), score_d=VALUES(score_d),'
                    . '  score_e=VALUES(score_e), total_score=VALUES(total_score)',
                    $b10Values
                );
            }
        } catch (\Throwable $b10e) {
            \Log::error('[Block10] market_score_log INSERT failed', ['err' => $b10e->getMessage()]);
        }
        // ── Block 10 End ──────────────────────────────────────────────────────────

        return compact('b8InfoMap', 'b10TotalScoreMap', 'b10ScoreDMap', 'b13UnderevalFlagMap', 'b13ScoreAMap', 'b10ScoreE');
    }

    /**
     * Block B-8: 偽流入警戒フラグ判定
     * 7番人気以下の馬について7条件のうち成立数を算出し fake_inflow_warning を付与する
     * シャドー期間中はログ保存のみ（候補からの実除外は本番有効化後）
     */
    private function _judgeFalseInflowFlags(
        array &$mergedHorses,
        array  $b8InfoMap,
        array  $b13UnderevalFlagMap,
        array  $horseFlagsMap,
        array  $b6TanPopMap,
        array  $b6FukuPopMap,
        string $date,
        int    $kaisuu,
        string $basho,
        int    $day,
        int    $race
    ): void {
        // ── Block B-8: 偽流入警戒フラグ判定（シャドー期間：ログ保存・フラグ付与のみ） ────────
        // 仕様: 7番人気以下の馬について7条件のうち成立数を算出する。
        //   3条件以上成立 → 有効流入（偽流入警戒フラグなし）
        //   2条件以下    → 偽流入警戒フラグON
        // 7条件（C7=市場過小評価フラグ: B-13算出済み → カウント対象）
        //   C1: 複勝が3区間以上で低下
        //   C2: 9分前→6分前も複勝が低下
        //   C3: 同人気帯の複勝流入ランク1〜2位（$horseFlagsMap['F3']）
        //   C4: 複勝人気順位が単勝人気順位より2順位以上高い（DB由来6分前人気で判定）
        //   C5: 急落後に計測開始値付近まで反発していない
        //   C6: 単勝と複勝の両方が計測開始→6分前で低下
        //   C7: 市場過小評価フラグが「あり」← 不明（カウント外）
        // シャドー期間中は mergedHorses に fake_inflow_warning フラグを付与し、
        // ログ保存のみ行う。高配当候補・馬券購入候補からの実際の除外は本番有効化後。
        foreach ($mergedHorses as &$b8mh) {
            $b8mhNum = (int)($b8mh['num']        ?? 0);
            $b8mhPop = (int)($b8mh['popularity'] ?? 0);

            // 7番人気未満は判定対象外（有効流入扱い）
            if ($b8mhPop < 7) {
                $b8mh['fake_inflow_warning'] = false;
                $b8mh['fake_inflow_true_cnt'] = null; // 対象外
                continue;
            }

            // 条件ごとに判定（null = 不明 → カウント外）
            $b8Info  = $b8InfoMap[$b8mhNum] ?? [];
            $b8Flags = $horseFlagsMap[$b8mhNum] ?? [];

            // C1: 複勝3区間以上低下
            $b8c1Val = $b8Info['c1'] ?? null;
            // C2: 9→6分前も低下
            $b8c2Val = $b8Info['c2'] ?? null;
            // C3: 同人気帯複勝流入ランク1〜2位（F3）
            $b8c3Val = isset($b8Flags['F3']) ? (bool)$b8Flags['F3'] : null;
            // F3が Block 6 で必ず bool 設定されているので null にはならないが念のため
            if (array_key_exists('F3', $b8Flags)) {
                $b8c3Val = (bool)$b8Flags['F3'];
            }
            // C4: 単勝人気順位 - 複勝人気順位 ≥ 2
            $b8c4TanP  = $b6TanPopMap[$b8mhNum]  ?? null;
            $b8c4FukuP = $b6FukuPopMap[$b8mhNum] ?? null;
            $b8c4Val   = ($b8c4TanP !== null && $b8c4FukuP !== null)
                         ? ($b8c4TanP - $b8c4FukuP >= 2)
                         : null;
            // C5: 急落後に計測開始値付近まで反発していない
            $b8c5Val = $b8Info['c5'] ?? null;
            // C6: 単複両方低下
            $b8c6Val = $b8Info['c6'] ?? null;
            // C7: 市場過小評価フラグ（B-13算出済み: true=あり / false=なし / null=不明）
            $b8c7Val = $b13UnderevalFlagMap[$b8mhNum] ?? null;

            // 成立数カウント（不明を除く）
            $b8TrueCount  = 0;
            $b8KnownCount = 0;
            foreach ([
                'c1' => $b8c1Val,
                'c2' => $b8c2Val,
                'c3' => $b8c3Val,
                'c4' => $b8c4Val,
                'c5' => $b8c5Val,
                'c6' => $b8c6Val,
                'c7' => $b8c7Val,
            ] as $b8ck => $b8cv) {
                if ($b8cv !== null) {
                    $b8KnownCount++;
                    if ($b8cv) $b8TrueCount++;
                }
            }

            // 2条件以下 → 偽流入警戒フラグON
            $b8FakeWarn = ($b8TrueCount <= 2);

            // mergedHorses にフラグ付与
            $b8mh['fake_inflow_warning']  = $b8FakeWarn;
            $b8mh['fake_inflow_true_cnt'] = $b8TrueCount;

            // シャドー期間: ログ保存のみ（候補からの除外は行わない）
//             \Log::info('[B-8] 偽流入警戒判定', [
//                 'date'        => $date,
//                 'kaisuu'      => $kaisuu,
//                 'basho_code'  => $basho,
//                 'day'         => $day,
//                 'race'        => $race,
//                 'num'         => $b8mhNum,
//                 'popularity'  => $b8mhPop,
//                 'true_count'  => $b8TrueCount,
//                 'known_count' => $b8KnownCount,
//                 'fake_warning'=> $b8FakeWarn,
//                 'conditions'  => [
//                     'c1_3area_decline'  => $b8c1Val,
//                     'c2_final_down'     => $b8c2Val,
//                     'c3_fuku_rank_top2' => $b8c3Val,
//                     'c4_fuku_pop_adv2'  => $b8c4Val,
//                     'c5_no_rebound'     => $b8c5Val,
//                     'c6_both_down'      => $b8c6Val,
//                     'c7_undereval_flag' => $b8c7Val, // B-13算出済み（null=不明）
//                 ],
//             ]);
        }
        unset($b8mh); // 参照変数の解放
        // ── Block B-8 End ────────────────────────────────────────────────────────
    }

    /**
     * Block B-10: シャドー期間中の高配当総合点・仮順位・馬券判定ログ保存
     * 高配当総合点（市場妙味基礎点＋能力適性補正、上限100点）を算出し
     * 仮順位・馬券判定（第1段階）・不一致馬分類とともにログ保存する
     */
    private function _saveHighPayoutShadow(
        array &$mergedHorses,
        array  $b10TotalScoreMap,
        array  $b13UnderevalFlagMap,
        array  $b13ScoreAMap,
        $b6OddsRows,
        array  $oddsHorseBlocks,
        string $gapTypeForMerge,
        array  $firstAiHorses,
        array  $secondAiHorses,
        array  $horseFlagsMap,
        string $date,
        int    $kaisuu,
        string $basho,
        int    $day,
        int    $race,
        object $raceRow
    ): void {
        // ── Block B-10: シャドー期間中の高配当総合点・仮順位・馬券判定ログ保存 ──────────
        // 仕様: AI回答後にPHPが高配当総合点（市場妙味基礎点＋能力適性補正、上限100点）を算出し、
        //   仮順位・馬券判定（第1段階）・不一致馬A〜D分類とともにログ保存する。
        //   シャドー期間中はFlutterへ出力しない。B-13算出済みのため
        //   馬券判定（第1段階）を実条件で評価してログ保存する。
        // テーブル: t_horse_odds_finder_high_payout_shadow_log
        {
            // ── 馬券判定用: 推定確定オッズ等を $oddsHorseBlocks テキストからパース ────
            // ※ $promptHorses は _getAiAnalysisPrompt() のローカル変数のためここでは使えない。
            // ※ $oddsData（プロンプトテキスト）に埋め込まれた値を正規表現で抽出する。
            $b13OddsMap = [];
            foreach ($b6OddsRows as $_b13or) {
                $_b13Num   = (int)$_b13or->num;
                $_b13Block = $oddsHorseBlocks[$_b13Num] ?? '';

                // 推定確定単勝オッズ: 「推定確定オッズ: X.X（補正係数...」
                $_b13EstTanVal = null;
                if (preg_match('/推定確定オッズ: ([\d.]+)/u', $_b13Block, $_b13m1)) {
                    $_b13EstTanVal = (float)$_b13m1[1];
                }

                // 推定確定複勝最小: 「推定確定複勝最小: X.X（補正係数...」
                $_b13EstFukuVal = null;
                if (preg_match('/推定確定複勝最小: ([\d.]+)/u', $_b13Block, $_b13m2)) {
                    $_b13EstFukuVal = (float)$_b13m2[1];
                }

                // 単勝下落率（正=下落）: 変化率が負（下落）→ 正に変換して tan_shrink_rate とする
                $_b13TanShrinkVal = null;
                if (preg_match('/変化率（計測開始→6分前）: ([+\-][\d.]+)%/u', $_b13Block, $_b13m3)) {
                    $_b13TanShrinkVal = -1.0 * (float)$_b13m3[1]; // 負=下落 → 正=下落に変換
                }

                $b13OddsMap[$_b13Num] = [
                    'estimated_final_odds'    => $_b13EstTanVal,
                    'estimated_final_fuku_min'=> $_b13EstFukuVal,
                    'fuku_min_6'              => (float)$_b13or->fuku_min ?: null,
                    'tan_shrink_rate'         => $_b13TanShrinkVal, // 正=単勝下落
                ];
            }

            $bB10AbilityCorrMap   = ['A' => 20, 'B' => 14, 'C' => 7, 'D' => 0];
            $bB10AbilityGradeRgx  = '/^能力適性:([ABCD])[（(](\d+)点[）)]/u';

            // ── 1st AI馬のability_grade/ability_scoreをreasonから抽出してマップ化 ──
            $bB10FirstAbMap = [];
            foreach ($firstAiHorses as $bB10fh) {
                $bB10fGrade = null;
                $bB10fCorr  = null;
                if (preg_match($bB10AbilityGradeRgx, $bB10fh['reason'], $bB10fm)) {
                    $bB10fGrade = $bB10fm[1];
                    $bB10fCorr  = $bB10AbilityCorrMap[$bB10fGrade] ?? 0;
                }
                $bB10FirstAbMap[(int)$bB10fh['num']] = ['grade' => $bB10fGrade, 'corr' => $bB10fCorr];
            }

            // ── 2nd AI馬のability_grade/ability_score（B-9で設定済み）をマップ化 ──
            $bB10SecondAbMap = [];
            foreach ($secondAiHorses as $bB10sh) {
                $bB10SecondAbMap[(int)$bB10sh['num']] = [
                    'grade' => $bB10sh['ability_grade'] ?? null,
                    'corr'  => $bB10sh['ability_score'] ?? null,
                ];
            }

            // ── 各mergedHorseの高配当総合点・不一致分類・馬券判定を算出 ──────────────
            $bB10HpRows = [];
            foreach ($mergedHorses as &$bB10h) {
                $bB10Num  = (int)($bB10h['num']        ?? 0);
                $bB10Cat  = $bB10h['category']          ?? '';
                $bB10Pop  = (int)($bB10h['popularity'] ?? 0);
                $bB10Mkt  = $b10TotalScoreMap[$bB10Num] ?? null; // 市場妙味基礎点

                // 能力適性補正の算出（category別）
                $bB10AbGrade = null;
                $bB10AbCorr  = null; // null = 不明

                if ($bB10Cat === 'matched') {
                    // 両AI一致馬: 両AIのcorrの平均（四捨五入）→ 補正値
                    $bB10fAb = $bB10FirstAbMap[$bB10Num]  ?? null;
                    $bB10sAb = $bB10SecondAbMap[$bB10Num] ?? null;
                    if ($bB10fAb !== null && $bB10fAb['corr'] !== null
                     && $bB10sAb !== null && $bB10sAb['corr'] !== null) {
                        $bB10AbCorr = (int) round(($bB10fAb['corr'] + $bB10sAb['corr']) / 2);
                        // グレード: 両AI同一グレードなら記録、異なればnull
                        if ($bB10fAb['grade'] !== null && $bB10fAb['grade'] === $bB10sAb['grade']) {
                            $bB10AbGrade = $bB10fAb['grade'];
                        }
                    }
                } elseif ($bB10Cat === 'first_only') {
                    $bB10fAb = $bB10FirstAbMap[$bB10Num] ?? null;
                    if ($bB10fAb !== null && $bB10fAb['corr'] !== null) {
                        $bB10AbGrade = $bB10fAb['grade'];
                        $bB10AbCorr  = $bB10fAb['corr'];
                    }
                } else { // second_only
                    $bB10sAb = $bB10SecondAbMap[$bB10Num] ?? null;
                    if ($bB10sAb !== null && $bB10sAb['corr'] !== null) {
                        $bB10AbGrade = $bB10sAb['grade'];
                        $bB10AbCorr  = $bB10sAb['corr'];
                    }
                }

                // 高配当総合点: 市場妙味基礎点 + 能力適性補正、上限100点
                // どちらかがnull → 高配当総合点もnull（不明）
                $bB10HpScore = null;
                if ($bB10Mkt !== null && $bB10AbCorr !== null) {
                    $bB10HpScore = min(100, $bB10Mkt + $bB10AbCorr);
                }

                // 不一致馬A〜D分類（matched馬は対象外）
                // B-13算出済みの市場過小評価フラグを使用
                $bB10MismatchCat   = null;
                $bB10UnderevalFlag = $b13UnderevalFlagMap[$bB10Num] ?? null; // B-13算出済み
                if ($bB10Cat !== 'matched') {
                    $bB10FakeWarn    = $bB10h['fake_inflow_warning'] ?? null;
                    $bB10ValidInflow = ($bB10FakeWarn === false);
                    $bB10GradeAB     = in_array($bB10AbGrade, ['A', 'B'], true);

                    if ($bB10GradeAB && $bB10UnderevalFlag === true && $bB10ValidInflow
                        && (int)($bB10h['score'] ?? 0) >= 70) {
                        $bB10MismatchCat = 'A';
                    } elseif ($bB10UnderevalFlag === true && $bB10ValidInflow) {
                        $bB10MismatchCat = 'B';
                    } elseif ($bB10GradeAB) {
                        $bB10MismatchCat = 'C'; // 能力適性A/Bだが市場過小評価なし/不明
                    } else {
                        $bB10MismatchCat = 'D';
                    }
                }

                // 馬券判定（第1段階）B-13算出済みのため実条件で評価
                // 単勝: 高配当総合点70点以上 + 市場過小評価あり + 単勝下落 + 推定確定単勝3.0倍以上
                // 複勝: 高配当総合点70点以上 + 市場過小評価あり + 複勝継続流入12点以上 + 推定確定複勝最小1.5倍以上
                $bB13OdData   = $b13OddsMap[$bB10Num] ?? [];
                $bB13EstTan   = $bB13OdData['estimated_final_odds'] ?? null;
                $bB13FukuMin  = $bB13OdData['estimated_final_fuku_min'] ?? null; // 推定確定複勝最小（仕様書準拠: null時は欠損扱い・6分前複勝への自動置換禁止）
                $bB13TanShrk  = $bB13OdData['tan_shrink_rate']      ?? null; // 正=単勝下落
                $bB13ScoreA   = $b13ScoreAMap[$bB10Num]               ?? null;

                $bB10TanOk = ($bB10HpScore !== null && $bB10HpScore >= 70)
                          && ($bB10UnderevalFlag === true)
                          && ($bB13TanShrk !== null && $bB13TanShrk > 0)
                          && ($bB13EstTan  !== null && $bB13EstTan  >= 3.0);

                $bB10FukuOk = ($bB10HpScore !== null && $bB10HpScore >= 70)
                           && ($bB10UnderevalFlag === true)
                           && (is_int($bB13ScoreA) && $bB13ScoreA >= 12)
                           && ($bB13FukuMin !== null && $bB13FukuMin >= 1.5);

                if ($bB10TanOk && $bB10FukuOk) {
                    $bB10BettingJudge = '単複';
                } elseif ($bB10TanOk) {
                    $bB10BettingJudge = '単勝';
                } elseif ($bB10FukuOk) {
                    $bB10BettingJudge = '複勝';
                } else {
                    $bB10BettingJudge = '監視';
                }

                // mergedHorsesに付与（シャドー値）
                $bB10h['high_payout_score']    = $bB10HpScore;
                $bB10h['market_score']         = $bB10Mkt;
                $bB10h['ability_grade_merged'] = $bB10AbGrade;
                $bB10h['ability_corr_merged']  = $bB10AbCorr;
                $bB10h['mismatch_category']    = $bB10MismatchCat;
                $bB10h['betting_judgment']     = $bB10BettingJudge;

                $bB10HpRows[] = [
                    'num'              => $bB10Num,
                    'name'             => $bB10h['name'] ?? '',
                    'popularity'       => $bB10Pop,
                    'category'         => $bB10Cat,
                    'market_score'     => $bB10Mkt,
                    'ability_grade'    => $bB10AbGrade,
                    'ability_corr'     => $bB10AbCorr,
                    'high_payout_score'=> $bB10HpScore,
                    'mismatch_cat'     => $bB10MismatchCat,
                    'fake_warn'        => ($bB10h['fake_inflow_warning'] ?? null) === true ? 1 : 0,
                    'undereval_flag'   => $bB10UnderevalFlag, // B-13算出済み（null=不明/true=あり/false=なし）
                    'betting_judgment' => $bB10BettingJudge,
                ];
            }
            unset($bB10h);

            // ── 仮順位の決定（高配当総合点降順、nullは後ろ、同点→matched優先、次に馬番小）
            usort($bB10HpRows, function ($x, $y) {
                $xs = $x['high_payout_score'];
                $ys = $y['high_payout_score'];
                // null（不明）は後ろ
                if ($xs !== null && $ys === null) return -1;
                if ($xs === null && $ys !== null) return  1;
                // 両方null or 両方有効値
                if ($xs !== $ys) return ($ys ?? 0) <=> ($xs ?? 0); // 降順
                // 同点: matched優先
                $xm = ($x['category'] === 'matched') ? 0 : 1;
                $ym = ($y['category'] === 'matched') ? 0 : 1;
                if ($xm !== $ym) return $xm <=> $ym;
                return $x['num'] <=> $y['num']; // 馬番昇順
            });

            // ── DB保存（t_horse_odds_finder_high_payout_shadow_log）────────────────
            try {
                if (!empty($bB10HpRows)) {
                    $bB10shPh  = implode(',', array_fill(0, count($bB10HpRows), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
                    $bB10shVal = [];
                    foreach (array_values($bB10HpRows) as $bB10rank => $bB10hpr) {
                        array_push($bB10shVal,
                            $date,                (int)$kaisuu,          $raceRow->basho_name ?? '',
                            $basho,               (int)$day,             (int)$race,
                            $bB10hpr['num'],      $bB10hpr['name'],      $bB10hpr['popularity'],
                            $bB10hpr['category'],
                            $bB10hpr['market_score'],  $bB10hpr['ability_grade'],
                            $bB10hpr['ability_corr'],  $bB10hpr['high_payout_score'],
                            $bB10rank + 1,             // shadow_rank（1始まり）
                            $bB10hpr['mismatch_cat'],
                            $bB10hpr['fake_warn'],
                            isset($bB10hpr['undereval_flag']) ? (int)$bB10hpr['undereval_flag'] : null, // B-13算出済み
                            $bB10hpr['betting_judgment']
                        );
                    }
                    DB::statement(
                        'INSERT INTO t_horse_odds_finder_high_payout_shadow_log'
                        . ' (date,kaisuu,basho,basho_code,day,race,num,name,popularity,category,'
                        . '  market_score,ability_grade,ability_corr,high_payout_score,'
                        . '  shadow_rank,mismatch_category,fake_inflow_warning,undereval_flag,betting_judgment)'
                        . ' VALUES ' . $bB10shPh
                        . ' ON DUPLICATE KEY UPDATE'
                        . '  market_score=VALUES(market_score),'
                        . '  ability_grade=VALUES(ability_grade),'
                        . '  ability_corr=VALUES(ability_corr),'
                        . '  high_payout_score=VALUES(high_payout_score),'
                        . '  shadow_rank=VALUES(shadow_rank),'
                        . '  mismatch_category=VALUES(mismatch_category),'
                        . '  fake_inflow_warning=VALUES(fake_inflow_warning),'
                        . '  undereval_flag=VALUES(undereval_flag),'
                        . '  betting_judgment=VALUES(betting_judgment)',
                        $bB10shVal
                    );
//                     \Log::info('[B-10] high_payout_shadow_log saved', [
//                         'date'       => $date,    'kaisuu' => $kaisuu,
//                         'basho_code' => $basho,   'day'    => $day,
//                         'race'       => $race,    'count'  => count($bB10HpRows),
//                         'top_score'  => $bB10HpRows[0]['high_payout_score'] ?? null,
//                     ]);
                }
            } catch (\Throwable $bB10she) {
                \Log::error('[B-10] high_payout_shadow_log INSERT failed', ['err' => $bB10she->getMessage()]);
            }

            // ── market_score_log の後追い更新（ability_grade / ability_score / high_score）──
            // 【理由】Block 10（AI呼び出し前）の時点では能力適性グレードと高配当総合点は
            //   まだ確定していないため、market_score_log の該当3列が常に NULL のままだった。
            //   実DDLに専用列が存在するので、確定した時点で同一ユニークキーへ書き戻す。
            //   UNIQUE KEY uq_market_score (date,kaisuu,basho_code,day,race,num) で一意に定まる。
            //   対象は統合候補馬のみ（全出走馬ではない）。
            try {
                $bB10MsRows = [];
                foreach ($bB10HpRows as $bB10ms) {
                    // 3列とも不明なら更新対象にしない
                    if ($bB10ms['ability_grade'] === null
                        && $bB10ms['ability_corr'] === null
                        && $bB10ms['high_payout_score'] === null) {
                        continue;
                    }
                    $bB10MsRows[] = $bB10ms;
                }
                if (!empty($bB10MsRows)) {
                    $bB10MsPh  = implode(',', array_fill(0, count($bB10MsRows), '(?,?,?,?,?,?,?,?,?)'));
                    $bB10MsVal = [];
                    foreach ($bB10MsRows as $bB10ms) {
                        // high_score は tinyint unsigned（0〜100）。範囲外は保存しない。
                        $bB10MsHigh = $bB10ms['high_payout_score'];
                        if ($bB10MsHigh !== null) {
                            $bB10MsHigh = (int) round($bB10MsHigh);
                            if ($bB10MsHigh < 0 || $bB10MsHigh > 100) { $bB10MsHigh = null; }
                        }
                        // ability_score は tinyint unsigned（A=20/B=14/C=7/D=0）。
                        $bB10MsAbil = $bB10ms['ability_corr'];
                        if ($bB10MsAbil !== null) {
                            $bB10MsAbil = (int) round($bB10MsAbil);
                            if ($bB10MsAbil < 0 || $bB10MsAbil > 255) { $bB10MsAbil = null; }
                        }
                        array_push($bB10MsVal,
                            $date, (int) $kaisuu, $basho, (int) $day, (int) $race,
                            $bB10ms['num'], $bB10ms['ability_grade'], $bB10MsAbil, $bB10MsHigh
                        );
                    }
                    // 行は Block 10 で作成済みのため通常は UPDATE 側が走る。
                    // 万一 Block 10 の保存に失敗していた場合に備えて INSERT 形で書く。
                    DB::statement(
                        'INSERT INTO t_horse_odds_finder_market_score_log'
                        . ' (date,kaisuu,basho_code,day,race,num,'
                        . '  ability_grade,ability_score,high_score)'
                        . ' VALUES ' . $bB10MsPh
                        . ' ON DUPLICATE KEY UPDATE'
                        . '  ability_grade=VALUES(ability_grade),'
                        . '  ability_score=VALUES(ability_score),'
                        . '  high_score=VALUES(high_score)',
                        $bB10MsVal
                    );
//                     \Log::info('[B-10] market_score_log ability/high_score updated', [
//                         'date'       => $date,  'kaisuu' => $kaisuu,
//                         'basho_code' => $basho, 'day'    => $day,
//                         'race'       => $race,  'count'  => count($bB10MsRows),
//                     ]);
                }
            } catch (\Throwable $bB10mse) {
                \Log::error('[B-10] market_score_log UPDATE failed', ['err' => $bB10mse->getMessage()]);
            }
        }
        // ── Block B-10 End ──────────────────────────────────────────────────────────
    }








    /**
     * 学習・評価結果の保存（評価指標の保存先）
     *
     * 仕様で求められている評価指標（M1〜M3 の 適合率・再現率・F1・ROC-AUC・
     * PR-AUC・Brier・校正誤差／M4 の 5着以内率・3着以内率・単複回収率・
     * 平均配当・中央値配当・最大連敗・最大値除外後回収率・平均選出頭数を揃えた比較）は、
     * runMlPipeline() が算出したあと、本メソッドで JSON ファイルとして保存する。
     *
     * 【保存先をファイルにしている理由】
     *   評価結果を格納する専用テーブルが存在しないため。勝手にテーブルを新設したり、
     *   存在しない列へ書いたりすると保存が落ちる（ai_merge_result で実際に起きた）。
     *   保存先テーブルが決まれば、本メソッドの中だけを DB 保存へ差し替えれば移行できる。
     *   呼び出し側も返り値の形も変えずに済むよう、保存処理はここに閉じてある。
     *
     * 保存パス: storage/app/ml_evaluation/YYYYMMDD_HHMMSS_<hash8>.json
     *   latest.json は常に最新の評価結果を指す（人が確認しやすいように）。
     *
     * @return array{saved:bool, path:?string, latest:?string, reason:?string}
     */
    private function _saveMlEvaluation(array $result): array
    {
        try {
            $seBase = function_exists('storage_path')
                ? storage_path('app/ml_evaluation')
                : rtrim(sys_get_temp_dir(), '/') . '/ml_evaluation';
            if (!is_dir($seBase) && !@mkdir($seBase, 0775, true) && !is_dir($seBase)) {
                return ['saved' => false, 'path' => null, 'latest' => null,
                        'reason' => '保存ディレクトリを作成できない: ' . $seBase];
            }
            // JSON_PRESERVE_ZERO_FRACTION: 70.0 が 70 に化けると「整数の回収率」に見えるため
            $seJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                         | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION
                                         | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($seJson === false) {
                return ['saved' => false, 'path' => null, 'latest' => null,
                        'reason' => 'JSONへ変換できない'];
            }
            $seName   = date('Ymd_His') . '_' . substr(hash('sha256', $seJson), 0, 8) . '.json';
            $sePath   = $seBase . '/' . $seName;
            $seLatest = $seBase . '/latest.json';
            if (@file_put_contents($sePath, $seJson) === false) {
                return ['saved' => false, 'path' => null, 'latest' => null,
                        'reason' => '書き込みに失敗: ' . $sePath];
            }
            @file_put_contents($seLatest, $seJson);

//             \Log::info('[Block11] 評価結果を保存', [
//                 'path'  => $sePath,
//                 'bytes' => strlen($seJson),
//                 'models'=> array_keys($result['evaluation'] ?? []),
//             ]);
            return ['saved' => true, 'path' => $sePath, 'latest' => $seLatest, 'reason' => null];
        } catch (\Throwable $seE) {
            \Log::error('[Block11] 評価結果の保存に失敗', ['err' => $seE->getMessage()]);
            return ['saved' => false, 'path' => null, 'latest' => null,
                    'reason' => $seE->getMessage()];
        }
    }

    /**
     * 断層学習パイプライン（Phase2以降で実行する学習・評価の一連処理）
     *
     * 呼び出し元: バッチ処理（artisan コマンド等）から public メソッドとして実行する。
     *   予測リクエストの経路からは呼ばない（学習は予測と切り離す）。
     *
     * これまで個別に実装していた各機能を、仕様の順序どおりに接続する。
     *   ① 時系列分割 70/15/15（日付順・ランダム分割禁止）
     *   ② カテゴリ辞書を【学習期間だけ】で作成
     *   ③ train 区分で M1〜M4 を GBDT 学習
     *   ④ validation 区分で確率校正（Platt scaling）
     *   ⑤ test 区分（未使用評価）で評価指標を算出
     *   ⑥ 現行版と学習版を並行比較
     *   ⑦ モデルをレジストリへ登録（旧モデルは残し、切戻し可能にする）
     *
     * 【重要】本メソッドは学習と評価を行うだけで、本番へは一切反映しない。
     *   本番候補・本番順位・Flutter表示への反映は、明示的な許可が出てから
     *   別途コードを変更して行う。戻り値の production_reflected は常に false。
     *
     * Phase1（1,000レース未満）では学習を行わず、理由つきで skipped を返す。
     *
     * @param  array $labeledRaces 学習用データ
     *        [['key'=>string, 'features'=>array, 'labels'=>['m1'=>int,...],
     *          'horse_labels'=>[num=>int], 'meta'=>[...]], ...]
     * @return array パイプラインの実行結果
     */
    public function runMlPipeline(array $labeledRaces = []): array
    {
        $plPhase = $this->_getMlPhase();

        if (!$plPhase['allow_training']) {
            return [
                'status' => 'skipped',
                'reason' => 'Phase1（1,000レース未満）のため学習を行わない',
                'phase'  => $plPhase,
                'production_reflected' => false,
            ];
        }
        if (empty($labeledRaces)) {
            return [
                'status' => 'skipped',
                'reason' => '正解ラベル付きデータが渡されていない',
                'phase'  => $plPhase,
                'production_reflected' => false,
            ];
        }

        // ── ① 時系列分割（日付順 70/15/15）──────────────────────────────
        $plSplit = $this->_assignTimeSeriesSplit(null);
        $plIdx   = ['train' => [], 'validation' => [], 'test' => []];
        foreach ($labeledRaces as $plI => $plR) {
            $plKey = (string)($plR['key'] ?? '');
            foreach (['train', 'validation', 'test'] as $plSeg) {
                if (in_array($plKey, $plSplit[$plSeg] ?? [], true)) { $plIdx[$plSeg][] = $plI; break; }
            }
        }

        // ── ② カテゴリ辞書は学習期間だけで作成 ──────────────────────────
        $plDict = null;
        if (!empty($plIdx['train'])) {
            $plTrainKeys = array_map(fn($i) => (string)$labeledRaces[$i]['key'], $plIdx['train']);
            sort($plTrainKeys);
            $plFrom = explode('_', $plTrainKeys[0])[0];
            $plTo   = explode('_', end($plTrainKeys))[0];
            $plDict = $this->_buildCategoryDict($plFrom, $plTo);
        }

        // ── ③ M1〜M3（レース単位）と M4（馬単位）を GBDT で学習 ──────────
        $plModels = []; $plCalib = []; $plEval = [];
        foreach (['m1', 'm2', 'm3'] as $plM) {
            $plX = []; $plY = [];
            foreach ($plIdx['train'] as $plI) {
                $plLab = $labeledRaces[$plI]['labels'][$plM] ?? null;
                if ($plLab === null) continue;   // 結果未確定は学習に使わない
                $plVec = $this->_vectorizeFeatures($labeledRaces[$plI]['features']);
                $plX[] = $plVec['race']; $plY[] = (int)$plLab;
            }
            $plModels[strtoupper($plM)] = $this->_trainGbdtModel($plX, $plY);
        }
        // M4: 馬単位
        $plX4 = []; $plY4 = []; $plMeta4 = [];
        foreach ($plIdx['train'] as $plI) {
            $plVec = $this->_vectorizeFeatures($labeledRaces[$plI]['features']);
            foreach (($labeledRaces[$plI]['horse_labels'] ?? []) as $plNum => $plLab) {
                if (!isset($plVec['horses'][$plNum])) continue;
                $plX4[] = $plVec['horses'][$plNum]; $plY4[] = (int)$plLab;
            }
        }
        $plModels['M4'] = $this->_trainGbdtModel($plX4, $plY4);

        // ── ④ validation 区分で確率校正（train/test は使わない）──────────
        foreach (['M1', 'M2', 'M3'] as $plM) {
            if (($plModels[$plM]['metrics']['status'] ?? '') !== 'trained') continue;
            $plS = []; $plL = [];
            foreach ($plIdx['validation'] as $plI) {
                $plLab = $labeledRaces[$plI]['labels'][strtolower($plM)] ?? null;
                if ($plLab === null) continue;
                $plVec = $this->_vectorizeFeatures($labeledRaces[$plI]['features']);
                $plP   = $this->_predictGbdt($plModels[$plM], $plVec['race']);
                $plS[] = $plP['probability'] * 100.0; $plL[] = (int)$plLab;
            }
            $plCalib[$plM] = $this->_calibrateProbabilities($plS, $plL);
        }

        // ── ⑤ test（未使用評価）で評価指標を算出 ────────────────────────
        // 仕様: ROC-AUC / PR-AUC / 人気帯別回収率 / 最大連敗 / 断層タイプ別
        foreach (['M1', 'M2', 'M3'] as $plM) {
            if (($plModels[$plM]['metrics']['status'] ?? '') !== 'trained') continue;
            $plP2 = []; $plL2 = []; $plMt = [];
            foreach ($plIdx['test'] as $plI) {
                $plLab = $labeledRaces[$plI]['labels'][strtolower($plM)] ?? null;
                if ($plLab === null) continue;
                $plVec = $this->_vectorizeFeatures($labeledRaces[$plI]['features']);
                $plPr  = $this->_predictGbdt($plModels[$plM], $plVec['race']);
                $plP2[] = $plPr['probability']; $plL2[] = (int)$plLab;
                $plMt[] = $labeledRaces[$plI]['meta'] ?? [];
            }
            $plEval[$plM] = $this->_evaluateMlModel($plP2, $plL2, $plMt);
        }
        // M4 の評価（仕様: 5着以内率 / 3着以内率 / 単複回収率 / 平均配当 /
        //   中央値配当 / 最大連敗 / 最大値除外後回収率 / 平均選出頭数を揃えた比較）
        if (($plModels['M4']['metrics']['status'] ?? '') === 'trained') {
            $plP4 = []; $plL4 = []; $plM4t = []; $plCurSel = [];
            foreach ($plIdx['test'] as $plI) {
                $plKey = (string)($labeledRaces[$plI]['key'] ?? '');
                $plVec = $this->_vectorizeFeatures($labeledRaces[$plI]['features']);
                // 現行版がこのレースで選んでいる馬番（平均選出頭数を揃えるために使う）
                $plCurSel[$plKey] = array_map('intval',
                    (array)($labeledRaces[$plI]['current_ranking'] ?? []));
                foreach (($labeledRaces[$plI]['horse_labels'] ?? []) as $plNum => $plLab) {
                    if (!isset($plVec['horses'][$plNum])) continue;
                    $plPr = $this->_predictGbdt($plModels['M4'], $plVec['horses'][$plNum]);
                    $plP4[] = $plPr['probability']; $plL4[] = (int)$plLab;
                    $plHm = $labeledRaces[$plI]['horse_meta'][$plNum] ?? [];
                    $plHm['race_key'] = $plKey;
                    $plHm['num']      = (int)$plNum;
                    $plM4t[] = $plHm;
                }
            }
            $plEval['M4'] = $this->_evaluateM4Model($plP4, $plL4, $plM4t, $plCurSel);
        }

        // ── ⑥ 現行版と学習版の並行比較 ──────────────────────────────────
        $plCompare = null;
        if (!empty($plIdx['test']) && ($plModels['M4']['metrics']['status'] ?? '') === 'trained') {
            $plI0   = $plIdx['test'][0];
            $plVec0 = $this->_vectorizeFeatures($labeledRaces[$plI0]['features']);
            // 現行版の順位（統合おすすめ度の降順＝保存済みの並び）
            $plCur = [];
            foreach (($labeledRaces[$plI0]['current_ranking'] ?? []) as $plRk => $plNum) {
                $plCur[] = ['num' => (int)$plNum, 'rank' => $plRk + 1];
            }
            // 学習版の順位（M4確率の降順）
            $plProbs = [];
            foreach ($plVec0['horses'] as $plNum => $plHv) {
                $plProbs[$plNum] = $this->_predictGbdt($plModels['M4'], $plHv)['probability'];
            }
            arsort($plProbs);
            $plLrn = []; $plRk2 = 1;
            foreach ($plProbs as $plNum => $_p) $plLrn[] = ['num' => (int)$plNum, 'rank' => $plRk2++];
            if (!empty($plCur)) $plCompare = $this->_compareModelVersions($plCur, $plLrn);
        }

        // ── ⑦ レジストリへ登録（旧モデルは残す＝切戻し可能）──────────────
        $plRegistry = $this->_buildModelRegistry('');
        $plRegistry['active']['models']             = $plModels;
        $plRegistry['active']['model_hash']         = hash('sha256', json_encode(
            array_map(fn($m) => $m['model_hash'] ?? '', $plModels)
        ));
        $plRegistry['active']['train_sample_count'] = count($plIdx['train']);
        $plRegistry['active']['phase']              = $plPhase['phase'];
        $plRegistry['active']['category_dict']      = $plDict['meta'] ?? null;

//         \Log::info('[Block11] 学習パイプライン実行（本番未反映）', [
//             'phase'      => $plPhase['phase'],
//             'split'      => array_map('count', $plIdx),
//             'trained'    => array_map(fn($m) => $m['metrics']['status'] ?? '', $plModels),
//         ]);

        $plResult = [
            'status'     => 'completed',
            'phase'      => $plPhase,
            'split'      => ['train' => count($plIdx['train']),
                             'validation' => count($plIdx['validation']),
                             'test' => count($plIdx['test'])],
            'category_dict' => $plDict['meta'] ?? null,
            'models'     => array_map(fn($m) => $m['metrics'], $plModels),
            'calibration'=> $plCalib,
            'evaluation' => $plEval,
            'comparison' => $plCompare,
            'registry'   => $plRegistry,
            // 学習・評価するだけ。本番へは反映しない
            'production_reflected' => false,
            'note' => '学習・校正・評価・比較まで実施。本番候補・順位・表示への反映は別途許可が必要',
        ];

        // 評価指標をファイルへ保存する（保存先は _saveMlEvaluation() のコメント参照）
        $plResult['evaluation_saved'] = $this->_saveMlEvaluation($plResult);

        return $plResult;
    }

    /**
     * レース結果確定後のラベル書き込み
     *
     * 呼び出し元: レース結果が確定した後のバッチ処理から public メソッドとして実行する。
     *
     * _calcMlLabels() で M1〜M4 の正解ラベルを算出し、
     * 保存済みスナップショットの features_json へ result_label として追記する。
     * 予測時点ではラベルが存在しないため、本処理で後から埋める。
     */
    public function writeMlLabels(string $date, int $kaisuu, string $basho, int $day, int $race): array
    {
        $wlLabels = $this->_calcMlLabels($date, $kaisuu, $basho, $day, $race);
        if (($wlLabels['detail']['status'] ?? '') !== 'confirmed') {
            return ['status' => 'pending', 'reason' => $wlLabels['detail']['reason'] ?? '結果未確定'];
        }
        try {
            $wlRow = DB::table('t_horse_odds_finder_ml_snapshot')
                ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho_code', $basho)
                ->where('day', $day)->where('race', $race)->first();
            if (!$wlRow || empty($wlRow->features)) {
                return ['status' => 'skipped', 'reason' => 'スナップショットが未保存'];
            }
            // 【実DDL照合済み】t_horse_odds_finder_ml_snapshot の専用列:
            //   result_m1 / result_m2 / result_m3 (tinyint(1)) /
            //   result_m4_json (json) / result_label_status (varchar(10))
            //   result_label_status の取りうる値は実DDLのコメントどおり
            //   filled / excluded / skipped とする。
            //   万一の列差異に備え、UPDATE は features のみのフォールバックを残す。
            $wlFeat = json_decode($wlRow->features, true);
            $wlFeat['result_label'] = [
                'm1' => $wlLabels['m1'], 'm2' => $wlLabels['m2'], 'm3' => $wlLabels['m3'],
                'm4' => $wlLabels['m4'], 'detail' => $wlLabels['detail'],
                'written_at' => date('Y-m-d H:i:s'),
            ];
            $wlFeatJson = json_encode($wlFeat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            try {
                DB::statement(
                    'UPDATE t_horse_odds_finder_ml_snapshot'
                    . ' SET features = ?, result_m1 = ?, result_m2 = ?, result_m3 = ?,'
                    . '     result_m4_json = ?, result_label_status = ?'
                    . ' WHERE date = ? AND kaisuu = ? AND basho_code = ? AND day = ? AND race = ?',
                    [
                        $wlFeatJson,
                        $wlLabels['m1'], $wlLabels['m2'], $wlLabels['m3'],
                        json_encode($wlLabels['m4'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'filled', // 実DDLのコメントに合わせる（filled / excluded / skipped）
                        $date, $kaisuu, $basho, $day, $race,
                    ]
                );
            } catch (\Throwable $wlColE) {
                // 専用列が存在しない環境では features だけでも確実に残す
//                 \Log::warning('[Block11] 専用列へのUPDATEに失敗。featuresのみ更新する', [
//                     'err' => $wlColE->getMessage(),
//                 ]);
                DB::statement(
                    'UPDATE t_horse_odds_finder_ml_snapshot SET features = ?'
                    . ' WHERE date = ? AND kaisuu = ? AND basho_code = ? AND day = ? AND race = ?',
                    [$wlFeatJson, $date, $kaisuu, $basho, $day, $race]
                );
            }
//             \Log::info('[Block11] result_label 書き込み', [
//                 'race' => "{$date}_{$kaisuu}_{$basho}_{$day}_{$race}",
//                 'm1' => $wlLabels['m1'], 'm2' => $wlLabels['m2'], 'm3' => $wlLabels['m3'],
//             ]);
            return ['status' => 'written', 'labels' => $wlLabels];
        } catch (\Throwable $wlE) {
            \Log::error('[Block11] result_label 書き込み失敗', ['err' => $wlE->getMessage()]);
            return ['status' => 'error', 'reason' => $wlE->getMessage()];
        }
    }

    /**
     * 決定木系 勾配ブースティング（GBDT）：学習
     *
     * 仕様（最新確定版）:
     *   「欠損と非線形な組合せを扱える決定木系勾配ブースティングを基本とする」
     *
     * 実装方式: XGBoost 系の二次近似を用いた勾配ブースティング決定木。
     *   損失は logloss。各反復で勾配 g = p - y、ヘシアン h = p(1-p) を求め、
     *   利得 Gain = GL^2/(HL+λ) + GR^2/(HR+λ) - (GL+GR)^2/(HL+HR+λ) - γ
     *   を最大化する分割を選ぶ。葉の重みは w = -G/(H+λ)。
     *
     * 【欠損値の扱い（仕様: 0へ置換しない）】
     *   特徴量の欠損は null のまま受け取る。0で埋めない。
     *   各分割で「欠損を左へ送るか右へ送るか」を利得が大きい方に学習し、
     *   ノードに missing_left として保存する。
     *   これが決定木系を使う理由そのもので、線形モデルでは実現できない。
     *
     * @param  array $X 特徴量行列（各要素は float または null）
     * @param  array $y 正解ラベル（0 or 1）
     * @return array{trees:array, base_score:float, model_hash:string, metrics:array}
     */
    private function _trainGbdtModel(
        array $X,
        array $y,
        int   $nEstimators = 60,
        int   $maxDepth = 3,
        float $learningRate = 0.1,
        float $lambda = 1.0,
        float $gamma = 0.0,
        int   $minChildSamples = 5
    ): array {
        $gbN = count($X);
        if ($gbN === 0 || $gbN !== count($y)) {
            return ['trees' => [], 'base_score' => 0.0, 'model_hash' => '',
                    'metrics' => ['status' => 'skipped', 'reason' => 'データ件数が0、または件数不一致']];
        }
        $gbD   = count($X[0]);
        $gbPos = count(array_filter($y, fn($v) => (int)$v === 1));
        if ($gbPos === 0 || $gbPos === $gbN) {
            return ['trees' => [], 'base_score' => 0.0, 'model_hash' => '',
                    'metrics' => ['status' => 'skipped',
                                  'reason' => '正例または負例が0件のため学習不能',
                                  'positive' => $gbPos, 'total' => $gbN]];
        }

        // 初期スコア = 全体の対数オッズ
        $gbP0   = $gbPos / $gbN;
        $gbBase = log(max(1e-9, $gbP0) / max(1e-9, 1.0 - $gbP0));
        $gbF    = array_fill(0, $gbN, $gbBase);
        $gbSig  = fn(float $z): float => 1.0 / (1.0 + exp(max(-60, min(60, -$z))));

        // クラス不均衡の補正（M3 大穴は正例が極端に少ないため）
        $gbWpos = $gbN / (2.0 * $gbPos);
        $gbWneg = $gbN / (2.0 * ($gbN - $gbPos));

        $gbTrees = [];
        for ($gbIt = 0; $gbIt < $nEstimators; $gbIt++) {
            // 勾配・ヘシアン
            $gbG = []; $gbH = [];
            for ($gbI = 0; $gbI < $gbN; $gbI++) {
                $gbPi = $gbSig($gbF[$gbI]);
                $gbYi = ((int)$y[$gbI] === 1) ? 1.0 : 0.0;
                $gbW  = ($gbYi > 0.5) ? $gbWpos : $gbWneg;
                $gbG[$gbI] = ($gbPi - $gbYi) * $gbW;
                $gbH[$gbI] = max(1e-6, $gbPi * (1.0 - $gbPi) * $gbW);
            }
            $gbTree = $this->_gbdtBuildTree(
                $X, $gbG, $gbH, range(0, $gbN - 1), $gbD, 0,
                $maxDepth, $lambda, $gamma, $minChildSamples
            );
            $gbTrees[] = $gbTree;
            // 予測値を更新
            for ($gbI = 0; $gbI < $gbN; $gbI++) {
                $gbF[$gbI] += $learningRate * $this->_gbdtTreePredict($gbTree, $X[$gbI]);
            }
        }

        // 学習データ上の当てはまり（過学習確認用。汎化はtestで別途評価）
        $gbCorrect = 0; $gbLoss = 0.0;
        for ($gbI = 0; $gbI < $gbN; $gbI++) {
            $gbPi = $gbSig($gbF[$gbI]);
            $gbYi = ((int)$y[$gbI] === 1) ? 1.0 : 0.0;
            if ((($gbPi >= 0.5) ? 1.0 : 0.0) === $gbYi) $gbCorrect++;
            $gbLoss += -($gbYi * log(max(1e-12, $gbPi)) + (1 - $gbYi) * log(max(1e-12, 1 - $gbPi)));
        }

        $gbHyper = ['n_estimators' => $nEstimators, 'max_depth' => $maxDepth,
                    'learning_rate' => $learningRate, 'lambda' => $lambda,
                    'gamma' => $gamma, 'min_child_samples' => $minChildSamples];
        $gbHash = hash('sha256', json_encode(['trees' => $gbTrees, 'base' => $gbBase, 'hyper' => $gbHyper]));

        return [
            'trees'      => $gbTrees,
            'base_score' => round($gbBase, 8),
            'model_hash' => $gbHash,
            'metrics'    => [
                'status'         => 'trained',
                'algorithm'      => 'gbdt_logloss',          // 決定木系 勾配ブースティング
                'sample_count'   => $gbN,
                'feature_count'  => $gbD,
                'positive'       => $gbPos,
                'tree_count'     => count($gbTrees),
                'class_weight'   => ['pos' => round($gbWpos, 4), 'neg' => round($gbWneg, 4)],
                'train_accuracy' => round($gbCorrect / $gbN, 4),
                'train_logloss'  => round($gbLoss / $gbN, 6),
                'hyper'          => $gbHyper,
                'missing_policy' => 'keep_null_learn_direction', // 0埋めせず分岐方向を学習
                'note'           => '学習データ上の指標。汎化性能は test 区分で別途評価する',
            ],
        ];
    }

    /**
     * GBDT: 1本の決定木を再帰的に構築する
     *
     * 欠損（null）は「左へ送る」「右へ送る」の両方を試し、
     * 利得が大きい方を missing_left としてノードへ記録する。
     */
    private function _gbdtBuildTree(
        array $X, array $g, array $h, array $idx, int $dim, int $depth,
        int $maxDepth, float $lambda, float $gamma, int $minChild
    ): array {
        $btG = 0.0; $btH = 0.0;
        foreach ($idx as $i) { $btG += $g[$i]; $btH += $h[$i]; }

        // 葉にする条件
        if ($depth >= $maxDepth || count($idx) < 2 * $minChild) {
            return ['leaf' => round(-$btG / ($btH + $lambda), 8)];
        }

        // 利得ゼロの分割も許可する（-1e-9 を初期値にする）。
        // XOR のような組合せは、根では単独の分割利得がちょうど0になり、
        // 「利得>0のみ許可」にすると木が根で止まって組合せを学習できない。
        // 深さ2以降で初めて効く相互作用を拾うために、ゼロ利得の分割を通す。
        // 明確に有害な負利得の分割は従来どおり却下する。
        $btBestGain = -1e-9;
        $btBest     = null;
        $btParent   = ($btG * $btG) / ($btH + $lambda);

        for ($btJ = 0; $btJ < $dim; $btJ++) {
            // 欠損とそれ以外に分ける
            $btVals = []; $btMissIdx = [];
            foreach ($idx as $i) {
                $v = $X[$i][$btJ] ?? null;
                if ($v === null) $btMissIdx[] = $i;
                else             $btVals[] = [(float)$v, $i];
            }
            if (count($btVals) < 2 * $minChild) continue;
            usort($btVals, fn($a, $b) => $a[0] <=> $b[0]);

            // 欠損分の勾配合計
            $btMg = 0.0; $btMh = 0.0;
            foreach ($btMissIdx as $i) { $btMg += $g[$i]; $btMh += $h[$i]; }

            // 非欠損の合計
            $btTg = 0.0; $btTh = 0.0;
            foreach ($btVals as [$v, $i]) { $btTg += $g[$i]; $btTh += $h[$i]; }

            // ── 分割候補①「欠損 vs 非欠損」────────────────────────────
            // 非欠損の値がすべて同じ場合、しきい値による分割候補は作れない。
            // それでも「データが無い馬」と「値を持つ馬」を分けることには意味があるため、
            // 欠損そのものを分割軸にする候補を明示的に評価する。
            // （これが無いと、欠損と値0の区別を木が学習できない）
            if (count($btMissIdx) >= $minChild && count($btVals) >= $minChild) {
                $btGainM = ($btMg ** 2) / ($btMh + $lambda)
                         + ($btTg ** 2) / ($btTh + $lambda) - $btParent - $gamma;
                if ($btGainM > $btBestGain) {
                    $btBestGain = $btGainM;
                    // threshold = -INF 相当。非欠損はすべて右、欠損は左へ送る
                    $btBest = ['feature' => $btJ, 'threshold' => -INF, 'missing_left' => true];
                }
            }

            // ── 分割候補②しきい値による分割 ────────────────────────────
            $btLg = 0.0; $btLh = 0.0; $btCnt = 0;
            for ($btK = 0; $btK < count($btVals) - 1; $btK++) {
                [$btV, $btI] = $btVals[$btK];
                $btLg += $g[$btI]; $btLh += $h[$btI]; $btCnt++;
                // 同値は分割点にしない
                if ($btVals[$btK + 1][0] === $btV) continue;
                if ($btCnt < $minChild || (count($btVals) - $btCnt) < $minChild) continue;

                $btRg = $btTg - $btLg; $btRh = $btTh - $btLh;
                $btThr = ($btV + $btVals[$btK + 1][0]) / 2.0;

                // 欠損を左へ送る場合
                $btGainL = ($btLg + $btMg) ** 2 / ($btLh + $btMh + $lambda)
                         + ($btRg ** 2) / ($btRh + $lambda) - $btParent - $gamma;
                // 欠損を右へ送る場合
                $btGainR = ($btLg ** 2) / ($btLh + $lambda)
                         + ($btRg + $btMg) ** 2 / ($btRh + $btMh + $lambda) - $btParent - $gamma;

                if ($btGainL > $btBestGain) {
                    $btBestGain = $btGainL;
                    $btBest = ['feature' => $btJ, 'threshold' => $btThr, 'missing_left' => true];
                }
                if ($btGainR > $btBestGain) {
                    $btBestGain = $btGainR;
                    $btBest = ['feature' => $btJ, 'threshold' => $btThr, 'missing_left' => false];
                }
            }
        }

        if ($btBest === null) {
            return ['leaf' => round(-$btG / ($btH + $lambda), 8)];
        }

        // 実際に振り分ける
        $btLeft = []; $btRight = [];
        foreach ($idx as $i) {
            $v = $X[$i][$btBest['feature']] ?? null;
            if ($v === null) {
                if ($btBest['missing_left']) $btLeft[] = $i; else $btRight[] = $i;
            } elseif ((float)$v <= $btBest['threshold']) {
                $btLeft[] = $i;
            } else {
                $btRight[] = $i;
            }
        }
        if (empty($btLeft) || empty($btRight)) {
            return ['leaf' => round(-$btG / ($btH + $lambda), 8)];
        }

        return [
            'feature'      => $btBest['feature'],
            // -INF は「欠損 vs 非欠損」の分割を表す（round すると INF が壊れるため分岐）
            'threshold'    => is_finite($btBest['threshold']) ? round($btBest['threshold'], 8) : $btBest['threshold'],
            'missing_left' => $btBest['missing_left'],
            'left'         => $this->_gbdtBuildTree($X, $g, $h, $btLeft,  $dim, $depth + 1, $maxDepth, $lambda, $gamma, $minChild),
            'right'        => $this->_gbdtBuildTree($X, $g, $h, $btRight, $dim, $depth + 1, $maxDepth, $lambda, $gamma, $minChild),
        ];
    }

    /** GBDT: 1本の木の予測値を返す（欠損は学習した方向へ送る） */
    private function _gbdtTreePredict(array $node, array $x): float
    {
        while (!isset($node['leaf'])) {
            $v = $x[$node['feature']] ?? null;
            if ($v === null) {
                $node = $node['missing_left'] ? $node['left'] : $node['right'];
            } else {
                $node = ((float)$v <= $node['threshold']) ? $node['left'] : $node['right'];
            }
        }
        return (float) $node['leaf'];
    }

    /**
     * GBDT: 推論（全木の合計を確率へ変換）
     *
     * 欠損は 0 で埋めず null のまま渡すこと。
     * 木が学習済みの分岐方向（missing_left）に従って振り分ける。
     */
    private function _predictGbdt(array $model, array $vector, float $learningRate = 0.1): array
    {
        if (empty($model['trees'])) {
            return ['probability' => 0.0, 'z' => 0.0, 'status' => 'skipped',
                    'reason' => 'モデルが未学習'];
        }
        $pgZ = (float)($model['base_score'] ?? 0.0);
        foreach ($model['trees'] as $pgT) {
            $pgZ += $learningRate * $this->_gbdtTreePredict($pgT, $vector);
        }
        return [
            'probability' => round(1.0 / (1.0 + exp(max(-60, min(60, -$pgZ)))), 6),
            'z'           => round($pgZ, 6),
            'status'      => 'predicted',
        ];
    }

    /**
     * 特徴量ベクトル化（推論エンジン入力の生成）
     *
     * features_json（可変構造のJSON）を、学習・推論で使える
     * 「固定長の数値ベクトル」へ変換する。
     *
     * 設計方針:
     *   ・欠損は 0.0 で埋めたうえで、別途「存在フラグ」を持たせる。
     *     0埋めだけだと「値が0」と「データが無い」を区別できないため。
     *   ・列の順序は $mvRaceKeys / $mvHorseKeys で固定する。
     *     順序が変わると学習済みモデルの重みと対応が崩れるので、
     *     この配列を書き換えるときは必ずモデルを再学習すること。
     *
     * @param  array $f features_json をデコードした配列
     * @return array{race:array, race_keys:array, horses:array, horse_keys:array}
     */
    private function _vectorizeFeatures(array $f): array
    {
        // 【仕様】欠損値は欠損フラグを別に持たせ、0へ置換しない。
        //   0で埋めると「値が0」と「データが無い」が区別できず、学習が歪む。
        //   GBDT は欠損を null のまま受け取り、分岐方向を学習するため 0埋めは不要。
        $mvNum = function ($v): ?float {
            if ($v === null || $v === '' || !is_numeric($v)) return null; // ← 0にしない
            return (float) $v;
        };
        // 欠損フラグ（0/1）。欠損そのものは null のまま残す。
        $mvHas = fn($v): float => ($v === null || $v === '') ? 0.0 : 1.0;
        // 単位換算つき（欠損は null を維持）
        $mvScale = function ($v, float $d) use ($mvNum): ?float {
            $n = $mvNum($v);
            return ($n === null) ? null : $n / $d;
        };

        // ── レース単位（M1・M2・M3 用）────────────────────────────────
        $mvGapType = strtoupper((string)($f['gap_type'] ?? ''));
        $mvRace = [];
        // 断層タイプ one-hot（A〜E）
        foreach (['A', 'B', 'C', 'D', 'E'] as $mvT) {
            $mvRace["gap_type_{$mvT}"] = ($mvGapType === $mvT) ? 1.0 : 0.0;
        }
        $mvRace['primary_gap_upper_pop']     = $mvNum($f['primary_gap_upper_pop'] ?? null);
        $mvRace['primary_gap_upper_pop_has'] = $mvHas($f['primary_gap_upper_pop'] ?? null);
        $mvRace['horse_count']        = $mvNum($f['horse_count']        ?? null);
        $mvRace['upset_race']         = $mvNum($f['upset_race']         ?? null);
        $mvRace['wave_level']         = $mvNum($f['wave_level']         ?? null);
        $mvRace['lower_entry']        = $mvNum($f['lower_entry']        ?? null);
        $mvRace['big_gap_entry']      = $mvNum($f['big_gap_entry']      ?? null);
        $mvRace['cond_a_met']         = $mvNum($f['cond_a_met']         ?? null);
        $mvRace['cond_b_met']         = $mvNum($f['cond_b_met']         ?? null);
        $mvRace['cond_c_met']         = $mvNum($f['cond_c_met']         ?? null);
        $mvRace['cond_d_met']         = $mvNum($f['cond_d_met']         ?? null);
        $mvRace['gap_max_ratio_6m']   = $mvNum($f['gap_max_ratio_6m']   ?? null);
        $mvRace['gap_min_ratio_6m']   = $mvNum($f['gap_min_ratio_6m']   ?? null);
        $mvRace['gap_primary_ratio_6m']= $mvNum($f['gap_primary_ratio_6m'] ?? null);
        $mvRace['gap_count_6m']       = $mvNum($f['gap_count_6m']       ?? null);
        $mvRace['tanpuku_consistent'] = $mvNum($f['tanpuku_gap_match']['is_consistent'] ?? null);
        $mvRace['merged_horse_count'] = $mvNum($f['merged_horse_count'] ?? null);
        $mvRace['matched_count']      = $mvNum($f['matched_count']      ?? null);
        $mvRace['score_e']            = $mvNum($f['score_e']            ?? null);
        $mvRace['distance']           = $mvScale($f['distance'] ?? null, 1000.0); // 欠損はnull維持
        // コース one-hot（芝・ダート・障害）
        $mvCourse = (string)($f['course'] ?? '');
        foreach (['芝' => 'turf', 'ダート' => 'dirt', '障害' => 'jump'] as $mvJp => $mvEn) {
            $mvRace["course_{$mvEn}"] = (mb_strpos($mvCourse, $mvJp) !== false) ? 1.0 : 0.0;
        }
        // 断層遷移の総量（出現・消滅の多さ＝相場の動きの激しさ）
        $mvApp = 0; $mvVan = 0;
        foreach (($f['gap_transitions'] ?? []) as $mvTr) {
            $mvApp += count($mvTr['appeared'] ?? []);
            $mvVan += count($mvTr['vanished'] ?? []);
        }
        $mvRace['gap_appeared_total'] = (float) $mvApp;
        $mvRace['gap_vanished_total'] = (float) $mvVan;

        // ── 馬単位（M4 用）────────────────────────────────────────────
        $mvHorses = [];
        foreach (($f['all_horses_features'] ?? []) as $mvH) {
            $mvAgg = $mvH['ability_vector']['agg'] ?? [];
            $mvSim = $mvH['similar_stats'] ?? [];
            $mvRt  = $mvH['rates'] ?? [];
            $mvRow = [];
            $mvRow['pop_6m']        = $mvNum($mvH['pop_6m']       ?? null);
            $mvRow['fuku_pop_6m']   = $mvNum($mvH['fuku_pop_6m']  ?? null);
            $mvRow['waku']          = $mvNum($mvH['waku']         ?? null);
            $mvRow['tan_odds_6m']   = $mvNum($mvH['tan_odds_6m']  ?? null);
            $mvRow['fuku_min_est']  = $mvNum($mvH['fuku_min_est'] ?? null);
            $mvRow['gap_upper']     = (($mvH['gap_position'] ?? '') === 'upper') ? 1.0 : 0.0;
            $mvRow['pop_swap_count']= $mvNum($mvH['pop_swap_count'] ?? null);
            $mvRow['in_ai_candidate']= $mvNum($mvH['in_ai_candidate'] ?? null);
            // 能力・適性（集約統計）
            $mvRow['ability_history_count'] = $mvNum($mvAgg['history_count'] ?? null);
            $mvRow['ability_avg_finish']    = $mvNum($mvAgg['avg_finish']    ?? null);
            $mvRow['ability_avg_finish_has']= $mvHas($mvAgg['avg_finish']    ?? null);
            $mvRow['ability_top3_rate']     = $mvNum($mvAgg['top3_rate']     ?? null);
            $mvRow['ability_top3_rate_has'] = $mvHas($mvAgg['top3_rate']     ?? null);
            $mvRow['ability_same_cond']     = $mvNum($mvAgg['same_cond_count']   ?? null);
            $mvRow['ability_same_dist']     = $mvNum($mvAgg['same_dist_count']   ?? null);
            $mvRow['ability_same_jockey']   = $mvNum($mvAgg['same_jockey_count'] ?? null);
            // 回収率3種（値とサンプル数）
            foreach (['過去回収率' => 'rate_past', 'OPI帯別回収率' => 'rate_opi',
                      'フェーズパターン別回収率' => 'rate_phase'] as $mvJp => $mvEn) {
                $mvRow[$mvEn]            = $mvScale($mvRt[$mvJp]['rate']    ?? null, 100.0);
                $mvRow["{$mvEn}_samples"]= $mvScale($mvRt[$mvJp]['samples'] ?? null, 100.0);
                $mvRow["{$mvEn}_has"]    = $mvHas($mvRt[$mvJp]['rate']    ?? null);
            }
            // 類似レース統計
            $mvRow['similar_top3_rate'] = $mvScale($mvSim['top3_rate'] ?? null, 100.0);
            $mvRow['similar_top5_rate'] = $mvScale($mvSim['top5_rate'] ?? null, 100.0);
            $mvRow['similar_samples']   = $mvScale($mvSim['sample_count'] ?? null, 100.0);
            $mvRow['similar_has']       = $mvHas($mvSim['top3_rate'] ?? null);
            // ── 不利フラグ（出遅れ／進路妨害／不利／外々）────────────────
            // 【仕様・07.txt指示】取得元が確定するまでは欠損（null）のまま学習へ渡す。
            //   0（不利なし）にはしない。自由記述からの推測生成も行わない。
            //   GBDT は欠損の分岐方向を学習するため、null のままで安全に扱える。
            $mvTrb = $mvH['ability_vector']['trouble'] ?? [];
            foreach (['late_start', 'interference', 'trouble', 'wide_course'] as $mvTk) {
                // 直近10走のうち該当回数。取得元が無い現状では必ず null。
                $mvTv = null;
                if (!empty($mvTrb['available']) && isset($mvTrb[$mvTk]) && is_array($mvTrb[$mvTk])) {
                    $mvTvals = array_filter($mvTrb[$mvTk], fn($x) => $x !== null);
                    $mvTv = empty($mvTvals) ? null : (float) array_sum($mvTvals);
                }
                $mvRow["trouble_{$mvTk}"]       = $mvTv;
                $mvRow["trouble_{$mvTk}_has"]   = ($mvTv === null) ? 0.0 : 1.0;
            }

            // 全時点オッズ推移（S→6分前の変化率）
            $mvTs = $mvH['tan_series'] ?? [];
            $mvS  = $mvNum($mvTs['999'] ?? null);
            $mvE6 = $mvNum($mvTs['6']   ?? null);
            // 算出できない場合は 0 ではなく null（「変化なし」と「データ無し」は別物）
            $mvRow['tan_change_rate'] = ($mvS !== null && $mvE6 !== null && $mvS > 0)
                ? round(($mvE6 - $mvS) / $mvS, 4) : null;

            $mvHorses[(int)($mvH['num'] ?? 0)] = $mvRow;
        }

        return [
            'race'        => array_values($mvRace),
            'race_keys'   => array_keys($mvRace),
            'horses'      => array_map('array_values', $mvHorses),
            'horse_keys'  => !empty($mvHorses) ? array_keys(reset($mvHorses)) : [],
        ];
    }

    /**
     * 【仕様外・未使用】ロジスティック回帰 + L2正則化
     * ※どこからも呼ばれない。仕様は決定木系勾配ブースティング（_trainGbdtModel）。
     *
     * 最新確定仕様は「決定木系 勾配ブースティングを基本とする」であり、
     * 本番の学習には _trainGbdtModel() を使用する。
     * 本メソッドは線形モデルとの比較用に残しているだけで、
     * _runMlInference() からは呼ばれない。
     * 仕様をロジスティック回帰へ変更する場合は、仕様書自体の改訂が必要。
     *
     * 仕様: Phase2以降で train 区分のスナップショットから学習する。
     *   モデルは重みベクトルとして model_registry へ保存し、
     *   input_hash と model_hash の組で当時の予測を再現できるようにする。
     *
     * 実装:
     *   ロジスティック回帰を勾配降下法で学習する。
     *   決定木やGBDTではなく線形モデルを選んだ理由は、
     *   ①重みがそのまま「どの特徴量が効いたか」の説明になる
     *   ②モデルがJSONで完全に保存でき、切戻し・再現が容易
     *   ③レース数が数千規模では過学習しにくい
     *   の3点。
     *
     * 特徴量の列順は _vectorizeFeatures() が返す *_keys で固定される。
     * 列順を変えたら必ず再学習すること（重みとの対応が崩れる）。
     *
     * @param  array $X 特徴量行列（各行が固定長の数値ベクトル）
     * @param  array $y 正解ラベル（0 or 1）
     * @return array{weights:array, bias:float, model_hash:string, metrics:array}
     */
    private function _trainMlModel(
        array $X,
        array $y,
        float $learningRate = 0.1,
        int   $epochs = 400,
        float $l2 = 0.01
    ): array {
        $tmN = count($X);
        if ($tmN === 0 || $tmN !== count($y)) {
            return ['weights' => [], 'bias' => 0.0, 'model_hash' => '',
                    'metrics' => ['status' => 'skipped', 'reason' => 'データ件数が0、または件数不一致']];
        }
        $tmD = count($X[0]);
        $tmPos = count(array_filter($y, fn($v) => (int)$v === 1));
        if ($tmPos === 0 || $tmPos === $tmN) {
            return ['weights' => [], 'bias' => 0.0, 'model_hash' => '',
                    'metrics' => ['status' => 'skipped',
                                  'reason' => '正例または負例が0件のため学習不能',
                                  'positive' => $tmPos, 'total' => $tmN]];
        }

        // クラス不均衡の補正（大穴モデルM3は正例が少ないため）
        $tmWpos = $tmN / (2.0 * $tmPos);
        $tmWneg = $tmN / (2.0 * ($tmN - $tmPos));

        $tmW = array_fill(0, $tmD, 0.0);
        $tmB = 0.0;
        $tmSig = fn(float $z): float => 1.0 / (1.0 + exp(max(-60, min(60, -$z))));

        for ($tmEp = 0; $tmEp < $epochs; $tmEp++) {
            $tmGw = array_fill(0, $tmD, 0.0);
            $tmGb = 0.0;
            for ($tmI = 0; $tmI < $tmN; $tmI++) {
                $tmZ = $tmB;
                for ($tmJ = 0; $tmJ < $tmD; $tmJ++) $tmZ += $tmW[$tmJ] * $X[$tmI][$tmJ];
                $tmP   = $tmSig($tmZ);
                $tmYi  = ((int)$y[$tmI] === 1) ? 1.0 : 0.0;
                $tmCw  = ($tmYi > 0.5) ? $tmWpos : $tmWneg;
                $tmErr = ($tmP - $tmYi) * $tmCw;
                for ($tmJ = 0; $tmJ < $tmD; $tmJ++) $tmGw[$tmJ] += $tmErr * $X[$tmI][$tmJ];
                $tmGb += $tmErr;
            }
            for ($tmJ = 0; $tmJ < $tmD; $tmJ++) {
                // L2正則化（バイアスには掛けない）
                $tmW[$tmJ] -= $learningRate * (($tmGw[$tmJ] / $tmN) + $l2 * $tmW[$tmJ]);
            }
            $tmB -= $learningRate * ($tmGb / $tmN);
        }

        // 学習データ上の当てはまり（過学習の確認用。評価はtestで別途行う）
        $tmCorrect = 0;
        $tmLoss = 0.0;
        for ($tmI = 0; $tmI < $tmN; $tmI++) {
            $tmZ = $tmB;
            for ($tmJ = 0; $tmJ < $tmD; $tmJ++) $tmZ += $tmW[$tmJ] * $X[$tmI][$tmJ];
            $tmP = $tmSig($tmZ);
            $tmYi = ((int)$y[$tmI] === 1) ? 1.0 : 0.0;
            if ((($tmP >= 0.5) ? 1.0 : 0.0) === $tmYi) $tmCorrect++;
            $tmLoss += -($tmYi * log(max(1e-12, $tmP)) + (1 - $tmYi) * log(max(1e-12, 1 - $tmP)));
        }

        $tmWr = array_map(fn($w) => round($w, 8), $tmW);
        $tmBr = round($tmB, 8);
        // モデルハッシュ: 重み・バイアス・ハイパーパラメータから生成
        $tmHash = hash('sha256', json_encode([
            'w' => $tmWr, 'b' => $tmBr, 'lr' => $learningRate, 'ep' => $epochs, 'l2' => $l2,
        ]));

        return [
            'weights'    => $tmWr,
            'bias'       => $tmBr,
            'model_hash' => $tmHash,
            'metrics'    => [
                'status'        => 'trained',
                'algorithm'     => 'logistic_regression_l2',
                'sample_count'  => $tmN,
                'feature_count' => $tmD,
                'positive'      => $tmPos,
                'class_weight'  => ['pos' => round($tmWpos, 4), 'neg' => round($tmWneg, 4)],
                'train_accuracy'=> round($tmCorrect / $tmN, 4),
                'train_logloss' => round($tmLoss / $tmN, 6),
                'hyper'         => ['learning_rate' => $learningRate, 'epochs' => $epochs, 'l2' => $l2],
                'note'          => '学習データ上の指標。汎化性能は test 区分で別途評価する',
            ],
        ];
    }

    /**
     * 断層学習 M1〜M4 推論エンジン：推論
     *
     * 学習済みの重みベクトルを特徴量へ適用し、確率を返す。
     * 返す確率は未校正なので、_calibrateProbabilities() を通してから
     * 「確率」として扱うこと。
     *
     * @return array{probability:float, z:float, status:string}
     */
    /** 【仕様外・未使用】線形モデル用の推論。本番は _predictGbdt() を使う。 */
    private function _predictMl(array $weights, float $bias, array $vector): array
    {
        if (empty($weights) || count($weights) !== count($vector)) {
            return ['probability' => 0.0, 'z' => 0.0, 'status' => 'skipped',
                    'reason' => '重みが未学習、または特徴量の次元が一致しない（列順の変更は再学習が必要）'];
        }
        $pmZ = $bias;
        foreach ($weights as $pmJ => $pmW) $pmZ += $pmW * $vector[$pmJ];
        return [
            'probability' => round(1.0 / (1.0 + exp(max(-60, min(60, -$pmZ)))), 6),
            'z'           => round($pmZ, 6),
            'status'      => 'predicted',
        ];
    }

    /**
     * 断層学習 M1〜M4 推論エンジン：実行（シャドー専用）
     *
     * Phase2以降で、保存済みスナップショットに対して M1〜M4 の推論を行う。
     * Phase1（1,000レース未満）では学習モデルが無いため実行せず、
     * 理由つきで skipped を返す。
     *
     * 【重要】結果は features_json へ保存するだけで、
     *   本番候補・本番順位・Flutter表示には一切反映しない。
     *   本番反映はよっしーの明示的な許可が出るまで行わない。
     *
     * @param  array $features features_json をデコードした配列
     * @param  array $models   ['M1'=>['weights'=>..,'bias'=>..], ...] 学習済みモデル
     * @return array 推論結果（シャドー値）
     */
    private function _runMlInference(array $features, array $models = []): array
    {
        $riPhase = $this->_getMlPhase();

        // Phase1 は学習モデルが存在しないため推論しない
        if (!$riPhase['allow_inference'] || empty($models)) {
            return [
                'status' => 'skipped',
                'reason' => empty($models)
                    ? '学習済みモデルが未生成（Phase1は保存のみで学習を行わない）'
                    : 'Phase1のため推論を行わない',
                'phase'  => $riPhase['phase'],
                'race_count' => $riPhase['race_count'],
                'production_reflected' => false,
            ];
        }

        $riVec = $this->_vectorizeFeatures($features);
        $riOut = ['status' => 'inferred', 'phase' => $riPhase['phase'],
                  'production_reflected' => false, 'race_models' => [], 'm4' => []];

        // ── M1〜M3: レース単位 ────────────────────────────────────────
        foreach (['M1', 'M2', 'M3'] as $riM) {
            if (!isset($models[$riM]['trees'])) {
                $riOut['race_models'][$riM] = ['status' => 'skipped', 'reason' => 'モデル未登録'];
                continue;
            }
            // 仕様: 決定木系 勾配ブースティング（GBDT）で推論する
            $riOut['race_models'][$riM] = $this->_predictGbdt(
                $models[$riM], $riVec['race'],
                (float)($models[$riM]['learning_rate'] ?? 0.1)
            );
        }

        // ── M4: 馬単位 ────────────────────────────────────────────────
        if (isset($models['M4']['trees'])) {
            foreach ($riVec['horses'] as $riNum => $riHv) {
                $riP = $this->_predictGbdt(
                    $models['M4'], $riHv, (float)($models['M4']['learning_rate'] ?? 0.1)
                );
                $riOut['m4'][$riNum] = $riP;
            }
        } else {
            $riOut['m4'] = ['status' => 'skipped', 'reason' => 'モデル未登録'];
        }

//         \Log::info('[Block11] M1〜M4 推論（シャドー専用・本番未反映）', [
//             'phase' => $riPhase['phase'],
//             'race_models' => array_map(fn($r) => $r['probability'] ?? null, $riOut['race_models']),
//         ]);
        return $riOut;
    }

    /**
     * 断層学習 M1〜M4 の正解ラベル算出（受入チェック #50〜#52）
     *
     * 仕様（よっしー最新確定版 / 20260913-10.txt・11.txt）:
     *   M1 上位完結モデル : 実着順5着以内が【すべて】6分前1〜6番人気なら1、それ以外0
     *   M2 中穴進入モデル : 実着順5着以内に6分前【7〜10番人気】が1頭以上いれば1、それ以外0
     *   M3 大穴進入モデル : 実着順5着以内に6分前【11番人気以下】が1頭以上いれば1、それ以外0
     *   M4 馬別正解ラベル : 馬ごとに5着以内なら1。母集団を
     *                       「2AI候補内」と「全頭発見」の2種類に分けて別々に保存する
     *
     * 重要な注意:
     *   ・人気順は【6分前時点】のもの。確定人気ではない。
     *   ・M2とM3の人気帯は重複させない（7〜10 と 11以下）。
     *   ・「5着以内」であって「馬券内（3着以内）」ではない。
     *
     * 実行タイミング:
     *   レース結果が確定した後のバッチ処理から呼ぶ。
     *   予測時には実着順が存在しないため、スナップショットには特徴量のみを保存し、
     *   本メソッドで算出したラベルを後から書き込む。
     *
     * @return array{m1:?int, m2:?int, m3:?int, m4:array, detail:array}
     *         結果が未確定・取得できない場合は m1〜m3 が null（0ではない）
     */
    private function _calcMlLabels(
        string $date,
        int    $kaisuu,
        string $basho,
        int    $day,
        int    $race
    ): array {
        // ── 6分前の単勝人気順を算出（馬番 => 人気順）────────────────────
        $mlOdds6 = DB::table('t_horse_odds_finder_odds')
            ->where('date',   $date)
            ->where('kaisuu', $kaisuu)
            ->where('basho',  $basho)
            ->where('day',    $day)
            ->where('race',   $race)
            ->where('minutes_before_start', 6)
            ->get(['num', 'odds']);

        $mlPopPairs = [];
        foreach ($mlOdds6 as $mlO) {
            if ((float)$mlO->odds > 0) $mlPopPairs[(int)$mlO->num] = (float)$mlO->odds;
        }
        asort($mlPopPairs);
        $mlPopMap = [];   // 馬番 => 6分前人気順
        $mlRank   = 1;
        foreach ($mlPopPairs as $mlNum => $_o) { $mlPopMap[$mlNum] = $mlRank++; }

        // ── 実着順を取得 ──────────────────────────────────────────────
        $mlResults = DB::table('t_horse_odds_finder_race_results')
            ->where('date',   $date)
            ->where('kaisuu', $kaisuu)
            ->where('basho',  $basho)
            ->where('day',    $day)
            ->where('race',   $race)
            ->get(['num', 'finishing_position']);

        // 結果未確定・人気順不明ならラベルを作らない（0で埋めない）
        if ($mlResults->count() === 0 || empty($mlPopMap)) {
            return [
                'm1' => null, 'm2' => null, 'm3' => null, 'm4' => [],
                'detail' => [
                    'status' => 'pending',
                    'reason' => ($mlResults->count() === 0)
                        ? 'レース結果が未確定（race_results に行が無い）'
                        : '6分前オッズが無く人気順を算出できない',
                ],
            ];
        }

        // ── 5着以内の馬を抽出し、その6分前人気順を集める ──────────────
        $mlTop5Pops = [];   // 5着以内の馬の6分前人気順
        $mlTop5Nums = [];   // 5着以内の馬番
        $mlFinishMap = [];  // 馬番 => 実着順
        foreach ($mlResults as $mlR) {
            $mlN  = (int)$mlR->num;
            $mlFp = isset($mlR->finishing_position) ? (int)$mlR->finishing_position : null;
            if ($mlFp === null || $mlFp <= 0) continue;   // 除外・取消などはスキップ
            $mlFinishMap[$mlN] = $mlFp;
            if ($mlFp <= 5) {
                $mlTop5Nums[] = $mlN;
                if (isset($mlPopMap[$mlN])) $mlTop5Pops[] = $mlPopMap[$mlN];
            }
        }

        if (empty($mlTop5Pops)) {
            return [
                'm1' => null, 'm2' => null, 'm3' => null, 'm4' => [],
                'detail' => ['status' => 'pending',
                             'reason' => '5着以内の馬の6分前人気順が取得できない'],
            ];
        }

        // ── M1: 5着以内が【すべて】1〜6番人気なら1 ────────────────────
        $mlM1 = 1;
        foreach ($mlTop5Pops as $mlP) {
            if ($mlP < 1 || $mlP > 6) { $mlM1 = 0; break; }
        }

        // ── M2: 5着以内に【7〜10番人気】が1頭以上いれば1 ──────────────
        $mlM2 = 0;
        foreach ($mlTop5Pops as $mlP) {
            if ($mlP >= 7 && $mlP <= 10) { $mlM2 = 1; break; }
        }

        // ── M3: 5着以内に【11番人気以下】が1頭以上いれば1 ──────────────
        // ※M2（7〜10）とM3（11以下）の人気帯は重複させない
        $mlM3 = 0;
        foreach ($mlTop5Pops as $mlP) {
            if ($mlP >= 11) { $mlM3 = 1; break; }
        }

        // ── M4: 馬別ラベル（5着以内なら1）を2つの母集団で分けて保存 ──────
        // 母集団①「2AI候補内」は features_json.m4_separated.in_ai_candidates から取る
        $mlCandNums = [];
        $mlSnap = DB::table('t_horse_odds_finder_ml_snapshot')
            ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho_code', $basho)
            ->where('day', $day)->where('race', $race)
            ->first();
        if ($mlSnap && !empty($mlSnap->features)) {
            $mlFeat = json_decode($mlSnap->features, true);
            $mlCandNums = $mlFeat['m4_separated']['in_ai_candidates']['nums'] ?? [];
        }

        $mlM4All  = [];
        $mlM4Cand = [];
        foreach ($mlPopMap as $mlN => $mlPop) {
            $mlLabel = (isset($mlFinishMap[$mlN]) && $mlFinishMap[$mlN] <= 5) ? 1 : 0;
            $mlRow = [
                'num'                => $mlN,
                'pop_6m'             => $mlPop,
                'finishing_position' => $mlFinishMap[$mlN] ?? null,
                'label'              => $mlLabel,
            ];
            $mlM4All[] = $mlRow;                                   // ②全頭発見
            if (in_array($mlN, $mlCandNums, true)) $mlM4Cand[] = $mlRow; // ①2AI候補内
        }

        $mlOut = [
            'm1' => $mlM1,
            'm2' => $mlM2,
            'm3' => $mlM3,
            'm4' => [
                'in_ai_candidates' => [
                    'rows'         => $mlM4Cand,
                    'count'        => count($mlM4Cand),
                    'hit_count'    => count(array_filter($mlM4Cand, fn($r) => $r['label'] === 1)),
                ],
                'all_horses'       => [
                    'rows'         => $mlM4All,
                    'count'        => count($mlM4All),
                    'hit_count'    => count(array_filter($mlM4All, fn($r) => $r['label'] === 1)),
                ],
            ],
            'detail' => [
                'status'        => 'confirmed',
                'top5_nums'     => $mlTop5Nums,
                'top5_pops_6m'  => $mlTop5Pops,
                'definition'    => [
                    'm1' => '実着順5着以内がすべて6分前1〜6番人気なら1',
                    'm2' => '実着順5着以内に6分前7〜10番人気が1頭以上いれば1',
                    'm3' => '実着順5着以内に6分前11番人気以下が1頭以上いれば1',
                    'm4' => '馬別: 5着以内なら1。母集団は2AI候補内／全頭発見の2種類',
                ],
            ],
        ];

//         \Log::info('[Block11] M1〜M4 正解ラベル算出', [
//             'race' => "{$date}_{$kaisuu}_{$basho}_{$day}_{$race}",
//             'm1' => $mlM1, 'm2' => $mlM2, 'm3' => $mlM3,
//             'top5_pops' => $mlTop5Pops,
//         ]);
        return $mlOut;
    }

    /**
     * 学習フェーズの判定（受入チェック #55・#56 / 最新確定仕様準拠）
     *
     * 仕様:
     *   Phase1 : 1,000レース未満 — 保存のみ。学習・推論は行わない
     *   Phase2 : 1,000〜 — 予備学習・シャドー推論。本番へは反映しない
     *   Phase3 : 正式評価可能。ただし件数だけでは不可で、次をすべて満たすこと
     *            ・2,000レース以上
     *            ・最新の【未使用評価期間】が500レース以上
     *            ・70/15/15 固定なら test が500レース以上 ⇒ 実質 3,334レース以上
     *
     * 「2,000件到達＝正式評価可能」は誤り。未使用評価500レースの確保を必ず判定する。
     *
     * 本番反映は、条件をすべて満たしても自動では解禁しない（明示許可が必要）。
     */
    private function _getMlPhase(): array
    {
        $phCount = (int) DB::table('t_horse_odds_finder_ml_snapshot')->count();

        // 70/15/15 固定のため test 区分は全体の15%
        $phTestCount   = intdiv($phCount * 15, 100);
        $phNeedHoldout = 500;                                   // 未使用評価に必要なレース数
        $phMinTotal    = 2000;                                  // 最低件数
        // test が500件に達するために必要な総件数（70/15/15 固定）
        $phRequiredForHoldout = (int) ceil($phNeedHoldout / 0.15);  // = 3,334

        $phHoldoutOk = ($phTestCount >= $phNeedHoldout);
        $phCountOk   = ($phCount >= $phMinTotal);

        if     ($phCount < 1000)              { $phPhase = 1; }
        elseif (!($phCountOk && $phHoldoutOk)) { $phPhase = 2; }
        else                                   { $phPhase = 3; }

        $phUnmet = [];
        if (!$phCountOk)   $phUnmet[] = "総レース数が{$phMinTotal}件未満（現在{$phCount}件）";
        if (!$phHoldoutOk) $phUnmet[] = "未使用評価が{$phNeedHoldout}レース未満"
                                      . "（現在の test 区分{$phTestCount}件／"
                                      . "70-15-15固定では総{$phRequiredForHoldout}件以上が必要）";

        $phOut = [
            'phase'            => $phPhase,
            'race_count'       => $phCount,
            'allow_training'   => ($phPhase >= 2),
            'allow_inference'  => ($phPhase >= 2),
            'allow_formal_eval'=> ($phPhase >= 3),
            // 本番反映は条件を満たしても自動解禁しない（明示許可が必要）
            'allow_production' => false,
            'phase3_conditions' => [
                'min_total_races'        => $phMinTotal,
                'min_holdout_races'      => $phNeedHoldout,
                'required_total_for_holdout' => $phRequiredForHoldout, // 3,334
                'current_test_count'     => $phTestCount,
                'total_ok'               => $phCountOk,
                'holdout_ok'             => $phHoldoutOk,
                'unmet'                  => $phUnmet,
            ],
            'note' => match ($phPhase) {
                1 => 'Phase1: スナップショット保存のみ。学習・推論は未実施',
                2 => 'Phase2: 予備学習・シャドー推論。正式評価の条件は未達（下記 unmet を参照）',
                3 => 'Phase3: 正式評価が可能。ただし本番有効化には別途明示許可が必要',
            },
            'thresholds' => ['phase2_from' => 1000, 'phase3_from' => $phMinTotal,
                             'phase3_holdout' => $phNeedHoldout],
        ];

//         \Log::debug('[Block11] ml phase', $phOut);
        return $phOut;
    }

    /**
     * モデル評価指標の算出（最新確定仕様の指定指標）
     *
     * 仕様で保存を求められている指標（M1〜M3）:
     *   ・適合率(precision) ・再現率(recall) ・F1
     *   ・ROC-AUC
     *   ・PR-AUC（Average Precision）
     *   ・Brier score
     *   ・校正誤差（ECE / MCE・10分割の信頼度曲線つき）
     *   ・人気帯別の回収率
     *   ・最大連敗
     *   ・断層タイプ別の集計
     * これらに加え、logloss / 的中率 も併せて返す。
     *
     * 適合率・再現率・F1 は 2 種類の閾値で算出する。
     *   ・threshold 0.5（既定の分類閾値）
     *   ・$betThreshold（購入とみなす閾値。回収率の集計と同じ条件）
     *
     * 回収率は「単勝100円均等買い」を前提に、
     *   回収率 = 払戻合計 ÷ 購入額合計 = Σ(label×odds×100) ÷ (件数×100)
     * で算出する（odds は推定確定単勝オッズ）。
     *
     * @param  array $probs    予測確率
     * @param  array $labels   正解ラベル（0/1）
     * @param  array $meta     各サンプルの付随情報 ['popularity'=>int,'odds'=>float,'gap_type'=>string]
     * @param  float $betThreshold 購入とみなす確率のしきい値
     */
    private function _evaluateMlModel(
        array $probs,
        array $labels,
        array $meta = [],
        float $betThreshold = 0.5
    ): array {
        $evN = count($probs);
        if ($evN === 0 || $evN !== count($labels)) {
            return ['status' => 'skipped', 'reason' => 'データ件数が0、または件数不一致'];
        }
        $evY = array_map(fn($v) => ((int)$v === 1) ? 1 : 0, $labels);
        $evPos = array_sum($evY);
        $evNeg = $evN - $evPos;

        // ── ROC-AUC（順位法。同値は平均順位で扱う）──────────────────────
        $evAuc = null;
        if ($evPos > 0 && $evNeg > 0) {
            $evIdx = range(0, $evN - 1);
            usort($evIdx, fn($a, $b) => $probs[$a] <=> $probs[$b]);
            $evRank = array_fill(0, $evN, 0.0);
            $evI = 0;
            while ($evI < $evN) {
                $evJ = $evI;
                while ($evJ + 1 < $evN && $probs[$evIdx[$evJ + 1]] === $probs[$evIdx[$evI]]) $evJ++;
                $evAvg = (($evI + 1) + ($evJ + 1)) / 2.0;   // 1始まりの平均順位
                for ($evK = $evI; $evK <= $evJ; $evK++) $evRank[$evIdx[$evK]] = $evAvg;
                $evI = $evJ + 1;
            }
            $evSumPos = 0.0;
            for ($evK = 0; $evK < $evN; $evK++) if ($evY[$evK] === 1) $evSumPos += $evRank[$evK];
            $evAuc = round(($evSumPos - $evPos * ($evPos + 1) / 2.0) / ($evPos * $evNeg), 6);
        }

        // ── PR-AUC（Average Precision）────────────────────────────────
        $evPrAuc = null;
        if ($evPos > 0) {
            $evOrd = range(0, $evN - 1);
            usort($evOrd, fn($a, $b) => $probs[$b] <=> $probs[$a]);   // 確率降順
            $evTp = 0; $evFp = 0; $evPrevRecall = 0.0; $evAp = 0.0;
            foreach ($evOrd as $evK) {
                if ($evY[$evK] === 1) $evTp++; else $evFp++;
                $evPrec   = $evTp / max(1, $evTp + $evFp);
                $evRecall = $evTp / $evPos;
                $evAp    += $evPrec * ($evRecall - $evPrevRecall);
                $evPrevRecall = $evRecall;
            }
            $evPrAuc = round($evAp, 6);
        }

        // ── logloss / Brier / 的中率 ───────────────────────────────────
        $evLoss = 0.0; $evBrier = 0.0; $evHit = 0;
        for ($evK = 0; $evK < $evN; $evK++) {
            $evP = min(1 - 1e-12, max(1e-12, (float)$probs[$evK]));
            $evLoss  += -($evY[$evK] * log($evP) + (1 - $evY[$evK]) * log(1 - $evP));
            $evBrier += ($evP - $evY[$evK]) ** 2;
            if ((($evP >= 0.5) ? 1 : 0) === $evY[$evK]) $evHit++;
        }

        // ── 適合率 / 再現率 / F1（閾値ごとの混同行列から算出）─────────────
        $evPrf = function (float $th) use ($evN, $probs, $evY): array {
            $tp = 0; $fp = 0; $fn = 0; $tn = 0;
            for ($k = 0; $k < $evN; $k++) {
                $pred = ((float)$probs[$k] >= $th) ? 1 : 0;
                if ($pred === 1 && $evY[$k] === 1) $tp++;
                elseif ($pred === 1 && $evY[$k] === 0) $fp++;
                elseif ($pred === 0 && $evY[$k] === 1) $fn++;
                else $tn++;
            }
            // 分母0のときは「算出不能」を null で表す（0にしない）
            $prec = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : null;
            $rec  = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : null;
            $f1   = ($prec !== null && $rec !== null && ($prec + $rec) > 0)
                    ? 2 * $prec * $rec / ($prec + $rec) : null;
            return [
                'threshold' => $th,
                'tp' => $tp, 'fp' => $fp, 'fn' => $fn, 'tn' => $tn,
                'precision' => $prec !== null ? round($prec, 6) : null,
                'recall'    => $rec  !== null ? round($rec,  6) : null,
                'f1'        => $f1   !== null ? round($f1,   6) : null,
            ];
        };
        $evPrf05  = $evPrf(0.5);
        $evPrfBet = $evPrf($betThreshold);

        // ── 校正誤差（ECE / MCE）──────────────────────────────────────
        // 予測確率を10等分の区間に分け、各区間で |平均予測確率 - 実際の発生率| を測る。
        //   ECE = サンプル数で重み付けした平均、MCE = 最大値。
        //   reliability には信頼度曲線（横軸=平均予測確率, 縦軸=実測率）の点を残す。
        $evBins = 10;
        $evBinAcc = array_fill(0, $evBins, ['n' => 0, 'p' => 0.0, 'y' => 0]);
        for ($evK = 0; $evK < $evN; $evK++) {
            $evP = min(1.0, max(0.0, (float)$probs[$evK]));
            $evB = (int) floor($evP * $evBins);
            if ($evB >= $evBins) $evB = $evBins - 1;
            $evBinAcc[$evB]['n']++;
            $evBinAcc[$evB]['p'] += $evP;
            $evBinAcc[$evB]['y'] += $evY[$evK];
        }
        $evEce = 0.0; $evMce = 0.0; $evRel = [];
        foreach ($evBinAcc as $evBi => $evBv) {
            if ($evBv['n'] === 0) continue;
            $evMeanP = $evBv['p'] / $evBv['n'];
            $evFrac  = $evBv['y'] / $evBv['n'];
            $evGapAbs = abs($evMeanP - $evFrac);
            $evEce += ($evBv['n'] / $evN) * $evGapAbs;
            if ($evGapAbs > $evMce) $evMce = $evGapAbs;
            $evRel[] = [
                'bin'            => sprintf('%.1f-%.1f', $evBi / $evBins, ($evBi + 1) / $evBins),
                'count'          => $evBv['n'],
                'mean_predicted' => round($evMeanP, 6),
                'observed_rate'  => round($evFrac, 6),
                'gap'            => round($evMeanP - $evFrac, 6),
            ];
        }

        // ── 購入シミュレーション（単勝100円均等買い）────────────────────
        $evBet = 0; $evRet = 0.0;
        $evStreak = 0; $evMaxStreak = 0;
        $evByPop = [];   // 人気帯別
        $evByGap = [];   // 断層タイプ別
        for ($evK = 0; $evK < $evN; $evK++) {
            if ((float)$probs[$evK] < $betThreshold) continue;
            $evOdds = (float)($meta[$evK]['odds'] ?? 0.0);
            $evBet++;
            $evPay = ($evY[$evK] === 1 && $evOdds > 0) ? $evOdds * 100.0 : 0.0;
            $evRet += $evPay;
            // 最大連敗
            if ($evY[$evK] === 1) { $evStreak = 0; }
            else { $evStreak++; if ($evStreak > $evMaxStreak) $evMaxStreak = $evStreak; }
            // 人気帯別（1-3 / 4-6 / 7-10 / 11+）
            $evPop = (int)($meta[$evK]['popularity'] ?? 0);
            $evBand = ($evPop >= 1 && $evPop <= 3) ? '1-3'
                    : (($evPop <= 6) ? '4-6' : (($evPop <= 10) ? '7-10' : '11+'));
            if ($evPop <= 0) $evBand = 'unknown';
            $evByPop[$evBand]['bet'] = ($evByPop[$evBand]['bet'] ?? 0) + 1;
            $evByPop[$evBand]['ret'] = ($evByPop[$evBand]['ret'] ?? 0.0) + $evPay;
            $evByPop[$evBand]['hit'] = ($evByPop[$evBand]['hit'] ?? 0) + $evY[$evK];
            // 断層タイプ別
            $evGt = (string)($meta[$evK]['gap_type'] ?? 'unknown');
            $evByGap[$evGt]['bet'] = ($evByGap[$evGt]['bet'] ?? 0) + 1;
            $evByGap[$evGt]['ret'] = ($evByGap[$evGt]['ret'] ?? 0.0) + $evPay;
            $evByGap[$evGt]['hit'] = ($evByGap[$evGt]['hit'] ?? 0) + $evY[$evK];
        }
        $evRate = fn(array $a): array => [
            'bet_count'     => $a['bet'],
            'hit_count'     => $a['hit'],
            'hit_rate'      => round($a['hit'] / max(1, $a['bet']), 4),
            'recovery_rate' => round($a['ret'] / max(1, $a['bet'] * 100.0) * 100.0, 2), // %
        ];
        ksort($evByPop); ksort($evByGap);

        return [
            'status'        => 'evaluated',
            'sample_count'  => $evN,
            'positive'      => $evPos,
            'roc_auc'       => $evAuc,
            'pr_auc'        => $evPrAuc,
            'logloss'       => round($evLoss / $evN, 6),
            'brier'         => round($evBrier / $evN, 6),
            'accuracy'      => round($evHit / $evN, 4),
            // 適合率・再現率・F1（仕様の必須指標）
            'precision'     => $evPrf05['precision'],
            'recall'        => $evPrf05['recall'],
            'f1'            => $evPrf05['f1'],
            'prf_at_0_5'    => $evPrf05,
            'prf_at_bet_threshold' => $evPrfBet,
            // 校正誤差（仕様の必須指標）
            'calibration_error' => [
                'ece'         => round($evEce, 6),
                'mce'         => round($evMce, 6),
                'bins'        => $evBins,
                'reliability' => $evRel,
            ],
            'bet_threshold' => $betThreshold,
            'overall'       => [
                'bet_count'     => $evBet,
                'recovery_rate' => round($evRet / max(1, $evBet * 100.0) * 100.0, 2),
                'max_losing_streak' => $evMaxStreak,
            ],
            'by_popularity' => array_map($evRate, $evByPop),
            'by_gap_type'   => array_map($evRate, $evByGap),
            'note'          => '回収率は単勝100円均等買いを前提に算出。odds は推定確定単勝オッズ',
        ];
    }


    /**
     * M4（馬単位・5着以内）専用の評価指標
     *
     * 最新確定仕様で M4 に求められている指標:
     *   ・5着以内率        ・3着以内率
     *   ・単勝回収率        ・複勝回収率
     *   ・平均配当          ・中央値配当
     *   ・最大連敗          ・最大値除外後回収率
     *   ・平均選出頭数を揃えた、現行版との比較
     *   ・上記を【全体および人気帯別】で保存する
     *   ・学習モデルによって追加で拾えた馬・押し出された馬・偽陽性・偽陰性を
     *     【断層タイプ別】に集計する
     *
     * ROC-AUC / PR-AUC / F1 / 校正誤差など共通の指標は _evaluateMlModel() が返すので、
     * 本メソッドは「買った場合にどうなるか」の指標に絞って算出し、両方を返す。
     *
     * 回収率は 100円均等買いを前提とする。
     *   単勝回収率 = Σ(1着 × 単勝オッズ × 100) ÷ (購入点数 × 100)
     *   複勝回収率 = Σ(3着以内 × 複勝オッズ × 100) ÷ (購入点数 × 100)
     *
     * 「最大値除外後回収率」は、最も高い払戻を出した1点を分子・分母の両方から除いて
     * 再計算する（1回の大穴だけで回収率が見かけ上良くなる現象を除くため）。
     *
     * 「平均選出頭数を揃えた比較」は、現行版が1レースあたり平均何頭選んでいるかを数え、
     * 学習版も同じ頭数だけ（M4確率の高い順に）選んだうえで比較する。
     * 頭数が違うまま比較すると、単に多く買っただけで数字が動くため。
     *
     * @param array      $probs   M4 予測確率（馬ごと）
     * @param array      $labels  5着以内ラベル（0/1）
     * @param array      $meta    馬ごとの付随情報
     *        ['race_key'=>string,'num'=>int,'win'=>0|1,'top3'=>0|1,
     *         'tan_odds'=>float,'fuku_odds'=>float,'popularity'=>int,'gap_type'=>string]
     * @param array|null $currentSelection 現行版の選出 ['race_key' => [馬番, ...], ...]
     * @param float      $betThreshold     購入とみなす確率のしきい値
     */
    private function _evaluateM4Model(
        array  $probs,
        array  $labels,
        array  $meta = [],
        ?array $currentSelection = null,
        float  $betThreshold = 0.5
    ): array {
        $m4N = count($probs);
        if ($m4N === 0 || $m4N !== count($labels)) {
            return ['status' => 'skipped', 'reason' => 'データ件数が0、または件数不一致'];
        }
        $m4Y = array_map(fn($v) => ((int)$v === 1) ? 1 : 0, $labels);

        // ── 選出した添字集合に対して、仕様の指標一式を算出する ────────────
        $m4Calc = function (array $idxList) use ($probs, $m4Y, $meta): array {
            $cnt = count($idxList);
            if ($cnt === 0) {
                return ['status' => 'skipped', 'reason' => '選出0件', 'bet_count' => 0];
            }
            $top5 = 0; $top3 = 0; $win = 0;
            $tanRet = 0.0; $fukuRet = 0.0;
            $tanPays = []; $fukuPays = [];
            $streak = 0; $maxStreak = 0;
            foreach ($idxList as $i) {
                $mW  = (int)   ($meta[$i]['win']       ?? 0);
                $mT3 = (int)   ($meta[$i]['top3']      ?? 0);
                $mTo = (float) ($meta[$i]['tan_odds']  ?? 0.0);
                $mFo = (float) ($meta[$i]['fuku_odds'] ?? 0.0);

                $top5 += $m4Y[$i];
                $top3 += $mT3;
                $win  += $mW;

                $pT = ($mW  === 1 && $mTo > 0) ? $mTo * 100.0 : 0.0;
                $pF = ($mT3 === 1 && $mFo > 0) ? $mFo * 100.0 : 0.0;
                $tanRet  += $pT;
                $fukuRet += $pF;
                $tanPays[]  = $pT;
                $fukuPays[] = $pF;

                // 最大連敗（複勝＝3着以内を外した連続回数）
                if ($mT3 === 1) { $streak = 0; }
                else { $streak++; if ($streak > $maxStreak) $maxStreak = $streak; }
            }

            // 的中したぶんだけの配当（平均配当・中央値配当）
            $hitTan  = array_values(array_filter($tanPays,  fn($v) => $v > 0));
            $hitFuku = array_values(array_filter($fukuPays, fn($v) => $v > 0));
            $avgOf   = fn(array $a) => empty($a) ? null : round(array_sum($a) / count($a), 2);
            $medOf   = function (array $a) {
                if (empty($a)) return null;
                sort($a); $n = count($a); $h = intdiv($n, 2);
                return round(($n % 2) ? $a[$h] : ($a[$h - 1] + $a[$h]) / 2.0, 2);
            };

            // 最大値除外後回収率（最高払戻の1点を分子・分母の両方から除く）
            $exRate = function (array $pays) use ($cnt): ?float {
                if ($cnt <= 1) return null;                 // 1点しかなければ算出できない
                $mx  = max($pays);
                $sum = array_sum($pays) - $mx;   // 最高払戻の1点を分子から除く
                return round($sum / (($cnt - 1) * 100.0) * 100.0, 2);
            };

            return [
                'status'        => 'evaluated',
                'bet_count'     => $cnt,
                'top5_rate'     => round($top5 / $cnt, 6),   // 5着以内率
                'top3_rate'     => round($top3 / $cnt, 6),   // 3着以内率
                'win_rate'      => round($win  / $cnt, 6),   // 勝率（参考）
                'tan_recovery_rate'  => round($tanRet  / ($cnt * 100.0) * 100.0, 2), // 単勝回収率(%)
                'fuku_recovery_rate' => round($fukuRet / ($cnt * 100.0) * 100.0, 2), // 複勝回収率(%)
                'avg_payout_tan'     => $avgOf($hitTan),     // 平均配当（単勝・的中分のみ）
                'median_payout_tan'  => $medOf($hitTan),     // 中央値配当（単勝・的中分のみ）
                'avg_payout_fuku'    => $avgOf($hitFuku),    // 平均配当（複勝・的中分のみ）
                'median_payout_fuku' => $medOf($hitFuku),    // 中央値配当（複勝・的中分のみ）
                'max_losing_streak'  => $maxStreak,          // 最大連敗（複勝基準）
                'tan_recovery_excluding_max'  => $exRate($tanPays),  // 最大値除外後回収率(単勝)
                'fuku_recovery_excluding_max' => $exRate($fukuPays), // 最大値除外後回収率(複勝)
            ];
        };

        // 人気帯別（1-3 / 4-6 / 7-10 / 11+ / unknown）に分けて同じ指標を出す
        $m4Band = function (int $i) use ($meta): string {
            $pv = (int) ($meta[$i]['popularity'] ?? 0);
            if ($pv <= 0)  return 'unknown';
            if ($pv <= 3)  return '1-3';
            if ($pv <= 6)  return '4-6';
            if ($pv <= 10) return '7-10';
            return '11+';
        };
        $m4ByBand = function (array $idxList) use ($m4Band, $m4Calc): array {
            $groups = [];
            foreach ($idxList as $i) $groups[$m4Band($i)][] = $i;
            ksort($groups);
            return array_map($m4Calc, $groups);
        };

        // ── ① しきい値方式での選出 ──────────────────────────────────────
        $m4ByTh = [];
        for ($i = 0; $i < $m4N; $i++) if ((float)$probs[$i] >= $betThreshold) $m4ByTh[] = $i;

        // ── ② 平均選出頭数を揃えた比較 ──────────────────────────────────
        // レース単位に添字をまとめる
        $m4Races = [];
        for ($i = 0; $i < $m4N; $i++) {
            $m4Races[(string)($meta[$i]['race_key'] ?? '')][] = $i;
        }
        $m4Aligned = null;
        $m4Diff    = null;
        if (!empty($currentSelection)) {
            // 現行版が1レースあたり平均何頭選んでいるか
            $m4CurCounts = array_map(fn($a) => count((array)$a), $currentSelection);
            $m4AvgN = !empty($m4CurCounts)
                ? array_sum($m4CurCounts) / count($m4CurCounts) : 0.0;
            $m4TakeN = max(1, (int) round($m4AvgN));

            // 現行版の選出添字（馬番で突き合わせる）
            $m4CurIdx = [];
            foreach ($m4Races as $m4Rk => $m4Idxs) {
                $m4Sel = array_map('intval', (array)($currentSelection[$m4Rk] ?? []));
                foreach ($m4Idxs as $i) {
                    if (in_array((int)($meta[$i]['num'] ?? -1), $m4Sel, true)) $m4CurIdx[] = $i;
                }
            }
            // 学習版：各レースで確率上位 $m4TakeN 頭を選ぶ（頭数を揃える）
            $m4LrnIdx = [];
            foreach ($m4Races as $m4Idxs) {
                usort($m4Idxs, fn($a, $b) => $probs[$b] <=> $probs[$a]);
                foreach (array_slice($m4Idxs, 0, $m4TakeN) as $i) $m4LrnIdx[] = $i;
            }
            $m4Aligned = [
                'race_count'            => count($m4Races),
                'current_avg_selected'  => round($m4AvgN, 4),
                'selected_per_race'     => $m4TakeN,
                'current_version'       => $m4Calc($m4CurIdx),
                'learned_version'       => $m4Calc($m4LrnIdx),
                'note' => '1レースあたりの選出頭数を揃えたうえで比較している（頭数差による見かけの差を排除）',
            ];
            // 人気薄を増やしただけの改善になっていないかの確認材料
            $m4PopAvg = function (array $idxList) use ($meta): ?float {
                $vals = [];
                foreach ($idxList as $i) {
                    $pv = $meta[$i]['popularity'] ?? null;
                    if ($pv !== null && (int)$pv > 0) $vals[] = (int)$pv;
                }
                return empty($vals) ? null : round(array_sum($vals) / count($vals), 3);
            };
            $m4Aligned['current_version']['avg_popularity'] = $m4PopAvg($m4CurIdx);
            $m4Aligned['learned_version']['avg_popularity'] = $m4PopAvg($m4LrnIdx);
            $m4Aligned['popularity_note'] =
                '平均人気が学習版だけ大きく下がっている場合、回収率の改善は「人気薄を多く買った」結果の可能性がある';
            // 人気帯別（仕様: 全体および人気帯別を保存する）
            $m4Aligned['current_version']['by_popularity'] = $m4ByBand($m4CurIdx);
            $m4Aligned['learned_version']['by_popularity'] = $m4ByBand($m4LrnIdx);

            // ── 追加で拾えた馬 / 押し出された馬 / 偽陽性 / 偽陰性（断層タイプ別）──
            // 学習版だけが選んだ馬 = 追加で拾えた馬、現行版だけが選んだ馬 = 押し出された馬。
            // 偽陽性 = 選んだのに5着以内でなかった、偽陰性 = 選ばなかったのに5着以内だった。
            $m4CurSet = array_fill_keys($m4CurIdx, true);
            $m4LrnSet = array_fill_keys($m4LrnIdx, true);
            $m4Diff = [];
            $m4Bump = function (string $gt, string $key) use (&$m4Diff) {
                if (!isset($m4Diff[$gt])) {
                    $m4Diff[$gt] = ['picked_up' => 0, 'picked_up_hit' => 0,
                                    'pushed_out' => 0, 'pushed_out_hit' => 0,
                                    'false_positive' => 0, 'false_negative' => 0];
                }
                $m4Diff[$gt][$key]++;
            };
            for ($i = 0; $i < $m4N; $i++) {
                $gt  = (string) ($meta[$i]['gap_type'] ?? 'unknown');
                $inC = isset($m4CurSet[$i]);
                $inL = isset($m4LrnSet[$i]);
                if ($inL && !$inC) {
                    $m4Bump($gt, 'picked_up');
                    if ($m4Y[$i] === 1) $m4Bump($gt, 'picked_up_hit');
                } elseif ($inC && !$inL) {
                    $m4Bump($gt, 'pushed_out');
                    if ($m4Y[$i] === 1) $m4Bump($gt, 'pushed_out_hit');
                }
                if ($inL && $m4Y[$i] === 0) $m4Bump($gt, 'false_positive');
                if (!$inL && $m4Y[$i] === 1) $m4Bump($gt, 'false_negative');
            }
            ksort($m4Diff);
        }

        return [
            'status'         => 'evaluated',
            'sample_count'   => $m4N,
            'bet_threshold'  => $betThreshold,
            // 共通指標（ROC-AUC / PR-AUC / F1 / Brier / 校正誤差 など）
            'common_metrics' => $this->_evaluateMlModel($probs, $labels, $meta, $betThreshold),
            // M4 固有指標（しきい値方式）: 全体
            'by_threshold'   => $m4Calc($m4ByTh),
            // 同じ指標の人気帯別内訳
            'by_popularity'  => $m4ByBand($m4ByTh),
            // 平均選出頭数を揃えた現行版との比較（全体＋人気帯別）
            'aligned_comparison' => $m4Aligned,
            // 追加で拾えた馬・押し出された馬・偽陽性・偽陰性（断層タイプ別）
            'diff_by_gap_type'   => $m4Diff,
            'note' => '回収率は100円均等買いを前提に算出。配当は的中した点のみで平均・中央値を取る',
        ];
    }


    /**
     * 確率校正（受入チェック #53）
     *
     * 仕様: モデルが出す生スコアは、そのままでは「確率」として読めない。
     *   実際の的中率と一致するよう補正してから確率として扱う。
     *
     * 方式: Platt scaling（ロジスティック回帰による1次元校正）
     *   p = 1 / (1 + exp(A * s + B))
     *   A・B を勾配降下法で学習し、生スコア s を校正済み確率 p へ写像する。
     *
     * 学習データの制約:
     *   校正は【validation区分】のデータだけで行う。
     *   train を使うとモデルが過学習した分まで学習してしまい、
     *   test を使うと評価が汚れるため。
     *
     * 品質指標:
     *   Brier Score（低いほど良い）と ECE（Expected Calibration Error）を
     *   校正前後で返し、校正が実際に効いているかを確認できるようにする。
     *
     * @param  array $scores 生スコア（0〜100想定）
     * @param  array $labels 正解ラベル（0 or 1）
     * @return array{a:float, b:float, calibrated:array, metrics:array}
     */
    private function _calibrateProbabilities(array $scores, array $labels, int $iterations = 300): array
    {
        $cbN = count($scores);
        if ($cbN === 0 || $cbN !== count($labels)) {
            return ['a' => 0.0, 'b' => 0.0, 'calibrated' => [],
                    'metrics' => ['status' => 'skipped', 'reason' => 'データ件数が0、または件数不一致']];
        }
        // 正例・負例が片方しか無いと校正できない
        $cbPos = count(array_filter($labels, fn($v) => (int)$v === 1));
        if ($cbPos === 0 || $cbPos === $cbN) {
            return ['a' => 0.0, 'b' => 0.0, 'calibrated' => [],
                    'metrics' => ['status' => 'skipped',
                                  'reason' => '正例または負例が0件のため校正不能',
                                  'positive' => $cbPos, 'total' => $cbN]];
        }

        // スコアを 0〜1 へ正規化（数値安定化のため）
        $cbMin = min($scores); $cbMax = max($scores);
        $cbRange = ($cbMax - $cbMin) > 0 ? ($cbMax - $cbMin) : 1.0;
        $cbX = array_map(fn($s) => ($s - $cbMin) / $cbRange, $scores);
        $cbY = array_map(fn($v) => (int)$v === 1 ? 1.0 : 0.0, $labels);

        // Platt の目標値（過学習を避けるための平滑化）
        $cbNp = $cbPos; $cbNn = $cbN - $cbPos;
        $cbHi = ($cbNp + 1.0) / ($cbNp + 2.0);
        $cbLo = 1.0 / ($cbNn + 2.0);
        $cbT  = array_map(fn($y) => $y > 0.5 ? $cbHi : $cbLo, $cbY);

        // 勾配降下法で A・B を学習
        $cbA = 0.0; $cbB = 0.0; $cbLr = 0.5;
        for ($cbIt = 0; $cbIt < $iterations; $cbIt++) {
            $cbGa = 0.0; $cbGb = 0.0;
            for ($cbI = 0; $cbI < $cbN; $cbI++) {
                $cbZ = $cbA * $cbX[$cbI] + $cbB;
                $cbP = 1.0 / (1.0 + exp(max(-60, min(60, $cbZ))));  // オーバーフロー防止
                // p = sigmoid(-z) のため dL/dz = (t - p)。
                // ここを (p - t) にすると勾配の符号が逆になり、
                // 学習するほど精度が悪化する（Brierが増え単調性も崩れる）。
                $cbD = $cbT[$cbI] - $cbP;
                $cbGa += $cbD * $cbX[$cbI];
                $cbGb += $cbD;
            }
            $cbA -= $cbLr * $cbGa / $cbN;
            $cbB -= $cbLr * $cbGb / $cbN;
        }

        // 校正済み確率
        $cbCal = [];
        for ($cbI = 0; $cbI < $cbN; $cbI++) {
            $cbZ = $cbA * $cbX[$cbI] + $cbB;
            $cbCal[] = round(1.0 / (1.0 + exp(max(-60, min(60, $cbZ)))), 6);
        }

        // ── 品質指標: Brier Score と ECE ──────────────────────────────
        $cbBrier = function (array $p, array $y): float {
            $s = 0.0;
            foreach ($p as $i => $v) $s += ($v - $y[$i]) ** 2;
            return round($s / max(1, count($p)), 6);
        };
        $cbEce = function (array $p, array $y, int $bins = 10): float {
            $n = count($p); if ($n === 0) return 0.0;
            $sum = 0.0;
            for ($b = 0; $b < $bins; $b++) {
                $lo = $b / $bins; $hi = ($b + 1) / $bins;
                $idx = [];
                foreach ($p as $i => $v) {
                    if ($v > $lo && $v <= $hi) $idx[] = $i;
                    elseif ($b === 0 && $v <= 0.0 + 1e-12) $idx[] = $i;
                }
                if (empty($idx)) continue;
                $avgP = array_sum(array_map(fn($i) => $p[$i], $idx)) / count($idx);
                $avgY = array_sum(array_map(fn($i) => $y[$i], $idx)) / count($idx);
                $sum += (count($idx) / $n) * abs($avgP - $avgY);
            }
            return round($sum, 6);
        };
        // 校正前は「正規化スコアをそのまま確率とみなした場合」と比較する
        $cbRaw = $cbX;

        return [
            'a' => round($cbA, 6),
            'b' => round($cbB, 6),
            'calibrated' => $cbCal,
            'metrics' => [
                'status'        => 'calibrated',
                'method'        => 'platt_scaling',
                'sample_count'  => $cbN,
                'positive'      => $cbPos,
                'brier_before'  => $cbBrier($cbRaw, $cbY),
                'brier_after'   => $cbBrier($cbCal, $cbY),
                'ece_before'    => $cbEce($cbRaw, $cbY),
                'ece_after'     => $cbEce($cbCal, $cbY),
                'note'          => '校正は validation 区分のみで行う（train は過学習、test は評価汚染のため）',
            ],
        ];
    }

    /**
     * 現行版と学習版の並行比較（受入チェック #54）
     *
     * 仕様: 学習版を本番へ入れる前に、現行版とどれだけ違うかを定量的に比較する。
     *   一致率・順位相関・上位N重なりを出し、差が大きすぎないかを確認する。
     *
     * 本メソッドは比較結果を返すだけで、順位の採否は行わない。
     * 学習版の本番反映はよっしーの許可が出るまで禁止。
     *
     * @param  array $currentRanking 現行版の順位（[['num'=>int,'rank'=>int], ...]）
     * @param  array $learnedRanking 学習版の順位（同形式）
     * @return array 比較メトリクス
     */
    private function _compareModelVersions(array $currentRanking, array $learnedRanking): array
    {
        $cmCur = []; foreach ($currentRanking as $r) $cmCur[(int)$r['num']] = (int)$r['rank'];
        $cmLrn = []; foreach ($learnedRanking as $r) $cmLrn[(int)$r['num']] = (int)$r['rank'];
        $cmCommon = array_values(array_intersect(array_keys($cmCur), array_keys($cmLrn)));
        $cmN = count($cmCommon);

        if ($cmN === 0) {
            return ['status' => 'skipped', 'reason' => '共通の馬が無く比較できない',
                    'current_count' => count($cmCur), 'learned_count' => count($cmLrn)];
        }

        // 順位完全一致数
        $cmSameRank = 0;
        $cmDiffSum  = 0;
        $cmDiffMax  = 0;
        foreach ($cmCommon as $cmNum) {
            $cmD = abs($cmCur[$cmNum] - $cmLrn[$cmNum]);
            if ($cmD === 0) $cmSameRank++;
            $cmDiffSum += $cmD;
            if ($cmD > $cmDiffMax) $cmDiffMax = $cmD;
        }

        // Spearman 順位相関（共通馬のみ・同順位なしを前提とした簡易式）
        $cmD2 = 0;
        foreach ($cmCommon as $cmNum) $cmD2 += ($cmCur[$cmNum] - $cmLrn[$cmNum]) ** 2;
        $cmSpearman = ($cmN > 1)
            ? round(1.0 - (6.0 * $cmD2) / ($cmN * (($cmN ** 2) - 1)), 4)
            : null;

        // 上位N重なり
        $cmTopN = function (array $map, int $n): array {
            asort($map);
            return array_slice(array_keys($map), 0, $n);
        };
        $cmOverlap = [];
        foreach ([1, 3, 5] as $cmK) {
            $cmA = $cmTopN($cmCur, $cmK);
            $cmB = $cmTopN($cmLrn, $cmK);
            $cmOverlap["top{$cmK}"] = [
                'current' => $cmA,
                'learned' => $cmB,
                'shared'  => array_values(array_intersect($cmA, $cmB)),
                'rate'    => round(count(array_intersect($cmA, $cmB)) / max(1, $cmK), 4),
            ];
        }

        return [
            'status'            => 'compared',
            'common_count'      => $cmN,
            'same_rank_count'   => $cmSameRank,
            'same_rank_rate'    => round($cmSameRank / $cmN, 4),
            'avg_rank_diff'     => round($cmDiffSum / $cmN, 4),
            'max_rank_diff'     => $cmDiffMax,
            'spearman'          => $cmSpearman,
            'top_n_overlap'     => $cmOverlap,
            'production_switch' => false,   // 比較するだけ。本番切替は行わない
            'note'              => '比較結果のみ。学習版の本番反映は許可が出るまで行わない',
        ];
    }

    /**
     * 日付順 70% / 15% / 15% の時系列分割（受入チェック #49）
     *
     * 仕様: 学習・検証・評価を「日付順」で分割する。
     *   ランダム分割は未来のレースが学習側へ混ざる（未来情報混入）ため禁止。
     *   古い順に 70% を train、次の 15% を validation、最後の 15% を test とする。
     *
     * 決定論性:
     *   並び順は date, kaisuu, basho, day, race の昇順で一意に定まるため、
     *   同じスナップショット集合からは常に同じ分割結果が得られる。
     *
     * 境界の扱い:
     *   intdiv で切り下げるため、端数は test 側に寄る。
     *   同一レース（同一キー）が2つの区分にまたがることはない。
     *
     * @param  int|null $limitDate この日付以前のスナップショットのみ対象（null=全件）
     * @return array{train:array, validation:array, test:array, summary:array}
     */
    private function _assignTimeSeriesSplit(?string $limitDate = null): array
    {
        $tsQuery = DB::table('t_horse_odds_finder_ml_snapshot')
            ->select(['date', 'kaisuu', 'basho_code', 'day', 'race'])
            ->orderBy('date')->orderBy('kaisuu')->orderBy('basho_code')
            ->orderBy('day')->orderBy('race');
        if ($limitDate !== null) {
            $tsQuery->where('date', '<=', $limitDate);
        }
        $tsRows = $tsQuery->get();

        $tsTotal = $tsRows->count();
        if ($tsTotal === 0) {
            return [
                'train' => [], 'validation' => [], 'test' => [],
                'summary' => ['total' => 0, 'train' => 0, 'validation' => 0, 'test' => 0,
                              'note' => 'スナップショットが0件のため分割なし'],
            ];
        }

        // 日付順に 70% / 15% / 15%
        $tsTrainEnd = intdiv($tsTotal * 70, 100);
        $tsValEnd   = intdiv($tsTotal * 85, 100);

        $tsOut = ['train' => [], 'validation' => [], 'test' => []];
        foreach ($tsRows->values()->all() as $tsI => $tsR) {
            $tsKey = sprintf('%s_%d_%s_%d_%d',
                $tsR->date, (int)$tsR->kaisuu, $tsR->basho_code, (int)$tsR->day, (int)$tsR->race);
            if     ($tsI <  $tsTrainEnd) $tsOut['train'][]      = $tsKey;
            elseif ($tsI <  $tsValEnd)   $tsOut['validation'][] = $tsKey;
            else                         $tsOut['test'][]       = $tsKey;
        }

        $tsOut['summary'] = [
            'total'      => $tsTotal,
            'train'      => count($tsOut['train']),
            'validation' => count($tsOut['validation']),
            'test'       => count($tsOut['test']),
            'ratio'      => '70/15/15（日付順・ランダム分割禁止）',
            'train_period' => [
                'from' => $tsOut['train'][0]      ?? null,
                'to'   => end($tsOut['train'])    ?: null,
            ],
            'test_period'  => [
                'from' => $tsOut['test'][0]       ?? null,
                'to'   => end($tsOut['test'])     ?: null,
            ],
        ];
        reset($tsOut['train']); reset($tsOut['test']);

//         \Log::info('[Block11] 時系列分割 70/15/15', $tsOut['summary']);
        return $tsOut;
    }

    /**
     * モデル更新・旧モデルへの切戻し管理（受入チェック #57）
     *
     * 仕様: モデルを更新したら旧モデルへ戻せること。戻したときに
     *   当時の予測を再現できること。
     *
     * 本メソッドはモデル登録簿（レジストリ）を返す。
     *   - 各モデルは version / hash / 有効フラグ / 学習期間 / 件数 を持つ
     *   - 有効なモデルは常に1つだけ（is_active=1）
     *   - 旧モデルの行は消さずに残すため、is_active を切り替えるだけで切戻しできる
     *   - 予測の再現には features_json の input_hash と model_hash の組が必要なため、
     *     両方をスナップショットへ保存している
     *
     * Phase1（1,000レース未満）は学習を行わないため、レジストリは
     * 「現行ルールベース版」1件のみを返す。学習開始後にモデル行が追加される。
     *
     * @return array{active:array, registry:array, rollback_ready:bool}
     */
    private function _buildModelRegistry(string $inputHash): array
    {
        // Phase1: 学習モデル未生成。現行のルールベース版のみが有効。
        $mrCurrent = [
            'model_id'           => 'rule-based-v7',
            'model_type'         => 'llm-ensemble+rule',
            'model_hash'         => null,   // 学習モデル未生成のため null
            'is_active'          => 1,
            'trained_at'         => null,
            'train_period'       => null,
            'train_sample_count' => 0,
            'phase'              => 1,
            // M1〜M4 の学習済みモデル（重みベクトル）。Phase1は未生成のため空。
            // Phase2で _trainMlModel() の結果をここへ格納する。
            'models'             => [],
            'note'               => 'Phase1: 学習未実施。ルールベースのみで候補・順位を決定している',
        ];

        // 学習モデルが登録され次第ここへ追加される。行は削除せず is_active のみ切り替える。
        $mrRegistry = [$mrCurrent];

        return [
            'active'         => $mrCurrent,
            'registry'       => $mrRegistry,
            // 切戻し可能条件: レジストリに2件以上あり、かつ再現用ハッシュが揃っていること
            'rollback_ready' => (count($mrRegistry) >= 2),
            'reproducibility' => [
                'input_hash' => $inputHash,
                'model_hash' => $mrCurrent['model_hash'],
                'note'       => 'input_hash と model_hash の組で当時の予測を再現する。'
                              . 'Phase1は model_hash が null のため、入力の再現のみ可能。',
            ],
        ];
    }

    /**
     * 走破時計の文字列を秒へ変換する
     *
     * DB の time 列は "1:34.5"（分:秒.小数）または "94.5"（秒）の形式。
     * 仕様では走破時計を別カラムで保持することになっているため数値へ直す。
     * 解釈できない値・空値は 0 ではなく null（欠損）を返す。
     */
    private function _parseRaceTimeToSeconds($value): ?float
    {
        if ($value === null) return null;
        $rtStr = trim((string) $value);
        if ($rtStr === '' || $rtStr === '-' || $rtStr === '－') return null;
        if (preg_match('/^(\d+):(\d{1,2}(?:\.\d+)?)$/', $rtStr, $rtM)) {
            return round((int)$rtM[1] * 60 + (float)$rtM[2], 3);
        }
        if (preg_match('/^\d+(\.\d+)?$/', $rtStr)) {
            return round((float)$rtStr, 3);
        }
        return null; // 解釈できない形式は欠損扱い
    }

    /**
     * 能力・適性データの固定長ベクトル化（受入チェック #63 / 最新確定仕様準拠）
     *
     * 仕様:
     *   ・直近【最大10走】を固定長で保持する（5走ではない）
     *   ・直近3走 / 5走 / 10走 の窓ごとに 件数・平均・中央値・最小・最大 を集約する
     *   ・条件別成績（同コース・同距離帯・同クラス・同騎手）を別途集計する
     *   ・カテゴリ項目は【学習期間だけで作った辞書】でIDへ変換し、
     *     辞書に無い値は UNKNOWN(0) とする
     *   ・欠損値は 0 へ置換せず null のまま保持する（欠損フラグを別に持つ）
     *   ・数値欠損の補完に使う中央値は model_version へ保存し、再現可能にする
     *
     * 未来情報混入の防止:
     *   取得を「今走日より前（date < $date）」に限定する。
     *
     * @param  array      $horseNums 対象馬番（全出走馬）
     * @param  array|null $catDict   学習期間で作成済みのカテゴリ辞書（null=辞書未作成）
     * @return array{vectors:array, imputation:array, dict_used:array}
     */
    private function _buildAbilityVectors(
        array  $horseNums,
        string $date,
        int    $kaisuu,
        string $basho,
        int    $day,
        int    $race,
        object $raceRow,
        ?array $catDict = null
    ): array {
        $avLen = 10; // 仕様: 最大10走の固定長

        $avTodayDist  = isset($raceRow->dist) ? (int)$raceRow->dist : null;
        $avTodayCond  = $raceRow->course    ?? null;
        $avTodayGrade = $raceRow->grade     ?? null;
        $avTodayBaba  = $raceRow->condition ?? null; // 馬場状態

        // ── カテゴリ辞書（学習期間だけで作る。無ければ UNKNOWN のみ）──────
        // 仕様: 辞書に無い値は UNKNOWN(0)。今走のデータで辞書を増やしてはいけない
        //       （増やすと学習期間外の情報が混入する）
        $avDict = $catDict ?? ['course' => [], 'grade' => [], 'condition' => [],
                               'jockey' => [], 'basho'  => []];
        $avCatId = function (string $kind, $value) use ($avDict): int {
            if ($value === null || $value === '') return 0;               // 欠損も UNKNOWN
            return (int)($avDict[$kind][(string)$value] ?? 0);            // 辞書に無ければ UNKNOWN(0)
        };

        $avHorses = DB::table('t_horse_odds_finder_horses')
            ->where('date', $date)->where('kaisuu', $kaisuu)->where('basho', $basho)
            ->where('day', $day)->where('race', $race)
            ->get(['num', 'name', 'jockey', 'waku']);

        // 集約対象にする数値系列（この一覧で窓集約を作る）
        // 仕様【固定長変換】2 の列挙に対応する数値系列。
        //   着順 / 着差 / 人気 / 単勝オッズ / 距離 / 頭数 / 枠番 / 通過順位(4コーナー個別) /
        //   上がり3F / 上がり順位 / 走破時計 / 斤量 / 斤量差 / 馬体重 / 増減 / 前走からの日数
        $avNumericKeys = ['finishing_position', 'popularity', 'num_horses', 'dist', 'dist_diff',
                          'last_3f', 'last_3f_rank', 'fin_time_diff', 'burden_weight',
                          'burden_weight_diff',
                          'horse_weight', 'horse_weight_diff', 'corner_avg', 'odds',
                          'rest_days', 'finish_rate',
                          'gate', 'time_sec',
                          'corner_1', 'corner_2', 'corner_3', 'corner_4'];

        $avOut = [];
        $avAllForMedian = []; // 補完用中央値の算出に使う（全馬ぶんを集める）

        foreach ($avHorses as $avH) {
            $avNum = (int)$avH->num;
            if (!in_array($avNum, $horseNums, true)) continue;

            $avRows = DB::table('t_horse_odds_finder_shutsuba_history')
                ->where('name', $avH->name)
                ->where('date', '<', $date)   // ← 未来情報混入の防止
                ->orderBy('date', 'desc')
                ->limit($avLen)
                ->get(['date', 'dist', 'condition', 'grade', 'finishing_position',
                       'num_horses', 'popularity', 'jockey', 'burden_weight',
                       'horse_weight', 'last_3f', 'fin_time_diff', 'odds',
                       'corner_1', 'corner_2', 'corner_3', 'corner_4',
                       // 仕様【M4能力・適性元データの固定長変換】2 で列挙されている項目
                       'time', 'gate', 'basho_code'])
                ->values()->all();

            // ── 固定長シリーズ（必ず10要素・欠損は null。0で埋めない）──────
            $avSeries = [];
            foreach ($avNumericKeys as $avK) $avSeries[$avK] = array_fill(0, $avLen, null);
            foreach (['same_condition', 'same_grade', 'same_jockey', 'same_baba',
                      'same_basho',
                      'course_id', 'grade_id', 'condition_id', 'jockey_id',
                      'basho_id'] as $avK) {
                $avSeries[$avK] = array_fill(0, $avLen, null);
            }

            $avPrevWeight = null;
            $avPrevBurden = null;
            $avPrevDate   = null;
            foreach ($avRows as $avI => $avR) {
                $avFp = isset($avR->finishing_position) ? (int)$avR->finishing_position : null;
                $avNh = isset($avR->num_horses)         ? (int)$avR->num_horses         : null;
                $avDs = isset($avR->dist)               ? (int)$avR->dist               : null;
                $avHw = isset($avR->horse_weight)       ? (int)$avR->horse_weight       : null;

                $avSeries['finishing_position'][$avI] = $avFp;
                $avSeries['popularity'][$avI]  = isset($avR->popularity) ? (int)$avR->popularity : null;
                $avSeries['num_horses'][$avI]  = $avNh;
                $avSeries['dist'][$avI]        = $avDs;
                $avSeries['dist_diff'][$avI]   = ($avDs !== null && $avTodayDist !== null)
                                                 ? $avDs - $avTodayDist : null;
                $avSeries['last_3f'][$avI]     = isset($avR->last_3f)       ? (float)$avR->last_3f       : null;
                $avSeries['fin_time_diff'][$avI]= isset($avR->fin_time_diff)? (float)$avR->fin_time_diff : null;
                $avSeries['burden_weight'][$avI]= isset($avR->burden_weight)? (float)$avR->burden_weight : null;
                // 斤量差（前走比）。前走が無ければ null（0にしない）
                $avBw = ($avSeries['burden_weight'][$avI] !== null)
                        ? $avSeries['burden_weight'][$avI] : null;
                $avSeries['burden_weight_diff'][$avI] = ($avBw !== null && $avPrevBurden !== null)
                                                        ? round($avBw - $avPrevBurden, 2) : null;
                $avPrevBurden = $avBw ?? $avPrevBurden;
                // 枠番
                $avSeries['gate'][$avI] = (isset($avR->gate) && $avR->gate !== '')
                                          ? (int)$avR->gate : null;
                // 走破時計（"1:34.5" / "94.5" を秒へ。解釈できなければ null。0にしない）
                $avSeries['time_sec'][$avI] = $this->_parseRaceTimeToSeconds($avR->time ?? null);
                $avSeries['horse_weight'][$avI] = $avHw;
                // 馬体重の増減（前走比）。前走が無ければ null
                $avSeries['horse_weight_diff'][$avI] = ($avHw !== null && $avPrevWeight !== null)
                                                       ? $avHw - $avPrevWeight : null;
                $avPrevWeight = $avHw ?? $avPrevWeight;
                // 単勝オッズ（当時）
                $avSeries['odds'][$avI] = isset($avR->odds) ? (float)$avR->odds : null;
                // 休養日数（次走との間隔）
                if ($avPrevDate !== null && !empty($avR->date)) {
                    $avT1 = strtotime((string)$avPrevDate);
                    $avT2 = strtotime((string)$avR->date);
                    $avSeries['rest_days'][$avI - 1] = ($avT1 && $avT2)
                        ? (int)round(($avT1 - $avT2) / 86400) : null;
                }
                $avPrevDate = $avR->date ?? null;

                $avCorners = array_values(array_filter(
                    [$avR->corner_1 ?? null, $avR->corner_2 ?? null,
                     $avR->corner_3 ?? null, $avR->corner_4 ?? null],
                    fn($c) => $c !== null && $c !== ''
                ));
                $avSeries['corner_avg'][$avI] = !empty($avCorners)
                    ? round(array_sum(array_map('intval', $avCorners)) / count($avCorners), 2) : null;
                // 通過順位は平均だけでなく各コーナーも別カラムで保持する（仕様【固定長変換】2）
                foreach ([1, 2, 3, 4] as $avCn) {
                    $avCv = $avR->{'corner_' . $avCn} ?? null;
                    $avSeries['corner_' . $avCn][$avI] = ($avCv === null || $avCv === '')
                                                         ? null : (int)$avCv;
                }
                // 競馬場（カテゴリ）。今走と同競馬場かどうかも保持する
                $avSeries['basho_id'][$avI]   = $avCatId('basho', $avR->basho_code ?? null);
                $avSeries['same_basho'][$avI] = (isset($avR->basho_code) && $avR->basho_code !== '')
                    ? ((string)$avR->basho_code === (string)$basho ? 1 : 0) : null;

                $avSeries['finish_rate'][$avI] = ($avFp !== null && $avNh !== null && $avNh > 0)
                    ? round($avFp / $avNh, 4) : null;

                // 条件一致フラグ（比較対象が無ければ null。0にしない）
                $avSeries['same_condition'][$avI] = ($avTodayCond !== null && isset($avR->condition))
                    ? (($avR->condition === $avTodayCond) ? 1 : 0) : null;
                $avSeries['same_grade'][$avI] = ($avTodayGrade !== null && isset($avR->grade))
                    ? (($avR->grade === $avTodayGrade) ? 1 : 0) : null;
                $avSeries['same_jockey'][$avI] = (isset($avH->jockey) && isset($avR->jockey))
                    ? (($avR->jockey === $avH->jockey) ? 1 : 0) : null;
                $avSeries['same_baba'][$avI] = ($avTodayBaba !== null && isset($avR->condition))
                    ? (($avR->condition === $avTodayBaba) ? 1 : 0) : null;

                // カテゴリID（学習期間の辞書のみ。未知は UNKNOWN=0）
                $avSeries['course_id'][$avI]    = $avCatId('course',    $avR->condition ?? null);
                $avSeries['grade_id'][$avI]     = $avCatId('grade',     $avR->grade     ?? null);
                $avSeries['condition_id'][$avI] = $avCatId('condition', $avR->condition ?? null);
                $avSeries['jockey_id'][$avI]    = $avCatId('jockey',    $avR->jockey    ?? null);
            }

            // 上がり3F順位（同一馬の履歴内での相対順位。全走の比較で算出）
            $avL3 = [];
            foreach ($avSeries['last_3f'] as $avI2 => $avV) if ($avV !== null) $avL3[$avI2] = $avV;
            asort($avL3);
            $avRk = 1;
            foreach ($avL3 as $avI2 => $_v) { $avSeries['last_3f_rank'][$avI2] = $avRk++; }

            // ── 窓別集約（3走 / 5走 / 10走）──────────────────────────────
            // 仕様: 件数・平均・中央値・最小・最大
            $avAgg = [];
            foreach ([3, 5, 10] as $avW) {
                foreach ($avNumericKeys as $avK) {
                    $avSlice = array_slice($avSeries[$avK], 0, $avW);
                    $avVals  = array_values(array_filter($avSlice, fn($v) => $v !== null));
                    $avCnt   = count($avVals);
                    $avPfx   = "w{$avW}_{$avK}";
                    $avAgg["{$avPfx}_count"]  = $avCnt;
                    // 件数0のとき平均等は null（0にしない＝「成績0」と誤認させない）
                    $avAgg["{$avPfx}_mean"]   = $avCnt ? round(array_sum($avVals) / $avCnt, 4) : null;
                    $avAgg["{$avPfx}_median"] = $avCnt ? $this->_medianOf($avVals) : null;
                    $avAgg["{$avPfx}_min"]    = $avCnt ? min($avVals) : null;
                    $avAgg["{$avPfx}_max"]    = $avCnt ? max($avVals) : null;
                    if ($avCnt) {
                        foreach ($avVals as $avV) $avAllForMedian[$avK][] = $avV;
                    }
                }
                // 着順ベースの率（窓ごと）
                $avFin = array_values(array_filter(array_slice($avSeries['finishing_position'], 0, $avW),
                                                   fn($v) => $v !== null));
                $avC = count($avFin);
                $avAgg["w{$avW}_win_count"]  = count(array_filter($avFin, fn($v) => $v === 1));
                $avAgg["w{$avW}_top3_count"] = count(array_filter($avFin, fn($v) => $v <= 3));
                $avAgg["w{$avW}_top3_rate"]  = $avC ? round(count(array_filter($avFin, fn($v) => $v <= 3)) / $avC, 4) : null;
                $avAgg["w{$avW}_top5_rate"]  = $avC ? round(count(array_filter($avFin, fn($v) => $v <= 5)) / $avC, 4) : null;
            }

            // ── 条件別成績（同コース・同距離帯±100m・同クラス・同騎手）──────
            $avCondAgg = [];
            // 仕様【固定長変換】4: 同競馬場・同距離・同芝ダート・同馬場状態・同クラスについて
            //   過去出走数／1着数／3着以内数／5着以内数／平均着順／中央値着順を作る。
            //   分母0は「率0」ではなく「不明」（null）とする。
            foreach ([
                'same_course' => fn($i) => $avSeries['same_condition'][$i] === 1,
                'same_dist'   => fn($i) => $avSeries['dist_diff'][$i] !== null && abs($avSeries['dist_diff'][$i]) <= 100,
                'same_grade'  => fn($i) => $avSeries['same_grade'][$i] === 1,
                'same_jockey' => fn($i) => $avSeries['same_jockey'][$i] === 1,
                'same_baba'   => fn($i) => $avSeries['same_baba'][$i] === 1,
                'same_basho'  => fn($i) => $avSeries['same_basho'][$i] === 1,
            ] as $avName => $avPred) {
                $avFinC = [];
                for ($avI3 = 0; $avI3 < $avLen; $avI3++) {
                    if ($avPred($avI3) && $avSeries['finishing_position'][$avI3] !== null) {
                        $avFinC[] = $avSeries['finishing_position'][$avI3];
                    }
                }
                $avN = count($avFinC);
                $avCondAgg["{$avName}_count"]     = $avN;
                $avCondAgg["{$avName}_mean"]      = $avN ? round(array_sum($avFinC) / $avN, 4) : null;
                $avCondAgg["{$avName}_median"]    = $avN ? $this->_medianOf($avFinC) : null;
                $avCondAgg["{$avName}_best"]      = $avN ? min($avFinC) : null;
                $avCondAgg["{$avName}_win_count"]  = $avN ? count(array_filter($avFinC, fn($v) => $v === 1)) : 0;
                $avCondAgg["{$avName}_top3_count"] = $avN ? count(array_filter($avFinC, fn($v) => $v <= 3)) : 0;
                $avCondAgg["{$avName}_top5_count"] = $avN ? count(array_filter($avFinC, fn($v) => $v <= 5)) : 0;
                // 分母0は「率0」ではなく「不明」（null）
                $avCondAgg["{$avName}_win_rate"]  = $avN ? round(count(array_filter($avFinC, fn($v) => $v === 1)) / $avN, 4) : null;
                $avCondAgg["{$avName}_top3_rate"] = $avN ? round(count(array_filter($avFinC, fn($v) => $v <= 3)) / $avN, 4) : null;
                $avCondAgg["{$avName}_top5_rate"] = $avN ? round(count(array_filter($avFinC, fn($v) => $v <= 5)) / $avN, 4) : null;
            }

            // ── 不利フラグ（出遅れ／進路妨害／不利／外々）────────────────────
            // 【仕様・07.txt指示】取得元が確定するまでは NULL（欠損）で保持する。
            //   ・0（＝不利なし）として扱ってはならない
            //   ・自由記述から推測生成してはならない
            //   実DDL照合の結果、t_horse_odds_finder_shutsuba_history には
            //   不利に相当する列も自由記述列も存在しない（取得元が無い）。
            //   取得元が用意された時点で、ここへ実値を詰めるだけで学習に載る。
            $avTrouble = ['source' => null, 'available' => false];
            foreach (['late_start', 'interference', 'trouble', 'wide_course'] as $avTk) {
                $avTrouble[$avTk] = array_fill(0, $avLen, null); // 0で埋めない
            }

            $avOut[$avNum] = [
                'series'     => $avSeries,    // 固定長10のシリーズ
                'trouble'    => $avTrouble,   // 不利フラグ（取得元未確定のため全て null）
                'agg'        => $avAgg,       // 3/5/10走の窓集約
                'cond_agg'   => $avCondAgg,   // 条件別成績
                'waku'       => isset($avH->waku) ? (int)$avH->waku : null,
                'num'        => $avNum,
                'today_ids'  => [            // 今走のカテゴリID（辞書ベース）
                    'course_id'    => $avCatId('course',    $avTodayCond),
                    'grade_id'     => $avCatId('grade',     $avTodayGrade),
                    'condition_id' => $avCatId('condition', $avTodayBaba),
                    'jockey_id'    => $avCatId('jockey',    $avH->jockey ?? null),
                ],
            ];
        }

        // 履歴が取れなかった馬も固定長の枠で埋める（値は null。0にしない）
        foreach ($horseNums as $avN2) {
            if (isset($avOut[$avN2])) continue;
            $avEmpty = [];
            foreach ($avNumericKeys as $avK) $avEmpty[$avK] = array_fill(0, $avLen, null);
            foreach (['same_condition','same_grade','same_jockey','same_baba','same_basho',
                      'course_id','grade_id','condition_id','jockey_id','basho_id'] as $avK) {
                $avEmpty[$avK] = array_fill(0, $avLen, null);
            }
            $avAgg2 = [];
            foreach ([3,5,10] as $avW) {
                foreach ($avNumericKeys as $avK) {
                    $avAgg2["w{$avW}_{$avK}_count"]  = 0;
                    $avAgg2["w{$avW}_{$avK}_mean"]   = null;
                    $avAgg2["w{$avW}_{$avK}_median"] = null;
                    $avAgg2["w{$avW}_{$avK}_min"]    = null;
                    $avAgg2["w{$avW}_{$avK}_max"]    = null;
                }
                $avAgg2["w{$avW}_win_count"]=0; $avAgg2["w{$avW}_top3_count"]=0;
                $avAgg2["w{$avW}_top3_rate"]=null; $avAgg2["w{$avW}_top5_rate"]=null;
            }
            $avCond2 = [];
            foreach (['same_course','same_dist','same_grade','same_jockey',
                      'same_baba','same_basho'] as $avName) {
                $avCond2["{$avName}_count"]=0; $avCond2["{$avName}_mean"]=null;
                $avCond2["{$avName}_median"]=null; $avCond2["{$avName}_best"]=null;
                $avCond2["{$avName}_win_count"]=0; $avCond2["{$avName}_top3_count"]=0;
                $avCond2["{$avName}_top5_count"]=0;
                $avCond2["{$avName}_win_rate"]=null; $avCond2["{$avName}_top3_rate"]=null;
                $avCond2["{$avName}_top5_rate"]=null;
            }
            // 不利フラグ（取得元未確定。NULL固定。0にしない・推測しない）
            $avTrouble2 = ['source' => null, 'available' => false];
            foreach (['late_start', 'interference', 'trouble', 'wide_course'] as $avTk2) {
                $avTrouble2[$avTk2] = array_fill(0, $avLen, null);
            }
            $avOut[$avN2] = ['series'=>$avEmpty,'agg'=>$avAgg2,'cond_agg'=>$avCond2,
                             'trouble'=>$avTrouble2,
                             'waku'=>null,'num'=>$avN2,
                             'today_ids'=>['course_id'=>0,'grade_id'=>0,'condition_id'=>0,'jockey_id'=>0]];
        }
        ksort($avOut);

        // ── 補完用中央値（model_version へ保存して再現可能にする）──────────
        // 仕様: 数値欠損の補完に使う中央値は学習期間から求め、モデルと一緒に保存する。
        //   ここで返す値は「このレース時点で観測できた値」の中央値であり、
        //   学習時は train 区分のみから作り直して model_version へ格納する。
        $avImput = [];
        foreach ($avAllForMedian as $avK => $avVals) {
            $avImput[$avK] = $this->_medianOf($avVals);
        }

        return [
            'vectors'    => $avOut,
            'imputation' => $avImput,
            'dict_used'  => [
                'is_loaded' => ($catDict !== null),
                'sizes'     => array_map('count', $avDict),
                'unknown_id'=> 0,
                'note'      => 'カテゴリ辞書は学習期間のみで作成し、未知の値は UNKNOWN(0) とする',
            ],
        ];
    }

    /** 中央値（空配列なら null）。偶数個は中央2値の平均。 */
    private function _medianOf(array $values): ?float
    {
        $mdV = array_values(array_filter($values, fn($v) => $v !== null));
        $mdN = count($mdV);
        if ($mdN === 0) return null;
        sort($mdV);
        $mdH = intdiv($mdN, 2);
        return ($mdN % 2 === 1)
            ? round((float)$mdV[$mdH], 4)
            : round(((float)$mdV[$mdH - 1] + (float)$mdV[$mdH]) / 2.0, 4);
    }

    /**
     * カテゴリ辞書の作成（学習期間のみ）
     *
     * 仕様: 辞書は【学習期間だけ】で作る。検証・評価期間や今走のデータで
     *   辞書を増やしてはいけない（学習期間外の情報が混入するため）。
     *   辞書に無い値は UNKNOWN(0) として扱う。ID は 1 から振る。
     *
     * @param  string $trainFromDate 学習期間の開始日
     * @param  string $trainToDate   学習期間の終了日
     * @return array{course:array, grade:array, condition:array, jockey:array, meta:array}
     */
    private function _buildCategoryDict(string $trainFromDate, string $trainToDate): array
    {
        $cdRows = DB::table('t_horse_odds_finder_shutsuba_history')
            ->where('date', '>=', $trainFromDate)
            ->where('date', '<=', $trainToDate)
            ->get(['condition', 'grade', 'jockey', 'basho_code']);

        $cdSets = ['course' => [], 'grade' => [], 'condition' => [], 'jockey' => [], 'basho' => []];
        foreach ($cdRows as $cdR) {
            if (!empty($cdR->condition)) {
                $cdSets['course'][(string)$cdR->condition]    = true;
                $cdSets['condition'][(string)$cdR->condition] = true;
            }
            if (!empty($cdR->grade))      $cdSets['grade'][(string)$cdR->grade]       = true;
            if (!empty($cdR->jockey))     $cdSets['jockey'][(string)$cdR->jockey]     = true;
            if (!empty($cdR->basho_code)) $cdSets['basho'][(string)$cdR->basho_code]  = true;
        }

        $cdDict = [];
        foreach ($cdSets as $cdKind => $cdVals) {
            $cdKeys = array_keys($cdVals);
            sort($cdKeys); // ID を決定論的にするため並べてから振る
            $cdMap = [];
            $cdId  = 1;    // 0 は UNKNOWN 予約
            foreach ($cdKeys as $cdV) $cdMap[$cdV] = $cdId++;
            $cdDict[$cdKind] = $cdMap;
        }
        $cdDict['meta'] = [
            'train_from' => $trainFromDate,
            'train_to'   => $trainToDate,
            'unknown_id' => 0,
            'sizes'      => array_map('count', array_diff_key($cdDict, ['meta' => 1])),
            'note'       => '学習期間のみで作成。未知の値は UNKNOWN(0)',
        ];
//         \Log::info('[Block11] カテゴリ辞書作成', $cdDict['meta']);
        return $cdDict;
    }

    /**
     * 帯基準馬方式（5点比較帯）による決定論的順位生成
     *
     * 仕様（よっしー最新確定版 / 20260913-11.txt）:
     *   統合おすすめ度が帯基準馬から5点以内の候補を同一比較帯にまとめ、
     *   帯内を次のタイブレーク順で並べ替える。
     *     ① 高配当総合点  ② 両AI一致馬  ③ 複勝継続流入A点  ④ 回収率裏付けD点
     *     ⑤ 予測補正OPI   ⑥ ability_score  ⑦ 馬番
     *
     * 帯の作り方:
     *   統合おすすめ度の降順に走査し、先頭馬を最初の「帯基準馬」とする。
     *   基準馬のスコアから5点以内の馬を同じ帯へ入れる。
     *   5点を超えて離れた馬が現れたら、その馬を次の帯の基準馬とする。
     *
     * 決定論性:
     *   最終タイブレークが馬番（全馬で一意）のため、同一入力からは常に同一順位が得られる。
     *   ソートは安定性に依存しない全順序比較で行う。
     *
     * 【重要】本メソッドはシャドー専用。戻り値は features_json への保存のみに使用し、
     *   本番候補・本番順位・Flutter表示には一切反映しない（よっしー指示により本番反映禁止）。
     *   呼び出し側でも $mergedHorses の並び順は変更しないこと。
     *
     * @param  array $horses          統合済み候補馬（_saveHighPayoutShadow 実行後）
     * @param  array $b13ScoreAMap    馬番 => 複勝継続流入A点
     * @param  array $b10ScoreDMap    馬番 => 回収率裏付けD点
     * @param  array $oddsHorseBlocks 馬番 => 馬別プロンプトブロック（予測補正OPI抽出用）
     * @return array{bands:array, ranking:array, band_width:float, tiebreak_order:array}
     */
    private function _calcBandBasedRanking(
        array $horses,
        array $b13ScoreAMap,
        array $b10ScoreDMap,
        array $oddsHorseBlocks
    ): array {
        $bandWidth = 5.0; // 帯幅: 帯基準馬から5点以内

        if (empty($horses)) {
            return [
                'bands'          => [],
                'ranking'        => [],
                'band_width'     => $bandWidth,
                'tiebreak_order' => [],
            ];
        }

        // ── タイブレーク用の値を各馬へ集約 ────────────────────────────────
        $bmRows = [];
        foreach ($horses as $bmH) {
            $bmNum = (int)($bmH['num'] ?? 0);
            if ($bmNum <= 0) continue;

            // ⑤ 予測補正OPI: 馬別ブロックから抽出（「－」表記や欠損は null）
            $bmOpi   = null;
            $bmBlock = $oddsHorseBlocks[$bmNum] ?? '';
            if (preg_match('/予測補正OPI: ([\d.]+)/u', $bmBlock, $bmOm)) {
                $bmOpi = (float)$bmOm[1];
            }

            $bmRows[] = [
                'num'               => $bmNum,
                'name'              => $bmH['name'] ?? '',
                'score'             => (float)($bmH['score'] ?? 0),   // 統合おすすめ度（帯の判定軸）
                'category'          => $bmH['category'] ?? '',
                // ① 高配当総合点（_saveHighPayoutShadow が付与済み。null=不明）
                'high_payout_score' => isset($bmH['high_payout_score']) ? (float)$bmH['high_payout_score'] : null,
                // ② 両AI一致馬
                'is_matched'        => (($bmH['category'] ?? '') === 'matched') ? 1 : 0,
                // ③ 複勝継続流入A点
                'score_a'           => isset($b13ScoreAMap[$bmNum]) ? (int)$b13ScoreAMap[$bmNum] : null,
                // ④ 回収率裏付けD点
                'score_d'           => isset($b10ScoreDMap[$bmNum]) ? (int)$b10ScoreDMap[$bmNum] : null,
                // ⑤ 予測補正OPI（小さいほど market が過小評価＝妙味あり → 昇順が上位）
                'predicted_opi'     => $bmOpi,
                // ⑥ ability_score（_saveHighPayoutShadow が付与済み）
                'ability_score'     => isset($bmH['ability_corr_merged']) ? (float)$bmH['ability_corr_merged'] : null,
            ];
        }
        if (empty($bmRows)) {
            return [
                'bands'          => [],
                'ranking'        => [],
                'band_width'     => $bandWidth,
                'tiebreak_order' => [],
            ];
        }

        // ── 帯分け: 統合おすすめ度 降順（同点は馬番昇順）で走査 ──────────────
        usort($bmRows, function ($x, $y) {
            if ($x['score'] !== $y['score']) return $y['score'] <=> $x['score'];
            return $x['num'] <=> $y['num'];
        });

        $bmBands       = [];
        $bmCurBand     = [];
        $bmBaseScore   = null;  // 現在の帯基準馬の統合おすすめ度
        $bmBaseNum     = null;  // 現在の帯基準馬の馬番
        foreach ($bmRows as $bmRow) {
            if ($bmBaseScore === null || ($bmBaseScore - $bmRow['score']) > $bandWidth) {
                // 新しい帯を開始（この馬が次の帯基準馬）
                if (!empty($bmCurBand)) {
                    $bmBands[] = ['base_num' => $bmBaseNum, 'base_score' => $bmBaseScore, 'members' => $bmCurBand];
                }
                $bmCurBand   = [];
                $bmBaseScore = $bmRow['score'];
                $bmBaseNum   = $bmRow['num'];
            }
            $bmCurBand[] = $bmRow;
        }
        if (!empty($bmCurBand)) {
            $bmBands[] = ['base_num' => $bmBaseNum, 'base_score' => $bmBaseScore, 'members' => $bmCurBand];
        }

        // ── 帯内タイブレーク（7段階・全順序） ────────────────────────────
        // null は「不明」として常に後ろへ回す（決定論性を保つため null 同士は次段へ）
        $bmCmpDesc = function ($a, $b) {          // 大きいほど上位
            if ($a === null && $b === null) return 0;
            if ($a === null) return  1;
            if ($b === null) return -1;
            return $b <=> $a;
        };
        $bmCmpAsc = function ($a, $b) {           // 小さいほど上位
            if ($a === null && $b === null) return 0;
            if ($a === null) return  1;
            if ($b === null) return -1;
            return $a <=> $b;
        };
        $bmTiebreak = function ($x, $y) use ($bmCmpDesc, $bmCmpAsc) {
            // ① 高配当総合点（降順）
            if (($r = $bmCmpDesc($x['high_payout_score'], $y['high_payout_score'])) !== 0) return $r;
            // ② 両AI一致馬（一致馬を上位）
            if ($x['is_matched'] !== $y['is_matched']) return $y['is_matched'] <=> $x['is_matched'];
            // ③ 複勝継続流入A点（降順）
            if (($r = $bmCmpDesc($x['score_a'], $y['score_a'])) !== 0) return $r;
            // ④ 回収率裏付けD点（降順）
            if (($r = $bmCmpDesc($x['score_d'], $y['score_d'])) !== 0) return $r;
            // ⑤ 予測補正OPI（昇順: 小さいほど市場の過小評価＝妙味あり）
            if (($r = $bmCmpAsc($x['predicted_opi'], $y['predicted_opi'])) !== 0) return $r;
            // ⑥ ability_score（降順）
            if (($r = $bmCmpDesc($x['ability_score'], $y['ability_score'])) !== 0) return $r;
            // ⑦ 馬番（昇順・全馬一意のため必ずここで確定＝決定論的）
            return $x['num'] <=> $y['num'];
        };

        $bmRanking  = [];
        $bmRank     = 1;
        $bmBandsOut = [];
        foreach ($bmBands as $bmBandIdx => $bmBand) {
            $bmMembers = $bmBand['members'];
            usort($bmMembers, $bmTiebreak);
            $bmMemberNums = [];
            foreach ($bmMembers as $bmM) {
                $bmMemberNums[]  = $bmM['num'];
                $bmRanking[]     = [
                    'rank'              => $bmRank++,
                    'band_id'           => $bmBandIdx + 1,
                    'num'               => $bmM['num'],
                    'name'              => $bmM['name'],
                    'score'             => $bmM['score'],
                    'category'          => $bmM['category'],
                    'high_payout_score' => $bmM['high_payout_score'],
                    'score_a'           => $bmM['score_a'],
                    'score_d'           => $bmM['score_d'],
                    'predicted_opi'     => $bmM['predicted_opi'],
                    'ability_score'     => $bmM['ability_score'],
                ];
            }
            $bmBandsOut[] = [
                'band_id'      => $bmBandIdx + 1,
                'base_num'     => $bmBand['base_num'],
                'base_score'   => $bmBand['base_score'],
                'score_range'  => [$bmBand['base_score'] - $bandWidth, $bmBand['base_score']],
                'member_count' => count($bmMembers),
                'member_nums'  => $bmMemberNums,
            ];
        }

//         \Log::info('[BandMethod] 帯基準馬方式 順位生成（シャドー専用・本番未反映）', [
//             'band_count'  => count($bmBandsOut),
//             'horse_count' => count($bmRanking),
//             'bands'       => array_map(
//                 fn($b) => "帯{$b['band_id']}: 基準馬番{$b['base_num']}({$b['base_score']}点) "
//                         . count($b['member_nums']) . '頭',
//                 $bmBandsOut
//             ),
//         ]);

        return [
            'bands'          => $bmBandsOut,
            'ranking'        => $bmRanking,
            'band_width'     => $bandWidth,
            'tiebreak_order' => [
                '1_high_payout_score', '2_matched_ai', '3_score_a_fuku_inflow',
                '4_score_d_recovery',  '5_predicted_opi', '6_ability_score', '7_num',
            ],
        ];
    }

    /**
     * Block 11: 断層パターン学習 特徴量スナップショット保存
     * フェーズ1: 1,000レース分の特徴量を蓄積するだけ（予測は行わない）
     * result_label はレース結果確定後のバッチ処理で別途書き込む
     */
    private function _saveMlSnapshot(
        array  $mergedHorses,
        string $gapTypeForMerge,
        ?int   $primaryGapUpperPopForMerge,
        int    $mergeUpperMax,
        int    $mergeMidMax,
        int    $mergeLowerMax,
        int    $horseCount2nd,
        int    $upsetRaceFinal,
        ?int   $b14WaveLevel,
        ?int   $b14LowerEntry,
        ?int   $b14BigGap,
        bool   $condAMet,
        bool   $condBMet,
        bool   $condCMet,
        bool   $condDMet,
        int    $b10ScoreE,
        array  $firstAiHorses,
        array  $secondAiHorses,
        string $oddsData,            // input_hash 計算用・全頭テキスト抽出用
        array  $oddsHorseBlocks,     // 馬別プロンプトブロック（各種特徴量抽出用）
        array  $b6TanPopMap,         // 馬番→単勝人気マップ（6分前基準）
        array  $b6FukuPopMap,        // 馬番→複勝人気マップ（6分前基準）
        $b6OddsRows,                 // 6分前オッズ行（Illuminate\Support\Collection）
                                     // ※型宣言なし: DB::table()->get() は Collection を返すため
                                     //   array 型宣言を付けると実行時 TypeError になる
        array  $b13ScoreAMap,        // 馬番→複勝継続流入A点（帯基準馬方式タイブレーク③）
        array  $b10ScoreDMap,        // 馬番→回収率裏付けD点（帯基準馬方式タイブレーク④）
        string $date,
        int    $kaisuu,
        string $basho,
        int    $day,
        int    $race,
        object $raceRow
    ): void {
        // ── Block 11: 断層パターン学習 特徴量スナップショット保存 ───────────────────
        // フェーズ1: 1,000レース分の特徴量を蓄積するだけ（予測は行わない）
        // result_label はレース結果確定後のバッチ処理で別途書き込む
        try {
            // ── features_json の構築 ──────────────────────────────────────────
            // 断層構造・レース指標・統合馬リストのサマリーを特徴量として記録
            $b11MergedPops    = array_values(array_map(fn($h) => (int)($h['popularity'] ?? 0), $mergedHorses));
            $b11MergedScores  = array_values(array_map(fn($h) => (int)($h['score']      ?? 0), $mergedHorses));
            $b11MergedNums    = array_values(array_map(fn($h) => (int)($h['num']        ?? 0), $mergedHorses)); // B-14: M4馬別正解ラベル算出用

            // カテゴリ別集計（matched / first_unique / second_unique）
            $b11CntMatched  = 0;
            $b11CntFirst    = 0;
            $b11CntSecond   = 0;
            foreach ($mergedHorses as $b11h) {
                switch ($b11h['category'] ?? '') {
                    case 'matched':       $b11CntMatched++; break;
                    case 'first_only':    $b11CntFirst++;   break;
                    case 'second_only':   $b11CntSecond++;  break;
                }
            }

            // ── input_hash: 推論の完全再現用ハッシュ（複数データソースを含む）──────
            // $oddsData（プロンプト本文）+ $b6TanPopMap（6分前人気マップ）
            // + $b6OddsRows行数ハッシュ（全時点オッズ変化検知）の連結
            //
            // 【能力・適性元データの包含証明（よっしー03.txt指摘#6）】
            //   $oddsData には getHorseOddsFinderAiAnalysis() 内で
            //     $oddsData .= $b9HistoryText;   （Block 9・本ファイル内）
            //   により「【各馬の直近成績（過去最大10走）と今走データ】」ブロックが結合される。
            //   $b9HistoryText は t_horse_odds_finder_shutsuba_history から取得した
            //   全出走馬の直近最大10走（着順・距離・コース・グレード・騎手・斤量・馬体重・
            //   コーナー通過順）と今走条件（dist/course/grade）を含む能力・適性の元データ。
            //   したがって能力・適性元データが1バイトでも変われば $oddsData が変わり、
            //   input_hash も必ず変化する（＝ハッシュ対象に含まれている）。
            $b11TanPopSerial = '';
            ksort($b6TanPopMap);
            foreach ($b6TanPopMap as $_b11num => $_b11pop) {
                $b11TanPopSerial .= "{$_b11num}:{$_b11pop}|";
            }
            $b11OddsRowsSerial = count($b6OddsRows) . ':' . md5(json_encode($b6OddsRows, JSON_UNESCAPED_UNICODE));
            $b11InputHash = hash('sha256',
                $oddsData . '||' . $b11TanPopSerial . '||' . $b11OddsRowsSerial
            );

            // ══════════════════════════════════════════════════════════════════
            // 全時点特徴量（よっしー03.txt指摘#4 対応）
            // S(999)・21・18・15・12・9・6分の全時点オッズ推移から
            // 断層比率・断層遷移（出現/継続/消滅/拡大/縮小）・人気入替回数を算出
            // ══════════════════════════════════════════════════════════════════
            $b11Timings = [Constants::ODDS_DB_FIRST, 21, 18, 15, 12, 9, 6]; // = [999,21,18,15,12,9,6]

            $b11AllOddsRows = DB::table('t_horse_odds_finder_odds')
                ->where('date',   $date)
                ->where('kaisuu', $kaisuu)
                ->where('basho',  $basho)
                ->where('day',    $day)
                ->where('race',   $race)
                ->whereIn('minutes_before_start', $b11Timings)
                ->get(['num', 'minutes_before_start', 'odds', 'fuku_min', 'fuku_max']);

            // 馬番 → 時点 → {tan, fuku_min, fuku_max}
            $b11Series = [];
            foreach ($b11AllOddsRows as $_b11r) {
                $_b11n = (int)$_b11r->num;
                $_b11t = (int)$_b11r->minutes_before_start;
                $b11Series[$_b11n][$_b11t] = [
                    'tan'      => (float)$_b11r->odds,
                    'fuku_min' => (float)$_b11r->fuku_min,
                    'fuku_max' => (float)$_b11r->fuku_max,
                ];
            }

            // ── 各時点の単勝人気順・複勝人気順を算出 ──────────────────────────
            $b11TanPopByTiming  = []; // [timing][num] = 単勝人気順
            $b11FukuPopByTiming = []; // [timing][num] = 複勝人気順
            foreach ($b11Timings as $_b11t) {
                $_b11tanPairs  = [];
                $_b11fukuPairs = [];
                foreach ($b11Series as $_b11n => $_b11byT) {
                    if (isset($_b11byT[$_b11t])) {
                        if ($_b11byT[$_b11t]['tan'] > 0) {
                            $_b11tanPairs[$_b11n] = $_b11byT[$_b11t]['tan'];
                        }
                        if ($_b11byT[$_b11t]['fuku_min'] > 0) {
                            $_b11fukuPairs[$_b11n] = $_b11byT[$_b11t]['fuku_min'];
                        }
                    }
                }
                asort($_b11tanPairs);
                asort($_b11fukuPairs);
                $_b11rank = 1;
                foreach ($_b11tanPairs as $_b11n => $_b11o) {
                    $b11TanPopByTiming[$_b11t][$_b11n] = $_b11rank++;
                }
                $_b11rank = 1;
                foreach ($_b11fukuPairs as $_b11n => $_b11o) {
                    $b11FukuPopByTiming[$_b11t][$_b11n] = $_b11rank++;
                }
            }

            // ── 人気入替回数: 隣接時点間で単勝人気順が変化した回数（馬別）──────
            $b11PopSwapCount = [];
            foreach ($b11Series as $_b11n => $_b11byT) {
                $_b11cnt  = 0;
                $_b11prev = null;
                foreach ($b11Timings as $_b11t) {
                    $_b11p = $b11TanPopByTiming[$_b11t][$_b11n] ?? null;
                    if ($_b11p !== null) {
                        if ($_b11prev !== null && $_b11p !== $_b11prev) $_b11cnt++;
                        $_b11prev = $_b11p;
                    }
                }
                $b11PopSwapCount[$_b11n] = $_b11cnt;
            }

            // ── 各時点の隣接人気間 断層比率（単勝・複勝）────────────────────
            // 断層比率 = 直下人気馬のオッズ ÷ 直上人気馬のオッズ（既存ロジックと同一）
            // is_gap: 比率 >= 2.0 で断層成立
            $b11BuildGapRows = function (array $popMap, int $timing, string $oddsKey) use ($b11Series): array {
                if (empty($popMap)) return [];
                asort($popMap); // 人気順に整列
                $_nums = array_keys($popMap);
                $_rows = [];
                for ($_i = 0; $_i < count($_nums) - 1; $_i++) {
                    $_uN = $_nums[$_i];
                    $_lN = $_nums[$_i + 1];
                    $_uO = $b11Series[$_uN][$timing][$oddsKey] ?? 0;
                    $_lO = $b11Series[$_lN][$timing][$oddsKey] ?? 0;
                    if ($_uO <= 0) continue;
                    $_ratio = round($_lO / $_uO, 2);
                    $_rows[] = [
                        'upper_pop' => $_i + 1,
                        'lower_pop' => $_i + 2,
                        'upper_num' => $_uN,
                        'lower_num' => $_lN,
                        'ratio'     => $_ratio,
                        'is_gap'    => ($_ratio >= 2.0) ? 1 : 0,
                    ];
                }
                return $_rows;
            };

            $b11TanGapByTiming  = [];
            $b11FukuGapByTiming = [];
            foreach ($b11Timings as $_b11t) {
                $b11TanGapByTiming[$_b11t]  = $b11BuildGapRows(
                    $b11TanPopByTiming[$_b11t] ?? [], $_b11t, 'tan'
                );
                $b11FukuGapByTiming[$_b11t] = $b11BuildGapRows(
                    $b11FukuPopByTiming[$_b11t] ?? [], $_b11t, 'fuku_min'
                );
            }

            // ── 断層の 出現／継続／消滅／拡大／縮小（隣接時点比較・単勝基準）──
            $b11GapTransitions = [];
            $b11PrevGapSet     = null;
            foreach ($b11Timings as $_b11t) {
                $_b11cur = [];
                foreach (($b11TanGapByTiming[$_b11t] ?? []) as $_b11g) {
                    if ($_b11g['is_gap']) $_b11cur[$_b11g['upper_pop']] = $_b11g['ratio'];
                }
                if ($b11PrevGapSet !== null) {
                    $_b11curPos  = array_keys($_b11cur);
                    $_b11prevPos = array_keys($b11PrevGapSet);
                    $_b11cont    = array_values(array_intersect($_b11curPos, $_b11prevPos));
                    $_b11exp     = [];
                    $_b11shr     = [];
                    foreach ($_b11cont as $_b11pos) {
                        if ($_b11cur[$_b11pos] > $b11PrevGapSet[$_b11pos])      $_b11exp[] = $_b11pos;
                        elseif ($_b11cur[$_b11pos] < $b11PrevGapSet[$_b11pos])  $_b11shr[] = $_b11pos;
                    }
                    $b11GapTransitions[$_b11t] = [
                        'appeared'  => array_values(array_diff($_b11curPos,  $_b11prevPos)),
                        'continued' => $_b11cont,
                        'vanished'  => array_values(array_diff($_b11prevPos, $_b11curPos)),
                        'expanded'  => $_b11exp,
                        'shrunk'    => $_b11shr,
                    ];
                }
                $b11PrevGapSet = $_b11cur;
            }

            // ── 最大・最小・主断層比率（6分前基準）────────────────────────
            $b11Gap6       = $b11TanGapByTiming[6] ?? [];
            $b11RatioList  = array_map(fn($g) => $g['ratio'], $b11Gap6);
            $b11MaxRatio   = !empty($b11RatioList) ? max($b11RatioList) : null;
            $b11MinRatio   = !empty($b11RatioList) ? min($b11RatioList) : null;
            $b11GapCount6  = count(array_filter($b11Gap6, fn($g) => $g['is_gap'] === 1));
            $b11PrimaryRatio = null;
            if ($primaryGapUpperPopForMerge !== null) {
                foreach ($b11Gap6 as $_b11g) {
                    if ((int)$_b11g['upper_pop'] === (int)$primaryGapUpperPopForMerge) {
                        $b11PrimaryRatio = $_b11g['ratio'];
                        break;
                    }
                }
            }

            // ── 単複断層の一致・不一致（6分前・断層成立位置の比較）──────────
            $b11TanGapPos6  = array_values(array_map(
                fn($g) => $g['upper_pop'],
                array_filter($b11Gap6, fn($g) => $g['is_gap'] === 1)
            ));
            $b11FukuGap6    = $b11FukuGapByTiming[6] ?? [];
            $b11FukuGapPos6 = array_values(array_map(
                fn($g) => $g['upper_pop'],
                array_filter($b11FukuGap6, fn($g) => $g['is_gap'] === 1)
            ));
            $b11GapMatch = [
                'tan_gap_positions'  => $b11TanGapPos6,
                'fuku_gap_positions' => $b11FukuGapPos6,
                'matched_positions'  => array_values(array_intersect($b11TanGapPos6, $b11FukuGapPos6)),
                'tan_only_positions' => array_values(array_diff($b11TanGapPos6, $b11FukuGapPos6)),
                'fuku_only_positions'=> array_values(array_diff($b11FukuGapPos6, $b11TanGapPos6)),
                'is_consistent'      => (
                    count(array_diff($b11TanGapPos6, $b11FukuGapPos6)) === 0
                    && count(array_diff($b11FukuGapPos6, $b11TanGapPos6)) === 0
                ) ? 1 : 0,
            ];

            // ── 当時の類似レース統計を保存（受入チェック #48）────────────────
            // 類似統計はプロンプトには出していたが保存していなかった。
            // 後から当時の統計を復元できないため、Phase1の今から保存する。
            // 取得方法: プロンプトへ埋め込んだ「類似レース統計（N番人気 × 帯）」行を
            //   正規表現で回収する。テーブルを引き直すと帯の判定条件がずれる可能性があり、
            //   また将来テーブルが更新されると当時の値と変わってしまう。
            //   プロンプトから回収すれば、当時AIへ渡した値そのものが保存され再現性が最も高い。
            $b11SimilarStats = [];
            foreach ($b6TanPopMap as $_b11sn => $_b11sp) {
                $_b11sb = $oddsHorseBlocks[$_b11sn] ?? '';
                if (preg_match(
                    '/類似レース統計[（(](\d+)番人気 × ([^・)）]+)・N=(\d+)・信頼度:([^)）]+)[）)]: '
                    . '3着以内率([\d.]+)% \/ 5着以内率([\d.]+)% \/ 平均着順([\d.]+)位 \/ 着外率([\d.]+)%/u',
                    $_b11sb, $_b11sm
                )) {
                    $b11SimilarStats[$_b11sn] = [
                        'popularity'    => (int)$_b11sm[1],
                        'band'          => trim($_b11sm[2]),
                        'sample_count'  => (int)$_b11sm[3],
                        'reliability'   => trim($_b11sm[4]),
                        'top3_rate'     => (float)$_b11sm[5],
                        'top5_rate'     => (float)$_b11sm[6],
                        'avg_finish'    => (float)$_b11sm[7],
                        'outside_rate'  => (float)$_b11sm[8],
                    ];
                } else {
                    $b11SimilarStats[$_b11sn] = ['status' => 'insufficient'];
                }
            }

            // ── 枠番マップ取得（よっしー03.txt指摘#4: 枠番）──────────────────
            $b11WakuMap = [];
            foreach (DB::table('t_horse_odds_finder_horses')
                ->where('date',   $date)
                ->where('kaisuu', $kaisuu)
                ->where('basho',  $basho)
                ->where('day',    $day)
                ->where('race',   $race)
                ->get(['num', 'waku']) as $_b11w) {
                $b11WakuMap[(int)$_b11w->num] = isset($_b11w->waku) ? (int)$_b11w->waku : null;
            }

            // ── 能力・適性データの固定長ベクトル化（受入チェック #63）──────────
            // 当時のデータを後から再現できないため Phase1 の今から保存する。
            // 取得は「今走日より前」に限定し未来情報混入を防止している。
            // カテゴリ辞書は学習期間のみで作成する（Phase1は学習未実施のため null）
            $b11CatDict = null;
            $b11AbilityRes = $this->_buildAbilityVectors(
                array_map('intval', array_keys($b6TanPopMap)),
                $date, $kaisuu, $basho, $day, $race, $raceRow, $b11CatDict
            );
            $b11AbilityVectors = $b11AbilityRes['vectors'];
            $b11Imputation     = $b11AbilityRes['imputation'];  // 中央値補完値
            $b11DictUsed       = $b11AbilityRes['dict_used'];

            // ── M4: 「2AI候補内」と「全頭発見」の分離保存 ──────────────────
            // 仕様: M4は2つの母集団で別々に正解ラベルを作るため、保存時点で分離しておく。
            $b11AllNums      = array_map('intval', array_keys($b6TanPopMap));
            sort($b11AllNums);
            $b11CandidateNums = array_values(array_unique(array_map(
                fn($h) => (int)($h['num'] ?? 0), $mergedHorses
            )));
            sort($b11CandidateNums);
            $b11M4Separated = [
                // ①2AI候補内: 統合候補に入った馬のみを母集団とする
                'in_ai_candidates' => [
                    'nums'  => $b11CandidateNums,
                    'count' => count($b11CandidateNums),
                ],
                // ②全頭発見: 出走全頭を母集団とする
                'all_horses'       => [
                    'nums'  => $b11AllNums,
                    'count' => count($b11AllNums),
                ],
                // 候補に入らなかった馬（全頭発見でのみ評価対象になる馬）
                'not_in_candidates' => array_values(array_diff($b11AllNums, $b11CandidateNums)),
                'note' => 'result_label はレース結果確定後のバッチ処理で母集団ごとに別々に書き込む',
            ];

            // ── all_horses_features: 全馬の固定長特徴量ベクトル ────────────────────
            // 各馬について: 馬番・6分前人気・6分前単勝オッズ・推定確定複勝最小・
            //              推定確定単勝・過去回収率（3種）・断層上下位置
            $b11AllHorsesFeatures = [];
            foreach ($b6TanPopMap as $_b11num => $_b11pop) {
                $_b11block     = $oddsHorseBlocks[$_b11num] ?? '';
                $_b11tanOdds   = null;
                $_b11fukuMin   = null;
                $_b11rateArr   = [];
                $_b11gapPos    = null;
                if (preg_match('/推定確定オッズ: ([\d.]+)/u', $_b11block, $_b11tm)) {
                    $_b11tanOdds = (float)$_b11tm[1];
                }
                if (preg_match('/推定確定複勝最小: ([\d.]+)/u', $_b11block, $_b11fm)) {
                    $_b11fukuMin = (float)$_b11fm[1];
                }
                foreach ([
                    '過去回収率',
                    'OPI帯別回収率',
                    'フェーズパターン別回収率',
                ] as $_b11rLabel) {
                    if (preg_match(
                        '/' . preg_quote($_b11rLabel, '/') . '[（(][^）)]+[）)]: 回収率([\d.]+)%\s+勝率[\d.]+%\s+サンプル(\d+)件/u',
                        $_b11block, $_b11rm
                    )) {
                        $_b11rateArr[$_b11rLabel] = [
                            'rate'    => (float)$_b11rm[1],
                            'samples' => (int)$_b11rm[2],
                        ];
                    }
                }
                // 断層上下位置: primaryGapUpperPopForMerge が有効な場合のみ判定
                if ($primaryGapUpperPopForMerge !== null) {
                    $_b11gapPos = ((int)$_b11pop <= $primaryGapUpperPopForMerge) ? 'upper' : 'lower';
                }
                // 全時点オッズ推移・人気順推移（S(999)・21・18・15・12・9・6分）
                $_b11tanSeries  = [];
                $_b11fukuSeries = [];
                $_b11popSeries  = [];
                foreach ($b11Timings as $_b11t) {
                    $_b11tanSeries[(string)$_b11t]  = $b11Series[$_b11num][$_b11t]['tan']      ?? null;
                    $_b11fukuSeries[(string)$_b11t] = $b11Series[$_b11num][$_b11t]['fuku_min'] ?? null;
                    $_b11popSeries[(string)$_b11t]  = $b11TanPopByTiming[$_b11t][$_b11num]     ?? null;
                }
                $b11AllHorsesFeatures[] = [
                    'num'            => $_b11num,
                    'waku'           => $b11WakuMap[$_b11num] ?? null,    // 枠番
                    'pop_6m'         => (int)$_b11pop,
                    'fuku_pop_6m'    => $b6FukuPopMap[$_b11num] ?? null,  // 複勝人気
                    'tan_odds_6m'    => $_b11tanOdds,
                    'fuku_min_est'   => $_b11fukuMin,
                    'rates'          => $_b11rateArr,
                    'gap_position'   => $_b11gapPos,
                    'tan_series'     => $_b11tanSeries,   // 全時点単勝オッズ
                    'fuku_series'    => $_b11fukuSeries,  // 全時点複勝最小オッズ
                    'pop_series'     => $_b11popSeries,   // 全時点単勝人気順
                    'pop_swap_count' => $b11PopSwapCount[$_b11num] ?? 0, // 人気入替回数
                    // #48: 当時の類似レース統計（AIへ渡した値そのもの）
                    'similar_stats'  => $b11SimilarStats[$_b11num] ?? null,
                    // #63: 能力・適性の固定長ベクトル（最大10走・不足はnull埋め）
                    'ability_vector' => $b11AbilityVectors[$_b11num] ?? null,
                    // M4: この馬が2AI統合候補に入っているか（母集団の分離用）
                    'in_ai_candidate'=> in_array($_b11num, $b11CandidateNums, true) ? 1 : 0,
                ];
            }

            // ── 時系列分割 70/15/15（#49）とモデルレジストリ（#57）────────────
            // 分割は日付順で決定論的に行う（ランダム分割は未来情報混入のため禁止）
            try {
                $b11Split = $this->_assignTimeSeriesSplit($date)['summary'];
            } catch (\Throwable $b11se) {
                $b11Split = ['error' => $b11se->getMessage()];
            }
            $b11ModelRegistry = $this->_buildModelRegistry($b11InputHash);

            // ── 学習フェーズ判定（#55・#56）────────────────────────────
            try { $b11Phase = $this->_getMlPhase(); }
            catch (\Throwable $b11pe) { $b11Phase = ['error' => $b11pe->getMessage()]; }

            // ── M1〜M4 推論エンジン実行（シャドー専用・Phase1ではskipped）──
            // 学習済みモデルは model_registry から渡す。Phase1は未生成のため空。
            // 結果は保存するだけで、本番候補・順位・Flutter表示には反映しない。
            $b11MlModels = $b11ModelRegistry['active']['models'] ?? [];
            try {
                $b11Inference = $this->_runMlInference(
                    ['gap_type' => $gapTypeForMerge,
                     'primary_gap_upper_pop' => $primaryGapUpperPopForMerge,
                     'horse_count' => (int)$horseCount2nd,
                     'all_horses_features' => $b11AllHorsesFeatures],
                    $b11MlModels
                );
            } catch (\Throwable $b11ie) { $b11Inference = ['error' => $b11ie->getMessage()]; }

            // ── 帯基準馬方式（5点比較帯）による決定論的順位を算出（シャドー専用）──
            // 本番順位（$mergedHorses の並び）は変更しない。結果は features_json へ保存するのみ。
            $b11BandRanking = $this->_calcBandBasedRanking(
                $mergedHorses, $b13ScoreAMap, $b10ScoreDMap, $oddsHorseBlocks
            );

            $b11Features = json_encode([
                // メタ情報
                'model_version'         => [
                    'schema_version'     => 'v3.0',                    // 特徴量スキーマ版
                    'model_type'         => 'llm-ensemble',            // モデル種別
                    'models'             => [
                        '1st_ai' => 'claude (AnthropicService)',
                        '2nd_ai' => 'deepseek-chat',
                    ],
                    'library_versions'   => [
                        'php'     => PHP_VERSION,
                        // バージョン取得の失敗でスナップショット保存全体を落とさない
                        'laravel' => function_exists('app') ? app()->version() : null,
                    ],
                    'feature_set'        => 'phase1-full-timeseries-v5',  // v5: GBDT・10走集約・辞書・補完値
                    'algorithm'          => 'gbdt',  // 仕様: 決定木系 勾配ブースティング
                    'missing_policy'     => 'keep_null_with_flag', // 仕様: 欠損を0へ置換しない
                    'ability_history_max'=> 10,      // 仕様: 最大10走
                    'agg_windows'        => [3, 5, 10],
                    // 仕様: 数値欠損の補完に使う中央値をモデルと一緒に保存し再現可能にする
                    'imputation_medians' => $b11Imputation,
                    'category_dict'      => $b11DictUsed,
                    'feature_count'      => count($b11AllHorsesFeatures),
                    'params'             => [
                        'gap_threshold'        => 2.0,   // 断層成立比率
                        'low_odds_fuku_thresh' => 1.5,   // Block13a 複勝除外閾値
                        'low_odds_tan_thresh'  => 3.0,   // Block13a 単勝除外閾値
                        'low_odds_exception'   => 'AND', // 例外3条件の結合方式
                        'recovery_min_sample'  => 30,    // 回収率有効サンプル数
                        'recovery_low_thresh'  => 90.0,  // Block12 ハード除外閾値
                        'recovery_high_thresh' => 110.0, // Block13a 例外③閾値
                        'score_a_thresh'       => 12,    // 複勝継続流入判定
                        'merge_bonus'          => 5,     // 一致馬ボーナス
                        'band_width'           => 5.0,   // 帯基準馬方式の帯幅（シャドー）
                        'ability_history_len'  => 10,    // 能力適性ベクトルの固定長（最大10走）
                    ],
                    'train_period'       => null, // Phase1: 学習未実施
                    'train_sample_count' => 0,    // Phase1: 学習未実施
                    'model_hash'         => null, // Phase1: 学習モデル未生成
                    'phase'              => 1,
                ],
                'input_hash'            => $b11InputHash,
                // 断層構造
                'gap_type'              => $gapTypeForMerge,
                'primary_gap_upper_pop' => $primaryGapUpperPopForMerge,
                'merge_upper_max'       => $mergeUpperMax,
                'merge_mid_max'         => $mergeMidMax,
                'merge_lower_max'       => $mergeLowerMax,
                // ── レース条件（よっしー03.txt指摘#4: 競馬場・距離・クラス等）────
                'race_date'             => $date,
                'basho_code'            => $basho,
                'basho_name'            => $raceRow->basho_name ?? $basho,
                'race_num'              => $race,
                'race_name'             => $raceRow->race_name ?? null,
                'distance'              => isset($raceRow->dist)   ? (int)$raceRow->dist : null,
                'course'                => $raceRow->course ?? null,   // 芝/ダート等
                'grade'                 => $raceRow->grade  ?? null,   // クラス・格付け
                'kaisuu'                => (int)$kaisuu,
                'day'                   => (int)$day,
                // レース指標
                'horse_count'           => (int)$horseCount2nd,
                'upset_race'            => $upsetRaceFinal,
                'wave_level'            => $b14WaveLevel,
                'lower_entry'           => $b14LowerEntry,
                'big_gap_entry'         => $b14BigGap,
                'cond_a_met'            => $condAMet ? 1 : 0,
                'cond_b_met'            => $condBMet ? 1 : 0,
                'cond_c_met'            => $condCMet ? 1 : 0,
                'cond_d_met'            => $condDMet ? 1 : 0,
                // 統合馬サマリー
                'merged_horse_count'    => count($mergedHorses),
                'matched_count'         => $b11CntMatched,
                'first_unique_count'    => $b11CntFirst,
                'second_unique_count'   => $b11CntSecond,
                'merged_pops'           => $b11MergedPops,
                'merged_scores'         => $b11MergedScores,
                'merged_nums'           => $b11MergedNums,
                // スコア指標
                'score_e'               => (int)($b10ScoreE ?? 0),
                'first_ai_count'        => count($firstAiHorses),
                'second_ai_count'       => count($secondAiHorses),
                // ── 全時点断層特徴量（よっしー03.txt指摘#4）──────────────
                'timings'               => $b11Timings,            // [999,21,18,15,12,9,6]
                'gap_ratios_by_timing'  => $b11TanGapByTiming,     // 各時点の単勝隣接断層比率
                'fuku_gap_by_timing'    => $b11FukuGapByTiming,    // 各時点の複勝隣接断層比率
                'gap_transitions'       => $b11GapTransitions,     // 出現/継続/消滅/拡大/縮小
                'gap_max_ratio_6m'      => $b11MaxRatio,           // 最大断層比率
                'gap_min_ratio_6m'      => $b11MinRatio,           // 最小断層比率
                'gap_primary_ratio_6m'  => $b11PrimaryRatio,       // 主断層比率
                'gap_count_6m'          => $b11GapCount6,          // 断層成立数
                'tanpuku_gap_match'     => $b11GapMatch,           // 単複断層の一致/不一致
                // ── M4: 「2AI候補内」と「全頭発見」の分離保存 ──────────────
                'm4_separated'          => $b11M4Separated,
                // ── 時系列分割 70/15/15（#49）────────────────────────────
                'timeseries_split'      => $b11Split,
                // ── モデル更新・切戻し管理（#57）──────────────────────────
                'model_registry'        => $b11ModelRegistry,
                // ── 学習フェーズ（#55・#56）──────────────────────────────
                'ml_phase'              => $b11Phase,
                // ── M1〜M4 推論結果（シャドー専用・本番未反映）──────────────
                'ml_inference'          => $b11Inference,
                // ── 帯基準馬方式（5点比較帯）シャドー順位 ────────────────
                // ※シャドー専用。本番候補・本番順位・Flutter表示には未反映
                'band_method_ranking'   => $b11BandRanking,
                // 全頭固定長特徴量
                'all_horses_features'   => $b11AllHorsesFeatures,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            DB::statement(
                'INSERT INTO t_horse_odds_finder_ml_snapshot'
                . ' (date, kaisuu, basho, basho_code, day, race, gap_type, features)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE'
                . '   gap_type = VALUES(gap_type),'
                . '   features = VALUES(features)',
                [
                    $date,
                    (int)$kaisuu,
                    $raceRow->basho_name ?? '',
                    $basho,
                    (int)$day,
                    (int)$race,
                    $gapTypeForMerge,
                    $b11Features,
                ]
            );
//             \Log::debug('[Block11] ml_snapshot saved', [
//                 'date'       => $date,
//                 'kaisuu'     => $kaisuu,
//                 'basho_code' => $basho,
//                 'day'        => $day,
//                 'race'       => $race,
//                 'gap_type'   => $gapTypeForMerge,
//                 'merged_cnt' => count($mergedHorses),
//             ]);
        } catch (\Throwable $b11e) {
            \Log::error('[Block11] ml_snapshot INSERT failed', ['err' => $b11e->getMessage()]);
        }
        // ── Block 11 End ──────────────────────────────────────────────────────────
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

        if (count($earlyTanGapTop6) >= 2 && count($earlyTanGapStrong) >= 1) {
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

}
