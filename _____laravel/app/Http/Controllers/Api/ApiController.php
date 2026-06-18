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
            
        // try {
        //     app(LineService::class)->send('getHorseOddsFinderRaceOneResultが呼ばれました。');
        // } catch (\Exception $e) {
        //     \Log::warning('LINE送信失敗: ' . $e->getMessage());
        // }
        
        return response()->json(['data' => $result]);
    }
    
}
