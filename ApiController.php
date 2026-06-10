<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use DB;

use App\Constants\Constants;

class ApiController extends Controller
{

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

    public function getHorseOddsFinderConfigs()
    {

        return response()->json(['data' => [
            "odds_get_timing" => implode("|", Constants::ODDS_GET_TIMING),
        ]]);

    }

    public function getHorseOddsFinderNetkeibaRaces()
    {
        $result = DB::table('t_horse_odds_finder_netkeiba_races')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->get();

        return response()->json(['data' => $result]);
    }

    public function getHorseOddsFinderNetkeibaOdds()
    {
        $result = DB::table('t_horse_odds_finder_netkeiba_odds')
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

    public function getHorseOddsFinderOddsWide()
    {
        $result = DB::table('t_horse_odds_finder_odds_wide')
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race')
            ->orderBy('minutes_before_start')
            ->orderBy('uma1')
            ->orderBy('uma2')
            ->get();

        return response()->json(['data' => $result]);
    }
    
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
    
}
