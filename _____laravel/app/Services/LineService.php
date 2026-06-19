<?php

namespace App\Services;

use DB;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;

class LineService
{
    public function sendLineDevelopperNews(string $message): void
    {
        $token  = config('services.line.channel_access_token');
        $secret = config('services.line.channel_secret');

        $httpClient = new CurlHTTPClient($token);
        $bot        = new LINEBot($httpClient, ['channelSecret' => $secret]);

        $messageBuilder = new TextMessageBuilder($message);
        $response = $bot->multicast(['Ue36f9e60c721d0f54c335b4b4f273399'], $messageBuilder);

        if (!$response->isSucceeded()) {
            throw new \Exception('LINE送信失敗: ' . $response->getRawBody());
        }
    }
    


    public function sendLineOddsNews(string $message, array $userIds = []): void
    {
        $token  = config('services.line.channel_access_token');
        $secret = config('services.line.channel_secret');

        // userIdsが渡されなかった場合はDBに登録されている全ユーザーに送る
        if (empty($userIds)) {
            $userIds = DB::table('t_horse_odds_finder_line_users')
                ->pluck('user_id')
                ->filter()
                ->values()
                ->toArray();
        }

        if (empty($token) || empty($userIds)) {
            return;
        }

        $httpClient = new CurlHTTPClient($token);
        $bot        = new LINEBot($httpClient, ['channelSecret' => $secret]);

        $messageBuilder = new TextMessageBuilder($message);
        $response = $bot->multicast(array_values($userIds), $messageBuilder);
        
        if (!$response->isSucceeded()) {
            throw new \Exception('LINE送信失敗: ' . $response->getRawBody());
        }
    }
    
}
