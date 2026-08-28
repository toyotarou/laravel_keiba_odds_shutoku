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

class AnalysisController extends Controller
{
    
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
    // ⚠️ Flutter未使用 - コメントアウト

    // public function getHorseOddsFinderCourseDistHistory(Request $request)
    // {
    // $date   = $request->query('date', date('Y-m-d'));
    // $kaisuu = $request->query('kaisuu');
    // $basho  = $request->query('basho');
    // $day    = $request->query('day');
    // $race   = $request->query('race');

    // // ── ① 今日のレース情報を取得（course と dist を知るため） ────────────
    // // t_horse_odds_finder_races に course="芝"/"ダート", dist=1700 のように入っている
    // $raceRow = DB::table('t_horse_odds_finder_races')
    // ->where('date',   $date)
    // ->where('kaisuu', $kaisuu)
    // ->where('basho',  $basho)
    // ->where('day',    $day)
    // ->where('race',   intval($race))
    // ->first();

    // if (!$raceRow) {
    // return response()->json(['error' => 'レースが見つかりません'], 404);
    // }

    // // 今日のレースのコース種別・距離（過去履歴の絞り込みキーになる）
    // $targetCourse = $raceRow->course; // "芝" or "ダート"
    // $targetDist   = (int) $raceRow->dist;

    // // ── ② 今日の出走馬一覧を取得 ─────────────────────────────────────────
    // $horses = DB::table('t_horse_odds_finder_horses')
    // ->where('date',   $date)
    // ->where('kaisuu', $kaisuu)
    // ->where('basho',  $basho)
    // ->where('day',    $day)
    // ->where('race',   intval($race))
    // ->orderBy('waku')
    // ->orderBy('num')
    // ->get();

    // if ($horses->isEmpty()) {
    // return response()->json(['error' => '出走馬が見つかりません'], 404);
    // }

    // $horseNames = $horses->pluck('name')->toArray();

    // // ── ③ 過去履歴を「同コース×同距離」に絞って一括取得 ──────────────────
    // //
    // // shutsuba_history.dist は "1700ダ", "1200芝" のような文字列なので
    // //   ・REGEXP_REPLACE で数字だけ抜き出して距離を比較
    // //   ・LIKE でコース種別（芝 or ダ）を絞る
    // //
    // // ダート = "ダ" を含む（"1700ダ" など）
    // // 芝    = "芝" を含む（"1200芝", 障害の "3200芝ダ" も含まれるが許容）
    // $courseLike = ($targetCourse === 'ダート') ? '%ダ%' : '%芝%';

    // $histories = DB::table('t_horse_odds_finder_shutsuba_history')
    // ->whereIn('name', $horseNames)
    // ->whereRaw("REGEXP_REPLACE(dist, '[^0-9]', '') = ?", [(string) $targetDist])
    // ->where('dist', 'LIKE', $courseLike)
    // ->orderBy('name')
    // ->orderBy('date', 'desc') // 新しい順（records は直近が先頭）
    // ->get();

    // // 馬名をキーにした連想配列に変換（後のループで O(1) アクセスするため）
    // $historyByName = [];
    // foreach ($histories as $h) {
    // $historyByName[$h->name][] = $h;
    // }

    // // ── ④ ヘルパークロージャ ─────────────────────────────────────────────

    // // タイム文字列 "1:44.3" を秒（float）に変換する
    // // 変換できない（null・空・形式違い）場合は null を返す
    // $parseTimeSec = function (?string $time): ?float {
    // if (!$time || !preg_match('/^(\d+):(\d+\.\d+)$/', trim($time), $m)) {
    // return null;
    // }
    // return (int)$m[1] * 60 + (float)$m[2];
    // };

    // // 最終コーナー通過順位の「頭数比率」から脚質を分類する
    // //   ratio = corner_4 / num_horses（0に近いほど前、1に近いほど後ろ）
    // //   0.00〜0.10 → 逃（ほぼ先頭）
    // //   0.11〜0.35 → 先（先行集団）
    // //   0.36〜0.60 → 中（中団）
    // //   0.61〜0.80 → 差（後方から差す）
    // //   0.81〜1.00 → 追（最後方からの追い込み）
    // $classifyStyle = function (?float $ratio): ?string {
    // if ($ratio === null) return null;
    // if ($ratio <= 0.10) return '逃';
    // if ($ratio <= 0.35) return '先';
    // if ($ratio <= 0.60) return '中';
    // if ($ratio <= 0.80) return '差';
    // return '追';
    // };

