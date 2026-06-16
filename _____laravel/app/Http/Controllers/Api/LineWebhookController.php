<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LineWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $events = $request->input('events', []);

        foreach ($events as $event) {

            // 友だち追加（follow）またはメッセージ送信（message）のときだけ処理
            if (!in_array($event['type'] ?? '', ['follow', 'message'])) {
                continue;
            }

            $userId = $event['source']['userId'] ?? null;

            if (empty($userId)) {
                continue;
            }

            // 重複しないように保存
            DB::table('t_horse_odds_finder_line_users')->updateOrInsert(
                ['user_id' => $userId]
            );

            Log::info('LINE User IDを保存しました: ' . $userId);
        }

        // LINEサーバーには必ず200を返す
        return response()->json(['status' => 'ok']);
    }
}
