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

class RaceController extends Controller
{


    /**
     * 開催スケジュール一覧を取得する
     *
     * t_horse_odds_finder_schedules の全件を返す。
     * date → kaisuu → basho → day の順でソート。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderSchedules()
    {
        $result = DB::table('t_horse_odds_finder_schedules')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->get();

        return response()->json(['data' => $result]);
    }


    /**
     * レース一覧を取得する
     *
     * t_horse_odds_finder_races の全件を返す。
     * コース・距離・出走頭数など、レース単位の基本情報が含まれる。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderRaces()
    {
        $result = DB::table('t_horse_odds_finder_races')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->get();

        return response()->json(['data' => $result]);
    }


    /**
     * 出走馬一覧を取得する
     *
     * t_horse_odds_finder_horses の全件を返す。
     * 枠番・馬番・馬名・騎手などが含まれる。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderHorses()
    {
        $result = DB::table('t_horse_odds_finder_horses')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->orderBy('waku')
            ->orderBy('num')
            ->get();

        return response()->json(['data' => $result]);
    }


    /**
     * オッズ一覧を取得する
     *
     * t_horse_odds_finder_odds の全件を返す。
     * minutes_before_start ごとに単勝・複勝オッズを記録した時系列データ。
     *   999 = 計測開始前ベースライン
     *     6 = 発走6分前（馬券購入可能な最終タイミング）
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderOdds()
    {
        $result = DB::table('t_horse_odds_finder_odds')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->orderBy('num')
            ->orderBy('minutes_before_start')
            ->get();

        return response()->json(['data' => $result]);
    }

    
    /**
     * オッズ取得タイミング一覧を取得する
     *
     * t_horse_odds_finder_odds_get_timing の全件を返す。
     * 各レースのオッズを何分前に取得したかの記録テーブル。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderOddsGetTiming()
    {
        $result = DB::table('t_horse_odds_finder_odds_get_timing')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->orderBy('timing')
            ->get();

        return response()->json(['data' => $result]);
    }


    /**
     * レースサマリー全件を取得する
     *
     * t_horse_odds_finder_summary の全件を返す。
     * 馬番ごとの単勝オッズ推移・結果などをまとめたサマリーテーブル。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderSummary()
    {
        $result = DB::table('t_horse_odds_finder_summary')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->orderBy('num')
            ->get();

        return response()->json(['data' => $result]);
    }

    
    /**
     * 指定レースのサマリーを取得する
     *
     * date・kaisuu・basho・day・race で1レースを指定し、
     * そのレースの全馬サマリーを返す。
     *
     * @param  Request $request  date, kaisuu, basho, day, race
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderSummaryOneRace(Request $request)
    {
        $result = DB::table('t_horse_odds_finder_summary')
            ->where('date', $request->date)
            ->where('kaisuu', $request->kaisuu)
            ->where('basho', $request->basho)
            ->where('day', $request->day)
            ->where('race', $request->race)
            ->get();
            
        return response()->json(['data' => $result]);
    }

    
    /**
     * レース結果一覧を取得する
     *
     * t_horse_odds_finder_race_results の全件を返す。
     * 着順・確定タイムなど、レース終了後に記録されるデータ。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderRaceOneResult()
    {
        $result = DB::table('t_horse_odds_finder_race_results')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->orderBy('result')
            ->get();
        return response()->json(['data' => $result]);
    }

    

    


    /**
     * レースごとの人気順位別オッズ中央値を取得する
     *
     * t_horse_odds_finder_popularity_rank_median の全件を返す。
     * 各レースに紐づく類似レース群から算出した、人気順位別（1〜18番人気）の
     * 単勝オッズ中央値が median_01〜median_18 カラムに格納されている。
     * date → kaisuu → basho → day → race の順でソート。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderPopularityRankMedian()
    {
        $result = DB::table('t_horse_odds_finder_popularity_rank_median')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->get();
        return response()->json(['data' => $result]);
    }

    


    public function getHorseOddsFinderHorseScores(){
        $result = DB::table('t_horse_odds_finder_horse_scores')->get();
        return response()->json(['data' => $result]);
    }

    
    public function getHorseOddsFinderJockeyScores(){
        $result = DB::table('t_horse_odds_finder_jockey_scores')->get();
        return response()->json(['data' => $result]);
    }



/**
 * アプリ設定値（コンフィグ）を取得する
 *
 * ─────────────────────────────────────────────────────────────
 * 【返却値】
 *   odds_get_timing → オッズ取得タイミング（発走何分前に取得するか）の配列
 *                     Constants::ODDS_GET_TIMING を | 区切り文字列で返す
 *   odds_drop_rate  → オッズ急落馬（発走前にオッズが30%以上下落）の
 *                     人気帯別複勝率
 *                       honmei  : 単勝5倍未満  （本命）
 *                       chu_ana : 5倍以上15倍未満（中穴）
 *                       daiana  : 15倍以上      （大穴）
 *   baganriki_brain → 馬眼力の脳みそ（baganriki_brain.txt の中身）
 *                     ファイルが無い場合は空文字を返す
 *
 * 【odds_drop_rate の算出条件】
 *   - 30分前と3分前の両オッズが数値で記録されていること
 *   - 3分前オッズ ÷ 30分前オッズ < 0.7（30%以上の下落）
 *   - 最終着順が記録済みであること
 * ─────────────────────────────────────────────────────────────
 *
 * @return \Illuminate\Http\JsonResponse  { data: { odds_get_timing: "...", odds_drop_rate: {...}, baganriki_brain: "..." } }
 */
public function getHorseOddsFinderConfigs()
{
//========================================================//
$sql = "
SELECT
CASE
WHEN CAST(odds_tan_before_3 AS DECIMAL(10,1)) < 5.0  THEN 'honmei'
WHEN CAST(odds_tan_before_3 AS DECIMAL(10,1)) < 15.0 THEN 'chu_ana'
ELSE 'daiana'
END AS odds_band,
ROUND(SUM(CASE WHEN result <= 3 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) AS rate
FROM t_horse_odds_finder_summary
WHERE odds_tan_before_24 REGEXP '^[0-9]'
AND odds_tan_before_3  REGEXP '^[0-9]'
AND result IS NOT NULL
AND (CAST(odds_tan_before_3 AS DECIMAL(10,1)) / CAST(odds_tan_before_24 AS DECIMAL(10,1))) < 0.7
GROUP BY odds_band
";

$rows = DB::select($sql);

$oddsDropRate = ['honmei' => null, 'chu_ana' => null, 'daiana' => null];
foreach ($rows as $row) {$oddsDropRate[$row->odds_band] = (float) $row->rate;}
//========================================================//

// ─── 馬眼力の脳みそ（判断基準）を読み出し
$brainFile      = public_path('baganriki_brain/baganriki_brain.txt');
$baganrikiBrain = file_exists($brainFile) ? trim(file_get_contents($brainFile)) : '';

return response()->json(['data' => [
'odds_get_timing'  => implode('|', Constants::ODDS_GET_TIMING),
'odds_drop_rate'   => $oddsDropRate,
'baganriki_brain'  => $baganrikiBrain,
]]);
}

}
