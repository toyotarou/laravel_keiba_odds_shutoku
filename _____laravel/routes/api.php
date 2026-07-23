<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\WebPushController;

// 新規ユーザー登録（user_id・email・password を受け取り確認メールを送信）
Route::post('signup', [ApiController::class, 'signup']);
// サインイン認証（user_id・password を照合し成功時に user_id を返す）
Route::post('signin', [ApiController::class, 'signin']);

// アプリ設定値（オッズ取得タイミング・急落馬の人気帯別複勝率）を返す
Route::get('getHorseOddsFinderConfigs', [ApiController::class, 'getHorseOddsFinderConfigs']);

// 開催スケジュール一覧を返す
Route::get('getHorseOddsFinderSchedules', [ApiController::class, 'getHorseOddsFinderSchedules']);
// レース一覧（コース・距離・出走頭数など基本情報）を返す
Route::get('getHorseOddsFinderRaces', [ApiController::class, 'getHorseOddsFinderRaces']);
// 出走馬一覧（枠番・馬番・馬名・騎手）を返す
Route::get('getHorseOddsFinderHorses', [ApiController::class, 'getHorseOddsFinderHorses']);
// オッズ時系列一覧（単勝・複勝、発走N分前ごとの記録）を返す
Route::get('getHorseOddsFinderOdds', [ApiController::class, 'getHorseOddsFinderOdds']);

// レース情報（旧スクレイピングデータ）を返す
Route::get('getHorseOddsFinderNetkeibaRaces', [ApiController::class, 'getHorseOddsFinderNetkeibaRaces']);
// オッズ情報（旧スクレイピングデータ）を返す
Route::get('getHorseOddsFinderNetkeibaOdds', [ApiController::class, 'getHorseOddsFinderNetkeibaOdds']);
// オッズ取得タイミング一覧（各レースを何分前に取得したかの記録）を返す
Route::get('getHorseOddsFinderOddsGetTiming', [ApiController::class, 'getHorseOddsFinderOddsGetTiming']);

// 指定馬（cname）の詳細情報をJRAサイトからスクレイピングして返す
Route::get('getHorseDetail', [ApiController::class, 'getHorseDetail']);

// レースサマリー全件（馬番ごとのオッズ推移・結果まとめ）を返す
Route::get('getHorseOddsFinderSummary', [ApiController::class, 'getHorseOddsFinderSummary']);

// 指定1レースのサマリー（date・kaisuu・basho・day・race で絞り込み）を返す
Route::get('getHorseOddsFinderSummaryOneRace', [ApiController::class, 'getHorseOddsFinderSummaryOneRace']);

// レース結果一覧（着順・確定タイムなど、レース終了後データ）を返す
Route::get('getHorseOddsFinderRaceOneResult', [ApiController::class, 'getHorseOddsFinderRaceOneResult']);

// ログインユーザー一覧を返す（管理画面用・機密情報除外）
Route::get('getHorseOddsFinderLoginUsers', [ApiController::class, 'getHorseOddsFinderLoginUsers']);
// ユーザーの管理者権限を変更する（管理画面用）
Route::post('changeAdmin', [ApiController::class, 'changeAdmin']);
// ユーザーの削除フラグを変更する（管理画面用・論理削除）
Route::post('changeDelete', [ApiController::class, 'changeDelete']);

// Web Push 用 VAPID 公開鍵を返す
Route::get('vapid-public-key', [WebPushController::class, 'vapidPublicKey']);
// Web Push サブスクリプションを登録する
Route::post('web-push/subscribe', [WebPushController::class, 'subscribe']);

// プッシュ通知サブスクリプション一覧を返す（管理画面用）
Route::get('getHorseOddsFinderPushSubscriptions', [ApiController::class, 'getHorseOddsFinderPushSubscriptions']);
// プッシュ通知サブスクリプションの削除フラグを変更する（管理画面用・論理削除で通知停止）
Route::post('changePushNotifierUserDelete', [ApiController::class, 'changePushNotifierUserDelete']);

// 年別・人気順位別のレース結果履歴を返す（year・popularity_rank で絞り込み）
Route::get('getHorseOddsFinderRaceResultHistory', [ApiController::class, 'getHorseOddsFinderRaceResultHistory']);
// 人気順位別の平均成績（勝率・複勝率・回収率の平均）を返す
Route::get('getHorseOddsFinderPopularityRankAverage', [ApiController::class, 'getHorseOddsFinderPopularityRankAverage']);

// 年別のレース一覧を返す（結果履歴テーブルからレース単位に集約）
Route::get('getHorseOddsFinderRaceResultHistoryRaceList', [ApiController::class, 'getHorseOddsFinderRaceResultHistoryRaceList']);
// 指定1レースの全馬結果（着順・オッズ・人気）を返す
Route::get('getHorseOddsFinderRaceResultHistoryRaceContents', [ApiController::class, 'getHorseOddsFinderRaceResultHistoryRaceContents']);

// 頭文字1文字で馬名を検索する（五十音リスト表示用）
Route::get('getHorseOddsFinderHorseName', [ApiController::class, 'getHorseOddsFinderHorseName']);

// 指定馬名の全戦績（日付昇順）を返す
Route::get('getHorseOddsFinderHorseBattleRecord', [ApiController::class, 'getHorseOddsFinderHorseBattleRecord']);

// パイプ区切りIDリストに対応する人気比率レコードを返す
Route::get('getHorseOddsFinderRacesPopularityRatio', [ApiController::class, 'getHorseOddsFinderRacesPopularityRatio']);

// スラッシュ区切りで指定した複数レースの払い戻し情報を返す
Route::get('getHorseOddsFinderRaceResultPayout', [ApiController::class, 'getHorseOddsFinderRaceResultPayout']);

// 各テーブルの日付別レコード件数を返す（バッチ処理のデータ投入状況確認用）
Route::get('getHorseOddsFinderSummaryTableCount', [ApiController::class, 'getHorseOddsFinderSummaryTableCount']);

// 過去類似レース統計から高複勝率が期待できる馬を返す（指定日・レース番号で絞り込み可）
Route::get('getHorseOddsFinderHighProbabilityHorses', [ApiController::class, 'getHorseOddsFinderHighProbabilityHorses']);

// 指定レースのAI分析結果を返す（未分析時はClaude APIを呼び出してDBにキャッシュ）
Route::get('getHorseOddsFinderAiAnalysis', [ApiController::class, 'getHorseOddsFinderAiAnalysis']);

// スラッシュ区切りの馬名リストの出走履歴を一括取得して返す
Route::get('getHorseOddsFinderShutsubaHistory', [ApiController::class, 'getHorseOddsFinderShutsubaHistory']);

// 指定レースの出走馬ごとにコース×距離別の過去成績・脚質を返す
Route::get('getHorseOddsFinderCourseDistHistory', [ApiController::class, 'getHorseOddsFinderCourseDistHistory']);

// レースごとの人気順位別オッズ中央値（類似レース統計）を返す
Route::get('getHorseOddsFinderPopularityRankMedian', [ApiController::class, 'getHorseOddsFinderPopularityRankMedian']);

// 全馬の最高着順時の馬体重を返す（ベストパフォーマンス時の体重把握用）
Route::get('getHorseOddsFinderBestHorseWeight', [ApiController::class, 'getHorseOddsFinderBestHorseWeight']);
