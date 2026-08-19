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

class ApiController extends Controller
{
    public function __construct(private AnthropicService $anthropic)
    {
    }
    
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
                'trifecta' => $result->trifecta,
                
                'grade' => $result->grade,
                'course' => $result->course,
                'dist' => $result->dist,
                'inner_outer' => $result->inner_outer
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
     *   1. place_rate 50%以上   → 2回に1回は5着以内に来ている
     *   2. 類似レース2件以上    → 1件だけでは信頼性が低いので除外
     *
     * 【処理の流れ】
     *   1. 対象日のレース一覧を取得
     *   2. 各レースの類似レース（popularity_ratio_table_ids）を参照
     *   3. 各馬の現在オッズ（999分前と6分前）を取得し人気順位を算出
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
        //   1. place_rate 50%以上   → 2回に1回は5着以内に来ている
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
            // 6    = 発走6分前（馬券購入可能な最終タイミング）
            // 0や-999は馬券購入後のため使用しない
            // =================================================
            $odds = DB::table('t_horse_odds_finder_odds')
                ->where('date', $targetDate)
                ->where('kaisuu', $race->kaisuu)
                ->where('basho', $race->basho)
                ->where('day', $race->day)
                ->where('race', $race->race)
                ->whereIn('minutes_before_start', [Constants::ODDS_DB_FIRST, 6])
                ->get()
                ->groupBy('num');

            $latestOdds = [];
            foreach ($odds as $num => $rows) {

                $latest = $rows
                    ->filter(fn($r) => is_numeric($r->odds) && floatval($r->odds) > 0)
                    ->sortBy('minutes_before_start')
                    ->first();

                $base = $rows->where('minutes_before_start', Constants::ODDS_DB_FIRST)->first();

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
                    if ($h->finishing_position <= 5) $stats[$pop]['place']++;
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
                    $resultComment = "過去{$similarCount}件の類似レースで{$pop}番人気の馬は5着以内{$placeCount}回、うち1着は{$winCount}回でした。";
                } else {
                    $resultComment = "過去{$similarCount}件の類似レースで{$pop}番人気の馬は5着以内{$placeCount}回、1着はありませんでした。";
                }

                // --- 5着以内率に応じたコメント ---
                if ($placeRate >= 100) {
                    $placeComment = "5着以内率100%と、類似レースでは必ず掲示板に入っています。";
                } elseif ($placeRate >= 75) {
                    $placeComment = "5着以内率{$placeRate}%と、類似レースでは高い確率で掲示板に入っています。";
                } else {
                    $placeComment = "5着以内率{$placeRate}%です。";
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
// ⚠️ TODO: Flutter未使用（これ使うの？）
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

        $sql = " select date, count(date) as count from t_horse_odds_finder_popularity_rank_median group by date; ";
        $median = [];
        $result = DB::select($sql);
        foreach($result as $v){
            $median[$v->date] = $v->count;
        }
        
        $sql = " select date, count(date) as count from t_horse_odds_finder_race_results group by date; ";
        $race_results = [];
        $result = DB::select($sql);
        foreach($result as $v){
            $race_results[$v->date] = $v->count;
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
                
                "median_count" => (isset($median[$date])) ? $median[$date] : 0,
                "race_results_count" => (isset($race_results[$date])) ? $race_results[$date] : 0
            ];
        }
        
