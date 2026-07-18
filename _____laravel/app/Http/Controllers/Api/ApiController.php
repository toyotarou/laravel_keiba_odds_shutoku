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


    /**
     * 新規ユーザー登録
     *
     * user_id・email・password を受け取りアカウントを作成する。
     * 登録後、確認メールを送信しメール認証を要求する（未認証ではサインインできない）。
     *
     * 重複チェック: user_id・email どちらか一方でも既存なら 409 を返す。
     * verify_token は 64 文字の hex 文字列で、有効期限は 24 時間。
     *
     * @param  Request $request  user_id, email, password（全て必須）
     * @return \Illuminate\Http\JsonResponse
     */
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
    
    /**
     * サインイン（ログイン認証）
     *
     * user_id と password を照合し、認証 OK なら user_id を返す。
     * メール未認証のユーザーは 'unverified' メッセージで 403 を返す。
     * フロント側はこの文字列を見てメール認証誘導画面に遷移する。
     *
     * @param  Request $request  user_id, password（全て必須）
     * @return \Illuminate\Http\JsonResponse
     */
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



/**
 * メール認証トークンの検証
 *
 * signup で送信したメール内のリンクからアクセスされる。
 * トークンが正当かつ有効期限内であれば is_verified=1 に更新し、
 * 結果を HTML ページとして返す（API ではなくブラウザ表示用）。
 *
 * エラーケース:
 *   - トークンなし        → 400（HTML）
 *   - 存在しないトークン  → 400（HTML）
 *   - 有効期限切れ        → 400（HTML、再登録を促す）
 *   - 認証成功            → 200（HTML）
 *
 * @param  Request $request  query: token
 * @return \Illuminate\Http\Response  HTML レスポンス
 */
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
     *     3 = 発走3分前（馬券購入可能な最終タイミング）
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
     * レース情報（旧スクレイピングデータ）を取得する
     *
     * t_horse_odds_finder_netkeiba_races の全件を返す。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
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

    /**
     * オッズ情報（旧スクレイピングデータ）を取得する
     *
     * t_horse_odds_finder_netkeiba_odds の全件を返す。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
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
     * 人気順位別の平均成績を取得する
     *
     * t_horse_odds_finder_popularity_rank_average の全件を返す。
     * 各人気順位（1番人気・2番人気…）の勝率・複勝率平均などが含まれる。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderPopularityRankAverage()
    {
        $result = DB::table('t_horse_odds_finder_popularity_rank_average')
            ->orderBy('popularity_rank')
            ->get();
        return response()->json(['data' => $result]);
    }
    
//----------

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
    
    /**
     * 指定IDの人気比率レコードを取得する
     *
     * パイプ区切り（|）で渡された id リストに対応する
     * t_horse_odds_finder_races_popularity_ratio のレコードを返す。
     * FIELD() 関数で入力 ID の順序通りに結果を並べる。
     *
     * クエリパラメータ:
     *   ids = パイプ区切り ID 例: 101|205|310
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
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

    /**
     * 指定レース群の払い戻し情報を取得する
     *
     * スラッシュ（/）区切りで複数レースを指定し、各レースの払い戻し金額を返す。
     * 各レースは "date|kaisuu|basho_code|race" 形式で指定する。
     * 存在しないレースはスキップするため、レスポンス件数は入力件数より少ない場合がある。
     *
     * レスポンスに含まれる払い戻し種別:
     *   tan（単勝）, fuku（複勝）, waku（枠連）, wide（ワイド）,
     *   umaren（馬連）, umatan（馬単）, trio（三連複）, trifecta（三連単）
     *
     * クエリパラメータ:
     *   races = スラッシュ区切りのレース指定文字列
     *           例: 2023-05-14|3|05|11/2023-05-14|3|05|12
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
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
    
    /**
     * 高可能性馬を検索する
     *
     * ─────────────────────────────────────────────────────────────
     * 【このAPIの目的】
     *   指定日のレースに出走する馬のうち、過去の類似レースデータから
     *   「来る可能性が高い」と統計的に判断できる馬だけを返す。
     *   馬券購入の参考情報として使用する。
     *
     * 【絞り込み条件（2つ全て満たす馬のみ）】
     *   1. place_rate 50%以上   → 2回に1回は3着以内に来ている
     *   2. 類似レース2件以上    → 1件だけでは信頼性が低いので除外
     *
     * 【処理の流れ】
     *   1. 対象日のレース一覧を取得
     *   2. 各レースの類似レース（popularity_ratio_table_ids）を参照
     *   3. 各馬の現在オッズ（999分前と3分前）を取得し人気順位を算出
     *   4. 類似レースの結果履歴から人気順位別の複勝率・回収率を集計
     *   5. 絞り込み条件を満たした馬だけをレスポンスに含める
     *
     * 【クエリパラメータ】
     *   date = 対象日付 例: 2026-07-12 （省略時は今日）
     *   race = レース番号 例: 3 （省略時は全レース）
     *
     * 【参照テーブル】
     *   t_horse_odds_finder_races                  → 当日レース一覧
     *   t_horse_odds_finder_races_popularity_ratio → 類似レースデータ
     *   t_horse_odds_finder_odds                   → 馬ごとのオッズ時系列
     *   t_horse_odds_finder_race_result_history    → 過去レース結果
     * ─────────────────────────────────────────────────────────────
     *
     * @param  Request $request
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
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
     * コース×距離別 過去成績 API
     *
     * ─────────────────────────────────────────────────────────────
     * 【このAPIの目的】
     *   今日出走する馬が、同じコース（芝/ダート）×同じ距離で
     *   過去にどんなレースをしてきたかを返す。
     *   「この馬はダート1700mを走ったことがあるか」
     *   「あるなら何秒で走ったか、どんな位置取りだったか」を
     *   一発で取得できる予想補助ツール。
     *
     * 【予想への使い方】
     *   1. has_experience=false の馬は当コース・距離が未経験 → 適性未知
     *   2. time_rank=1（最速タイム）の馬はスピード実績が最も高い
     *   3. running_style で今日の展開を読む
     *      → 逃げ馬が多い = ハイペース = 差し・追い込み有利
     *      → 逃げ馬が少ない = スロー = 先行馬有利
     *   4. avg_last_surge がプラスの馬は直線で追い込む末脚型
     *      → 差しが決まる展開になれば一発あり
     *      → マイナスが大きい馬は直線でバテる傾向 → 消し候補
     *
     * 【レスポンス構造】
     *   race              → 対象レースの基本情報（コース・距離含む）
     *   data[]            → 出走馬ごとの情報
     *     has_experience  → このコース×距離の経験があるか (bool)
     *     best_time_sec   → 同コース×距離での最速タイム（秒）例: 104.3
     *     time_rank       → 経験馬の中での最速タイム順位（1=最速）
     *     running_style   → 脚質: 逃/先/中/差/追（null=経験なし）
     *     avg_last_surge  → 直線での平均伸び順位数
     *                       プラス = 追い込み型（差しが決まると爆発）
     *                       マイナス = バテ型（末脚が続かない）
     *                       0付近 = 位置取りそのまま
     *     course_dist_stats → 同コース×距離の勝率・複勝率・平均着順
     *     records[]       → 同コース×距離での過去全レース明細
     *       corner_1〜4   → 各コーナー通過時点の順位
     *       last_surge    → そのレースの直線での伸び（corner_4 - finishing_position）
     *       time_sec      → タイムを秒換算した数値（比較・ソート用）
     *
     * 【クエリパラメータ】
     *   date   = 対象日付 例: 2026-07-18 （省略時は今日）
     *   kaisuu = 開催回数  例: 2
     *   basho  = 場コード  例: 03
     *   day    = 開催日次  例: 7
     *   race   = レース番号 例: 10
     *
     * 【参照テーブル】
     *   t_horse_odds_finder_races          → 今日のレース情報（course, dist を取得）
     *   t_horse_odds_finder_horses         → 今日の出走馬リスト
     *   t_horse_odds_finder_shutsuba_history → 各馬の過去出走履歴
     *
     * 【dist の形式について】
     *   shutsuba_history.dist は "1700ダ", "1200芝", "3200芝ダ" のような文字列。
     *   数字部分 = 距離(m)、文字部分 = コース種別。
     *   REGEXP_REPLACE で数字を抜き出して races.dist と突き合わせる。
     * ─────────────────────────────────────────────────────────────
     */
    public function getHorseOddsFinderCourseDistHistory(Request $request)
    {
        $date   = $request->query('date', date('Y-m-d'));
        $kaisuu = $request->query('kaisuu');
        $basho  = $request->query('basho');
        $day    = $request->query('day');
        $race   = $request->query('race');

        // ── ① 今日のレース情報を取得（course と dist を知るため） ────────────
        // t_horse_odds_finder_races に course="芝"/"ダート", dist=1700 のように入っている
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

        // 今日のレースのコース種別・距離（過去履歴の絞り込みキーになる）
        $targetCourse = $raceRow->course; // "芝" or "ダート"
        $targetDist   = (int) $raceRow->dist;

        // ── ② 今日の出走馬一覧を取得 ─────────────────────────────────────────
        $horses = DB::table('t_horse_odds_finder_horses')
            ->where('date',   $date)
            ->where('kaisuu', $kaisuu)
            ->where('basho',  $basho)
            ->where('day',    $day)
            ->where('race',   intval($race))
            ->orderBy('waku')
            ->orderBy('num')
            ->get();

        if ($horses->isEmpty()) {
            return response()->json(['error' => '出走馬が見つかりません'], 404);
        }

        $horseNames = $horses->pluck('name')->toArray();

        // ── ③ 過去履歴を「同コース×同距離」に絞って一括取得 ──────────────────
        //
        // shutsuba_history.dist は "1700ダ", "1200芝" のような文字列なので
        //   ・REGEXP_REPLACE で数字だけ抜き出して距離を比較
        //   ・LIKE でコース種別（芝 or ダ）を絞る
        //
        // ダート = "ダ" を含む（"1700ダ" など）
        // 芝    = "芝" を含む（"1200芝", 障害の "3200芝ダ" も含まれるが許容）
        $courseLike = ($targetCourse === 'ダート') ? '%ダ%' : '%芝%';

        $histories = DB::table('t_horse_odds_finder_shutsuba_history')
            ->whereIn('name', $horseNames)
            ->whereRaw("REGEXP_REPLACE(dist, '[^0-9]', '') = ?", [(string) $targetDist])
            ->where('dist', 'LIKE', $courseLike)
            ->orderBy('name')
            ->orderBy('date', 'desc') // 新しい順（records は直近が先頭）
            ->get();

        // 馬名をキーにした連想配列に変換（後のループで O(1) アクセスするため）
        $historyByName = [];
        foreach ($histories as $h) {
            $historyByName[$h->name][] = $h;
        }

        // ── ④ ヘルパークロージャ ─────────────────────────────────────────────

        // タイム文字列 "1:44.3" を秒（float）に変換する
        // 変換できない（null・空・形式違い）場合は null を返す
        $parseTimeSec = function (?string $time): ?float {
            if (!$time || !preg_match('/^(\d+):(\d+\.\d+)$/', trim($time), $m)) {
                return null;
            }
            return (int)$m[1] * 60 + (float)$m[2];
        };

        // 最終コーナー通過順位の「頭数比率」から脚質を分類する
        //   ratio = corner_4 / num_horses（0に近いほど前、1に近いほど後ろ）
        //   0.00〜0.10 → 逃（ほぼ先頭）
        //   0.11〜0.35 → 先（先行集団）
        //   0.36〜0.60 → 中（中団）
        //   0.61〜0.80 → 差（後方から差す）
        //   0.81〜1.00 → 追（最後方からの追い込み）
        $classifyStyle = function (?float $ratio): ?string {
            if ($ratio === null) return null;
            if ($ratio <= 0.10) return '逃';
            if ($ratio <= 0.35) return '先';
            if ($ratio <= 0.60) return '中';
            if ($ratio <= 0.80) return '差';
            return '追';
        };

        // ── ⑤ 直近5走を全馬まとめて一括取得（コース・距離問わず） ────────────
        // 今の調子（上昇/下降傾向）を見るために全距離・全コースの直近5走を取得する。
        // N+1 を避けるため、全出走馬の名前でまとめて取り、後でグループ化する。
        $recentAllHistories = DB::table('t_horse_odds_finder_shutsuba_history')
            ->whereIn('name', $horseNames)
            ->whereNotNull('finishing_position')
            ->orderBy('name')
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        // 馬名ごとに直近5走だけ残す
        $recentByName = [];
        foreach ($recentAllHistories as $r) {
            if (!isset($recentByName[$r->name])) {
                $recentByName[$r->name] = [];
            }
            if (count($recentByName[$r->name]) < 5) {
                $recentByName[$r->name][] = $r;
            }
        }

        // ── ⑥ 馬ごとに集計 ───────────────────────────────────────────────────
        $data = [];
        foreach ($horses as $horse) {
            $name    = $horse->name;
            $records = $historyByName[$name] ?? []; // 同コース×同距離の過去レース一覧

            $total       = count($records);
            $win         = 0;   // 1着回数
            $top3        = 0;   // 3着以内回数（複勝圏内）
            $finishSum   = 0;   // 着順の合計（平均着順の計算用）
            $bestTimeSec = null; // このコース×距離での最速タイム（秒）

            // 脚質算出用：corner_4 / num_horses の平均を取る
            $styleRatioSum = 0.0;
            $styleCount    = 0;

            // 直線伸び算出用：(corner_4 - finishing_position) の平均を取る
            //   プラス = 直線で前の馬を抜いた（追い込み）
            //   マイナス = 直線で後ろの馬に抜かれた（バテ）
            $surgeSum   = 0.0;
            $surgeCount = 0;

            // 馬場状態別成績集計用
            // 例: ['良' => ['total'=>3,'win'=>1,'top3'=>2], '稍重' => [...], ...]
            $conditionStats = [];

            foreach ($records as $r) {
                // 着順集計
                if (!is_null($r->finishing_position)) {
                    if ($r->finishing_position == 1) $win++;
                    if ($r->finishing_position <= 3) $top3++;
                    $finishSum += $r->finishing_position;
                }

                // 最速タイム更新（秒換算して比較）
                $sec = $parseTimeSec($r->time);
                if ($sec !== null && ($bestTimeSec === null || $sec < $bestTimeSec)) {
                    $bestTimeSec = $sec;
                }

                // 脚質：最終コーナー順位 ÷ 出走頭数 を積み上げる
                if (!is_null($r->corner_4) && !is_null($r->num_horses) && $r->num_horses > 0) {
                    $styleRatioSum += $r->corner_4 / $r->num_horses;
                    $styleCount++;
                }

                // 直線での伸び：最終コーナー順位 - 最終着順
                //   例) corner_4=5, finishing_position=3 → +2（2頭抜いた）
                //   例) corner_4=3, finishing_position=7 → -4（4頭に抜かれた）
                if (!is_null($r->corner_4) && !is_null($r->finishing_position)) {
                    $surgeSum += ($r->corner_4 - $r->finishing_position);
                    $surgeCount++;
                }

                // 馬場状態別成績を集計
                // condition は "良", "稍重", "重", "不良" など
                // 障害レースは "稍重/重" のように複合表記になる場合があるが、そのまま格納する
                if (!empty($r->condition) && !is_null($r->finishing_position)) {
                    $cond = $r->condition;
                    if (!isset($conditionStats[$cond])) {
                        $conditionStats[$cond] = ['total' => 0, 'win' => 0, 'top3' => 0];
                    }
                    $conditionStats[$cond]['total']++;
                    if ($r->finishing_position == 1) $conditionStats[$cond]['win']++;
                    if ($r->finishing_position <= 3) $conditionStats[$cond]['top3']++;
                }
            }

            // 平均比率から脚質文字列に変換
            $avgStyleRatio = $styleCount > 0 ? $styleRatioSum / $styleCount : null;
            $runningStyle  = $classifyStyle($avgStyleRatio);

            // 直線での平均伸び順位数（小数第1位まで）
            $avgLastSurge = $surgeCount > 0 ? round($surgeSum / $surgeCount, 1) : null;

            // 馬場状態別に複勝率を付与し、最も複勝率が高い条件を best_condition として返す
            $bestCondition     = null;
            $bestConditionRate = -1;
            $conditionStatsOut = [];
            foreach ($conditionStats as $cond => $cs) {
                $rate = $cs['total'] > 0 ? round($cs['top3'] / $cs['total'] * 100, 1) : 0;
                $conditionStatsOut[$cond] = [
                    'total'     => $cs['total'],
                    'win'       => $cs['win'],
                    'top3'      => $cs['top3'],
                    'top3_rate' => $rate,
                ];
                if ($rate > $bestConditionRate) {
                    $bestConditionRate = $rate;
                    $bestCondition     = $cond;
                }
            }

            // 直近5走の着順リストと調子トレンドを算出
            // recent_form: 新しい順に並んだ着順の配列 例: [1, 3, 5, 2, 8]
            $recentRecords = $recentByName[$name] ?? [];
            $recentForm    = array_map(fn($r) => $r->finishing_position, $recentRecords);

            // recent_trend: 直近5走の前半2走と後半2走の平均着順を比較してトレンドを判定
            //   "上昇" = 最近の方が着順が良い（数字が小さい）
            //   "下降" = 最近の方が着順が悪い（数字が大きい）
            //   "安定" = ほぼ変化なし（差が1着順以内）
            //   null   = データが3走未満で判定不能
            $recentTrend = null;
            if (count($recentForm) >= 3) {
                $newer = array_slice($recentForm, 0, 2); // 直近2走
                $older = array_slice($recentForm, -2);   // 最古2走
                $newerAvg = array_sum($newer) / count($newer);
                $olderAvg = array_sum($older) / count($older);
                $diff = $olderAvg - $newerAvg; // プラス=最近の方が着順良い
                if ($diff > 1)       $recentTrend = '上昇';
                elseif ($diff < -1)  $recentTrend = '下降';
                else                 $recentTrend = '安定';
            }

            // 騎手変更フラグ
            // 同コース×距離で最も直近のレースの騎手と今日の騎手を比較する。
            // 騎手名には "▲", "△", "☆" などの見習いマーク が付く場合があるため除去して比較。
            $stripMark    = fn(?string $j): string => preg_replace('/^[▲△☆★◇◆]+/', '', (string)$j);
            $lastJockey   = !empty($records) ? $stripMark($records[0]->jockey) : null;
            $todayJockey  = $stripMark($horse->jockey);
            $jockeyChanged = ($lastJockey !== null && $lastJockey !== $todayJockey);

            $data[] = [
                // ── 馬の基本情報 ──
                'waku'   => $horse->waku,
                'num'    => $horse->num,
                'name'   => $name,
                'jockey' => $horse->jockey,

                // ── 適性判定フィールド ──
                // has_experience: このコース×距離の出走経験があるか
                //   false の馬は適性が完全に未知。予想では注意が必要。
                'has_experience' => $total > 0,

                // best_time_sec: 同コース×距離での最速タイム（秒）
                //   タイムが速い馬ほどこの条件でのスピード実績がある。
                //   ただし馬場状態・メンバーレベルが違う点は考慮が必要。
                'best_time_sec' => $bestTimeSec,

                // time_rank: 経験馬の中での最速タイム順位（1=最速）
                //   null = 経験なしのため圏外
                'time_rank' => null, // 後のステップ⑦で付与する

                // running_style: 脚質（逃/先/中/差/追）
                //   同コース×距離でのcorner_4平均位置から算出。
                //   予想での使い方：
                //     今日のレースで「逃」が多い → ハイペース → 差し・追い込み有利
                //     今日のレースで「逃」が少ない → スロー → 先行馬有利
                //   null = 経験なしのため不明
                'running_style' => $runningStyle,

                // avg_last_surge: 直線での平均伸び順位数
                //   プラス: 追い込み型（末脚がある）→ 差しが決まる展開で狙い目
                //   マイナス: バテ型（直線で失速）→ 消し候補
                //   0付近: 位置取りをそのまま維持するタイプ
                //   null = 経験なしのため不明
                'avg_last_surge' => $avgLastSurge,

                // jockey_changed: 同コース×距離の前走から騎手が変わったか
                //   true = 変わった → 脚質・running_style が参考にならない可能性あり
                //   false = 同じ騎手 → 過去データの信頼性が高い
                //   null = 比較できる過去データなし（経験なし馬）
                'jockey_changed' => $total > 0 ? $jockeyChanged : null,

                // best_condition: このコース×距離で最も複勝率が高い馬場状態
                //   例: "稍重" → 稍重で特に好走している
                //   今日の馬場状態と照合して予想の参考にする
                //   null = 経験なしのため不明
                'best_condition' => $bestCondition,

                // condition_stats: 馬場状態別の成績内訳
                //   キー = 馬場状態（良/稍重/重/不良）
                //   値   = {total, win, top3, top3_rate}
                //   null = 経験なしのため空
                'condition_stats' => !empty($conditionStatsOut) ? $conditionStatsOut : null,

                // recent_form: コース・距離問わず直近5走の着順（新しい順）
                //   例: [1, 3, 5, 2, 8] → 直近1走が1着、2走前が3着...
                //   今の馬の調子を把握するために使う
                'recent_form' => $recentForm,

                // recent_trend: 直近5走の調子トレンド
                //   "上昇" = 最近の方が着順が良い（上り調子）→ 狙い目
                //   "下降" = 最近の方が着順が悪い（下り調子）→ 注意
                //   "安定" = ほぼ変化なし
                //   null   = データ不足（3走未満）
                'recent_trend' => $recentTrend,

                // ── 同コース×距離の成績サマリー ──
                'course_dist_stats' => [
                    'course'        => $targetCourse,
                    'dist'          => $targetDist,
                    'total'         => $total,                // 同条件での出走回数
                    'win'           => $win,                  // 1着回数
                    'top3'          => $top3,                 // 3着以内回数
                    'win_rate'      => $total > 0 ? round($win  / $total * 100, 1) : null, // 勝率(%)
                    'top3_rate'     => $total > 0 ? round($top3 / $total * 100, 1) : null, // 複勝率(%)
                    'avg_finishing' => $total > 0 ? round($finishSum / $total, 1)  : null, // 平均着順
                ],

                // ── 同コース×距離の過去レース明細（新しい順） ──
                'records' => array_map(fn($r) => [
                    'date'               => $r->date,
                    'basho'              => $r->basho,
                    'race'               => $r->race,
                    'race_name'          => $r->race_name,
                    'dist_raw'           => $r->dist,            // 元の文字列 例: "1700ダ"
                    'dist'               => (int) preg_replace('/[^0-9]/', '', (string)$r->dist), // 距離(m) 例: 1700
                    'course'             => preg_replace('/[0-9]/', '', (string)$r->dist) ?: null, // コース種別 例: "ダ", "芝", "芝ダ"
                    'finishing_position' => $r->finishing_position,
                    'num_horses'         => $r->num_horses,
                    'popularity'         => $r->popularity,       // 人気順位
                    'jockey'             => $r->jockey,
                    'condition'          => $r->condition,        // 馬場状態（良/稍重/重/不良）
                    'time'               => $r->time,             // タイム文字列 例: "1:44.3"
                    'time_sec'           => $parseTimeSec($r->time), // タイム（秒）例: 104.3
                    'last_3f'            => $r->last_3f,          // 上がり3ハロンタイム
                    'grade'              => $r->grade,
                    // ── コーナー通過順位 ──
                    // 位置取りの変化を追える。1コーナーから4コーナーにかけて
                    // 順位が上がれば前に行った、下がれば後退したことを示す。
                    'corner_1'           => $r->corner_1,
                    'corner_2'           => $r->corner_2,
                    'corner_3'           => $r->corner_3,
                    'corner_4'           => $r->corner_4,
                    // last_surge: 直線での伸び（corner_4 - finishing_position）
                    //   プラス = 直線で前の馬を抜いた
                    //   マイナス = 直線で後ろの馬に抜かれた（バテ）
                    'last_surge' => (!is_null($r->corner_4) && !is_null($r->finishing_position))
                                    ? ($r->corner_4 - $r->finishing_position)
                                    : null,
                ], $records),
            ];
        }

        // ── ⑦ time_rank を付与 ──────────────────────────────────────────────
        // best_time_sec が null でない馬（経験あり）だけを取り出してタイム昇順でソートし、
        // 順位を各馬に付与する。経験なし馬は time_rank=null のまま。
        $experienced = array_filter($data, fn($h) => $h['best_time_sec'] !== null);
        usort($experienced, fn($a, $b) => $a['best_time_sec'] <=> $b['best_time_sec']);
        $rank = 1;
        $rankedNums = [];
        foreach ($experienced as $h) {
            $rankedNums[$h['num']] = $rank++;
        }
        foreach ($data as &$h) {
            $h['time_rank'] = $rankedNums[$h['num']] ?? null;
        }
        unset($h);

        return response()->json([
            'race' => [
                'date'       => $raceRow->date,
                'kaisuu'     => $raceRow->kaisuu,
                'basho'      => $raceRow->basho,
                'basho_name' => $raceRow->basho_name,
                'day'        => $raceRow->day,
                'race'       => $raceRow->race,
                'race_name'  => $raceRow->race_name,
                'course'     => $targetCourse,
                'dist'       => $targetDist,
            ],
            'data' => $data,
        ]);
    }
    
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
/// コンフィグ値取得


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
 *
 * 【odds_drop_rate の算出条件】
 *   - 24時間前と3分前の両オッズが数値で記録されていること
 *   - 3分前オッズ ÷ 24時間前オッズ < 0.7（30%以上の下落）
 *   - 最終着順が記録済みであること
 * ─────────────────────────────────────────────────────────────
 *
 * @return \Illuminate\Http\JsonResponse  { data: { odds_get_timing: "...", odds_drop_rate: {...} } }
 */
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


    /**
     * ログインユーザー一覧を取得する（管理画面用）
     *
     * id・user_id・is_admin・is_delete のみを返す（パスワードなどの機密情報は除外）。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderLoginUsers()
    {
        $result = DB::table('t_horse_odds_finder_login_users')->select('id', 'user_id', 'is_admin', 'is_delete')->get();
        return response()->json(['data' => $result]);
    }
    
    /**
     * ユーザーの管理者権限を変更する（管理画面用）
     *
     * @param  Request $request  id, is_admin（0=一般 / 1=管理者）
     * @return void
     */
    public function changeAdmin(Request $request)
    {
        $id      = $request->input('id');
        $isAdmin = $request->input('is_admin');

        DB::table('t_horse_odds_finder_login_users')->where('id', $id)->update(['is_admin' => $isAdmin]);
    }
    
    /**
     * ユーザーの削除フラグを変更する（管理画面用）
     *
     * is_delete=1 にしてもレコードは残る（論理削除）。
     * サインイン時は is_delete=0 の場合のみ認証を通す。
     *
     * @param  Request $request  id, is_delete（0=有効 / 1=削除済み）
     * @return void
     */
    public function changeDelete(Request $request)
    {
        $id       = $request->input('id');
        $isDelete = $request->input('is_delete');
        
        DB::table('t_horse_odds_finder_login_users')->where('id', $id)->update(['is_delete' => $isDelete]);
    }
    


