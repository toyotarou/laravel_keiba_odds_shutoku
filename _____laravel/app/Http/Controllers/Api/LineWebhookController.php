<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;

class LineWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $events = $request->input('events', []);

        $httpClient = new CurlHTTPClient(config('services.line.channel_access_token'));
        $bot = new LINEBot($httpClient, ['channelSecret' => config('services.line.channel_secret')]);

        foreach ($events as $event) {

            // 友だち追加（follow）またはメッセージ送信（message）のときだけ処理
            if (!in_array($event['type'] ?? '', ['follow', 'message'])) {
                continue;
            }

            $userId = $event['source']['userId'] ?? null;
            $replyToken = $event['replyToken'] ?? null;

            if (empty($userId)) {
                continue;
            }

            // すでにテーブルに登録済みかどうかを先に確認
            $alreadyExists = DB::table('t_horse_odds_finder_line_users')
                ->where('user_id', $userId)
                ->exists();

            // 重複しないように保存（初回でも2回目以降でも実行）
            DB::table('t_horse_odds_finder_line_users')->updateOrInsert(
                ['user_id' => $userId]
            );

            Log::info('LINE User IDを保存しました: ' . $userId);

            // 返信メッセージを分岐
            if (!$alreadyExists) {
                // 初回
                $replyText = "登録ありがとうございます！\n今後、最新情報を配信していきます。";
            } else {
                // 2回目以降
                $replyText = "ご登録は完了しております😊\n配信をお楽しみに！";
            }

            if ($replyToken) {
                $bot->replyMessage($replyToken, new TextMessageBuilder($replyText));
            }
        }

        // LINEサーバーには必ず200を返す
        return response()->json(['status' => 'ok']);
    }
}