    // // ── ⑤ 直近5走を全馬まとめて一括取得（コース・距離問わず） ────────────
    // // 今の調子（上昇/下降傾向）を見るために全距離・全コースの直近5走を取得する。
    // // N+1 を避けるため、全出走馬の名前でまとめて取り、後でグループ化する。
    // $recentAllHistories = DB::table('t_horse_odds_finder_shutsuba_history')
    // ->whereIn('name', $horseNames)
    // ->whereNotNull('finishing_position')
    // ->orderBy('name')
    // ->orderBy('date', 'desc')
    // ->orderBy('id', 'desc')
    // ->get();

    // // 馬名ごとに直近5走だけ残す
    // $recentByName = [];
    // foreach ($recentAllHistories as $r) {
    // if (!isset($recentByName[$r->name])) {
    // $recentByName[$r->name] = [];
    // }
    // if (count($recentByName[$r->name]) < 5) {
    // $recentByName[$r->name][] = $r;
    // }
    // }

    // // ── ⑥ 馬ごとに集計 ───────────────────────────────────────────────────
    // $data = [];
    // foreach ($horses as $horse) {
    // $name    = $horse->name;
    // $records = $historyByName[$name] ?? []; // 同コース×同距離の過去レース一覧

    // $total       = count($records);
    // $win         = 0;   // 1着回数
    // $top3        = 0;   // 3着以内回数（複勝圏内）
    // $finishSum   = 0;   // 着順の合計（平均着順の計算用）
    // $bestTimeSec = null; // このコース×距離での最速タイム（秒）

    // // 脚質算出用：corner_4 / num_horses の平均を取る
    // $styleRatioSum = 0.0;
    // $styleCount    = 0;

    // // 直線伸び算出用：(corner_4 - finishing_position) の平均を取る
    // //   プラス = 直線で前の馬を抜いた（追い込み）
    // //   マイナス = 直線で後ろの馬に抜かれた（バテ）
    // $surgeSum   = 0.0;
    // $surgeCount = 0;

    // // 馬場状態別成績集計用
    // // 例: ['良' => ['total'=>3,'win'=>1,'top3'=>2], '稍重' => [...], ...]
    // $conditionStats = [];

    // foreach ($records as $r) {
    // // 着順集計
    // if (!is_null($r->finishing_position)) {
    // if ($r->finishing_position == 1) $win++;
    // if ($r->finishing_position <= 3) $top3++;
    // $finishSum += $r->finishing_position;
    // }

    // // 最速タイム更新（秒換算して比較）
    // $sec = $parseTimeSec($r->time);
    // if ($sec !== null && ($bestTimeSec === null || $sec < $bestTimeSec)) {
    // $bestTimeSec = $sec;
    // }

    // // 脚質：最終コーナー順位 ÷ 出走頭数 を積み上げる
    // if (!is_null($r->corner_4) && !is_null($r->num_horses) && $r->num_horses > 0) {
    // $styleRatioSum += $r->corner_4 / $r->num_horses;
    // $styleCount++;
    // }

    // // 直線での伸び：最終コーナー順位 - 最終着順
    // //   例) corner_4=5, finishing_position=3 → +2（2頭抜いた）
    // //   例) corner_4=3, finishing_position=7 → -4（4頭に抜かれた）
    // if (!is_null($r->corner_4) && !is_null($r->finishing_position)) {
    // $surgeSum += ($r->corner_4 - $r->finishing_position);
    // $surgeCount++;
    // }

    // // 馬場状態別成績を集計
    // // condition は "良", "稍重", "重", "不良" など
    // // 障害レースは "稍重/重" のように複合表記になる場合があるが、そのまま格納する
    // if (!empty($r->condition) && !is_null($r->finishing_position)) {
    // $cond = $r->condition;
    // if (!isset($conditionStats[$cond])) {
    // $conditionStats[$cond] = ['total' => 0, 'win' => 0, 'top3' => 0];
    // }
    // $conditionStats[$cond]['total']++;
    // if ($r->finishing_position == 1) $conditionStats[$cond]['win']++;
    // if ($r->finishing_position <= 3) $conditionStats[$cond]['top3']++;
    // }
    // }

    // // 平均比率から脚質文字列に変換
    // $avgStyleRatio = $styleCount > 0 ? $styleRatioSum / $styleCount : null;
    // $runningStyle  = $classifyStyle($avgStyleRatio);

    // // 直線での平均伸び順位数（小数第1位まで）
    // $avgLastSurge = $surgeCount > 0 ? round($surgeSum / $surgeCount, 1) : null;

