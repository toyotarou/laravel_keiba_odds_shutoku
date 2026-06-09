<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApiController;

Route::get('getHorseOddsFinderConfigs', [ApiController::class, 'getHorseOddsFinderConfigs']);

Route::get('getHorseOddsFinderSchedules', [ApiController::class, 'getHorseOddsFinderSchedules']);
Route::get('getHorseOddsFinderRaces', [ApiController::class, 'getHorseOddsFinderRaces']);
Route::get('getHorseOddsFinderHorses', [ApiController::class, 'getHorseOddsFinderHorses']);
Route::get('getHorseOddsFinderOdds', [ApiController::class, 'getHorseOddsFinderOdds']);

Route::get('getHorseOddsFinderNetkeibaRaces', [ApiController::class, 'getHorseOddsFinderNetkeibaRaces']);
Route::get('getHorseOddsFinderNetkeibaOdds', [ApiController::class, 'getHorseOddsFinderNetkeibaOdds']);
Route::get('getHorseOddsFinderOddsGetTiming', [ApiController::class, 'getHorseOddsFinderOddsGetTiming']);

Route::get('getHorseDetail', [ApiController::class, 'getHorseDetail']);

Route::get('getHorseOddsFinderOddsWide', [ApiController::class, 'getHorseOddsFinderOddsWide']);

Route::get('getHorseOddsFinderSummary', [ApiController::class, 'getHorseOddsFinderSummary']);
