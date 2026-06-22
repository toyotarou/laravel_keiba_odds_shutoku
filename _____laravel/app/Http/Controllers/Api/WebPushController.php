<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebPushController extends Controller
{
    // Subscription登録
    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'  => 'required|string',
            'endpoint' => 'required|string',
            'p256dh'   => 'required|string',
            'auth'     => 'required|string',
        ]);

        DB::table('t_horse_odds_finder_push_subscriptions')->upsert(
            [
                'user_id'   => $request->input('user_id'),
                'endpoint'  => $request->input('endpoint'),
                'p256dh'    => $request->input('p256dh'),
                'auth'      => $request->input('auth'),
                'is_delete' => 0,
            ],
            ['endpoint'],
            ['user_id', 'p256dh', 'auth', 'is_delete']
        );

        return response()->json(['success' => true]);
    }

    // VAPID公開鍵を返す
    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'public_key' => config('services.vapid.public_key'),
        ]);
    }
}