    // // 馬場状態別に複勝率を付与し、最も複勝率が高い条件を best_condition として返す
    // $bestCondition     = null;
    // $bestConditionRate = -1;
    // $conditionStatsOut = [];
    // foreach ($conditionStats as $cond => $cs) {
    // $rate = $cs['total'] > 0 ? round($cs['top3'] / $cs['total'] * 100, 1) : 0;
    // $conditionStatsOut[$cond] = [
    // 'total'     => $cs['total'],
    // 'win'       => $cs['win'],
    // 'top3'      => $cs['top3'],
    // 'top3_rate' => $rate,
    // ];
    // if ($rate > $bestConditionRate) {
    // $bestConditionRate = $rate;
    // $bestCondition     = $cond;
    // }
    // }

    // // 直近5走の着順リストと調子トレンドを算出
    // // recent_form: 新しい順に並んだ着順の配列 例: [1, 3, 5, 2, 8]
    // $recentRecords = $recentByName[$name] ?? [];
    // $recentForm    = array_map(fn($r) => $r->finishing_position, $recentRecords);

    // // recent_trend: 直近5走の前半2走と後半2走の平均着順を比較してトレンドを判定
    // //   "上昇" = 最近の方が着順が良い（数字が小さい）
    // //   "下降" = 最近の方が着順が悪い（数字が大きい）
    // //   "安定" = ほぼ変化なし（差が1着順以内）
    // //   null   = データが3走未満で判定不能
    // $recentTrend = null;
    // if (count($recentForm) >= 3) {
    // $newer = array_slice($recentForm, 0, 2); // 直近2走
    // $older = array_slice($recentForm, -2);   // 最古2走
    // $newerAvg = array_sum($newer) / count($newer);
    // $olderAvg = array_sum($older) / count($older);
    // $diff = $olderAvg - $newerAvg; // プラス=最近の方が着順良い
    // if ($diff > 1)       $recentTrend = '上昇';
    // elseif ($diff < -1)  $recentTrend = '下降';
    // else                 $recentTrend = '安定';
    // }

    // // 騎手変更フラグ
    // // 同コース×距離で最も直近のレースの騎手と今日の騎手を比較する。
    // // 騎手名には "▲", "△", "☆" などの見習いマーク が付く場合があるため除去して比較。
    // $stripMark    = fn(?string $j): string => preg_replace('/^[▲△☆★◇◆]+/', '', (string)$j);
    // $lastJockey   = !empty($records) ? $stripMark($records[0]->jockey) : null;
    // $todayJockey  = $stripMark($horse->jockey);
    // $jockeyChanged = ($lastJockey !== null && $lastJockey !== $todayJockey);

    // $data[] = [
    // // ── 馬の基本情報 ──
    // 'waku'   => $horse->waku,
    // 'num'    => $horse->num,
    // 'name'   => $name,
    // 'jockey' => $horse->jockey,

    // // ── 適性判定フィールド ──
    // // has_experience: このコース×距離の出走経験があるか
    // //   false の馬は適性が完全に未知。予想では注意が必要。
    // 'has_experience' => $total > 0,

    // // best_time_sec: 同コース×距離での最速タイム（秒）
    // //   タイムが速い馬ほどこの条件でのスピード実績がある。
    // //   ただし馬場状態・メンバーレベルが違う点は考慮が必要。
    // 'best_time_sec' => $bestTimeSec,

    // // time_rank: 経験馬の中での最速タイム順位（1=最速）
    // //   null = 経験なしのため圏外
    // 'time_rank' => null, // 後のステップ⑦で付与する

    // // running_style: 脚質（逃/先/中/差/追）
    // //   同コース×距離でのcorner_4平均位置から算出。
    // //   予想での使い方：
    // //     今日のレースで「逃」が多い → ハイペース → 差し・追い込み有利
    // //     今日のレースで「逃」が少ない → スロー → 先行馬有利
    // //   null = 経験なしのため不明
    // 'running_style' => $runningStyle,

    // // avg_last_surge: 直線での平均伸び順位数
    // //   プラス: 追い込み型（末脚がある）→ 差しが決まる展開で狙い目
    // //   マイナス: バテ型（直線で失速）→ 消し候補
    // //   0付近: 位置取りをそのまま維持するタイプ
    // //   null = 経験なしのため不明
    // 'avg_last_surge' => $avgLastSurge,

    // // jockey_changed: 同コース×距離の前走から騎手が変わったか
    // //   true = 変わった → 脚質・running_style が参考にならない可能性あり
    // //   false = 同じ騎手 → 過去データの信頼性が高い
    // //   null = 比較できる過去データなし（経験なし馬）
    // 'jockey_changed' => $total > 0 ? $jockeyChanged : null,

