<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\ApiController;
Route::get('/verify', [ApiController::class, 'verify']);

Route::get('/', function () {
    return view('welcome');
});
