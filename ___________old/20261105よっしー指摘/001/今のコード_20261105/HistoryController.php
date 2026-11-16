<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;
use App\Services\LineService;

class HistoryController extends Controller
{
    


    public function getHorseOddsFinderRaceIntrospection()
    {
        $result = DB::table('t_horse_odds_finder_race_introspection')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho_code')
            ->orderBy('day')
            ->orderBy('race')
            ->get();
        return response()->json(['data' => $result]);
    }


    /**
     * 指定馬の詳細情報を取得する（スクレイピング）
     *
     * cname（馬ID）を受け取り、JRAサイトから詳細情報を取得して返す。
     * Node.js スクリプト（keibaOddsGetHorseDetail.mjs）を shell_exec で呼び出す。
     *
     * 注意: 外部スクレイピングのため応答が遅い場合がある。
     *
     * @param  Request $request  query: cname（馬ID、必須）
     * @return \Illuminate\Http\JsonResponse  { data: {...} }
     */
    public function getHorseDetail(Request $request)
    {
        $cname = $request->query('cname');
        if (!$cname) {
            return response()->json(['error' => 'cname パラメータが必要です'], 400);
        }
        $script = base_path('scripts/keibaOddsGetHorseDetail.mjs');
        if (!file_exists($script)) {
            return response()->json(['error' => 'スクリプトが見つかりません: ' . $script], 500);
        }
        $output = shell_exec('/usr/local/bin/node ' . escapeshellarg($script) . ' ' . escapeshellarg($cname) . ' 2>/dev/null');
        if (!$output) {
            return response()->json(['error' => 'スクレイピング失敗（出力なし）'], 500);
        }
        $data = json_decode($output, true);
        if (!$data) {
            return response()->json(['error' => 'JSONパース失敗'], 500);
        }
        return response()->json(['data' => $data]);
    }

    
    /**
     * 年別・人気順位別のレース結果履歴を取得する
     *
     * 指定した year の1月1日〜翌年1月1日の範囲で、
     * 指定した popularity_rank の馬のみを抽出して返す。
     * tan が NULL のレコードは除外する（確定オッズが未記録のため）。
     *
     * クエリパラメータ:
     *   year            = 対象年 例: 2023（2000〜2100 に制限）
     *   popularity_rank = 人気順位 例: 1
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderRaceResultHistory(Request $request)
    {
        $year = (int) $request->query('year');
        $popularityRank = (int) $request->query('popularity_rank');

        // バリデーション（妥当な年の範囲に制限）
        if ($year < 2000 || $year > 2100) {
            return response()->json(['error' => 'year パラメータが不正です'], 400);
        }

        $start = sprintf('%04d-01-01', $year);       // '2021-01-01'
        $end   = sprintf('%04d-01-01', $year + 1);   // '2022-01-01'

        $result = DB::table('t_horse_odds_finder_race_result_history')
            ->where('popularity_rank', $popularityRank)
            ->where('date', '>=', $start)
            ->where('date', '<', $end)
            ->whereNotNull('tan')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho_code')
            ->orderBy('day')
            ->orderBy('race')
            ->orderBy('num')
            ->get();

        return response()->json(['data' => $result]);
    }

    
    /**
     * 年別のレース一覧を取得する（結果履歴テーブルから集約）
     *
     * 指定した year のレースを date・kaisuu・basho_code・day・race でグループ化し、
     * レース単位のサマリーリストを返す。
     * 同じレースに複数馬のレコードがあるため GROUP BY で重複を除去している。
     * basho・race_name は MIN() で代表値1件を取得する。
     *
     * クエリパラメータ:
     *   year = 対象年 例: 2023（2000〜2100 に制限）
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderRaceResultHistoryRaceList(Request $request)
    {
        $year = (int) $request->query('year');

        if ($year < 2000 || $year > 2100) {
            return response()->json(['error' => 'year パラメータが不正です'], 400);
        }

        $start = sprintf('%04d-01-01', $year);       // '2023-01-01'
        $end   = sprintf('%04d-01-01', $year + 1);   // '2024-01-01'

        $result = DB::table('t_horse_odds_finder_race_result_history')
            ->select(
                'date',
                'kaisuu',
                DB::raw('MIN(basho) AS basho'),
                'basho_code',
                'day',
                'race',
                DB::raw('MIN(race_name) AS race_name')
            )
            ->where('date', '>=', $start)
            ->where('date', '<', $end)
            ->groupBy('date', 'kaisuu', 'basho_code', 'day', 'race')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->get();

        return response()->json(['data' => $result]);
    }

    
    /**
     * 指定レースの全馬結果を取得する（結果履歴テーブル）
     *
     * date・kaisuu・basho_code・day・race で1レースを特定し、
     * 出走全馬の着順・オッズ・人気などを返す。
     *
     * クエリパラメータ:
     *   date       = 対象日付 例: 2023-05-14
     *   kaisuu     = 開催回数 例: 3
     *   basho_code = 場コード 例: 05
     *   day        = 開催日次 例: 2
     *   race       = レース番号 例: 11
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderRaceResultHistoryRaceContents(Request $request)
    {
        $result = DB::table('t_horse_odds_finder_race_result_history')
            ->where('date', $request->query('date'))
            ->where('kaisuu', $request->query('kaisuu'))
            ->where('basho_code', $request->query('basho_code'))
            ->where('day', $request->query('day'))
            ->where('race', $request->query('race'))
            ->orderBy('num')
            ->get();

        return response()->json(['data' => $result]);
    }

    
    /**
     * 頭文字（1文字）で馬名を検索する
     *
     * 指定した頭文字から始まる馬名を全て返す。五十音リスト表示などに使用。
     * COLLATE utf8mb4_bin で大文字・小文字・全半角を区別して検索する。
     * LIKE のワイルドカード文字（% _ \）は addcslashes でエスケープ済み。
     *
     * クエリパラメータ:
     *   initial = 頭文字1文字 例: ア、カ、T
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse  { data: [{ name: "..." }, ...] }
     */
    public function getHorseOddsFinderHorseName(Request $request)
    {
        $initial = (string) $request->query('initial');

        // 頭文字は1文字のみ
        if (mb_strlen($initial, 'UTF-8') !== 1) {
            return response()->json(['error' => 'initial は1文字で指定してください'], 400);
        }

        // LIKEのワイルドカード(% _ \)が来ても素直に1文字として扱う
        $escaped = addcslashes($initial, '\\%_');

        $result = DB::table('t_horse_odds_finder_race_result_history')
            ->distinct()
            ->selectRaw('name COLLATE utf8mb4_bin AS name')
            ->whereRaw('name LIKE ? COLLATE utf8mb4_bin', [$escaped . '%'])
            ->orderByRaw('name COLLATE utf8mb4_bin')
            ->get();

        return response()->json(['data' => $result]);
    }

    
    /**
     * 指定した馬名の全戦績を取得する
     *
     * 馬名で t_horse_odds_finder_race_result_history を検索し、
     * 日付昇順で全レースの出走記録を返す。
     * COLLATE utf8mb4_bin で大文字・小文字・全半角を区別した完全一致で検索する。
     *
     * クエリパラメータ:
     *   name = 馬名（必須）例: エフフォーリア
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderHorseBattleRecord(Request $request)
    {
        $name = (string) $request->query('name');

        if ($name === '') {
            return response()->json(['error' => 'name パラメータが必要です'], 400);
        }

        $names = explode('/', $name);
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        $result = DB::table('t_horse_odds_finder_race_result_history as h')
            ->leftJoin('t_horse_odds_finder_race_result_payout as p', function ($join) {
                $join->on('h.date',       '=', 'p.date')
                     ->on('h.kaisuu',     '=', 'p.kaisuu')
                     ->on('h.basho_code', '=', 'p.basho_code')
                     ->on('h.day',        '=', 'p.day')
                     ->on('h.race',       '=', 'p.race');
            })
            ->select('h.*', 'p.course', 'p.dist', 'p.inner_outer')
            ->whereRaw("h.name COLLATE utf8mb4_bin IN ({$placeholders})", $names)
            ->orderBy('h.name')
            ->orderBy('h.date')
            ->orderBy('h.kaisuu')
            ->orderBy('h.basho_code')
            ->orderBy('h.day')
            ->orderBy('h.race')
            ->get();

        return response()->json(['data' => $result]);
    }

    
    /**
     * 指定馬名リストの出走履歴を取得する
     *
     * スラッシュ（/）区切りの馬名リストを受け取り、
     * t_horse_odds_finder_shutsuba_history から一括取得して返す。
     * 馬名ごと・日付順にソートされる。
     *
     * クエリパラメータ:
     *   names = スラッシュ区切りの馬名 例: エフフォーリア/イクイノックス
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderShutsubaHistory(Request $request)
    {
        $ex_names = array_filter(explode("/", $request->names));

        $result = DB::table('t_horse_odds_finder_shutsuba_history')
            ->whereIn('name', $ex_names)
            ->orderBy('name')
            ->orderBy('date')
            ->get();

        return response()->json(['data' => $result]);
    }































    /**
     * 全馬の最高着順時の馬体重を取得する
     *
     * 各馬の全レース結果の中から着順が最も良いレース（同着の場合は直近）を1件選び、
     * そのレースに紐づく出馬表履歴（shutsuba_history）から馬体重を取得して返す。
     * 馬体重の増減傾向や、ベストパフォーマンス時の体重を把握するために使用する。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderBestHorseWeight()
    {

// 20261102: 速度改善のため書き換え（旧SQLは下にコメントアウトで残しています）
//   旧：全レース結果（約18万行）すべてに出馬表履歴を結合してから、馬ごとに1件へ絞っていた（約2.3秒）
//   新：先に馬ごとのベスト1件（約2.5万行）へ絞り、その分だけ出馬表履歴を結合する（約1.0秒）
//   返す列・並び順・値は旧と同じ（本番DBで全25,572行のチェックサム一致を確認済み）
// 20261102: さらに、返すのを「今週の出走馬（t_horse_odds_finder_horses）」かつ「馬体重あり」に限定（約0.26秒）
//   Flutter で使っているのは race_content_page.dart の「Best Weight」表示だけで、
//   今週の出走馬の馬名で引き、馬体重が空なら表示しない作り。なので表示される内容は変わらない。
//   起動時に毎回 25,572行 → 約270行 の転送になる（本番DBで、表示対象の行が全件一致することを確認済み）
$sql = "
SELECT
ranked.name,
ranked.best_finishing_position,
ranked.best_date       as date,
ranked.best_basho      as basho,
ranked.best_basho_code as basho_code,
ranked.best_kaisuu     as kaisuu,
ranked.best_day        as day,
ranked.best_race       as race,
ranked.best_race_name  as race_name,
s.horse_weight
FROM (
SELECT
r.name,
r.finishing_position AS best_finishing_position,
r.date               AS best_date,
r.basho              AS best_basho,
r.basho_code         AS best_basho_code,
r.kaisuu             AS best_kaisuu,
r.day                AS best_day,
r.race               AS best_race,
r.race_name          AS best_race_name,
ROW_NUMBER() OVER (
PARTITION BY r.name
ORDER BY r.finishing_position ASC, r.date DESC
) AS rn
FROM t_horse_odds_finder_race_result_history r
WHERE r.finishing_position IS NOT NULL
AND r.finishing_position > 0
AND r.name IN (SELECT h.name FROM t_horse_odds_finder_horses h)
) ranked
LEFT JOIN t_horse_odds_finder_shutsuba_history s
ON  ranked.name            = s.name
AND ranked.best_date       = s.date
AND ranked.best_basho_code = s.basho_code
AND ranked.best_race       = s.race
WHERE ranked.rn = 1
AND s.horse_weight IS NOT NULL
AND s.horse_weight <> ''
ORDER BY ranked.name;
";

$result = DB::select($sql);

/* 20261102: 旧SQL（速度改善前）
$sql = "
SELECT
name,
best_finishing_position,
best_date as date,
best_basho as basho,
best_basho_code as basho_code,
best_kaisuu as kaisuu,
best_day as day,
best_race as race,
best_race_name as race_name,
horse_weight
FROM (
SELECT
r.name,
r.finishing_position AS best_finishing_position,
r.date               AS best_date,
r.basho              AS best_basho,
r.basho_code         AS best_basho_code,
r.kaisuu             AS best_kaisuu,
r.day                AS best_day,
r.race               AS best_race,
r.race_name          AS best_race_name,
s.horse_weight,
ROW_NUMBER() OVER (
PARTITION BY r.name
ORDER BY r.finishing_position ASC, r.date DESC
) AS rn
FROM t_horse_odds_finder_race_result_history r
LEFT JOIN t_horse_odds_finder_shutsuba_history s
ON  r.name       = s.name
AND r.date       = s.date
AND r.basho_code = s.basho_code
AND r.race       = s.race
WHERE r.finishing_position IS NOT NULL
AND r.finishing_position > 0
) ranked
WHERE rn = 1
ORDER BY name;
";

$result = DB::select($sql);
*/

return response()->json(['data' => $result]);

    }

    /**
     * 20261026: 1日分のAI結果（1st / 2nd）を、保存済みの回答だけからまとめて返す
     *
     * 【なぜ必要か】
     *   Flutter の「過去レースのオッズ遷移表」は、日付を開くとレースごとに
     *   getHorseOddsFinderAiAnalysis / getHorseOddsFinderSecondAiOpinion（AiController）を1回ずつ呼んでいた
     *   （1日36レースなら72回）。API の回数制限（429）に当たっていたうえ、
     *   保存済みの回答が無いレースでは、その場で AI（有料）を呼びに行く作りだった。
     * 【動き】
     *   ・保存済みの回答を読むだけ。AI は呼ばない。ロックも取らない。
     *   ・画面用に整える処理は AiController のものをそのまま使う（二重管理しない）。
     *     1st は publicPart()（内部監査用JSONを落とす）を通して返す。
     *     2nd は getHorseOddsFinderSecondAiOpinion のキャッシュ返却と同じ条件（_canReturnSecondAiCache）を
     *     満たすときだけ、同じ形（analysis_text + _loadSavedMergeResult）で返す。満たさなければ null。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [ { date, kaisuu, basho_code, day, race, first_analysis_text, second }, ... ] }
     */
    public function getHorseOddsFinderAiAnalysisByDate(Request $request)
    {
        $date = (string) $request->query('date', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => 'date が不正です'], 422);
        }

        /** @var \App\Http\Controllers\Api\AiController $ai */
        $ai = app(\App\Http\Controllers\Api\AiController::class);

        $cols = ['kaisuu', 'basho_code', 'day', 'race', 'analysis_text'];

        $firstRows = DB::table('t_horse_odds_finder_ai_analysis')
            ->where('date', $date)
            ->get($cols);

        $secondRows = DB::table('t_horse_odds_finder_ai_analysis2')
            ->where('date', $date)
            ->get($cols);

        $keyOf = static function ($r): string {
            return ((int) $r->kaisuu) . '_' . ((string) $r->basho_code) . '_' . ((int) $r->day) . '_' . ((int) $r->race);
        };

        $blank = static function ($r) use ($date): array {
            return [
                'date'                => $date,
                'kaisuu'              => (int) $r->kaisuu,
                'basho_code'          => (string) $r->basho_code,
                'day'                 => (int) $r->day,
                'race'                => (int) $r->race,
                'first_analysis_text' => null,
                'second'              => null,
            ];
        };

        $items = [];

        foreach ($firstRows as $r) {
            $k = $keyOf($r);
            if (!isset($items[$k])) {
                $items[$k] = $blank($r);
            }
            $items[$k]['first_analysis_text'] = trim(AiController::publicPart((string) $r->analysis_text));
        }

        foreach ($secondRows as $r) {
            $k = $keyOf($r);
            if (!isset($items[$k])) {
                $items[$k] = $blank($r);
            }
            if ($ai->_canReturnSecondAiCache($date, $r->kaisuu, $r->basho_code, $r->day, $r->race)) {
                $items[$k]['second'] = array_merge([
                    'date'          => $date,
                    'kaisuu'        => $r->kaisuu,
                    'basho_code'    => $r->basho_code,
                    'day'           => $r->day,
                    'race'          => $r->race,
                    'analysis_text' => trim(AiController::publicPart((string) $r->analysis_text)),
                ], $ai->_loadSavedMergeResult($date, $r->kaisuu, $r->basho_code, $r->day, $r->race));
            }
        }

        // @ANCHOR-20261102-PREDICTION-PUBLIC  新しい予想の対象レースは、新しい予想で上書きします
        //   BAGANRIKI_PREDICTION_PUBLIC が下りていれば何もしません（今までどおり）。
        //   predictionAnswerFor が null のレース（旧方式の時代のレース等）は、上の旧方式の値のままです。
        if (AiController::predictionPublicEnabled()) {
            foreach (DB::table('t_horse_odds_finder_races')->where('date', $date)
                        ->get(['kaisuu', 'basho', 'day', 'race']) as $r) {
                $pa = $ai->predictionAnswerFor($date, $r->kaisuu, $r->basho, $r->day, $r->race);
                if ($pa === null) { continue; }
                $k = ((int) $r->kaisuu) . '_' . ((string) $r->basho) . '_' . ((int) $r->day) . '_' . ((int) $r->race);
                if (!isset($items[$k])) {
                    $items[$k] = [
                        'date' => $date, 'kaisuu' => (int) $r->kaisuu, 'basho_code' => (string) $r->basho,
                        'day' => (int) $r->day, 'race' => (int) $r->race,
                        'first_analysis_text' => null, 'second' => null,
                    ];
                }
                // null の側は旧方式の保存済み（上で入れた値）のまま
                if ($pa['first'] !== null) {
                    $items[$k]['first_analysis_text'] = !empty($pa['first']['not_ready'])
                        ? null : (string) ($pa['first']['analysis_text'] ?? '');
                }
                if ($pa['second'] !== null) {
                    $items[$k]['second'] = !empty($pa['second']['not_ready']) ? null : $pa['second'];
                }
            }
        }

        return response()->json(['data' => array_values($items)]);
    }

}