        return response()->json(['data' => $response]);
    }
    


    /**
     * コース・距離別の過去成績統計を計算する（内部共通処理）
     *
     * getHorseOddsFinderCourseDistStats と _getAiAnalysisPrompt の両方から使用する。
     *
     * @param  string $course  コース種別 例: 芝, ダート
     * @param  int    $dist    距離(m)   例: 1600
     * @return array|null  集計結果、データなしの場合は null
     */
    private function _calcCourseDistStats(string $course, int $dist): ?array
    {
        $payouts = DB::table('t_horse_odds_finder_race_result_payout')
            ->where('course', $course)
            ->where('dist',   $dist)
            ->get();

        if ($payouts->isEmpty()) return null;

        $raceCount       = 0;
        $popularityStats = [];

        foreach ($payouts as $payout) {
            $histories = DB::table('t_horse_odds_finder_race_result_history')
                ->where('date',       $payout->date)
                ->where('kaisuu',     $payout->kaisuu)
                ->where('basho_code', $payout->basho_code)
                ->where('day',        $payout->day)
                ->where('race',       $payout->race)
                ->get();

            if ($histories->isEmpty()) continue;

            $raceCount++;
            $tanPayout = floatval(explode('|', $payout->tan)[1] ?? 0);

            $fukuMap = [];
            foreach (explode('/', $payout->fuku ?? '') as $entry) {
                $parts = explode('|', $entry);

                if (count($parts) === 2) {$fukuMap[(int)$parts[0]] = floatval($parts[1]);}
            }

            foreach ($histories as $h) {
                if (is_null($h->finishing_position)) continue;
                if (is_null($h->popularity_rank))    continue;

                $pop = (int) $h->popularity_rank;
                if (!isset($popularityStats[$pop])) {
                    $popularityStats[$pop] = [
                        'total'       => 0,
                        'top3'        => 0,
                        'tan_payout'  => 0.0,
                        'fuku_payout' => 0.0,
                    ];
                }

                $popularityStats[$pop]['total']++;
                if ($h->finishing_position <= 3) {
                    $popularityStats[$pop]['top3']++;

                    if (isset($fukuMap[$h->num])) {$popularityStats[$pop]['fuku_payout'] += $fukuMap[$h->num];}
                }

                if ($h->finishing_position === 1) {$popularityStats[$pop]['tan_payout'] += $tanPayout;}

            }
        }

        if ($raceCount === 0) return null;

        ksort($popularityStats);

        $MIN_TOTAL    = 800;
        $byPopularity = [];
        foreach ($popularityStats as $pop => $s) {
            $byPopularity[] = [
                'popularity_rank'    => $pop,
                'total'              => $s['total'],
                'top3'               => $s['top3'],
                'top3_rate'          => round($s['top3']        / $s['total'] * 100, 1),
                'tan_recovery_rate'  => round($s['tan_payout']  / ($s['total'] * 100) * 100, 1),
                'fuku_recovery_rate' => round($s['fuku_payout'] / ($s['total'] * 100) * 100, 1),
            ];
        }

        // 単勝回収率ランキング
        $tanSorted = collect($byPopularity)
            ->filter(fn($item) => $item['total'] >= $MIN_TOTAL)
            ->sortByDesc('tan_recovery_rate')
            ->values()->take(3);

        $tanRanking = [];
        foreach ($tanSorted as $i => $item) {
            $tanRanking[] = [
                'rank'              => $i + 1,
                'popularity_rank'   => $item['popularity_rank'],
                'total'             => $item['total'],
                'tan_recovery_rate' => $item['tan_recovery_rate'],
            ];
        }

        // 複勝回収率ランキング
        $fukuSorted = collect($byPopularity)
            ->filter(fn($item) => $item['total'] >= $MIN_TOTAL)
            ->sortByDesc('fuku_recovery_rate')
            ->values()->take(3);

        $fukuRanking = [];
        foreach ($fukuSorted as $i => $item) {
            $fukuRanking[] = [
                'rank'               => $i + 1,
                'popularity_rank'    => $item['popularity_rank'],
                'total'              => $item['total'],
                'fuku_recovery_rate' => $item['fuku_recovery_rate'],
            ];
        }

        return [
            'course'        => $course,
            'dist'          => $dist,
            'race_count'    => $raceCount,
            'by_popularity' => $byPopularity,
            'tan_ranking'   => $tanRanking,
            'fuku_ranking'  => $fukuRanking,
        ];
    }
    






































// ⚠️ TODO: Flutter未使用（これ使うの？）
public function getHorseOddsFinderCourseDistStats(Request $request)
{
$course = $request->query('course');
$dist   = (int) $request->query('dist');

if (!$course || !$dist) {
return response()->json(['error' => 'course と dist は必須です'], 400);
}

$stats = $this->_calcCourseDistStats($course, $dist);

if ($stats === null) {
return response()->json(['data' => [
'course'        => $course,
'dist'          => $dist,
'race_count'    => 0,
'by_popularity' => [],
'tan_ranking'   => [],
'fuku_ranking'  => [],
]]);
}

return response()->json(['data' => $stats]);
}






























/**
 * 指定レースの期待値スコアを取得する
 *
 * date・kaisuu・basho・day・race で1レースを特定し、
 * 6分前オッズを基に人気順を算出、過去回収率テーブルと掛け合わせて
 * 馬番ごとの期待値スコアを返す。
 *
 * 期待値スコア = 人気順別回収率(%) × 6分前オッズ ÷ 100
 * スコアが1.0以上で理論上プラス期待値、高いほど妙味あり。
 *
 * クエリパラメータ:
 *   date   = 対象日付 例: 2026-07-25
 *   kaisuu = 開催回数 例: 1
 *   basho  = 場コード 例: 01
 *   day    = 開催日次 例: 1
 *   race   = レース番号 例: 1
 *
 * @param  Request $request
 * @return \Illuminate\Http\JsonResponse  { data: [...] }
 */
