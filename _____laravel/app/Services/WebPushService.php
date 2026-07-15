<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Google\Auth\Credentials\ServiceAccountCredentials;

class WebPushService
{
    private string $projectId;
    private string $credentialsPath;

    public function __construct()
    {
        $this->projectId = config('services.fcm.project_id');
        $this->credentialsPath = config('services.fcm.credentials');
    }

    // 開発者向け通知（特定ユーザーのみ）
    public function sendPushNotifierDeveloperNews(string $title, string $body): void
    {
        $developerUserIds = ['toyohide'];

        $subscriptions = DB::table('t_horse_odds_finder_push_subscriptions')
            ->whereIn('user_id', $developerUserIds)
            ->get();

        $this->sendToSubscriptions($subscriptions, $title, $body);
    }

    // オッズ通知（全ユーザー向け）
    public function sendPushNotifierOddsNews(string $title, string $body, string $url = 'https://baganriki.com/horse_odds_finder/'): void
    {
        $subscriptions = DB::table('t_horse_odds_finder_push_subscriptions')
            ->where('is_delete', '0')
            ->get();

        $this->sendToSubscriptions($subscriptions, $title, $body, $url);
    }

    // 内部送信処理
    private function sendToSubscriptions($subscriptions, string $title, string $body, string $url = 'https://baganriki.com/horse_odds_finder/'): void
    {
        $accessToken = $this->getAccessToken();

        foreach ($subscriptions as $row) {
            $this->sendFcmV1($row, $title, $body, $url, $accessToken);
        }

        // 送信ログを記録
        DB::table('t_horse_odds_finder_push_send_logs')->insert([
            'title'      => $title,
            'body'       => $body,
            'url'        => $url,
            'sent_count' => $subscriptions->count(),
            'sent_at'    => now(),
        ]);
    }

    private function getAccessToken(): string
    {
        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
        $credentials = new ServiceAccountCredentials($scopes, $this->credentialsPath);
        $token = $credentials->fetchAuthToken();
        return $token['access_token'];
    }

    private function sendFcmV1($row, string $title, string $body, string $url, string $accessToken): void
    {
        // FCM登録トークンをendpointから抽出
        $endpoint = $row->endpoint;
        $token = basename(parse_url($endpoint, PHP_URL_PATH));

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                ],
                'webpush' => [
                    'notification' => [
                        'icon' => '/horse_odds_finder/icons/Icon-192.png',
                    ],
                    'fcm_options' => [
                        'link' => $url,
                    ],
                ],
            ],
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Log::warning('WebPush送信失敗: ' . $response . ' endpoint: ' . $endpoint);
            // 無効なsubscriptionを削除
            if ($httpCode === 404 || $httpCode === 410) {
                DB::table('t_horse_odds_finder_push_subscriptions')
                    ->where('endpoint', $endpoint)
                    ->delete();
            }
        }
    }
}
