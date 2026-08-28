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

return response()->json(['data' => $result]);

    }

}
