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

        // 20261101: アプリ起動のたびに subscribe が呼ばれるため、is_delete=0 固定だと
        // ホーム画面のスイッチで OFF にしても次の起動で ON に戻ってしまう。
        // そのユーザーの今の設定（ON/OFF）を引き継いで登録する。
        $userId   = $request->input('user_id');
        $isDelete = $this->getUserPushIsDelete($userId);

        DB::table('t_horse_odds_finder_push_subscriptions')->upsert(
            [
                'user_id'   => $userId,
                'endpoint'  => $request->input('endpoint'),
                'p256dh'    => $request->input('p256dh'),
                'auth'      => $request->input('auth'),
                // 'is_delete' => 0,
                'is_delete' => $isDelete,
            ],
            ['endpoint'],
            ['user_id', 'p256dh', 'auth', 'is_delete']
        );

        return response()->json(['success' => true]);
    }

    // 20261101: ユーザーの今の push 受信設定を is_delete の値で返す（0=受信 / 1=非受信）
    // ・1件でも is_delete=0 の行があれば受信（0）
    // ・行はあるが全部 is_delete=1 なら非受信（1）
    // ・行が無ければ受信（0）＝これまでどおり
    private function getUserPushIsDelete(string $userId): int
    {
        $rows = DB::table('t_horse_odds_finder_push_subscriptions')
            ->where('user_id', $userId)
            ->pluck('is_delete');

        if ($rows->isEmpty()) {
            return 0;
        }

        return $rows->contains(fn ($v) => (int) $v === 0) ? 0 : 1;
    }

    // 20261101: ホーム画面の「通知」スイッチ用。ユーザーの受信設定を返す
    //   is_receive : 1=受信 / 0=非受信
    //   count      : そのユーザーの登録端末数（0 なら通知が許可されていない）
    public function getMyPushReceive(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|string',
        ]);

        $userId = $request->input('user_id');

        $count = DB::table('t_horse_odds_finder_push_subscriptions')
            ->where('user_id', $userId)
            ->count();

        $isDelete = $this->getUserPushIsDelete($userId);

        return response()->json([
            'is_receive' => $isDelete === 0 ? 1 : 0,
            'count'      => $count,
        ]);
    }

    // 20261101: ホーム画面の「通知」スイッチ用。ユーザーの全端末の is_delete をまとめて切り替える
    //   is_receive : 1=受信（is_delete=0） / 0=非受信（is_delete=1）
    public function changeMyPushReceive(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'    => 'required|string',
            'is_receive' => 'required|integer|in:0,1',
        ]);

        $userId    = $request->input('user_id');
        $isReceive = (int) $request->input('is_receive');

        $updated = DB::table('t_horse_odds_finder_push_subscriptions')
            ->where('user_id', $userId)
            ->update(['is_delete' => $isReceive === 1 ? 0 : 1]);

        return response()->json(['success' => true, 'updated' => $updated]);
    }

    // VAPID公開鍵を返す
    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'public_key' => config('services.vapid.public_key'),
        ]);
    }
}