////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
/// プッシュ通信ユーザーリスト


    /**
     * プッシュ通知サブスクリプション一覧を取得する（管理画面用）
     *
     * id・user_id・is_delete のみを返す。
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
    public function getHorseOddsFinderPushSubscriptions()
    {
        $result = DB::table('t_horse_odds_finder_push_subscriptions')->select('id', 'user_id', 'is_delete')->get();
        return response()->json(['data' => $result]);
    }
    
    /**
     * プッシュ通知サブスクリプションの削除フラグを変更する（管理画面用）
     *
     * is_delete=1 にすることでそのユーザーへのプッシュ通知を停止する（論理削除）。
     *
     * @param  Request $request  id, is_delete（0=有効 / 1=停止）
     * @return void
     */
    public function changePushNotifierUserDelete(Request $request)
    {
        $id       = $request->input('id');
        $isDelete = $request->input('is_delete');
        
        DB::table('t_horse_odds_finder_push_subscriptions')->where('id', $id)->update(['is_delete' => $isDelete]);
    }
    
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
/// サマリーテーブルのカウント取得


    /**
     * 各テーブルの日付別レコード件数を取得する（データ投入状況確認用）
     *
     * ─────────────────────────────────────────────────────────────
     * 【このAPIの目的】
     *   バッチ処理でデータが正しく投入されているかを日付単位で確認する。
     *   各テーブルの件数が揃っているかを一覧で確認できる。
     *
     * 【レスポンスの各フィールド】
     *   summary_count                    → t_horse_odds_finder_summary のレコード数
     *   history_count                    → t_horse_odds_finder_race_result_history の総レコード数
     *   history_popularity_rank_count    → popularity_rank が入っているレコード数
     *   history_finishing_position_count → finishing_position が入っているレコード数
     *   payout_count                     → t_horse_odds_finder_race_result_payout のレコード数
     *   ratio_count                      → t_horse_odds_finder_races_popularity_ratio のレコード数
     *
     * history と history_popularity_rank_count・history_finishing_position_count の
     * 差分が大きい場合はデータ投入が途中で止まっている可能性がある。
     * ─────────────────────────────────────────────────────────────
     *
     * @return \Illuminate\Http\JsonResponse  { data: [...] }
     */
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
                'analysis_text' => trim(preg_replace('/^PICKUP:.+$/mu', '', $cached->analysis_text)),
                'pickup_horse'  => $parsePickupHorses($cached->analysis_text),
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
        $prompt = $this->_getAiAnalysisPrompt($date, $kaisuu, $basho, $day, $race);

        if ($prompt === null) {
            return response()->json(['error' => 'プロンプト生成に失敗しました（レースまたはオッズデータが不足しています）'], 404);
        }

        // ─── プロンプトをファイルに出力（デバッグ・履歴用） ──────────────
        file_put_contents(
            public_path('prompt/prompt_' . date('Y-m-d_H-i-s') . '.data'),
            $prompt
        );

        // ─── Claude API 呼び出し（529 Overloaded 時は指数バックオフでリトライ） ──
        $maxAttempts = 3;
        $aiResponse  = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
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

            if ($aiResponse->status() !== 529) {
                break;
            }

            \Log::warning('Anthropic API overloaded, retrying', [
                'attempt' => $attempt,
                'body'    => $aiResponse->body(),
            ]);

            if ($attempt < $maxAttempts) {
                sleep($attempt * 2);
            }
        }

        if ($aiResponse->failed()) {
            \Log::error('Anthropic API error', [
                'status' => $aiResponse->status(),
                'body'   => $aiResponse->body(),
            ]);
            return response()->json(['error' => 'AI分析に失敗しました'], 500);
        }

        $rawText = $aiResponse->json('content.0.text') ?? '';

        $pickupHorse  = $parsePickupHorses($rawText);
        $analysisText = trim(preg_replace('/^PICKUP:.+$/mu', '', $rawText));

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

        return response()->json(['data' => [
            'date'          => $date,
            'kaisuu'        => $kaisuu,
            'basho_code'    => $basho,
            'day'           => $day,
            'race'          => $race,
            'analysis_text' => $analysisText,
            'pickup_horse'  => $pickupHorse,
        ]]);

    } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
        return response()->json(['error' => 'しばらくしてから再試行してください'], 503);
    } finally {
        $lock->release();
    }
}



