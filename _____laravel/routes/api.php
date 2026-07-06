<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\WebPushController;

Route::post('signup', [ApiController::class, 'signup']);
Route::post('signin', [ApiController::class, 'signin']);

Route::get('getHorseOddsFinderConfigs', [ApiController::class, 'getHorseOddsFinderConfigs']);

Route::get('getHorseOddsFinderSchedules', [ApiController::class, 'getHorseOddsFinderSchedules']);
Route::get('getHorseOddsFinderRaces', [ApiController::class, 'getHorseOddsFinderRaces']);
Route::get('getHorseOddsFinderHorses', [ApiController::class, 'getHorseOddsFinderHorses']);
Route::get('getHorseOddsFinderOdds', [ApiController::class, 'getHorseOddsFinderOdds']);

Route::get('getHorseOddsFinderNetkeibaRaces', [ApiController::class, 'getHorseOddsFinderNetkeibaRaces']);
Route::get('getHorseOddsFinderNetkeibaOdds', [ApiController::class, 'getHorseOddsFinderNetkeibaOdds']);
Route::get('getHorseOddsFinderOddsGetTiming', [ApiController::class, 'getHorseOddsFinderOddsGetTiming']);

Route::get('getHorseDetail', [ApiController::class, 'getHorseDetail']);

Route::get('getHorseOddsFinderSummary', [ApiController::class, 'getHorseOddsFinderSummary']);

Route::get('getHorseOddsFinderSummaryOneRace', [ApiController::class, 'getHorseOddsFinderSummaryOneRace']);

Route::get('getHorseOddsFinderRaceOneResult', [ApiController::class, 'getHorseOddsFinderRaceOneResult']);

Route::get('getHorseOddsFinderLoginUsers', [ApiController::class, 'getHorseOddsFinderLoginUsers']);
Route::post('changeAdmin', [ApiController::class, 'changeAdmin']);
Route::post('changeDelete', [ApiController::class, 'changeDelete']);

Route::get('vapid-public-key', [WebPushController::class, 'vapidPublicKey']);
Route::post('web-push/subscribe', [WebPushController::class, 'subscribe']);

Route::get('getHorseOddsFinderPushSubscriptions', [ApiController::class, 'getHorseOddsFinderPushSubscriptions']);
Route::post('changePushNotifierUserDelete', [ApiController::class, 'changePushNotifierUserDelete']);

Route::get('getHorseOddsFinderRaceResultHistory', [ApiController::class, 'getHorseOddsFinderRaceResultHistory']);
Route::get('getHorseOddsFinderPopularityRankAverage', [ApiController::class, 'getHorseOddsFinderPopularityRankAverage']);

Route::get('getHorseOddsFinderRaceResultHistoryRaceList', [ApiController::class, 'getHorseOddsFinderRaceResultHistoryRaceList']);
Route::get('getHorseOddsFinderRaceResultHistoryRaceContents', [ApiController::class, 'getHorseOddsFinderRaceResultHistoryRaceContents']);

Route::get('getHorseOddsFinderHorseName', [ApiController::class, 'getHorseOddsFinderHorseName']);

Route::get('getHorseOddsFinderHorseBattleRecord', [ApiController::class, 'getHorseOddsFinderHorseBattleRecord']);

Route::get('getHorseOddsFinderRacesPopularityRatio', [ApiController::class, 'getHorseOddsFinderRacesPopularityRatio']);

Route::get('getHorseOddsFinderRaceResultPayout', [ApiController::class, 'getHorseOddsFinderRaceResultPayout']);
