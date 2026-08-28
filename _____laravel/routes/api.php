<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RaceController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\WebPushController;

////////////////////////////////////////////////////////////////////////////////////
// AuthController — 認証
//   signup     : 新規ユーザー登録（確認メール送信）
//   signin     : サインイン認証
//   verify     : メールアドレス確認（メール内リンクから呼ばれる）
////////////////////////////////////////////////////////////////////////////////////

// 新規ユーザー登録（user_id・email・password を受け取り確認メールを送信）
Route::post('signup', [AuthController::class, 'signup']);
// サインイン認証（user_id・password を照合し成功時に user_id を返す）
Route::post('signin', [AuthController::class, 'signin']);

////////////////////////////////////////////////////////////////////////////////////
// RaceController — レース基本データ
//   getHorseOddsFinderConfigs          : アプリ設定値
//   getHorseOddsFinderSchedules        : 開催スケジュール一覧
//   getHorseOddsFinderRaces            : レース一覧
//   getHorseOddsFinderHorses           : 出走馬一覧
//   getHorseOddsFinderOdds             : オッズ時系列一覧
//   getHorseOddsFinderOddsGetTiming    : オッズ取得タイミング一覧
//   getHorseOddsFinderSummary          : レースサマリー全件
//   getHorseOddsFinderSummaryOneRace   : 指定1レースのサマリー
//   getHorseOddsFinderRaceOneResult    : レース結果一覧
//   getHorseOddsFinderPopularityRankMedian : 人気順位別オッズ中央値
//   getHorseOddsFinderHorseScores      : 馬スコア一覧
//   getHorseOddsFinderJockeyScores     : 騎手スコア一覧
////////////////////////////////////////////////////////////////////////////////////

// アプリ設定値（オッズ取得タイミング・急落馬の人気帯別複勝率）を返す
Route::get('getHorseOddsFinderConfigs', [RaceController::class, 'getHorseOddsFinderConfigs']);
// 開催スケジュール一覧を返す
Route::get('getHorseOddsFinderSchedules', [RaceController::class, 'getHorseOddsFinderSchedules']);
// レース一覧（コース・距離・出走頭数など基本情報）を返す
Route::get('getHorseOddsFinderRaces', [RaceController::class, 'getHorseOddsFinderRaces']);
// 出走馬一覧（枠番・馬番・馬名・騎手）を返す
Route::get('getHorseOddsFinderHorses', [RaceController::class, 'getHorseOddsFinderHorses']);
// オッズ時系列一覧（単勝・複勝、発走N分前ごとの記録）を返す
Route::get('getHorseOddsFinderOdds', [RaceController::class, 'getHorseOddsFinderOdds']);
// オッズ取得タイミング一覧（各レースを何分前に取得したかの記録）を返す
Route::get('getHorseOddsFinderOddsGetTiming', [RaceController::class, 'getHorseOddsFinderOddsGetTiming']);
// レースサマリー全件（馬番ごとのオッズ推移・結果まとめ）を返す
Route::get('getHorseOddsFinderSummary', [RaceController::class, 'getHorseOddsFinderSummary']);
// 指定1レースのサマリー（date・kaisuu・basho・day・race で絞り込み）を返す
Route::get('getHorseOddsFinderSummaryOneRace', [RaceController::class, 'getHorseOddsFinderSummaryOneRace']);
// レース結果一覧（着順・確定タイムなど、レース終了後データ）を返す
Route::get('getHorseOddsFinderRaceOneResult', [RaceController::class, 'getHorseOddsFinderRaceOneResult']);
// レースごとの人気順位別オッズ中央値（類似レース統計）を返す
Route::get('getHorseOddsFinderPopularityRankMedian', [RaceController::class, 'getHorseOddsFinderPopularityRankMedian']);
// 出走馬ごとの総合スコア一覧を返す
Route::get('getHorseOddsFinderHorseScores', [RaceController::class, 'getHorseOddsFinderHorseScores']);
// 騎手ごとの総合スコア一覧を返す
Route::get('getHorseOddsFinderJockeyScores', [RaceController::class, 'getHorseOddsFinderJockeyScores']);