/**
 * AI分析用のプロンプト文字列を組み立てる
 *
 * 以下の3テーブルを参照してプロンプトを生成する。
 *   - t_horse_odds_finder_races  : レース基本情報（開催・レース名など）
 *   - t_horse_odds_finder_horses : 出走馬情報（馬番・馬名）
 *   - t_horse_odds_finder_odds   : 単勝・複勝オッズ（計測開始前: 999分前, 発走3分前: 3分前）
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

    // ─── 馬番ごとに2時点のオッズをまとめる ──────────────────────────
    $oddsByNum = [];
    foreach ($oddsRows as $row) {
        $num = $row->num;
        if (!isset($oddsByNum[$num])) {
            $oddsByNum[$num] = [
                'odds_base'     => null,
                'odds_3'        => null,
                'fuku_min_base' => null,
                'fuku_max_base' => null,
                'fuku_min_3'    => null,
                'fuku_max_3'    => null,
            ];
        }
        if ($row->minutes_before_start == 999) {
            $oddsByNum[$num]['odds_base']     = floatval($row->odds);
            $oddsByNum[$num]['fuku_min_base'] = floatval($row->fuku_min);
            $oddsByNum[$num]['fuku_max_base'] = floatval($row->fuku_max);
        }
        if ($row->minutes_before_start == 3) {
            $oddsByNum[$num]['odds_3']        = floatval($row->odds);
            $oddsByNum[$num]['fuku_min_3']    = floatval($row->fuku_min);
            $oddsByNum[$num]['fuku_max_3']    = floatval($row->fuku_max);
        }
    }

    // ─── プロンプト用データの組み立て ────────────────────────────────
    $promptHorses = [];
    foreach ($oddsByNum as $num => $o) {
        // 片方でも欠けている、またはベースオッズが0の馬はスキップ（0除算防止）
        if ($o['odds_base'] === null || $o['odds_3'] === null || $o['odds_base'] == 0) continue;

        // 単勝変動率（%）= (3分前オッズ - ベースオッズ) / ベースオッズ × 100
        $changeRate = round(($o['odds_3'] - $o['odds_base']) / $o['odds_base'] * 100, 1);
        if ($changeRate < 0) {
            $changeLabel = '下落 ' . abs($changeRate) . '%';
        } elseif ($changeRate > 0) {
            $changeLabel = '上昇 +' . $changeRate . '%';
        } else {
            $changeLabel = '変化なし';
        }

        // 複勝変動率（最小値ベース）
        $fukuChangeLabel = '－';
        if ($o['fuku_min_base'] && $o['fuku_min_3'] && $o['fuku_min_base'] > 0) {
            $fukuChange = round(($o['fuku_min_3'] - $o['fuku_min_base']) / $o['fuku_min_base'] * 100, 1);
            if ($fukuChange < 0) {
                $fukuChangeLabel = '下落 ' . abs($fukuChange) . '%';
            } elseif ($fukuChange > 0) {
                $fukuChangeLabel = '上昇 +' . $fukuChange . '%';
            } else {
                $fukuChangeLabel = '変化なし';
            }
        }

        // 単複比（単勝3分前 ÷ 複勝最小3分前）
        $tanpukuRatio = '－';
        if ($o['fuku_min_3'] && $o['fuku_min_3'] > 0) {
            $tanpukuRatio = round($o['odds_3'] / $o['fuku_min_3'], 1) . '倍';
        }

        // 馬名が取れない場合は「馬{番号}」で代替
        $name = isset($horses[$num]) ? $horses[$num]->name : '馬' . $num;

        $promptHorses[] = [
            'num'            => $num,
            'name'           => $name,
            'odds_base'      => $o['odds_base'],
            'odds_3'         => $o['odds_3'],
            'change_label'   => $changeLabel,
            'fuku_min_3'     => $o['fuku_min_3'],
            'fuku_max_3'     => $o['fuku_max_3'],
            'fuku_change'    => $fukuChangeLabel,
            'tanpuku_ratio'  => $tanpukuRatio,
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
            '%2d番 %-12s  単勝: %5.1f倍→%5.1f倍(%s)  複勝: %4.1f-%4.1f倍(%s)  単複比: %s',
            $h['num'],
            $h['name'],
            $h['odds_base'],
            $h['odds_3'],
            $h['change_label'],
            $h['fuku_min_3'] ?? 0,
            $h['fuku_max_3'] ?? 0,
            $h['fuku_change'],
            $h['tanpuku_ratio']
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
        . '単勝・複勝オッズデータ（計測開始前から発走3分前）' . "\n"
        . $table . "\n\n"
        . '分析依頼' . "\n"
        . 'オッズ推移から以下を教えてください。' . "\n"
        . '1. 勝つ確率が高そうな馬（最大3頭）と理由' . "\n"
        . '2. 積極的に消してよい馬と理由' . "\n"
        . '3. 複勝・ワイドで狙える馬（単複比・複勝変動に注目）' . "\n"
        . '4. このレースの総評（混戦か本命か、買い方の方向性）' . "\n\n"
        . '分析の観点：' . "\n"
        . '・単勝オッズ下落10%以上は人気急上昇として注目' . "\n"
        . '・単複比が高い馬＝勝ちにくいが3着以内には絡みやすい' . "\n"
        . '・複勝の最小・最大の幅が広い馬＝市場の評価が割れている不安定な馬' . "\n"
        . '・複勝の最小・最大の幅が狭い馬＝安定して3着以内が期待されている馬' . "\n"
        . '・複勝オッズが下落している馬は3着以内の信頼度が高い' . "\n"
        . '日本語・箇条書きで簡潔にまとめてください。' . "\n\n"
        . '【必須】回答の最後の行に、必ず以下の形式だけで注目馬を出力してください。' . "\n"
        . '他の文章や説明は一切付けず、この1行だけを最終行にしてください。' . "\n"
        . 'PICKUP:馬番|馬名|おすすめ度/馬番|馬名|おすすめ度/馬番|馬名|おすすめ度' . "\n"
        . '例）PICKUP:3|トーアマリシテン|99/6|モスクロッサー|99/5|フェイトライン|99'. "\n"
        . '※画面表示に影響するので、この形を守ってください。';
}

}