    // // best_condition: このコース×距離で最も複勝率が高い馬場状態
    // //   例: "稍重" → 稍重で特に好走している
    // //   今日の馬場状態と照合して予想の参考にする
    // //   null = 経験なしのため不明
    // 'best_condition' => $bestCondition,

    // // condition_stats: 馬場状態別の成績内訳
    // //   キー = 馬場状態（良/稍重/重/不良）
    // //   値   = {total, win, top3, top3_rate}
    // //   null = 経験なしのため空
    // 'condition_stats' => !empty($conditionStatsOut) ? $conditionStatsOut : null,

    // // recent_form: コース・距離問わず直近5走の着順（新しい順）
    // //   例: [1, 3, 5, 2, 8] → 直近1走が1着、2走前が3着...
    // //   今の馬の調子を把握するために使う
    // 'recent_form' => $recentForm,

    // // recent_trend: 直近5走の調子トレンド
    // //   "上昇" = 最近の方が着順が良い（上り調子）→ 狙い目
    // //   "下降" = 最近の方が着順が悪い（下り調子）→ 注意
    // //   "安定" = ほぼ変化なし
    // //   null   = データ不足（3走未満）
    // 'recent_trend' => $recentTrend,

    // // ── 同コース×距離の成績サマリー ──
    // 'course_dist_stats' => [
    // 'course'        => $targetCourse,
    // 'dist'          => $targetDist,
    // 'total'         => $total,                // 同条件での出走回数
    // 'win'           => $win,                  // 1着回数
    // 'top3'          => $top3,                 // 3着以内回数
    // 'win_rate'      => $total > 0 ? round($win  / $total * 100, 1) : null, // 勝率(%)
    // 'top3_rate'     => $total > 0 ? round($top3 / $total * 100, 1) : null, // 複勝率(%)
    // 'avg_finishing' => $total > 0 ? round($finishSum / $total, 1)  : null, // 平均着順
    // ],

    // // ── 同コース×距離の過去レース明細（新しい順） ──
    // 'records' => array_map(fn($r) => [
    // 'date'               => $r->date,
    // 'basho'              => $r->basho,
    // 'race'               => $r->race,
    // 'race_name'          => $r->race_name,
    // 'dist_raw'           => $r->dist,            // 元の文字列 例: "1700ダ"
    // 'dist'               => (int) preg_replace('/[^0-9]/', '', (string)$r->dist), // 距離(m) 例: 1700
    // 'course'             => preg_replace('/[0-9]/', '', (string)$r->dist) ?: null, // コース種別 例: "ダ", "芝", "芝ダ"
    // 'finishing_position' => $r->finishing_position,
    // 'num_horses'         => $r->num_horses,
    // 'popularity'         => $r->popularity,       // 人気順位
    // 'jockey'             => $r->jockey,
    // 'condition'          => $r->condition,        // 馬場状態（良/稍重/重/不良）
    // 'time'               => $r->time,             // タイム文字列 例: "1:44.3"
    // 'time_sec'           => $parseTimeSec($r->time), // タイム（秒）例: 104.3
    // 'last_3f'            => $r->last_3f,          // 上がり3ハロンタイム
    // 'grade'              => $r->grade,
    // // ── コーナー通過順位 ──
    // // 位置取りの変化を追える。1コーナーから4コーナーにかけて
    // // 順位が上がれば前に行った、下がれば後退したことを示す。
    // 'corner_1'           => $r->corner_1,
    // 'corner_2'           => $r->corner_2,
    // 'corner_3'           => $r->corner_3,
    // 'corner_4'           => $r->corner_4,
    // // last_surge: 直線での伸び（corner_4 - finishing_position）
    // //   プラス = 直線で前の馬を抜いた
    // //   マイナス = 直線で後ろの馬に抜かれた（バテ）
    // 'last_surge' => (!is_null($r->corner_4) && !is_null($r->finishing_position))
    // ? ($r->corner_4 - $r->finishing_position)
    // : null,
    // ], $records),
    // ];
    // }

    // // ── ⑦ time_rank を付与 ──────────────────────────────────────────────
    // // best_time_sec が null でない馬（経験あり）だけを取り出してタイム昇順でソートし、
    // // 順位を各馬に付与する。経験なし馬は time_rank=null のまま。
    // $experienced = array_filter($data, fn($h) => $h['best_time_sec'] !== null);
    // usort($experienced, fn($a, $b) => $a['best_time_sec'] <=> $b['best_time_sec']);
    // $rank = 1;
    // $rankedNums = [];
    // foreach ($experienced as $h) {
    // $rankedNums[$h['num']] = $rank++;
    // }
    // foreach ($data as &$h) {
    // $h['time_rank'] = $rankedNums[$h['num']] ?? null;
    // }
    // unset($h);