////////////////////////////////////////////////////////////////////////////////////
// HistoryController — 履歴・戦績
//   getHorseDetail                              : 馬の詳細情報（JRAスクレイピング）
//   getHorseOddsFinderRaceResultHistory         : 年別・人気順位別レース結果履歴
//   getHorseOddsFinderRaceResultHistoryRaceList : 年別レース一覧
//   getHorseOddsFinderRaceResultHistoryRaceContents : 指定1レースの全馬結果
//   getHorseOddsFinderHorseName                 : 馬名検索（頭文字1文字）
//   getHorseOddsFinderHorseBattleRecord         : 指定馬名の全戦績
//   getHorseOddsFinderShutsubaHistory           : 複数馬名の出走履歴一括取得
//   getHorseOddsFinderBestHorseWeight           : 全馬の最高着順時の馬体重
//   getHorseOddsFinderRaceIntrospection         : AI によるレース振り返り
////////////////////////////////////////////////////////////////////////////////////

// 指定馬（cname）の詳細情報をJRAサイトからスクレイピングして返す
Route::get('getHorseDetail', [HistoryController::class, 'getHorseDetail']);
// 年別・人気順位別のレース結果履歴を返す（year・popularity_rank で絞り込み）
Route::get('getHorseOddsFinderRaceResultHistory', [HistoryController::class, 'getHorseOddsFinderRaceResultHistory']);
// 年別のレース一覧を返す（結果履歴テーブルからレース単位に集約）
Route::get('getHorseOddsFinderRaceResultHistoryRaceList', [HistoryController::class, 'getHorseOddsFinderRaceResultHistoryRaceList']);
// 指定1レースの全馬結果（着順・オッズ・人気）を返す
Route::get('getHorseOddsFinderRaceResultHistoryRaceContents', [HistoryController::class, 'getHorseOddsFinderRaceResultHistoryRaceContents']);
// 頭文字1文字で馬名を検索する（五十音リスト表示用）
Route::get('getHorseOddsFinderHorseName', [HistoryController::class, 'getHorseOddsFinderHorseName']);
// 指定馬名の全戦績（日付昇順）を返す
Route::get('getHorseOddsFinderHorseBattleRecord', [HistoryController::class, 'getHorseOddsFinderHorseBattleRecord']);
// スラッシュ区切りの馬名リストの出走履歴を一括取得して返す
Route::get('getHorseOddsFinderShutsubaHistory', [HistoryController::class, 'getHorseOddsFinderShutsubaHistory']);
// 全馬の最高着順時の馬体重を返す（ベストパフォーマンス時の体重把握用）
Route::get('getHorseOddsFinderBestHorseWeight', [HistoryController::class, 'getHorseOddsFinderBestHorseWeight']);
// 指定レースの AI による振り返りコメントを返す
Route::get('getHorseOddsFinderRaceIntrospection', [HistoryController::class, 'getHorseOddsFinderRaceIntrospection']);

////////////////////////////////////////////////////////////////////////////////////
// AnalysisController — 統計・分析
//   getHorseOddsFinderRacesPopularityRatio   : 人気比率レコード
//   getHorseOddsFinderRaceResultPayout       : 払い戻し情報
//   getHorseOddsFinderHighProbabilityHorses  : 高複勝率馬
//   getHorseOddsFinderKitaichi               : 期待値スコア（来帯指数）
//   ── Flutter未使用 ──────────────────────────
//   getHorseOddsFinderCourseDistHistory      : コース×距離別過去成績
//   getHorseOddsFinderCourseDistStats        : コース×距離別統計
//   getHorseOddsFinderExpectedValueScore     : 期待値スコア（旧）
////////////////////////////////////////////////////////////////////////////////////

// パイプ区切りIDリストに対応する人気比率レコードを返す
Route::get('getHorseOddsFinderRacesPopularityRatio', [AnalysisController::class, 'getHorseOddsFinderRacesPopularityRatio']);
// スラッシュ区切りで指定した複数レースの払い戻し情報を返す
Route::get('getHorseOddsFinderRaceResultPayout', [AnalysisController::class, 'getHorseOddsFinderRaceResultPayout']);
// 過去類似レース統計から高複勝率が期待できる馬を返す（指定日・レース番号で絞り込み可）
Route::get('getHorseOddsFinderHighProbabilityHorses', [AnalysisController::class, 'getHorseOddsFinderHighProbabilityHorses']);
// 来帯指数（期待値スコア）を返す
Route::get('getHorseOddsFinderKitaichi', [AnalysisController::class, 'getHorseOddsFinderKitaichi']);
// ⚠️ Flutter未使用 — 指定レースの出走馬ごとにコース×距離別の過去成績・脚質を返す
Route::get('getHorseOddsFinderCourseDistHistory', [AnalysisController::class, 'getHorseOddsFinderCourseDistHistory']);
// ⚠️ Flutter未使用 — コース×距離別の統計サマリーを返す
Route::get('getHorseOddsFinderCourseDistStats', [AnalysisController::class, 'getHorseOddsFinderCourseDistStats']);
// ⚠️ Flutter未使用 — 馬ごとの期待値スコア（旧版）を返す
Route::get('getHorseOddsFinderExpectedValueScore', [AnalysisController::class, 'getHorseOddsFinderExpectedValueScore']);

