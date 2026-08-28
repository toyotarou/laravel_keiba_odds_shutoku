<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

// メールアドレス確認（signup 後に送信される確認メール内のリンクから呼ばれる）
Route::get('/verify', [AuthController::class, 'verify']);

Route::get('/', function () {
    return view('welcome');
});