    // return response()->json([
    // 'race' => [
    // 'date'       => $raceRow->date,
    // 'kaisuu'     => $raceRow->kaisuu,
    // 'basho'      => $raceRow->basho,
    // 'basho_name' => $raceRow->basho_name,
    // 'day'        => $raceRow->day,
    // 'race'       => $raceRow->race,
    // 'race_name'  => $raceRow->race_name,
    // 'course'     => $targetCourse,
    // 'dist'       => $targetDist,
    // ],
    // 'data' => $data,
    // ]);
    // }

    


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
    // ⚠️ Flutter未使用 - コメントアウト

    // public function getHorseOddsFinderCourseDistStats(Request $request)
    // {
    // $course = $request->query('course');
    // $dist   = (int) $request->query('dist');

    // if (!$course || !$dist) {
    // return response()->json(['error' => 'course と dist は必須です'], 400);
    // }

    // $stats = $this->_calcCourseDistStats($course, $dist);

    // if ($stats === null) {
    // return response()->json(['data' => [
    // 'course'        => $course,
    // 'dist'          => $dist,
    // 'race_count'    => 0,
    // 'by_popularity' => [],
    // 'tan_ranking'   => [],
    // 'fuku_ranking'  => [],
    // ]]);
    // }

    // return response()->json(['data' => $stats]);
    // }
    // ⚠️ Flutter未使用 - コメントアウト

    // public function getHorseOddsFinderExpectedValueScore(Request $request)
    // {
    // $date   = $request->query('date');
    // $kaisuu = $request->query('kaisuu');
    // $basho  = $request->query('basho');
    // $day    = $request->query('day');
    // $race   = $request->query('race');

    // if (!$date || !$kaisuu || !$basho || !$day || !$race) {
    // return response()->json(['error' => 'date, kaisuu, basho, day, race は必須です'], 400);
    // }

    // $sql = "
    // SELECT
    // o.num,
    // CAST(o.odds AS DECIMAL(10,1))                                        AS current_odds,
    // pop_rank.popularity_rank                                             AS calc_popularity_rank,
    // rr.recovery_rate,
    // ROUND(rr.recovery_rate * CAST(o.odds AS DECIMAL(10,1)) / 100, 2)    AS expected_value_score
    // FROM t_horse_odds_finder_odds o

    // INNER JOIN (
    // SELECT
    // date, kaisuu, basho, day, race, num,
    // RANK() OVER (
    // PARTITION BY date, kaisuu, basho, day, race
    // ORDER BY CAST(odds AS DECIMAL(10,1)) ASC
    // ) AS popularity_rank
    // FROM t_horse_odds_finder_odds
    // WHERE minutes_before_start = 6
    // AND odds IS NOT NULL
    // AND odds != ''
    // AND date   = ?
    // AND kaisuu = ?
    // AND basho  = ?
    // AND day    = ?
    // AND race   = ?
    // ) pop_rank
    // ON  o.date    = pop_rank.date
    // AND o.kaisuu  = pop_rank.kaisuu
    // AND o.basho   = pop_rank.basho
    // AND o.day     = pop_rank.day
    // AND o.race    = pop_rank.race
    // AND o.num     = pop_rank.num

    // INNER JOIN (
    // SELECT
    // popularity_rank,
    // ROUND(
    // SUM(CASE WHEN finishing_position = 1 THEN CAST(tan AS DECIMAL(10,1)) ELSE 0 END)
    // / COUNT(*) * 100
    // , 1) AS recovery_rate
    // FROM t_horse_odds_finder_race_result_history
    // WHERE tan IS NOT NULL
    // AND tan != ''
    // GROUP BY popularity_rank
    // ) rr
    // ON rr.popularity_rank = pop_rank.popularity_rank

    // WHERE o.minutes_before_start = 6
    // AND o.odds IS NOT NULL
    // AND o.odds != ''
    // AND o.date   = ?
    // AND o.kaisuu = ?
    // AND o.basho  = ?
    // AND o.day    = ?
    // AND o.race   = ?

    // ORDER BY expected_value_score DESC
    // ";

    // $bindings = [
    // $date, $kaisuu, $basho, $day, $race,
    // $date, $kaisuu, $basho, $day, $race,
    // ];

    // $result = DB::select($sql, $bindings);

    // return response()->json(['data' => $result]);
    // }


































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

}
