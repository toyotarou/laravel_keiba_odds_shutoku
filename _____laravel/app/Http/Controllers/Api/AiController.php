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

        // ─── Claude API 呼び出し（529 Overloaded 時は指数バックオフでリトライ） ──
        $aiResponse = $this->anthropic->sendWithRetry(
            prompt:      $prompt,
            system:      $firstAiSystemPrompt,
            maxAttempts: 1,
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
                \Log::info('[2nd AI prefetch] skip: ' . $e->getMessage());
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
        } else {
            $h['estimated_final_odds']     = null;
            $h['correction_ratio']         = null;
            $h['correction_std']           = null;
            $h['estimated_final_fuku_min'] = null;
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
    $horseCount  = count($displayHorses);
    $pickupCount = $horseCount <= 8 ? 4 : ($horseCount <= 13 ? 5 : 6);

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
        $lines[] = $tanInflowLine;
        $lines[] = $fukuInflowLine;
        $lines[] = $opiLine;
        $lines[] = $estOpiLine;
        $lines[] = $fukuOpiLine;
        $lines[] = $estLine;
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
        $gapTypeGuide = '6番人気以内を本候補の中心に。断層下側でも複勝への継続流入や類似好成績があれば補欠として残す。';

    // E: 単勝・複勝断層の矛盾（どちらか一方にだけ断層）
    } elseif ($tanHasGap !== $fukuHasGap) {
        $gapType      = 'E';
        $gapTypeDesc  = '判定困難型（単勝断層' . ($tanHasGap ? 'あり' : 'なし') . '・複勝断層' . ($fukuHasGap ? 'あり' : 'なし') . 'で矛盾）';
        $gapTypeGuide = '断層より複勝オッズの継続的な動きと相対的な変化率を優先して判断すること。';

    // B: 上位断層型（top6に断層1か所）
    } elseif (count($tanGapTop6) === 1) {
        $e            = $tanGapTop6[0];
        $gapType      = 'B';
        $gapTypeDesc  = '上位断層型（' . $e['upper_pop'] . '〜' . $e['lower_pop'] . '番人気間に断層、比率' . $e['ratio'] . '）';
        $gapTypeGuide = '断層上側グループを中心に。断層が拡大中なら上側重視、縮小中なら下側からの浮上に注意。';

    // C: 中間断層型（断層はあるがtop6外）
    } elseif ($tanHasGap) {
        $e            = $tanGapAll[0];
        $gapType      = 'C';
        $gapTypeDesc  = '中間断層型（' . $e['upper_pop'] . '〜' . $e['lower_pop'] . '番人気間に断層、比率' . $e['ratio'] . '）';
        $gapTypeGuide = '断層上側を中心グループ、下側を穴グループとして評価。断層拡大と上側への複勝流入が同時確認できれば上側重視。';

    // D: 断層なし・混戦型
    } else {
        $gapType      = 'D';
        $gapTypeDesc  = '断層なし・混戦型（2.0以上の断層なし）';
        $gapTypeGuide = '上位人気だけで本候補を固めない。複勝支持・変化率・単複人気差を重視し、6〜10番人気も通常比較に含める。';
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
    $pickupTotalMax = $pickupUpperMax + $pickupMidMax + $pickupLowerMax;

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
    $lines[] = 'D（断層なし・混戦型）: 複勝支持・変化率・単複人気差を重視。6〜10番人気も均等に比較。波乱度目安：4〜5（波乱〜大波乱）';
    $lines[] = 'E（判定困難型）: 断層は参考程度。複勝の継続的な動きを最優先で評価。波乱度目安：4〜5（波乱〜大波乱）';
    $lines[] = '';
    $lines[] = '【波乱度の修正ルール（1〜5：1=堅い、2=やや堅い、3=中波乱、4=波乱、5=大波乱）】';
    $lines[] = 'タイプ別の波乱度目安を基準に、以下の条件で1段階上下してください。';
    $lines[] = '■ 波乱度を1段階「上げる」条件（複数該当で2段階まで）';
    $lines[] = '・断層時系列が6分前に向けて急縮小している';
    $lines[] = '・断層より下の複数馬に複勝流入が確認できる';
    $lines[] = '・6〜10番人気の複勝流入ランクが継続上昇している';
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
        $lines[] = '【断層時系列（単勝）】';
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
        '',
        '【出力フォーマット（厳守）】',
        'このフォーマットは画面表示アプリがそのままパースします。',
        '前置き・後書き・補足コメントは不要です。フォーマット通りに出力してください。',
        '',
        '─────────────────────────────',
        '厳選穴レース|1または0',
        '馬番：X、馬名：XXX、人気順: X、6分前オッズ: X.X、おすすめ度: XX、選出理由：XXXXXXXXXXXXXXXXXXXXXXXXXXXX（改行なしの1行で、客観的根拠を4〜5要素含めること。改行・箇条書き禁止）',
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
        '・条件A: 選出した馬の中に6〜10番人気の馬が1頭以上含まれている',
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
        'おすすめ度は100点満点で、以下の固定配点に従って採点してください。項目の追加・削除・配点変更・信頼度と妙味の間での点数移動は禁止です。',
        '',
        '■ 信頼度（60点満点・7項目）: この馬が5着以内に来そうか',
        '　①複勝支持・安定性：0〜15点',
        '　　複勝オッズが継続下落・複勝最小と最大の幅が狭い・安定した支持継続 → 高評価',
        '　②単勝・複勝の継続資金流入：0〜12点',
        '　　単複ともに複数時点で継続資金流入 → 高評価',
        '　③単複人気差・支持差：0〜8点',
        '　　複勝人気が単勝人気より上位（複勝流入ランク > 単勝流入ランク） → 高評価',
        '　④断層位置・時間変化・単複一致：0〜10点',
        '　　断層上側に位置、単複断層が同位置に出ている → 高評価',
        '　⑤類似レース統計：0〜8点',
        '　　5着以内率が高いパターン、統計N数が大きいほど信頼度アップ',
        '　⑥予測補正の維持・直前傾向：0〜4点',
        '　　推定確定オッズが6分前より下落する補正係数 → 高評価（直前さらに人気集中）',
        '　⑦能力・今回条件への適性：0〜3点',
        '　　A=3点（最高適性）、B=2点、C=1点、D=0点（第2AIが評価する項目）',
        '',
        '■ 妙味（40点満点・4項目）: そのオッズで買う価値があるか',
        '　①推定確定配当水準：0〜15点',
        '　　推定確定複勝最小オッズを基準に判定。1.5倍未満 → 小計5点以下（来ても儲からない）',
        '　②3種類の回収率の裏付け：0〜12点',
        '　　過去回収率・OPI帯別回収率・フェーズパターン別回収率がいずれも110%以上 → 高評価',
        '　③OPI・予測補正OPIによる市場評価：0〜8点',
        '　　OPI<0.8または予測補正OPI<0.95 → 市場が過小評価 → 妙味あり → 高評価',
        '　④配当と市場流入・断層構造の整合性：0〜5点',
        '　　高配当なのに資金流入継続・断層上側 → 価値が高い整合性 → 高評価',
        '',
        'おすすめ度（信頼度＋妙味）の降順でソートしてください。',
        '人気順は上記テーブルの「X人気」欄の値をそのまま出力してください。自分で計算しないでください。',
        '',
        '【選出ルール】',
        '・選出した馬が全員4番人気以内の場合、選出理由の最後に必ず「※妙味補足：〜（なぜ高人気馬だけになったか1行で）」を追記してください',
        '・6〜10番人気でオッズが継続下落している馬は、信頼度・妙味ともに積極的に加点してください',
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
        '・複勝人気が単勝人気より2順位以上高い（複勝流入ランクが単勝流入ランクより大きく上回る）',
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
        '・断層なし（タイプD）または断層縮小・矛盾（タイプE）のレースであること',
        '上記を全て満たさない11番人気以下の馬は選出禁止。「来そうな気がする」だけでは選ばないこと。',
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
        '・単複比が高い馬＝勝ちにくいが3着以内には絡みやすい',
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
        '3行目以降: 「馬番：X、馬名：XXX、人気順: X、6分前オッズ: X.X、おすすめ度: XX、選出理由：〜」を選出頭数分',
        '※画面表示に影響するので、この形を必ず守ってください。',
    ]);

    // ── Block A: 直近5走データ取得・プロンプト付加（① ⑩ 両AI対応）────────────────
    // _getAiAnalysisPrompt 内で 1st AI (Claude) にも全頭の過去成績・能力適性データを渡す。
    // $race->dist/$race->course/$race->grade = 今走レース条件
    // $horses（冒頭で全カラム取得済み）から今走騎手を参照
    {
        $todayDist   = isset($race->dist)   ? (int)$race->dist   : null;
        $todayCourse = isset($race->course) ? $race->course       : null;
        $todayGrade  = isset($race->grade)  ? $race->grade        : null;

        $aHistoryText  = "

【各馬の直近成績（過去5走）と今走データ】
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
                ->limit(5)
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

    if ($cached) {
        return response()->json(['data' => [
            'date'          => $date,
            'kaisuu'        => $kaisuu,
            'basho_code'    => $basho,
            'day'           => $day,
            'race'          => $race,
            'analysis_text' => trim($cached->analysis_text),
        ]]);
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

        if ($cached) {
            return response()->json(['data' => [
                'date'          => $date,
                'kaisuu'        => $kaisuu,
                'basho_code'    => $basho,
                'day'           => $day,
                'race'          => $race,
                'analysis_text' => trim($cached->analysis_text),
            ]]);
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
        // 【厳選穴レースの判定ルール】ブロックを除去
        $oddsData = preg_replace('/【厳選穴レースの判定ルール】.*?(?=\nおすすめ度は)/s', '', $oddsData);
        // 出力フォーマット内の「厳選穴レース|1または0」行を除去
        $oddsData = preg_replace('/^厳選穴レース\|1または0\n?/m', '', $oddsData);
        // 末尾の「選出馬は必ず「厳選穴レース|X」を1行目に〜」の行を除去
        $oddsData = preg_replace('/^選出馬は必ず「厳選穴レース[^\n]*\n?/m', '', $oddsData);
        // ※仕様書により「おすすめ度計算方法」「システムの目的」「回収率優先・低配当除外ルール」は除去しない（維持必須）
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
        // 対象馬の直近5走を shutsuba_history から取得し、能力適性評価の根拠として追記する
        // ⑩ 拡充: grade/jockey/burden_weight/horse_weight/corner_1〜4 を追加
        // 今走条件（$raceRow->dist/$raceRow->course/$raceRow->grade）も先頭に付加
        $b9HorseRows = DB::table('t_horse_odds_finder_horses')
            ->where('date',   $date)
            ->where('kaisuu', $raceRow->kaisuu)
            ->where('basho',  $raceRow->basho)
            ->where('day',    $raceRow->day)
            ->where('race',   $raceRow->race)
            ->orderBy('num')
            ->get(['num', 'name', 'jockey']); // ⑩ 今走騎手を追加

        $b9TodayDist   = isset($raceRow->dist)   ? (int)$raceRow->dist   : null;
        $b9TodayCourse = isset($raceRow->course) ? $raceRow->course       : null;
        $b9TodayGrade  = isset($raceRow->grade)  ? $raceRow->grade        : null;

        $b9HistoryText  = "\n\n【各馬の直近成績（過去5走）と今走データ】\n";
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
                ->limit(5)
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
        $oddsData .= $b9HistoryText;
        // ── Block 9 End ────────────────────────────────────────────────────────────

        // 除去された末尾指示の代替として頭数を明示追記（Block 9: 能力適性フォーマット追加）
        $oddsData .= "\n\nオッズ推移の分析に基づき注目馬を最大{$pickupCount}頭まで選出してください。「馬番：X、馬名：XXX、人気順: X、6分前オッズ: X.X、おすすめ度: XX、選出理由：能力適性:X（XX点）。〜」の形式で出力してください。選出理由は必ず「能力適性:X（XX点）。」で始めてください。最低基準未満の馬を追加して{$pickupCount}頭へ埋めないでください。候補が0頭の場合は、「厳選穴レース行」と「レース指標行」のみを出力し、「該当馬なし」「候補なし」等の文字列は追加しないでください。";

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
        $b13UnderevalFlagMap = [];
        $b13ScoreAMap        = [];
        $b10ScoreE           = 0;
        [
            'b8InfoMap'           => $b8InfoMap,
            'b10TotalScoreMap'    => $b10TotalScoreMap,
            'b13UnderevalFlagMap' => $b13UnderevalFlagMap,
            'b13ScoreAMap'        => $b13ScoreAMap,
            'b10ScoreE'           => $b10ScoreE,
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
・おすすめ度の採点は自分の判断で。ただし固定スキームの上限・下限（低配当除外等）は遵守
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
プロンプト末尾の【各馬の直近成績（過去5走）】を根拠に、各馬を以下の6項目で採点し、
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

        // ─── DeepSeek API 呼び出し（通信エラー時のみ最大3回リトライ） ────
        // ※形式不正時は再試行禁止（B-7: 1レース合計2回AI呼び出し上限）
        $analysisText     = '';
        $b7SecondAiFailed = false; // B-7: 2nd AI失敗フラグ（失敗時は1st AI単独継続）
        $maxRetries       = 1; // 仕様: 自動再試行禁止（1回のみ）
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
                \Log::warning('[B-7] DeepSeek全リトライ失敗、1st AI単独継続', [
                    'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
                ]);
                break;
            }

            $result       = $response->json();
            $analysisText = trim($result['choices'][0]['message']['content'] ?? '');

            // 候補なし|0 = 正常な0件回答（形式不正ではない・1st AI単独継続）
            if (preg_match('/^候補なし\|0$/mu', $analysisText)) {
                \Log::info('[B-7] DeepSeek正常0件（候補なし|0）、1st AI単独継続', [
                    'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
                ]);
                $analysisText = ''; // 0件として正常終了（$b7SecondAiFailed は false のまま）
                break;
            }

            if (!preg_match('/馬番[：:]\d+/', $analysisText)) {
                // 形式不正 → 再試行禁止（B-7仕様）。即座に2nd AI失敗扱い
                $b7SecondAiFailed = true;
                \Log::warning('[B-7] DeepSeek形式不正（再試行禁止）、1st AI単独継続', [
                    'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
                    'text' => mb_substr($analysisText, 0, 200),
                ]);
                break;
            }

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
                \Log::info('[Block13b] 最低基準点未満除外（merge後）', [
                    'num'      => $h['num'], 'name' => $h['name'],
                    'score'    => $score,    'popularity' => $pop,
                    'category' => $h['category'] ?? '',
                ]);
                return false;
            }));
        }
        // ── Block 13b End ─────────────────────────────────────────────────────────

        // ── Block 13a: 低配当除外（merge後・正規仕様）────────────────────────────
        // 除外条件: 推定確定複勝最小 < 1.5倍 かつ 推定確定単勝（推定確定オッズ）< 3.0倍
        //           → 両方を満たした馬を原則除外
        // 例外解除（いずれか1つ以上成立 → 除外しない）:
        //   例外①: 断層最上位グループ（人気順 <= 主断層上限人気）
        //   例外②: 単勝・複勝の両方が複数時点で継続流入
        //   例外③: サンプル30件以上の回収率110%以上が2種類以上
        {
            $mergedHorses = array_values(array_filter(
                $mergedHorses,
                function ($h) use ($oddsHorseBlocks, $primaryGapUpperPopForMerge, $b13ScoreAMap) {
                    $b13aBlock = $oddsHorseBlocks[(int)$h['num']] ?? '';

                    // 推定確定複勝最小（なければ除外しない）
                    if (!preg_match('/推定確定複勝最小: ([\d.]+)/u', $b13aBlock, $b13am)) return true;
                    $b13aFukuMin = (float)$b13am[1];

                    // 推定確定単勝 = 「推定確定オッズ」フィールド（なければ除外しない）
                    if (!preg_match('/推定確定オッズ: ([\d.]+)/u', $b13aBlock, $b13atm)) return true;
                    $b13aTanOdds = (float)$b13atm[1];

                    // 除外条件: 複勝最小 < 1.5 かつ 単勝 < 3.0（両方満たした場合のみ除外判定へ）
                    if ($b13aFukuMin >= 1.5 || $b13aTanOdds >= 3.0) return true;

                    // 例外①: 断層最上位グループ（主断層より人気上側に属する馬）
                    $ex1 = ($primaryGapUpperPopForMerge !== null
                            && (int)($h['popularity'] ?? 999) <= $primaryGapUpperPopForMerge);

                    // 例外②: 単勝・複勝の両方が複数時点で継続流入
                    //   単勝: 全体短縮率 < 0（計測開始→6分前で流入傾向）
                    //          かつ 直前9→6分前も流入（変化率 < 0）
                    $b13aTanShrink = null;
                    if (preg_match(
                        '/単勝流入ランク:[^※]+※短縮率: ([+\-][\d.]+)%/u',
                        $b13aBlock, $b13ats
                    )) {
                        $b13aTanShrink = (float)$b13ats[1];
                    }
                    $b13aTanDirect = false;
                    if (preg_match(
                        '/直前流入（9→6分前）:.*単勝\s*([+\-][\d.]+)%/u',
                        $b13aBlock, $b13atd
                    )) {
                        $b13aTanDirect = ((float)$b13atd[1] < 0.0);
                    }
                    $b13aTanCont = ($b13aTanShrink !== null && $b13aTanShrink < 0.0 && $b13aTanDirect);
                    //   複勝: ScoreA >= 12（3区間以上連続低下 + 直前も低下 = 強い流入シグナル）
                    $b13aScoreA   = $b13ScoreAMap[(int)$h['num']] ?? null;
                    $b13aFukuCont = (is_int($b13aScoreA) && $b13aScoreA >= 12);
                    $ex2 = ($b13aTanCont && $b13aFukuCont);

                    // 例外③: サンプル30件以上の回収率110%以上が2種類以上
                    $b13aHiCnt = 0;
                    if (preg_match(
                        '/過去回収率[（(][^）)]+[）)]: 回収率([\d.]+)% 勝率[\d.]+% サンプル(\d+)件/u',
                        $b13aBlock, $b13ar1
                    ) && (int)$b13ar1[2] >= 30 && (float)$b13ar1[1] >= 110.0) $b13aHiCnt++;
                    if (preg_match(
                        '/OPI帯別回収率[（(][^）)]+[）)]: 回収率([\d.]+)% 勝率[\d.]+% サンプル(\d+)件/u',
                        $b13aBlock, $b13ar2
                    ) && (int)$b13ar2[2] >= 30 && (float)$b13ar2[1] >= 110.0) $b13aHiCnt++;
                    if (preg_match(
                        '/フェーズパターン別回収率[（(][^）)]+[）)]: 回収率([\d.]+)% 勝率[\d.]+% サンプル(\d+)件/u',
                        $b13aBlock, $b13ar3
                    ) && (int)$b13ar3[2] >= 30 && (float)$b13ar3[1] >= 110.0) $b13aHiCnt++;
                    $ex3 = ($b13aHiCnt >= 2);

                    // いずれか1つ以上の例外成立 → 除外しない
                    if ($ex1 || $ex2 || $ex3) return true;

                    \Log::info('[Block13a] 低配当除外（merge後）', [
                        'num'                => $h['num'],    'name'     => $h['name'],
                        'fuku_min'           => $b13aFukuMin, 'tan_odds' => $b13aTanOdds,
                        'ex1_最上位グループ' => $ex1,
                        'ex2_単複継続流入'   => $ex2,
                        'ex3_回収率110x2'    => $ex3,
                    ]);
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
                    '/過去回収率[（(][^）)]+[）)]: 回収率([\d.]+)% 勝率[\d.]+% サンプル(\d+)件/u',
                    $b12mBlock, $b12me1
                )) {
                    $b12mEntries[] = ['rate' => (float)$b12me1[1], 'samples' => (int)$b12me1[2]];
                }
                if (preg_match(
                    '/OPI帯別回収率[（(][^）)]+[）)]: 回収率([\d.]+)% 勝率[\d.]+% サンプル(\d+)件/u',
                    $b12mBlock, $b12me2
                )) {
                    $b12mEntries[] = ['rate' => (float)$b12me2[1], 'samples' => (int)$b12me2[2]];
                }
                if (preg_match(
                    '/フェーズパターン別回収率[（(][^）)]+[）)]: 回収率([\d.]+)% 勝率[\d.]+% サンプル(\d+)件/u',
                    $b12mBlock, $b12me3
                )) {
                    $b12mEntries[] = ['rate' => (float)$b12me3[1], 'samples' => (int)$b12me3[2]];
                }
                $b12mValid = array_values(array_filter($b12mEntries, fn($r) => $r['samples'] >= 30));
                $b12mLow   = array_values(array_filter($b12mValid,   fn($r) => $r['rate'] < 90.0));
                if (count($b12mValid) >= 2 && count($b12mLow) >= 2) {
                    $b12mExcludeNums[] = $b12mNum;
                    \Log::info("[Block12] ハード除外（merge後）: 馬番{$b12mNum}", [
                        'valid_rates' => $b12mValid,
                    ]);
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
            $date, $kaisuu, $basho, $day, $race, $raceRow
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
            $oddsData, $oddsHorseBlocks, $b6TanPopMap, $b6OddsRows,
            $date, $kaisuu, $basho, $day, $race, $raceRow
        );
        // ── Block 11 End ──────────────────────────────────────────────────────────

        return response()->json(['data' => [
            'date'          => $date,
            'kaisuu'        => $kaisuu,
            'basho_code'    => $basho,
            'day'           => $day,
            'race'          => $race,
            'analysis_text' => $analysisText,
            'merged_horses' => $mergedHorses,
            'upset_race'    => $upsetRaceFinal,
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
            \Log::warning("[B16-{$aiLabel}] 除去: " . implode(', ', $removed));
        }
        if (!empty($fixed)) {
            \Log::info("[B16-{$aiLabel}] 補正: " . implode(', ', $fixed));
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
                    \Log::info('[Block6] タイプA 独自発見枠昇格', [
                        'num'       => $qh['num'],
                        'name'      => $qh['name'],
                        'score'     => $qh['score'],
                        'trueCount' => $qCount,
                    ]);
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
            \Log::debug('[Block9] ability', [
                'num'         => $b9h['num'],
                'name'        => $b9h['name'],
                'grade'       => $b9Grade,
                'pts'         => $b9Pts,
                'corr'        => $b9Corr,
                'score_orig'  => $b9h['score'], // scoreは変更しない
            ]);
        }
        unset($b9h);
        // ── Block 9 Session 11 End ─────────────────────────────────────────────────
    }

    /**
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
            if (preg_match('/過去回収率[（(][^）)]+[）)]: 回収率([\d.]+)% 勝率[\d.]+% サンプル(\d+)件/u', $b12Block, $b12m1)) {
                $b12RateEntries[] = ['rate' => (float)$b12m1[1], 'samples' => (int)$b12m1[2]];
            }
            // ② OPI帯別回収率
            if (preg_match('/OPI帯別回収率[（(][^）)]+[）)]: 回収率([\d.]+)% 勝率[\d.]+% サンプル(\d+)件/u', $b12Block, $b12m2)) {
                $b12RateEntries[] = ['rate' => (float)$b12m2[1], 'samples' => (int)$b12m2[2]];
            }
            // ③ フェーズパターン別回収率
            if (preg_match('/フェーズパターン別回収率[（(][^）)]+[）)]: 回収率([\d.]+)% 勝率[\d.]+% サンプル(\d+)件/u', $b12Block, $b12m3)) {
                $b12RateEntries[] = ['rate' => (float)$b12m3[1], 'samples' => (int)$b12m3[2]];
            }

            // サンプル30件以上のみ有効値
            $b12ValidRates = array_values(array_filter($b12RateEntries, fn($r) => $r['samples'] >= 30));
            $b12LowRates   = array_values(array_filter($b12ValidRates,  fn($r) => $r['rate']    < 90.0));

            if (count($b12ValidRates) >= 2 && count($b12LowRates) >= 2) {
                $hardExcludeNums[] = $b12Num;
                \Log::info("[Block12] ハード除外: 馬番{$b12Num}", [
                    'valid_rates' => $b12ValidRates,
                ]);
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

            \Log::debug('[Block6] F-flags', [
                'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho,
                'day'  => $day,  'race'   => $race,
                'num'  => $b6Num, 'name'  => $b6sh['name'],
                'flags' => $horseFlagsMap[$b6Num],
            ]);
        }
        // ── Block 6 End ───────────────────────────────────────────────────────────

        return compact('horseFlagsMap', 'oddsHorseBlocks', 'b6OddsRows', 'b6TanPopMap', 'b6FukuPopMap');
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
        object  $raceRow
    ): void {
        // ── Block 14: 統合結果を ai_merge_result テーブルに UPSERT ──────────────────
        // _mergeAiResults() の出力と厳選穴レース再判定結果を JSON で保存する
        // 実テーブルの merge_result 列（text）に全データを JSON として格納する
        try {
            $b14MergeJson = json_encode([
                'upset_race'    => $upsetRaceFinal,
                'gap_type'      => $gapTypeForMerge,
                'wave_level'    => $b14WaveLevel,
                'lower_entry'   => $b14LowerEntry,
                'big_gap_entry' => $b14BigGap,
                'merged_horses' => $mergedHorses,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            DB::statement(
                'INSERT INTO t_horse_odds_finder_ai_merge_result'
                . ' (date, kaisuu, basho, basho_code, day, race, race_name, merge_result)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE'
                . '   merge_result = VALUES(merge_result),'
                . '   race_name    = VALUES(race_name),'
                . '   basho        = VALUES(basho),'
                . '   updated_at   = CURRENT_TIMESTAMP',
                [
                    $date,
                    (int) $kaisuu,
                    $raceRow->basho_name ?? '',
                    $basho,
                    (int) $day,
                    (int) $race,
                    $raceRow->race_name  ?? '',
                    $b14MergeJson,
                ]
            );
            \Log::debug('[Block14] ai_merge_result UPSERT', [
                'date'       => $date,
                'kaisuu'     => $kaisuu,
                'basho_code' => $basho,
                'day'        => $day,
                'race'       => $race,
                'upset_race' => $upsetRaceFinal,
                'gap_type'   => $gapTypeForMerge,
                'horses_cnt' => count($mergedHorses),
            ]);
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
        $b13UnderevalFlagMap = []; // B-13: 市場過小評価フラグ馬別マップ（try外で初期化）
        $b13ScoreAMap        = []; // B-13: Score_A（複勝継続流入）馬別マップ（馬券判定用）
        $b10ScoreE = 0; // try 失敗時フォールバック
        // ── Block 10: 市場妙味基礎点 算出・シャドーログ保存 ─────────────────────────
        // フェーズ1: PHP側で score_a〜e・total_score を算出し market_score_log に INSERT。
        // AIへは送信しない（シャドー期間中）。Flutter 表示にも使わない。
        // F1（基礎点A≥12）・F5（基礎点E≥6）の本番有効化も別フェーズ。
        try {
            // ── Score A 用: 複勝オッズ時系列データを一括取得 ─────────────────────
            // t_horse_odds_finder_odds から [21,18,15,12,9,6] 分前の fuku_min を取得
            $b10FukuSeriesRaw = DB::table('t_horse_odds_finder_odds')
                ->where('date', $date)
                ->where('kaisuu', $kaisuu)
                ->where('basho', $basho)
                ->where('day', $day)
                ->where('race', $race)
                ->whereIn('minutes_before_start', [21, 18, 15, 12, 9, 6])
                ->get(['num', 'minutes_before_start', 'fuku_min']);

            // 馬番 → [minutes_before_start => fuku_min] のマップに整形
            $b10FukuSeriesMap = [];
            foreach ($b10FukuSeriesRaw as $b10fs) {
                $b10FukuSeriesMap[(int)$b10fs->num][(int)$b10fs->minutes_before_start] = (float)$b10fs->fuku_min;
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
                    // 有効時点を古い順（21→6方向）で配列化
                    $b10FukuSorted = [];
                    foreach ($b10TimePoints as $b10min) {
                        if (isset($b10FukuVals[$b10min])) {
                            $b10FukuSorted[] = $b10FukuVals[$b10min];
                        }
                    }
                    // 低下区間数をカウント（次の値 < 前の値 = オッズが下がる = 資金流入）
                    $b10DeclineCnt = 0;
                    for ($b10i = 1; $b10i < count($b10FukuSorted); $b10i++) {
                        if ($b10FukuSorted[$b10i] < $b10FukuSorted[$b10i - 1]) {
                            $b10DeclineCnt++;
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
                if (preg_match('/過去回収率[（(][^）)]+[）)]: 回収率([\d.]+)% 勝率[\d.]+% サンプル(\d+)件/u', $b10Block, $b10md1)) {
                    $b10RateEntries[] = ['rate' => (float)$b10md1[1], 'samples' => (int)$b10md1[2]];
                }
                // OPI帯別回収率
                if (preg_match('/OPI帯別回収率[（(][^）)]+[）)]: 回収率([\d.]+)% 勝率[\d.]+% サンプル(\d+)件/u', $b10Block, $b10md2)) {
                    $b10RateEntries[] = ['rate' => (float)$b10md2[1], 'samples' => (int)$b10md2[2]];
                }
                // フェーズパターン別回収率
                if (preg_match('/フェーズパターン別回収率[（(][^）)]+[）)]: 回収率([\d.]+)% 勝率[\d.]+% サンプル(\d+)件/u', $b10Block, $b10md3)) {
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

                // ── Score E（馬別）: 断層流入確認点（0〜6 or null=不明）──────────
                // 簡易実装: 断層下側 + Score A>=12 → 6点。縮小・接近・不一致は後で精緻化。
                $b10ScoreEPerHorse = null; // default: 不明
                if ($primaryGapUpperPopForMerge !== null && $b10TanP !== null) {
                    $b10IsGapLowerSide = ($b10TanP > $primaryGapUpperPopForMerge);
                    $b10HasFukuInflow  = (is_int($b10ScoreA) && $b10ScoreA >= 12);
                    $b10ScoreEPerHorse = ($b10IsGapLowerSide && $b10HasFukuInflow) ? 6 : 0;
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

                \Log::debug('[Block10] market_score', [
                    'num'       => $b10Num,
                    'score_a'   => $b10ScoreA,
                    'score_b'   => $b10ScoreB,
                    'score_c'   => $b10ScoreC,
                    'score_d'   => $b10ScoreD,
                    'score_e'   => $b10ScoreEPerHorse,
                    'valid_max' => $b10ValidMax,
                    'total'     => $b10TotalScore,
                    'f1'        => $b10F1Active,
                    'f5'        => $b10F5Active,
                ]);

                $b10Rows[] = [
                    'date'        => $date,
                    'kaisuu'      => (int)$kaisuu,
                    'basho'       => $raceRow->basho_name ?? '',
                    'basho_code'  => $basho,
                    'day'         => (int)$day,
                    'race'        => (int)$race,
                    'num'         => $b10Num,
                    'score_a'     => $b10ScoreA,         // null=不明
                    'score_b'     => $b10ScoreB,         // null=不明
                    'score_c'     => $b10ScoreC,         // null=不明
                    'score_d'     => $b10ScoreD,         // null=不明
                    'score_e'     => $b10ScoreEPerHorse, // null=不明
                    'total_score' => $b10TotalScore,     // null=不明
                ];
                $b10TotalScoreMap[$b10Num] = $b10TotalScore; // B-10: 高配当総合点算出用

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
                \Log::info('[B-13] 市場過小評価フラグ', [
                    'date'        => $date,
                    'kaisuu'      => $kaisuu,
                    'basho_code'  => $basho,
                    'day'         => $day,
                    'race'        => $race,
                    'num'         => $b10Num,
                    'total_score' => $b10TotalScore,
                    'score_a'     => $b10ScoreA,
                    'score_c'     => $b10ScoreC,
                    'valid_max'   => $b10ValidMax,
                    'undereval'   => $b13Flag,
                ]);
            }

            // UPSERT（全馬まとめてバルク INSERT ... ON DUPLICATE KEY UPDATE）
            if (!empty($b10Rows)) {
                $b10PlaceHolders = implode(',', array_fill(0, count($b10Rows), '(?,?,?,?,?,?,?,?,?,?,?,?,?)'));
                $b10Values       = [];
                foreach ($b10Rows as $b10r) {
                    array_push($b10Values,
                        $b10r['date'],    $b10r['kaisuu'],  $b10r['basho'],
                        $b10r['basho_code'], $b10r['day'], $b10r['race'],
                        $b10r['num'],
                        $b10r['score_a'], $b10r['score_b'], $b10r['score_c'],
                        $b10r['score_d'], $b10r['score_e'], $b10r['total_score']
                    );
                }
                DB::statement(
                    'INSERT INTO t_horse_odds_finder_market_score_log'
                    . ' (date,kaisuu,basho,basho_code,day,race,num,'
                    . '  score_a,score_b,score_c,score_d,score_e,total_score)'
                    . ' VALUES ' . $b10PlaceHolders
                    . ' ON DUPLICATE KEY UPDATE'
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

        return compact('b8InfoMap', 'b10TotalScoreMap', 'b13UnderevalFlagMap', 'b13ScoreAMap', 'b10ScoreE');
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
            \Log::info('[B-8] 偽流入警戒判定', [
                'date'        => $date,
                'kaisuu'      => $kaisuu,
                'basho_code'  => $basho,
                'day'         => $day,
                'race'        => $race,
                'num'         => $b8mhNum,
                'popularity'  => $b8mhPop,
                'true_count'  => $b8TrueCount,
                'known_count' => $b8KnownCount,
                'fake_warning'=> $b8FakeWarn,
                'conditions'  => [
                    'c1_3area_decline'  => $b8c1Val,
                    'c2_final_down'     => $b8c2Val,
                    'c3_fuku_rank_top2' => $b8c3Val,
                    'c4_fuku_pop_adv2'  => $b8c4Val,
                    'c5_no_rebound'     => $b8c5Val,
                    'c6_both_down'      => $b8c6Val,
                    'c7_undereval_flag' => $b8c7Val, // B-13算出済み（null=不明）
                ],
            ]);
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
                    \Log::info('[B-10] high_payout_shadow_log saved', [
                        'date'       => $date,    'kaisuu' => $kaisuu,
                        'basho_code' => $basho,   'day'    => $day,
                        'race'       => $race,    'count'  => count($bB10HpRows),
                        'top_score'  => $bB10HpRows[0]['high_payout_score'] ?? null,
                    ]);
                }
            } catch (\Throwable $bB10she) {
                \Log::error('[B-10] high_payout_shadow_log INSERT failed', ['err' => $bB10she->getMessage()]);
            }
        }
        // ── Block B-10 End ──────────────────────────────────────────────────────────
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
        array  $b6OddsRows,          // 全時点オッズ行
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
            $b11TanPopSerial = '';
            ksort($b6TanPopMap);
            foreach ($b6TanPopMap as $_b11num => $_b11pop) {
                $b11TanPopSerial .= "{$_b11num}:{$_b11pop}|";
            }
            $b11OddsRowsSerial = count($b6OddsRows) . ':' . md5(json_encode($b6OddsRows, JSON_UNESCAPED_UNICODE));
            $b11InputHash = hash('sha256',
                $oddsData . '||' . $b11TanPopSerial . '||' . $b11OddsRowsSerial
            );

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
                        '/' . preg_quote($_b11rLabel, '/') . '[（(][^）)]+[）)]: 回収率([\d.]+)% 勝率[\d.]+% サンプル(\d+)件/u',
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
                $b11AllHorsesFeatures[] = [
                    'num'          => $_b11num,
                    'pop_6m'       => (int)$_b11pop,
                    'tan_odds_6m'  => $_b11tanOdds,
                    'fuku_min_est' => $_b11fukuMin,
                    'rates'        => $_b11rateArr,
                    'gap_position' => $_b11gapPos,
                ];
            }

            $b11Features = json_encode([
                // メタ情報
                'model_version'         => 'claude+deepseek-chat',
                'input_hash'            => $b11InputHash,
                // 断層構造
                'gap_type'              => $gapTypeForMerge,
                'primary_gap_upper_pop' => $primaryGapUpperPopForMerge,
                'merge_upper_max'       => $mergeUpperMax,
                'merge_mid_max'         => $mergeMidMax,
                'merge_lower_max'       => $mergeLowerMax,
                // レース条件
                'race_date'             => $date,
                'basho_name'            => $raceRow->basho_name ?? $basho,
                'race_num'              => $race,
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
            \Log::debug('[Block11] ml_snapshot saved', [
                'date'       => $date,
                'kaisuu'     => $kaisuu,
                'basho_code' => $basho,
                'day'        => $day,
                'race'       => $race,
                'gap_type'   => $gapTypeForMerge,
                'merged_cnt' => count($mergedHorses),
            ]);
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