// ⚠️ TODO: Flutter未使用（これ使うの？） 2026.7.30
public function getHorseOddsFinderExpectedValueScore(Request $request)
{
$date   = $request->query('date');
$kaisuu = $request->query('kaisuu');
$basho  = $request->query('basho');
$day    = $request->query('day');
$race   = $request->query('race');

if (!$date || !$kaisuu || !$basho || !$day || !$race) {
return response()->json(['error' => 'date, kaisuu, basho, day, race は必須です'], 400);
}

$sql = "
SELECT
o.num,
CAST(o.odds AS DECIMAL(10,1))                                        AS current_odds,
pop_rank.popularity_rank                                             AS calc_popularity_rank,
rr.recovery_rate,
ROUND(rr.recovery_rate * CAST(o.odds AS DECIMAL(10,1)) / 100, 2)    AS expected_value_score
FROM t_horse_odds_finder_odds o

INNER JOIN (
SELECT
date, kaisuu, basho, day, race, num,
RANK() OVER (
PARTITION BY date, kaisuu, basho, day, race
ORDER BY CAST(odds AS DECIMAL(10,1)) ASC
) AS popularity_rank
FROM t_horse_odds_finder_odds
WHERE minutes_before_start = 6
AND odds IS NOT NULL
AND odds != ''
AND date   = ?
AND kaisuu = ?
AND basho  = ?
AND day    = ?
AND race   = ?
) pop_rank
ON  o.date    = pop_rank.date
AND o.kaisuu  = pop_rank.kaisuu
AND o.basho   = pop_rank.basho
AND o.day     = pop_rank.day
AND o.race    = pop_rank.race
AND o.num     = pop_rank.num

INNER JOIN (
SELECT
popularity_rank,
ROUND(
SUM(CASE WHEN finishing_position = 1 THEN CAST(tan AS DECIMAL(10,1)) ELSE 0 END)
/ COUNT(*) * 100
, 1) AS recovery_rate
FROM t_horse_odds_finder_race_result_history
WHERE tan IS NOT NULL
AND tan != ''
GROUP BY popularity_rank
) rr
ON rr.popularity_rank = pop_rank.popularity_rank

WHERE o.minutes_before_start = 6
AND o.odds IS NOT NULL
AND o.odds != ''
AND o.date   = ?
AND o.kaisuu = ?
AND o.basho  = ?
AND o.day    = ?
AND o.race   = ?

ORDER BY expected_value_score DESC
";

$bindings = [
$date, $kaisuu, $basho, $day, $race,
$date, $kaisuu, $basho, $day, $race,
];

$result = DB::select($sql, $bindings);

return response()->json(['data' => $result]);
}

































/**
 * 指定レースの期待値スコアを返す（SQL④ オッズ帯方式）
 *
 * ─────────────────────────────────────────────────────────────
 * 【このAPIの目的と設計思想】
 *   「過去に同じオッズ帯の馬は何割勝ったか」という統計を使い、
 *   6分前オッズと掛け合わせて期待値を算出する。
 *   スコアが1.0以上の馬は長期的にプラスが期待できる「買い目候補」。
 *
 * 【期待値スコアの計算式】
 *   単勝期待値スコア = 過去同オッズ帯の実勝率(%) ÷ 100 × 6分前単勝オッズ
 *   複勝期待値スコア = 過去同オッズ帯の実3着内率(%) ÷ 100 × 6分前複勝オッズ中間値
 *
 *   → 単勝・複勝ともに 1.0 以上が理論上のプラス圏
 *
 * 【「オッズ帯」の定義】
 *   FLOOR(確定単勝オッズ) でグルーピング。
 *   例: 23.6倍 → 23倍帯、1.7倍 → 1倍帯
 *   サンプル数が30件未満の帯は除外（信頼性確保）。
 *
 * 【タイプ A（リアルタイム）の理由】
 *   6分前オッズはレースごとに毎回変わるため、キャッシュ不可。
 *   Flutter から kaisuu / basho / day / race を渡してリアルタイムで取得する。
 *
 * 【クエリパラメータ（全て必須）】
 *   date   = 対象日付   例: 2026-07-25
 *   kaisuu = 開催回数   例: 2    ※ TEXT型のため文字列で渡すこと
 *   basho  = 場コード   例: 07   ※ TEXT型のため文字列で渡すこと（ゼロ埋め）
 *   day    = 開催日次   例: 1    ※ TEXT型のため文字列で渡すこと
 *   race   = レース番号 例: 2
 *
 * 【レスポンス構造】
 *   data[]: 単勝期待値スコア降順で全馬を返す
 *     num              → 馬番
 *     popularity_rank  → 人気順（6分前オッズ昇順の RANK）
 *     current_odds     → 6分前単勝オッズ
 *     fuku_min         → 6分前複勝オッズ（最小）
 *     fuku_max         → 6分前複勝オッズ（最大）
 *     win_rate_pct     → 過去同オッズ帯の実勝率(%)
 *     place_rate_pct   → 過去同オッズ帯の実3着内率(%)
 *     sample_count     → 参考サンプル数
 *     tan_ev_score     → 単勝期待値スコア（1.0超え = 買い目候補）
 *     fuku_ev_score    → 複勝期待値スコア（1.0超え = 買い目候補）
 * ─────────────────────────────────────────────────────────────
 *
 * @param  Request $request
 * @return \Illuminate\Http\JsonResponse  { data: [...] }
 */
