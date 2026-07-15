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
    
    public function getHorseOddsFinderHighProbabilityHorses(Request $request)
    {
        // =====================================================
        // 高可能性馬検索
        // 過去の類似レースで「来る可能性が高い」と判断された馬だけを返す
        //
        // クエリパラメータ:
        //   date  = 対象日付 例: 2026-07-12 （省略時は今日）
        //   race  = レース番号 例: 3 （省略時は全レース）
        //
        // 絞り込み条件（2つ全て満たす馬のみ）:
        //   1. place_rate 50%以上   → 2回に1回は3着以内に来ている
        //   2. 類似レース2件以上    → 1件だけでは信頼性が低いので除外
        // =====================================================

        $MIN_PLACE_RATE    = 50.0;
        $MIN_SIMILAR_TOTAL = 2;

        // dateパラメータがあればそれを使う、なければ今日の日付
        $targetDate = $request->query('date', date('Y-m-d'));

        // raceパラメータがあればそのレースだけ、なければ全レース
        $targetRace = $request->query('race', null);

        $query = DB::table('t_horse_odds_finder_races')
            ->where('date', $targetDate)
            ->orderBy('kaisuu')
            ->orderBy('basho')
            ->orderBy('day')
            ->orderBy('race');

        if ($targetRace !== null) {
            $query->where('race', intval($targetRace));
        }

        $races = $query->get();

        $result = [];

        foreach ($races as $race) {

            $ids = array_values(array_filter(explode('|', $race->popularity_ratio_table_ids ?? '')));

            if (empty($ids)) continue;

            $similarRaces = DB::table('t_horse_odds_finder_races_popularity_ratio')
                ->whereIn('id', $ids)
                ->get();

            if ($similarRaces->isEmpty()) continue;

            // =================================================
            // 現在レースの各馬のオッズを取得
            // 999  = 計測開始前のベースライン
            // 3    = 発走3分前（馬券購入可能な最終タイミング）
            // 0や-999は馬券購入後のため使用しない
            // =================================================
            $odds = DB::table('t_horse_odds_finder_odds')
                ->where('date', $targetDate)
                ->where('kaisuu', $race->kaisuu)
                ->where('basho', $race->basho)
                ->where('day', $race->day)
                ->where('race', $race->race)
                ->whereIn('minutes_before_start', [999, 3])
                ->get()
                ->groupBy('num');

            $latestOdds = [];
            foreach ($odds as $num => $rows) {

                $latest = $rows
                    ->filter(fn($r) => is_numeric($r->odds) && floatval($r->odds) > 0)
                    ->sortBy('minutes_before_start')
                    ->first();

                $base = $rows->where('minutes_before_start', 999)->first();

                if (!$latest) continue;

                $latestOdds[$num] = [
                    'num'       => $num,
                    'odds_base' => $base ? floatval($base->odds) : null,
                    'odds_now'  => floatval($latest->odds),
                    'fuku_min'  => floatval($latest->fuku_min),
                    'timing'    => $latest->minutes_before_start,
                ];
            }

            uasort($latestOdds, fn($a, $b) => $a['odds_now'] <=> $b['odds_now']);
            $rank = 1;
            foreach ($latestOdds as $num => &$horse) {
                $horse['popularity_rank'] = $rank++;

                if ($horse['odds_base'] && $horse['odds_base'] > 0) {
                    $horse['odds_change_rate'] = round(
                        ($horse['odds_now'] - $horse['odds_base']) / $horse['odds_base'] * 100,
                        1
                    );
                } else {
                    $horse['odds_change_rate'] = null;
                }
            }
            unset($horse);

            $stats = [];
            foreach ($similarRaces as $sr) {
                $histories = DB::table('t_horse_odds_finder_race_result_history')
                    ->where('date', $sr->date)
                    ->where('kaisuu', $sr->kaisuu)
                    ->where('basho_code', $sr->basho)
                    ->where('day', $sr->day)
                    ->where('race', $sr->race)
                    ->get();

                foreach ($histories as $h) {
                    $pop = $h->popularity_rank;

                    if (!isset($stats[$pop])) {
                        $stats[$pop] = [
                            'total'    => 0,
                            'win'      => 0,
                            'place'    => 0,
                            'tan_sum'  => 0.0,
                            'fuku_sum' => 0.0,
                        ];
                    }

                    $stats[$pop]['total']++;
                    if ($h->finishing_position == 1) $stats[$pop]['win']++;
                    if ($h->finishing_position <= 3) $stats[$pop]['place']++;
                    $stats[$pop]['tan_sum']  += floatval($h->tan);
                    $stats[$pop]['fuku_sum'] += floatval($h->fuku_min);
                }
            }

            $horses = [];
            foreach ($latestOdds as $num => $horse) {
                $pop = $horse['popularity_rank'];
                $s   = $stats[$pop] ?? null;

                if (!$s || $s['total'] === 0) continue;

                $placeRate      = round($s['place'] / $s['total'] * 100, 1);
                $tanReturnRate  = round($s['tan_sum']  / $s['total'], 1);
                $fukuReturnRate = round($s['fuku_sum'] / $s['total'], 1);

                if (count($ids) < $MIN_SIMILAR_TOTAL) continue;
                if ($placeRate  < $MIN_PLACE_RATE)    continue;

                // --- オッズの動きを文章化 ---
                $changeRate = $horse['odds_change_rate'];
                if ($changeRate === null) {
                    $oddsComment = "直前オッズは{$horse['odds_now']}倍です。";
                } elseif ($changeRate <= -10) {
                    $oddsComment = "計測開始前{$horse['odds_base']}倍から直前{$horse['odds_now']}倍へオッズが大きく下落（{$changeRate}%）しており、直前に人気が急上昇しています。";
                } elseif ($changeRate < 0) {
                    $oddsComment = "計測開始前{$horse['odds_base']}倍から直前{$horse['odds_now']}倍へオッズが下落（{$changeRate}%）しており、直前に人気が上昇しています。";
                } elseif ($changeRate == 0.0) {
                    $oddsComment = "計測開始前から直前まで{$horse['odds_now']}倍と、オッズに変化はなく安定した支持を受けています。";
                } elseif ($changeRate <= 10) {
                    $oddsComment = "計測開始前{$horse['odds_base']}倍から直前{$horse['odds_now']}倍へオッズがやや上昇しており、人気がわずかに落ちています。";
                } else {
                    $oddsComment = "計測開始前{$horse['odds_base']}倍から直前{$horse['odds_now']}倍へオッズが大きく上昇（{$changeRate}%）しており、人気が落ちています。";
                }

                // --- 類似レースでの成績を文章化 ---
                $similarCount = count($ids);
                $winCount     = $s['win'];
                $placeCount   = $s['place'];

                if ($winCount > 0) {
                    $resultComment = "過去{$similarCount}件の類似レースで{$pop}番人気の馬は複勝圏内{$placeCount}回、うち1着は{$winCount}回でした。";
                } else {
                    $resultComment = "過去{$similarCount}件の類似レースで{$pop}番人気の馬は複勝圏内{$placeCount}回、1着はありませんでした。";
                }

                // --- 複勝率に応じたコメント ---
                if ($placeRate >= 100) {
                    $placeComment = "複勝率100%と、類似レースでは必ず馬券圏内に入っています。";
                } elseif ($placeRate >= 75) {
                    $placeComment = "複勝率{$placeRate}%と、類似レースでは高い確率で馬券圏内に入っています。";
                } else {
                    $placeComment = "複勝率{$placeRate}%です。";
                }

                $analysis = $oddsComment . $resultComment . $placeComment;

                $horses[] = [
                    'num'              => $num,
                    'popularity_rank'  => $pop,
                    'odds_base'        => $horse['odds_base'],
                    'odds_now'         => $horse['odds_now'],
                    'odds_change_rate' => $horse['odds_change_rate'],
                    'fuku_min'         => $horse['fuku_min'],
                    'win_count'        => $s['win'],
                    'place_count'      => $s['place'],
                    'win_rate'         => round($s['win'] / $s['total'] * 100, 1),
                    'place_rate'       => $placeRate,
                    'tan_return_rate'  => $tanReturnRate,
                    'fuku_return_rate' => $fukuReturnRate,
                    'analysis'         => $analysis,
                ];
            }

            if (empty($horses)) continue;

            usort($horses, fn($a, $b) => $b['place_rate'] <=> $a['place_rate']);

            $result[] = [
                'date'          => $race->date,
                'kaisuu'        => $race->kaisuu,
                'basho'         => $race->basho,
                'basho_name'    => $race->basho_name,
                'day'           => $race->day,
                'race'          => $race->race,
                'race_name'     => $race->race_name,
                'similar_count' => count($ids),
                'similar_ids'   => $ids,
                'horses'        => $horses,
            ];
        }

        return response()->json(['data' => $result]);
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

    
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
/// Claude AIの使用

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

        // ─── 注目馬の抽出ロジック ─────────────────────────────────────────
        // AIにプロンプトで "PICKUP:馬番|馬名/..." 形式の最終行を出力させているので、
        // その行だけを抜き出す。形式が固定されているため自由文のパースより確実。
        $parsePickupHorses = function (string $text): string {
            if (preg_match('/^PICKUP:(.+)$/mu', $text, $m)) {
                return trim($m[1]);
            }
            return '';
        };

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
                'analysis_text' => trim(preg_replace('/^PICKUP:.+$/mu', '', $cached->analysis_text)),
                'pickup_horse'  => $parsePickupHorses($cached->analysis_text),
            ]]);
        }

        // ─── レース基本情報の取得 ─────────────────────────────────────────
        // basho_name（場名称）と race_name をDBから取得する。
        // ※ 全件取得は不要。対象レースの1行だけ絞り込めば十分。
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
        // 馬情報・オッズ推移を組み合わせてプロンプトを構築する（_getAiAnalysisPrompt 参照）。
        // オッズデータが不足している場合は null が返るので早期リターンする。
        $prompt = $this->_getAiAnalysisPrompt($date, $kaisuu, $basho, $day, $race);

        if ($prompt === null) {
            return response()->json(['error' => 'プロンプト生成に失敗しました（レースまたはオッズデータが不足しています）'], 404);
        }

        $now  = date('Y-m-d_H-i-s');  // コロン(:)はファイル名に使えないので - に変更
        $file = "/var/www/horse_odds_finder/public/prompt/prompt_{$now}.data";
        file_put_contents($file, $prompt);
        
        // ─── Claude API 呼び出し ──────────────────────────────────────────
        $aiResponse = \Illuminate\Support\Facades\Http::withHeaders([
            'x-api-key'         => config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model'      => 'claude-haiku-4-5',
            'max_tokens' => 1024,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if ($aiResponse->failed()) {
            \Log::error('Anthropic API error', [
                'status' => $aiResponse->status(),
                'body'   => $aiResponse->body(),
            ]);
            return response()->json(['error' => 'AI分析に失敗しました'], 500);
        }

        // レスポンスのテキスト部分を取り出す（ドット記法でネストを辿る）
        $rawText = $aiResponse->json('content.0.text') ?? '';

        // PICKUP: 行を抽出して pickup_horse を確定する
        $pickupHorse  = $parsePickupHorses($rawText);
        // レスポンス用に PICKUP: 行を除去したテキストを作成
        $analysisText = trim(preg_replace('/^PICKUP:.+$/mu', '', $rawText));

        // ─── 分析結果をDBに保存（次回以降はキャッシュから返す） ──────────
        // analysis_text には PICKUP: 行を含んだまま保存する。
        // キャッシュ返却時にも pickup_horse を再抽出できるようにするため。
        // APIレスポンスでは PICKUP: 行を除去して返す（上の $analysisText を使用）。
        //   basho_code ← $basho          （場コード: races.basho）
        //   basho      ← basho_name      （場名称:   races.basho_name）
        DB::table('t_horse_odds_finder_ai_analysis')->insert([
            'date'          => $date,
            'kaisuu'        => $kaisuu,
            'basho_code'    => $basho,
            'basho'         => $raceRow->basho_name,
            'day'           => $day,
            'race'          => $race,
            'race_name'     => $raceRow->race_name,
            'analysis_text' => $rawText,  // PICKUP: 行を含めて保存
        ]);

        return response()->json(['data' => [
            'date'          => $date,
            'kaisuu'        => $kaisuu,
            'basho_code'    => $basho,
            'day'           => $day,
            'race'          => $race,
            'analysis_text' => $analysisText,
            'pickup_horse'  => $pickupHorse,
        ]]);
    }


    /**
     * AI分析用のプロンプト文字列を組み立てる
     *
     * 以下の3テーブルを参照してプロンプトを生成する。
     *   - t_horse_odds_finder_races  : レース基本情報（開催・レース名など）
     *   - t_horse_odds_finder_horses : 出走馬情報（馬番・馬名）
     *   - t_horse_odds_finder_odds   : 単勝オッズ（計測開始前: 999分前, 発走3分前: 3分前）
     *
     * オッズが両方揃っている馬だけを分析対象とし、変動率（%）を算出してプロンプトに含める。
     * レースが存在しないか、分析対象馬が0頭の場合は null を返す。
     *
     * @param  string $targetDate    対象日付（Y-m-d）
     * @param  string $targetKaisuu  開催回数
     * @param  string $targetBasho   場コード（t_horse_odds_finder_races.basho）
     * @param  string $targetDay     開催日次
     * @param  string $targetRace    レース番号
     * @return string|null  プロンプト文字列、または null（データ不足時）
     */
    private function _getAiAnalysisPrompt($targetDate, $targetKaisuu, $targetBasho, $targetDay, $targetRace)
    {
        // ─── レース存在確認 ───────────────────────────────────────────────
        $raceQuery = DB::table('t_horse_odds_finder_races')
            ->where('date',   $targetDate)
            ->where('kaisuu', $targetKaisuu)
            ->where('basho',  $targetBasho)
            ->where('day',    $targetDay)
            ->where('race',   intval($targetRace));

        $race = $raceQuery->first();

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

        // ─── オッズ取得（計測開始前と3分前の2時点のみ） ─────────────────
        // minutes_before_start = 999 : 計測開始時点（ベースオッズ）
        // minutes_before_start = 3   : 発走3分前（最終オッズに近い値）
        $oddsRows = DB::table('t_horse_odds_finder_odds')
            ->where('date',   $targetDate)
            ->where('kaisuu', $race->kaisuu)
            ->where('basho',  $race->basho)
            ->where('day',    $race->day)
            ->where('race',   $race->race)
            ->whereIn('minutes_before_start', [999, 3])
            ->get();

        // 馬番ごとに2時点のオッズをまとめる
        $oddsByNum = [];
        foreach ($oddsRows as $row) {
            $num = $row->num;
            if (!isset($oddsByNum[$num])) {
                $oddsByNum[$num] = ['odds_base' => null, 'odds_3' => null];
            }
            if ($row->minutes_before_start == 999) {
                $oddsByNum[$num]['odds_base'] = floatval($row->odds);
            }
            if ($row->minutes_before_start == 3) {
                $oddsByNum[$num]['odds_3'] = floatval($row->odds);
            }
        }

        // ─── プロンプト用データの組み立て ────────────────────────────────
        // 両時点のオッズが揃っている馬のみ対象。変動率を計算してラベルも付与する。
        $promptHorses = [];
        foreach ($oddsByNum as $num => $o) {
            // 片方でも欠けている、またはベースオッズが0の馬はスキップ（0除算防止）
            if ($o['odds_base'] === null || $o['odds_3'] === null || $o['odds_base'] == 0) continue;

            // 変動率（%）= (3分前オッズ - ベースオッズ) / ベースオッズ × 100
            $changeRate = round(($o['odds_3'] - $o['odds_base']) / $o['odds_base'] * 100, 1);

            if ($changeRate < 0) {
                $changeLabel = '下落 ' . abs($changeRate) . '%';
            } elseif ($changeRate > 0) {
                $changeLabel = '上昇 +' . $changeRate . '%';
            } else {
                $changeLabel = '変化なし';
            }

            // 馬名が取れない場合は「馬{番号}」で代替
            $name = isset($horses[$num]) ? $horses[$num]->name : '馬' . $num;

            $promptHorses[] = [
                'num'          => $num,
                'name'         => $name,
                'odds_base'    => $o['odds_base'],
                'odds_3'       => $o['odds_3'],
                'change_rate'  => $changeRate,
                'change_label' => $changeLabel,
            ];
        }

        // 分析対象馬が1頭もいなければプロンプト生成不可
        if (empty($promptHorses)) {
            return null;
        }

        // 馬番順に並べてテーブル形式のテキストを作成
        usort($promptHorses, fn($a, $b) => $a['num'] <=> $b['num']);

        $lines = [];
        foreach ($promptHorses as $h) {
            $lines[] = sprintf(
                '%2d番 %-12s  計測開始前: %5.1f倍  3分前: %5.1f倍  変動: %s',
                $h['num'],
                $h['name'],
                $h['odds_base'],
                $h['odds_3'],
                $h['change_label']
            );
        }
        $table = implode("\n", $lines);

        // ─── プロンプト本文の構築 ─────────────────────────────────────────
        $raceLabel = $race->kaisuu . '回' . $race->basho_name . $race->day . '日';
        $raceNum   = $race->race . 'R';
        $raceName  = $race->race_name ?? '';

        return 'あなたは競馬オッズ分析の専門家です。' . "\n" . '有料公開するものなので、できるだけ正しい日本語で返してください。' . "\n\n"
            . 'レース情報' . "\n"
            . '日付: ' . $targetDate . "\n"
            . '開催: ' . $raceLabel . "\n"
            . 'レース: ' . $raceNum . ' ' . $raceName . "\n\n"
            . '単勝オッズデータ（計測開始前から発走3分前）' . "\n"
            . $table . "\n\n"
            . '分析依頼' . "\n"
            . 'オッズ推移から以下を教えてください。' . "\n"
            . '1. 勝つ確率が高そうな馬（最大3頭）と理由' . "\n"
            . '2. 積極的に消してよい馬と理由' . "\n"
            . '3. このレースの総評（混戦か本命か、買い方の方向性）' . "\n\n"
            . 'オッズ下落10%以上は人気急上昇として注目してください。' . "\n"
            . '日本語・箇条書きで簡潔にまとめてください。' . "\n\n"
            . '【必須】回答の最後の行に、必ず以下の形式だけで注目馬を出力してください。' . "\n"
            . '他の文章や説明は一切付けず、この1行だけを最終行にしてください。' . "\n"
            . 'PICKUP:馬番|馬名|おすすめ度/馬番|馬名|おすすめ度/馬番|馬名|おすすめ度' . "\n"
            . '例）PICKUP:3|トーアマリシテン|99/6|モスクロッサー|99/5|フェイトライン|99';
    }
    
}