////////////////////////////////////////////////////////////////////////////////////
// AiController — AI 分析（Claude / DeepSeek）
//   getHorseOddsFinderAiAnalysis        : 1st AI（Claude）分析結果
//   getHorseOddsFinderSecondAiOpinion   : 2nd AI（DeepSeek）分析意見
//   getHorseOddsFinderBaganrikiIndex    : 馬柱力指数
//   ── Flutter未使用 ──────────────────────────
//   getHorseOddsFinderAiRecoverySummary : AI 回収率サマリー
////////////////////////////////////////////////////////////////////////////////////

// 指定レースのAI分析結果を返す（未分析時はClaude APIを呼び出してDBにキャッシュ）
Route::get('getHorseOddsFinderAiAnalysis', [AiController::class, 'getHorseOddsFinderAiAnalysis']);
// 2nd AI（DeepSeek）による分析意見を返す（未分析時はDeepSeek APIを呼び出してDBにキャッシュ）
Route::get('getHorseOddsFinderSecondAiOpinion', [AiController::class, 'getHorseOddsFinderSecondAiOpinion']);
// 馬柱力指数（バガン力インデックス）を返す
Route::get('getHorseOddsFinderBaganrikiIndex', [AiController::class, 'getHorseOddsFinderBaganrikiIndex']);
// ⚠️ Flutter未使用 — AI回収率サマリー（累積回収率・最大連敗・ドローダウン・人気帯別回収率）
Route::get('getHorseOddsFinderAiRecoverySummary', [AiController::class, 'getHorseOddsFinderAiRecoverySummary']);

////////////////////////////////////////////////////////////////////////////////////
// AdminController — 管理機能
//   getHorseOddsFinderLoginUsers            : ログインユーザー一覧
//   changeAdmin                             : 管理者権限変更
//   changeDelete                            : ユーザー論理削除
//   getHorseOddsFinderPushSubscriptions     : プッシュ通知サブスクリプション一覧
//   changePushNotifierUserDelete            : プッシュ通知停止（論理削除）
//   getHorseOddsFinderSummaryTableCount     : テーブル別レコード件数
//   getHorseOddsFinderPushSendLogsDeveloperNews : プッシュ通知送信ログ
////////////////////////////////////////////////////////////////////////////////////

// ログインユーザー一覧を返す（管理画面用・機密情報除外）
Route::get('getHorseOddsFinderLoginUsers', [AdminController::class, 'getHorseOddsFinderLoginUsers']);
// ユーザーの管理者権限を変更する（管理画面用）
Route::post('changeAdmin', [AdminController::class, 'changeAdmin']);
// ユーザーの削除フラグを変更する（管理画面用・論理削除）
Route::post('changeDelete', [AdminController::class, 'changeDelete']);
// プッシュ通知サブスクリプション一覧を返す（管理画面用）
Route::get('getHorseOddsFinderPushSubscriptions', [AdminController::class, 'getHorseOddsFinderPushSubscriptions']);
// プッシュ通知サブスクリプションの削除フラグを変更する（管理画面用・論理削除で通知停止）
Route::post('changePushNotifierUserDelete', [AdminController::class, 'changePushNotifierUserDelete']);
// 各テーブルの日付別レコード件数を返す（バッチ処理のデータ投入状況確認用）
Route::get('getHorseOddsFinderSummaryTableCount', [AdminController::class, 'getHorseOddsFinderSummaryTableCount']);
// 開発者向けプッシュ通知の送信ログ一覧を返す
Route::get('getHorseOddsFinderPushSendLogsDeveloperNews', [AdminController::class, 'getHorseOddsFinderPushSendLogsDeveloperNews']);

////////////////////////////////////////////////////////////////////////////////////
// WebPushController — Web プッシュ通知
//   vapid-public-key    : VAPID 公開鍵
//   web-push/subscribe  : サブスクリプション登録
////////////////////////////////////////////////////////////////////////////////////

// Web Push 用 VAPID 公開鍵を返す
Route::get('vapid-public-key', [WebPushController::class, 'vapidPublicKey']);
// Web Push サブスクリプションを登録する
Route::post('web-push/subscribe', [WebPushController::class, 'subscribe']);
