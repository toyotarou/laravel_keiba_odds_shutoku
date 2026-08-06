<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    private WebPush $webPush;

    public function __construct()
    {
        $auth = [
            'VAPID' => [
                'subject'    => config('services.vapid.subject'),
                'publicKey'  => config('services.vapid.public_key'),
                'privateKey' => config('services.vapid.private_key'),
            ],
        ];
        $this->webPush = new WebPush($auth);
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
        $subscriptions = DB::table('t_horse_odds_finder_push_subscriptions')->get();
        $this->sendToSubscriptions($subscriptions, $title, $body, $url);
    }

    private function sendToSubscriptions($subscriptions, string $title, string $body, string $url = 'https://baganriki.com/horse_odds_finder/'): void
    {
        $sentCount = 0;

        try {
            foreach ($subscriptions as $row) {
                $subscription = Subscription::create([
                    'endpoint' => $row->endpoint,
                    'keys'     => ['p256dh' => $row->p256dh, 'auth' => $row->auth],
                ]);
                $this->webPush->queueNotification(
                    $subscription,
                    json_encode([
                        'title' => $title,
                        'body'  => $body,
                        'icon'  => '/horse_odds_finder/icons/Icon-192.png',
                        'url'   => $url,
                    ])
                );
            }
            foreach ($this->webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $sentCount++;
                } else {
                    Log::warning('WebPush送信失敗: ' . $report->getReason());
                    DB::table('t_horse_odds_finder_push_subscriptions')
                        ->where('endpoint', $report->getEndpoint())
                        ->delete();
                }
            }
        } catch (\Throwable $e) {
            Log::error('WebPush送信エラー: ' . $e->getMessage());
        } finally {
            DB::table('t_horse_odds_finder_push_send_logs')->insert([
                'title'      => $title,
                'body'       => $body,
                'url'        => $url,
                'sent_count' => $sentCount,
                'sent_at'    => now(),
            ]);
        }
    }
}
