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

class AdminController extends Controller
{


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
     * getHorseOddsFinderPushSendLogsDevelopperNews
     *
     * 【概要】
     *   t_horse_odds_finder_push_send_logs から developer 向け通知ログを取得し、
     *   コマンド種別（kind）ごとにグルーピングして返す。
     *   各レコードには、あらかじめ定義した想定実行時刻（kind_time）との差分秒数
     *   （diff_seconds）を付与する。プラスなら遅延、マイナスなら早着。
     *
     * 【kind_time について】
     *   各コマンドが "本来何時に動くべきか" を "H:i" 形式で定義したマップ。
     *   セパレータ（A〜G）は時間帯の区切りを示すだけで、値は空文字のため
     *   diff_seconds は null になる。
     *
     * 【レスポンス形式】
     *   {
     *     "data": {
     *       "CommandName": [
     *         { "kind": "...", "sent_at": "HH:ii:ss", "diff_seconds": 秒数|null },
     *         ...
     *       ],
     *       ...
     *     }
     *   }
     */
    public function getHorseOddsFinderPushSendLogsDeveloperNews()
    {
        $ary = [];

        // 各コマンドの想定実行時刻（"H:i" 形式）
        // A〜G はグループのセパレータで、diff_seconds は null になる
        $kind_time = [
            'DeleteKeibaTableRecords'             => '5:50',
            'A'                                  => '',       // ── セパレータ ──
            'ImportKeibaSchedule'                => '6:00',
            'SummaryRacesPopularityRatio'        => '6:10',
            'SummaryCalculateHorseScore'          => '6:20',
            'SummaryCalculateJockeyScore'         => '6:30',
            'B'                                  => '',       // ── セパレータ ──
            'ImportKeibaBaseOdds'                => '7:00',
            'SummaryForecastFromLastRace'         => '7:30',
            'C'                                  => '',       // ── セパレータ ──
            'ImportRacesPopularityRatio'          => '8:00',
            'SummaryPopularityRankMedian'         => '8:30',
            'D'                                  => '',       // ── セパレータ ──
            'SummaryKeibaInfo'                   => '20:10',
            'ImportKeibaJraRaceResult'            => '20:20',
            'ImportKeibaRaceResultHistory'        => '20:30',
            'SummaryHistoryPopularityRank'        => '20:40',
            'SummaryPopularityRankAverage'        => '20:50',
            'E'                                  => '',       // ── セパレータ ──
            'SummaryHistoryFinishingPosition'     => '21:00',
            'SummaryComputeOddsCorrection'        => '21:10',
            'SummaryPopularityHorseCheck'         => '21:20',
            'SummaryOddsPhasePatternRecoveryRate' => '21:30',
            'ImportKeibaRaceResultPayout'         => '21:40',
            'SummaryFukuPopularityRankAverage'    => '21:50',
            'F'                                  => '',       // ── セパレータ ──
            'ImportKeibaShutsubaHistory'          => '22:00',
            'SummaryOddsGapRecoveryRate'          => '22:10',
            'SummaryOpiRecoveryRate'              => '22:20',
            'ImportKeibaPayoutCourseDist'         => '22:30',
            'ImportKeibaPayoutInnerOuter'         => '22:40',
            'ImportKeibaPayoutGrade'              => '22:50',
            'G'                                  => '',       // ── セパレータ ──
            'SummaryRacesIntrospection'           => '23:00',
            'SummarySimilarRaceStats'             => '23:10',
            'SummaryAiAnalysisCompensate'         => '23:40',
            'SummaryMakeBaganrikiBrain'           => '23:50',
        ];

        $description = [
            'DeleteKeibaTableRecords'             => 'シーズン開始前などに使う開発・リセット用コマンド。複数のDBテーブルを一括削除し、関連するログファイルや一時ファイルもまとめてクリアして初期状態に戻すバッチ処理。',
            'ImportKeibaSchedule'                 => '外部スクリプト経由で当週の開催スケジュール・レース一覧・出走馬情報をスクレイピングし、曜日に応じて既存データを削除・再投入するインポートバッチ処理。',
            'SummaryRacesPopularityRatio'         => '過去レースの単勝オッズを人気順に並べ、隣り合うオッズの比率を算出・文字列化して、類似レース検索のマスタデータとして蓄積するバッチ処理。',
            'SummaryCalculateHorseScore'          => '一定回数以上の出走実績がある馬を対象に、着順と出走頭数から算出した相対的な強さのスコアを毎回全件再計算してDBに保存するバッチ処理。',
            'SummaryCalculateJockeyScore'         => '一定回数以上の騎乗実績がある騎手を対象に、着順と出走頭数から算出した相対的な強さのスコアを毎回全件再計算してDBに保存するバッチ処理。',
            'ImportKeibaBaseOdds'                 => '週初めに一度だけ実行し、当週全レースの単勝・複勝オッズをスクレイピングして「基準値」としてDBに保存するバッチ処理。以降の毎分オッズ取得の比較ベースラインとなる。',
            'SummaryForecastFromLastRace'         => '各馬の過去出走履歴をAIに渡し、可能性のある候補馬を選出させて結果をDBに保存するバッチ処理。レート制限対策としてバッチ並列送信とリトライ制御を実装している。',
            'ImportRacesPopularityRatio'          => '当日レースのオッズから人気順の比率パターンを計算し、過去レースのマスタデータと類似度を比較・スコアリングしてDBに保存するバッチ処理。',
            'SummaryPopularityRankMedian'         => '当日レースに類似する過去レース群を参照し、人気順位ごとの単勝オッズ中央値を算出してDBに保存するバッチ処理。オッズの期待値ベースラインとして機能する。',
            'SummaryKeibaInfo'                    => '当日の出走馬・オッズ・レース情報を統合して馬ごとのサマリーデータを生成しDBに保存するとともに、発走直前オッズをもとに人気順を算出してレース結果履歴テーブルにも反映するバッチ処理。',
            'ImportKeibaJraRaceResult'            => 'JRA公式サイトから当日の全レース着順をスクレイピングで取得し、サマリーテーブルの結果カラムを更新するとともにレース結果テーブルにも保存するバッチ処理。',
            'ImportKeibaRaceResultHistory'        => '指定年月の全開催・全レースの最終オッズをスクレイピングで取得し、過去実績データとして履歴テーブルに蓄積するバッチ処理。取得済みの開催はNode.js実行前にスキップして処理を効率化している。',
            'SummaryHistoryPopularityRank'        => '過去レース履歴の各馬に対して、単勝オッズの低い順に人気順位を計算し採番してDBを更新するバッチ処理。未設定のレースのみを対象に処理して効率化している。',
            'SummaryPopularityRankAverage'        => '過去レース履歴から人気順位ごとの単勝オッズ平均を算出するバッチ処理。元データが削除される運用に対応するため、加重平均の仕組みを使って新着分だけを既存の集計値に増分で反映している。',
            'SummaryHistoryFinishingPosition'     => '着順が未取得の過去レース開催をスクレイピングで取得し、1開催分を1クエリで一括更新するバッチ処理。中止・除外馬は専用の値で記録して再処理対象から除外している。',
            'SummaryComputeOddsCorrection'        => 'レース直前（6分前）のオッズと確定オッズを突き合わせ、人気順位ごとにオッズがどの方向にどれだけ動く傾向があるかの補正係数を集計してDBに保存するバッチ処理。',
            'SummaryPopularityHorseCheck'         => '類似レースの中央値オッズと実際のオッズの比率から候補馬を選出し、レースの実際の着順と照合してピックアップ精度を検証・記録するバッチ処理。',
            'SummaryOddsPhasePatternRecoveryRate' => 'レース前のオッズ推移を前半・後半に分けて上昇・横ばい・下落の9パターンに分類し、人気帯との組み合わせごとの勝率・単勝回収率を集計してDBに保存するバッチ処理。',
            'ImportKeibaRaceResultPayout'         => '指定年月の全開催・全レースの払戻金をスクレイピングで取得し、券種ごとに整形してDBに蓄積するバッチ処理。取得済みの開催はNode.js実行前にスキップして効率化している。',
            'SummaryFukuPopularityRankAverage'    => '過去レース履歴から人気順位ごとの複勝オッズ平均を算出するバッチ処理。単勝版と同じ加重平均の増分更新方式を採用しつつ、DB側で集計してからPHPに渡すことでメモリ消費を抑えている。',
            'ImportKeibaShutsubaHistory'          => '翌日（または指定日）の全開催・全レースの出馬表を取得し、各馬の前走〜4走前の出走履歴をスクレイピングしてDBに蓄積するバッチ。ロック管理・リトライ・WebPush通知付き。',
            'SummaryOddsGapRecoveryRate'          => '計測開始時点から6分前オッズへの変化率を9段階の変化率帯×4段階の人気帯に分類し、組み合わせごとの勝率・単勝回収率をSQL集計してDBに保存するバッチ。AI分析プロンプトの補助データとして利用される。',
            'SummaryOpiRecoveryRate'              => '過去平均6分前オッズと今回6分前オッズの比率（OPI）を算出し、OPI帯×人気帯別の単勝勝率・回収率をSQL集計してDBに保存するバッチ。市場の過大／過小評価パターンをAI分析プロンプトの補助データとして提供する。',
            'ImportKeibaPayoutCourseDist'         => '払戻金テーブルのコース・距離が未設定のレコードをスクレイピングで取得し、course/distが空のレコードのみを対象に一括補完するバッチ。取得済みはWHERE条件で自動スキップし冪等性を確保。',
            'ImportKeibaPayoutInnerOuter'         => '払戻金テーブルの内外コース区分が未設定のレコードをスクレイピングで補完するバッチ。対象は内外の概念がある4競馬場の芝コースのみで、障害レースと直線コースは除外。未設定分のみWHERE条件でスキップし冪等性を確保。',
            'ImportKeibaPayoutGrade'              => '払戻金テーブルのグレード（G1/G2/G3等）が未設定のレコードをスクレイピングで取得し補完するバッチ。通常の条件戦はmjs側で除外されるため、グレードレースのみを対象に絞り込み、未設定分だけWHERE条件で一括更新する。',
            'SummaryRacesIntrospection'           => '過去レースのオッズ推移と実際の着順をAIに送り、有力馬選出の振り返り（ピックアップ・結果・分析）を生成してDBに保存するバッチ。バッチ並列送信・リトライ・フォーマット自動補正付き。回収率向上を目的とした自己学習用。',
            'SummaryMakeBaganrikiBrain'           => '過去の振り返り分析テキストを30件ずつAIに送り、「有力馬の選び方の目線（脳みそ）」を段階的に統合・磨き上げてファイルに書き出すバッチ。次回のAI分析プロンプトの羅針盤として読み込まれ、回収率向上に活用される。',
            'SummarySimilarRaceStats'             => '過去レースの人気順×頭数帯（小/中/大）ごとに3着以内率・5着以内率・平均着順をDB側GROUP BYで集計（最大54行）してDBに保存するバッチ。AI分析プロンプトで「過去の類似レース傾向」として参照される。',
        ];

        $SQL = " select * from t_horse_odds_finder_push_send_logs where title = 'develop' and body not like '%時刻修正%' order by body, sent_at; ";
        $result = DB::select($SQL);
        
        foreach($result as $v){
            $ex_body  = explode("::", $v->body);
            $kindKey  = trim($ex_body[0]);

            // kind_time（例: "5:30"）と sent_at との差分を秒数で計算する
            // sent_at は "2026-08-22 05:50:03" のようなフル日時形式のため、
            // substr で時刻部分（"05:50:03"）だけ切り出してから変換する
            $kindTimeStr = $kind_time[$kindKey] ?? null;
            $diffSeconds = null;
            if ($kindTimeStr !== null && $kindTimeStr !== '') {
                $ktParts     = explode(':', $kindTimeStr);
                $kindSeconds = (int)$ktParts[0] * 3600 + (int)$ktParts[1] * 60;

                $timePart    = substr($v->sent_at, 11, 8); // "HH:MM:SS" 部分のみ取得
                $saParts     = explode(':', $timePart);
                $sentSeconds = (int)$saParts[0] * 3600 + (int)$saParts[1] * 60 + (int)($saParts[2] ?? 0);

                $diffSeconds = $sentSeconds - $kindSeconds;
            }

            $ary[] = [
                'kind'         => $kindKey,
                'sent_date'    => substr($v->sent_at, 0, 10), // "YYYY-MM-DD" 部分のみ
                'diff_seconds' => $diffSeconds,
                'time'         => $kindTimeStr ?? '',
                'description'  => $description[$kindKey] ?? '',
            ];
        }
        
        return response()->json(['data' => $ary]);
    }

}
