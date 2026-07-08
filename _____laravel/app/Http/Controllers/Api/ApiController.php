<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use DB;

use App\Constants\Constants;

use App\Services\LineService;

class ApiController extends Controller
{
    
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
/// サインアップ、サインイン、認証


    public function signup(Request $request)
    {
        $userId   = $request->input('user_id');
        $email    = $request->input('email');
        $password = $request->input('password');

        if (!$userId || !$email || !$password) {
            return response()->json(['success' => false, 'message' => 'user_id、email、passwordは必須です'], 400);
        }

        $exists = DB::table('t_horse_odds_finder_login_users')
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'message' => 'すでに登録済みのuser_idです'], 409);
        }

        $emailExists = DB::table('t_horse_odds_finder_login_users')
            ->where('email', $email)
            ->exists();

        if ($emailExists) {
            return response()->json(['success' => false, 'message' => 'すでに登録済みのメールアドレスです'], 409);
        }

        $token = bin2hex(random_bytes(32));

        DB::table('t_horse_odds_finder_login_users')->insert([
            'user_id'          => $userId,
            'email'            => $email,
            'password'         => Hash::make($password),
            'is_delete'        => 0,
            'is_verified'      => 0,
            'is_admin'         => 0,
            'verify_token'     => $token,
            'token_expires_at' => now()->addHours(24),
        ]);

        $verifyUrl = url('/verify?token=' . $token);

        \Mail::raw(
            "馬眼力 Odds Finder にご登録いただきありがとうございます。\n\n"
            . "以下のリンクをクリックしてメール認証を完了してください。\n"
            . "（リンクの有効期限は24時間です）\n\n"
            . $verifyUrl . "\n\n"
            . "このメールに心当たりがない場合は無視してください。",
            function ($message) use ($email, $userId) {
                $message->to($email)->subject('【馬眼力 Odds Finder】メール認証のご案内');
            }
        );

        return response()->json(['success' => true, 'message' => 'メールを送信しました。確認してください。']);
    }
    
    public function signin(Request $request)
    {
        $userId   = $request->input('user_id');
        $password = $request->input('password');

        if (!$userId || !$password) {
            return response()->json(['success' => false, 'message' => 'user_idとpasswordは必須です'], 400);
        }

        $user = DB::table('t_horse_odds_finder_login_users')
            ->where('user_id', $userId)
            ->where('is_delete', 0)
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json(['success' => false, 'message' => 'user_idまたはパスワードが間違っています'], 401);
        }

        if (!$user->is_verified) {
            return response()->json(['success' => false, 'message' => 'unverified'], 403);
        }

        return response()->json(['success' => true, 'user_id' => $user->user_id]);
    }