public function getHorseOddsFinderKitaichi(Request $request)
{
$date   = $request->query('date');
$kaisuu = $request->query('kaisuu');
$basho  = $request->query('basho');
$day    = $request->query('day');
$race   = $request->query('race');

// ── バリデーション ──────────────────────────────────────────────────
if (!$date || !$kaisuu || !$basho || !$day || !$race) {
return response()->json([
'error' => 'date, kaisuu, basho, day, race は全て必須です'
], 400);
}

// ── SQL④（オッズ帯方式・人気順付き） ─────────────────────────────
//
// WITH odds_baseline:
//   t_horse_odds_finder_race_result_history の全履歴から
//   「確定単勝オッズ帯ごとの実勝率・実3着内率」を集計。
//   HAVING COUNT(*) >= 30 でサンプル不足の帯を除外。
//
// メインクエリ:
//   指定レースの6分前オッズを取得し、odds_baseline と結合。
//   RANK() OVER で人気順を動的に計算（history不要）。
//
// ※ t_horse_odds_finder_odds の kaisuu / basho / day は TEXT型。
//   文字列として比較すること（'2', '07', '1' のように）。
//
$sql = "
WITH odds_baseline AS (
SELECT
FLOOR(CAST(tan AS DECIMAL(10,1)))              AS odds_floor,
COUNT(*)                                        AS sample_count,
ROUND(AVG(finishing_position = 1) * 100, 2)    AS win_rate_pct,
ROUND(AVG(finishing_position <= 3) * 100, 2)   AS place_rate_pct,
AVG(finishing_position = 1)                     AS win_rate,
AVG(finishing_position <= 3)                    AS place_rate
FROM t_horse_odds_finder_race_result_history
WHERE finishing_position IS NOT NULL
AND finishing_position > 0
AND tan REGEXP '^[0-9]'
AND CAST(tan AS DECIMAL(10,1)) BETWEEN 1.0 AND 199.9
GROUP BY odds_floor
HAVING COUNT(*) >= 30
)
SELECT
o.num                                                                AS num,
RANK() OVER (
ORDER BY CAST(o.odds AS DECIMAL(10,1)) ASC
)                                                                    AS popularity_rank,
CAST(o.odds     AS DECIMAL(10,1))                                   AS current_odds,
CAST(o.fuku_min AS DECIMAL(10,1))                                   AS fuku_min,
CAST(o.fuku_max AS DECIMAL(10,1))                                   AS fuku_max,
b.win_rate_pct,
b.place_rate_pct,
b.sample_count,
ROUND(
b.win_rate * CAST(o.odds AS DECIMAL(10,1))
, 3)                                                                 AS tan_ev_score,
ROUND(
b.place_rate
* (CAST(o.fuku_min AS DECIMAL(10,1))
+ CAST(o.fuku_max AS DECIMAL(10,1))) / 2
, 3)                                                                 AS fuku_ev_score
FROM t_horse_odds_finder_odds o
JOIN odds_baseline b
ON FLOOR(CAST(o.odds AS DECIMAL(10,1))) = b.odds_floor
WHERE o.date                 = ?
AND o.kaisuu               = ?
AND o.basho                = ?
AND o.day                  = ?
AND o.race                 = ?
AND o.minutes_before_start = 6
AND o.odds    REGEXP '^[0-9]'
AND o.fuku_min REGEXP '^[0-9]'
AND o.fuku_max REGEXP '^[0-9]'
ORDER BY tan_ev_score DESC
";

$result = DB::select($sql, [
$date,
$kaisuu,
$basho,
$day,
intval($race),
]);

// ── レスポンス ──────────────────────────────────────────────────────
// データが空の場合は minutes_before_start = 6 のオッズが未収録の可能性。
// Flutter 側でエラーとして扱わず「データなし」として表示すること。
return response()->json(['data' => $result]);
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

        $promptHorses[] = [
            'num'           => $num,
            'name'          => $name,
            'tan_series'    => $o['tan'],      // 全時点の単勝オッズ
            'fuku_min_series' => $o['fuku_min'], // 全時点の複勝最小オッズ
            'fuku_max_series' => $o['fuku_max'], // 全時点の複勝最大オッズ
            'odds_base'     => $tanBase,       // 人気順ソート用
            'odds_6'        => $tan6,          // 人気順ソート用
            'change_label'  => $changeLabel,
            'fuku_min_6'    => $fukuMin6,
            'fuku_max_6'    => $fukuMax6,
            'fuku_change'   => $fukuChangeLabel,
            'tanpuku_ratio' => $tanpukuRatio,
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

    // ─── 補正係数の読み込み（t_horse_odds_finder_compute_odds_correction）──
    // 推定確定オッズ = 6分前オッズ × avg_correction_ratio
    // std_correction_ratio = 補正誤差（標準偏差）
    $correctionRows = DB::table('t_horse_odds_finder_compute_odds_correction')->get();
    $correctionMap  = [];
    foreach ($correctionRows as $row) {
        $correctionMap[(int)$row->popularity_rank] = $row;
    }

    foreach ($promptHorses as &$h) {
        $avgOdds = $popularityAvgMap[$h['popularity']] ?? null;
        if ($avgOdds && $h['odds_6'] > 0) {
            $h['opi'] = round($avgOdds / $h['odds_6'], 2);
        } else {
            $h['opi'] = null;
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
    }
    unset($h);

    // ─── 馬番順テーブルの組み立て ────────────────────────────────────
    $displayHorses = $promptHorses;
    usort($displayHorses, fn($a, $b) => $a['num'] <=> $b['num']);

    // 頭立て数からピックアップ頭数を決定
    $horseCount  = count($displayHorses);
    $pickupCount = $horseCount <= 8 ? 4 : ($horseCount <= 13 ? 5 : 6);

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

        $lines[] = sprintf('%2d番(%2d人気) %s', $h['num'], $h['popularity'], $h['name']);
        $lines[] = '  単勝: ' . $tanLine;
        $lines[] = '  複勝: 計測前' . $fukuBase . '→6分前' . $fuku6 . '（' . $h['fuku_change'] . '）  単複比: ' . $h['tanpuku_ratio'];
        $lines[] = $opiLine;
        $lines[] = $estLine;
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
            $gapFlag        = $gapRatio >= 2.0 ? '  ★断層' : '';
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
            $gapFlag  = $gapRatio >= 2.0 ? '  ★断層' : '';
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
        '※比率が2.00以上の箇所を「断層あり」と判断しています',
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
    $lines[] = '※ タイプ別の詳細ルール';
    $lines[] = 'A（二重断層・上位完結型）: 断層上側を中心に。断層下側は自動除外せず、複勝流入が強ければ補欠候補に。';
    $lines[] = 'B（上位断層型）: 断層上側グループが中心。断層拡大中は上側重視、縮小中は下側の浮上を警戒。';
    $lines[] = 'C（中間断層型）: 断層上側=中心グループ、断層下側=穴グループ。複勝流入の方向で判断を補正。';
    $lines[] = 'D（断層なし・混戦型）: 複勝支持・変化率・単複人気差を重視。6〜10番人気も均等に比較。';
    $lines[] = 'E（判定困難型）: 断層は参考程度。複勝の継続的な動きを最優先で評価。';
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
        '【このシステムの目的（最重要）】',
        'このシステムの目的は「当てること」ではなく「回収率を上げること」です。',
        '1〜3番人気ばかりを正確に当てても、オッズが低いため回収率は上がりません。',
        '「来そうかどうか（信頼度）」と「そのオッズで買う価値があるか（妙味）」を必ず両方考えてください。',
        '信頼度が同等の馬が複数いる場合は、オッズが高い馬（妙味がある馬）を優先して選出してください。',
        '',
        "オッズ推移から注目馬を{$pickupCount}頭選出してください。",
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
        '・条件A: 選出した馬の中に6〜10番人気の馬が1頭以上いる → 1（おすすめ）',
        '・条件B: 6番人気以内の隣接間に断層（比率2.00以上）が2つ以上ある → 0に上書き',
        '・条件AとBが両方成立 → 0',
        '・条件Aのみ成立 → 1',
        '・それ以外 → 0',
        "条件B（PHP算出済み）: {$condition2Desc}",
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
        '分析の観点：',
        '・複勝オッズの動きを単勝より重視してください。複勝は「来るかどうか」を市場が評価している数値です',
        '・単勝より複勝で継続的に資金が入っている馬を高く評価してください',
        '・単勝断層と複勝断層が同じ位置に出ている場合は、その断層を強いシグナルとして扱ってください',
        '・単勝オッズ下落10%以上は人気急上昇として注目（ただし単発の急落は過大評価しない）',
        '・単複比が高い馬＝勝ちにくいが3着以内には絡みやすい',
        '・複勝の最小・最大の幅が広い馬＝市場の評価が割れている不安定な馬',
        '・複勝の最小・最大の幅が狭い馬＝安定して3着以内が期待されている馬',
        '・複勝オッズが下落している馬は3着以内の信頼度が高い',
        '・OPI（Over Popularity Index）の見方: OPI>1.2は過去同人気より低オッズ＝市場が過大評価している可能性（妙味低）、OPI<0.8は過去同人気より高オッズ＝市場が過小評価している可能性（妙味高）。妙味スコアの補正材料として活用してください',
        '・推定確定オッズの見方: 過去の6分前→確定オッズの変動パターンから算出した「発走時点での最終オッズ予測値」です。6分前オッズより推定確定オッズが大きく下がる馬（補正係数<1）は直前にさらに人気が集中する傾向があり、信頼度の補強材料になります。逆に推定確定オッズが上がる馬（補正係数>1）は直前に売られる傾向があります。±の補正誤差が大きい馬は予測の振れ幅が大きいため参考程度に留めてください。妙味スコアを算出する際は、6分前オッズではなく推定確定オッズを基準にしてください',
        '',
        "選出馬は必ず「厳選穴レース|X」を1行目に、続けて「馬番：X、馬名：XXX、人気順: X、6分前オッズ: X.X、おすすめ度: XX、選出理由：〜」の形式で{$pickupCount}頭分出力してください。",
        '※画面表示に影響するので、この形を守ってください。',
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

    // ─── キャッシュ確認 ───────────────────────────────────────────────
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

        // ロック後に再度キャッシュ確認
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

        // ─── DeepSeek API 呼び出し ────────────────────────────────────
        $response = Http::timeout(60)->withHeaders([
            'Authorization' => 'Bearer ' . env('DEEPSEEK_API_KEY'),
        ])->post('https://api.deepseek.com/v1/chat/completions', [
            'model'    => 'deepseek-chat',
            'messages' => [
                ['role' => 'system', 'content' => '競馬のオッズ推移を分析して有力馬を絞り込む専門家です。ただし、人気上位馬だけを並べる予想は面白くありません。オッズ推移に確かな根拠があれば、中穴・大穴馬も積極的に取り上げてください。まじめに、しかし少し遊んでみてください。'],
                ['role' => 'user',   'content' => $oddsData],
            ],
        ]);

        if ($response->failed()) {
            \Log::error('DeepSeek API error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return response()->json(['error' => 'DeepSeek AI分析に失敗しました'], 500);
        }

        $result       = $response->json();
        $analysisText = trim($result['choices'][0]['message']['content'] ?? '');

        // ─── 分析結果をDBに保存（次回以降はキャッシュから返す） ──────────
        DB::table('t_horse_odds_finder_ai_analysis2')->insertOrIgnore([
            'date'          => $date,
            'kaisuu'        => $kaisuu,
            'basho_code'    => $basho,
            'basho'         => $raceRow->basho_name,
            'day'           => $day,
            'race'          => $race,
            'race_name'     => $raceRow->race_name,
            'analysis_text' => $analysisText,
        ]);

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



}
