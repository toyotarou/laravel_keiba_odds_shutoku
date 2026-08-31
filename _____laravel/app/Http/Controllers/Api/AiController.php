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

        // ─── Claude API 呼び出し（529 Overloaded 時は指数バックオフでリトライ） ──
        $aiResponse = $this->anthropic->sendWithRetry(
            prompt:      $prompt,
            system:      null,
            maxAttempts: 3,
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
            $h['estimated_final_odds'] = round($h['odds_6'] * floatval($corr->avg_correction_ratio), 2);
            $h['correction_ratio']     = floatval($corr->avg_correction_ratio);
            $h['correction_std']       = floatval($corr->std_correction_ratio);
        } else {
            $h['estimated_final_odds'] = null;
            $h['correction_ratio']     = null;
            $h['correction_std']       = null;
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
        $gapTypeDesc  = '二重断層・上位完結型（6番人気以内に断層' . count($tanGapTop6) . 'か所、うち比率2.5以上' . count($tanGapStrong) . 'か所）';
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

    // ─── レース構造タイプ別の推奨頭数上限 ───────────────────────────────────
    // 1〜6番人気(Upper) / 7〜10番人気(Mid) / 11番人気以下(Lower)
    switch ($gapType) {
        case 'A':
            $pickupUpperMax = 4; $pickupMidMax = 0; $pickupLowerMax = 0; break;
        case 'B':
            $pickupUpperMax = 4; $pickupMidMax = 2; $pickupLowerMax = 1; break;
        case 'C':
            $pickupUpperMax = 3; $pickupMidMax = 3; $pickupLowerMax = 1; break;
        case 'D':
            $pickupUpperMax = 3; $pickupMidMax = 3; $pickupLowerMax = 2; break;
        case 'E':
        default:
            $pickupUpperMax = 3; $pickupMidMax = 3; $pickupLowerMax = 1; break;
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
        '馬番：X、馬名：XXX、人気順: X、6分前オッズ: X.X、おすすめ度: XX、選出理由：XXXXXXXXXXXXXXXXXXXXXXXXXXXX（4〜5行の文章。箇条書き不要）',
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
        'おすすめ度は100点満点で、以下の2軸を合算して判断してください。',
        '',
        '■ 信頼度（60点分）: この馬が5着以内に来そうか',
        '　複勝オッズの継続下落・断層上側への所属・単複ともに流入継続　→ 高評価',
        '　オッズが一時的に動いただけ・複勝が上昇傾向・断層下側　→ 低評価',
        '',
        '■ 妙味（40点分）: そのオッズで買う価値があるか',
        '　複勝オッズ1.5倍未満　→ 妙味 5点以下（来ても儲からない）',
        '　複勝オッズ1.5〜2.5倍　→ 妙味 10〜20点',
        '　複勝オッズ2.5〜4倍　→ 妙味 20〜30点',
        '　複勝オッズ4〜7倍　→ 妙味 30〜38点',
        '　複勝オッズ7倍以上かつ継続的な資金流入あり　→ 妙味 38〜40点',
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
        // 回収率データの「使い方指示」行を除去（数値は残す。制約だけ取り除く）
        $oddsData = preg_replace('/^・過去回収率[^\n]*\n?/m', '', $oddsData);
        // 「おすすめ度の計算方法」固定スキームブロックを除去
        $oddsData = preg_replace('/【おすすめ度の計算方法】.*?(?=\n選出馬|\n※|$)/s', '', $oddsData);
        // 「このシステムの目的（最重要）」ブロックを除去
        $oddsData = preg_replace('/【このシステムの目的（最重要）】.*?(?=\n選出馬|\n分析の観点|$)/s', '', $oddsData);
        // ─── 頭数から選出数を再計算（1st AIと同じロジック） ─────────────────
        $horseCount2nd = DB::table('t_horse_odds_finder_horses')
            ->where('date',   $date)
            ->where('kaisuu', $raceRow->kaisuu)
            ->where('basho',  $raceRow->basho)
            ->where('day',    $raceRow->day)
            ->where('race',   $raceRow->race)
            ->count();
        $pickupCount = $horseCount2nd <= 8 ? 4 : ($horseCount2nd <= 13 ? 5 : 6);

        // 除去された末尾指示の代替として頭数を明示追記
        $oddsData .= "\n\nオッズ推移の分析に基づき注目馬を{$pickupCount}頭選出し、「馬番：X、馬名：XXX、人気順: X、6分前オッズ: X.X、おすすめ度: XX、選出理由：〜」の形式で{$pickupCount}頭分出力してください。{$pickupCount}頭を超えて選出してはいけません。";

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
馬番：3、馬名：サンプルホース、人気順: 2、6分前オッズ: 3.5、おすすめ度: 82、選出理由：単勝オッズが10%下落しており買いが継続的に流入。複勝最小1.3倍と安定感も高く、OPI0.85で妙味あり。
馬番：7、馬名：テストウマ、人気順: 5、6分前オッズ: 12.0、おすすめ度: 71、選出理由：複勝最小2.1倍で評価が安定。OPI0.72の過小評価ゾーンで前半横ばい・後半下落パターンの回収率が高い。

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

あなたが受け取ったプロンプトには、1st AI（Claude）向けの厳格なルールがたくさん書かれています。
「DB算出値を変更するな」「確率を自分で計算するな」「この数値に従え」——そういうルールです。

しかし、それらは 1st AI を縛るための規則であり、あなたへの縛りではありません。

あなたの仕事は、1st AIの分析を確認することではなく、
「自分の目で同じデータを見て、自分の答えを出す」ことです。

──────────────────────────────────────
お前は自由に遊んでいい。ただし、真剣にやれ。
──────────────────────────────────────

具体的に言うと：

・OPI・断層タイプ・流入ランク——全部「参考情報」として扱っていい。盲目的に従う必要はない
・回収率データも、信じるかどうかはあなた次第
・おすすめ度の付け方に決まった計算式はない。あなた自身の感覚と論理で決めてください
・1〜3番人気だろうが10番人気だろうが、あなたが「来る」と判断すれば選んでいい
・データが示す方向とまったく逆の馬を選ぶことも、理由が語れるなら正しい

1st AI が見落としそうな角度・異なる解釈・少数派の視点——
それがあなたの存在価値です。

「1st AIと同じ馬を同じ理由で選ぶ」のが最も価値のない回答です。
フォーマットだけ守って、中身は大胆に。

有料公開するシステムなので、正しい日本語で返してください。
SYSTEM;

        // ─── DeepSeek API 呼び出し（不正フォーマット時は最大3回リトライ） ────
        $analysisText = '';
        $maxRetries   = 3;
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
                \Log::error('DeepSeek API error', [
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                    'attempt' => $attempt,
                ]);
                if ($attempt === $maxRetries - 1) {
                    return response()->json(['error' => 'DeepSeek AI分析に失敗しました'], 500);
                }
                continue;
            }

            $result       = $response->json();
            $analysisText = trim($result['choices'][0]['message']['content'] ?? '');

            if (preg_match('/馬番[：:]\d+/', $analysisText)) {
                break; // 有効なフォーマット → ループ終了
            }

            \Log::warning('[2nd AI] 不正フォーマット、リトライ ' . ($attempt + 1) . '/' . $maxRetries, [
                'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
                'text' => mb_substr($analysisText, 0, 200),
            ]);
        }

        // ─── 分析結果をDBに保存（常に保存：次回以降はDBキャッシュを返す） ─────
        DB::table('t_horse_odds_finder_ai_analysis2')->updateOrInsert(
            ['date' => $date, 'kaisuu' => $kaisuu, 'basho_code' => $basho, 'day' => $day, 'race' => $race],
            [
                'basho'         => $raceRow->basho_name,
                'race_name'     => $raceRow->race_name,
                'analysis_text' => $analysisText,
            ]
        );
        if (!preg_match('/馬番[：:]\d+/', $analysisText)) {
            \Log::warning('[2nd AI] 全リトライ失敗、不正フォーマットを保存', [
                'date' => $date, 'kaisuu' => $kaisuu, 'basho' => $basho, 'day' => $day, 'race' => $race,
            ]);
        }

        return response()->json(['data' => [
            'date'          => $date,
            'kaisuu'        => $kaisuu,
            'basho_code'    => $basho,
            'day'           => $day,
            'race'          => $race,
            'analysis_text' => $analysisText,
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
    // ⚠️ Flutter未使用 - コメントアウト


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