public function verify(Request $request)
{
$token = $request->query('token');

$html = function(string $icon, string $title, string $message, string $color, int $status = 400) {
$body = <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>馬眼力 Odds Finder</title>

<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #0f0f0f; font-family: -apple-system, BlinkMacSystemFont, 'Hiragino Sans', sans-serif; color: #e0e0e0; padding: 20px;}
.card {background: #1c1c1e; border-radius: 16px; padding: 48px 40px; max-width: 480px; width: 100%; text-align: center; box-shadow: 0 8px 32px rgba(0,0,0,0.4); border: 1px solid #2c2c2e;}
.icon { font-size: 64px; margin-bottom: 24px; }
h1 { font-size: 18px; font-weight: 700; color: {COLOR}; margin-bottom: 12px; }
p  { font-size: 12px; color: #9e9e9e; line-height: 1.6; }
.app-name {margin-top: 40px; font-size: 12px; color: #555; letter-spacing: 0.05em; text-transform: uppercase;}
</style>

</head>

<body>
<div class="card">
<div class="icon">{ICON}</div>
<h1>{TITLE}</h1>
<p>{MESSAGE}</p>
<div class="app-name">馬眼力 Odds Finder</div>
</div>
</body>
</html>
HTML;

$body = str_replace(['{ICON}','{TITLE}','{MESSAGE}','{COLOR}'], [$icon, $title, $message, $color], $body);
return response($body, $status)->header('Content-Type', 'text/html; charset=UTF-8');
};

if (!$token) {
return $html('🔗', 'トークンが見つかりません', 'URLが正しいか確認してください。', '#ff6b6b');
}

$user = DB::table('t_horse_odds_finder_login_users')->where('verify_token', $token)->first();

if (!$user) {
return $html('❌', '無効なトークンです', 'すでに認証済みか、URLが正しくありません。', '#ff6b6b');
}

if (now()->greaterThan($user->token_expires_at)) {
return $html('⏰', 'リンクの有効期限が切れています', 'もう一度アプリからサインアップしてください。', '#ffa94d');
}

DB::table('t_horse_odds_finder_login_users')
->where('verify_token', $token)
->update(['is_verified' => 1, 'verify_token' => null, 'token_expires_at' => null]);

return $html('✅', 'メール認証が完了しました', 'アプリに戻ってログインしてください。', '#69db7c', 200);
}



////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
/// テーブルデータ取得


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
    

    
    public function getHorseOddsFinderPopularityRankAverage()
    {
        $result = DB::table('t_horse_odds_finder_popularity_rank_average')
            ->orderBy('popularity_rank')
            ->get();
        return response()->json(['data' => $result]);
    }
    
//----------

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
    
    public function getHorseOddsFinderHorseBattleRecord(Request $request)
    {
        $name = (string) $request->query('name');

        if ($name === '') {
            return response()->json(['error' => 'name パラメータが必要です'], 400);
        }

        $result = DB::table('t_horse_odds_finder_race_result_history')
            ->whereRaw('name = ? COLLATE utf8mb4_bin', [$name])
            ->orderBy('date')
            ->orderBy('kaisuu')
            ->orderBy('basho_code')
            ->orderBy('day')
            ->orderBy('race')
            ->get();

        return response()->json(['data' => $result]);
    }
    
    public function getHorseOddsFinderRacesPopularityRatio(Request $request)
    {
        $ids = explode("|", $request->ids);

        // whereIn は入力順を保証しないため FIELD() で並び順を固定する
        $intIds = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($intIds), '?'));

        $result = DB::table('t_horse_odds_finder_races_popularity_ratio')
            ->whereIn('id', $intIds)
            ->orderByRaw("FIELD(id, {$placeholders})", $intIds)
            ->get();

        return response()->json(['data' => $result]);
    }

    public function getHorseOddsFinderRaceResultPayout(Request $request)
    {
        $ex_races = array_filter(explode("/", $request->races));

        $response = [];

        foreach($ex_races as $v){
            list($date, $kaisuu, $basho_code, $race) = explode("|", trim($v));

            $result = DB::table('t_horse_odds_finder_race_result_payout')
                ->where('date', $date)
                ->where('kaisuu', $kaisuu)
                ->where('basho_code', $basho_code)
                ->where('race', $race)
                ->first();

            if ($result === null) {
                continue;
            }

            $response[] = [
                'id' => $result->id,
                'date' => $result->date,
                'kaisuu' => $result->kaisuu,
                'basho' => $result->basho,
                'basho_code' => $result->basho_code,
                'day' => $result->day,
                'race' => $result->race,
                'race_name' => $result->race_name,
                'tan' => $result->tan,
                'fuku' => $result->fuku,
                'waku' => $result->waku,
                'wide' => $result->wide,
                'umaren' => $result->umaren,
                'umatan' => $result->umatan,
                'trio' => $result->trio,
                'trifecta' => $result->trifecta
            ];
        }

        return response()->json(['data' => $response]);
    }

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
/// コンフィグ値取得


public function getHorseOddsFinderConfigs()
{
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

return response()->json(['data' => [
'odds_get_timing' => implode('|', Constants::ODDS_GET_TIMING),
'odds_drop_rate'  => $oddsDropRate,
]]);
}



////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
/// ユーザーリスト


    public function getHorseOddsFinderLoginUsers()
    {
        $result = DB::table('t_horse_odds_finder_login_users')->select('id', 'user_id', 'is_admin', 'is_delete')->get();
        return response()->json(['data' => $result]);
    }
    
    public function changeAdmin(Request $request)
    {
        $id      = $request->input('id');
        $isAdmin = $request->input('is_admin');

        DB::table('t_horse_odds_finder_login_users')->where('id', $id)->update(['is_admin' => $isAdmin]);
    }
    
    public function changeDelete(Request $request)
    {
        $id       = $request->input('id');
        $isDelete = $request->input('is_delete');
        
        DB::table('t_horse_odds_finder_login_users')->where('id', $id)->update(['is_delete' => $isDelete]);
    }
    


////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
/// プッシュ通信ユーザーリスト


    public function getHorseOddsFinderPushSubscriptions()
    {
        $result = DB::table('t_horse_odds_finder_push_subscriptions')->select('id', 'user_id', 'is_delete')->get();
        return response()->json(['data' => $result]);
    }
    
    public function changePushNotifierUserDelete(Request $request)
    {
        $id       = $request->input('id');
        $isDelete = $request->input('is_delete');
        
        DB::table('t_horse_odds_finder_push_subscriptions')->where('id', $id)->update(['is_delete' => $isDelete]);
    }
    
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
/// サマリーテーブルのカウント取得


    public function getHorseOddsFinderSummaryTableCount()
    {
        $sql = " select date, count(date) as count from t_horse_odds_finder_race_result_history group by date; ";
        $history = [];
        $result = DB::select($sql);
        foreach($result as $v){
            $history[$v->date] = $v->count;
        }
        
        $sql = " select date, count(date) as count from t_horse_odds_finder_race_result_history where popularity_rank is not null group by date; ";
        $history_popularity_rank = [];
        $result = DB::select($sql);
        foreach($result as $v){
            $history_popularity_rank[$v->date] = $v->count;
        }

        $sql = " select date, count(date) as count from t_horse_odds_finder_race_result_history where finishing_position is not null group by date; ";
        $history_finishing_position = [];
        $result = DB::select($sql);
        foreach($result as $v){
            $history_finishing_position[$v->date] = $v->count;
        }
        
        $sql = " select date, count(date) as count from t_horse_odds_finder_race_result_payout group by date; ";
        $payout = [];
        $result = DB::select($sql);
        foreach($result as $v){
            $payout[$v->date] = $v->count;
        }
        
        $sql = " select date, count(date) as count from t_horse_odds_finder_races_popularity_ratio group by date; ";
        $ratio = [];
        $result = DB::select($sql);
        foreach($result as $v){
            $ratio[$v->date] = $v->count;
        }
        
        $sql = " select date, count(date) as count from t_horse_odds_finder_summary group by date; ";
        $summary = [];
        $result = DB::select($sql);
        foreach($result as $v){
            $summary[$v->date] = $v->count;
        }
        
        //------------

        foreach($history as $date=>$count){
            $response[] = [
                "date" => $date,
                "summary_count" => (isset($summary[$date])) ? $summary[$date] : 0,
                "history_count" => $count,
                "history_popularity_rank_count" => (isset($history_popularity_rank[$date])) ? $history_popularity_rank[$date] : 0,
                "history_finishing_position_count" => (isset($history_finishing_position[$date])) ? $history_finishing_position[$date] : 0,
                "payout_count" => (isset($payout[$date])) ? $payout[$date] : 0,
                "ratio_count" => (isset($ratio[$date])) ? $ratio[$date] : 0,
            ];
        }
        
        return response()->json(['data' => $response]);
    }
    
}
